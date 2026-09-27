<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FixtureState;
use App\Enums\FutbolFantasyLinkRule;
use App\Http\Integrations\FutbolFantasy\FutbolFantasyConnector;
use App\Models\Fixture;
use App\Models\PlayerStartProbability;
use App\Models\Season;
use App\Models\Team;
use App\Services\FutbolFantasyPageException;
use App\Services\FutbolFantasyPlayer;
use App\Services\FutbolFantasyPlayerLinker;
use App\Services\FutbolFantasyTeamPage;
use App\Services\FutbolFantasyTeamPageParser;
use App\Services\FutbolFantasyTeams;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-start-probabilities {--force : Fetch every team now, ignoring when each one is next due}')]
#[Description('Synchronize every team\'s start probabilities for its next match from its FútbolFantasy team page')]
class SyncCurrentSeasonStartProbabilities extends Command
{
    /** A team whose next match is this close is fetched on every run. */
    private const int EVERY_RUN_WITHIN_HOURS = 48;

    /** Further away, a team is fetched at most this often. */
    private const int FAR_AWAY_EVERY_HOURS = 6;

    private const int MIN_PAUSE_SECONDS = 10;

    private const int MAX_PAUSE_SECONDS = 30;

    private const string ATTEMPTED_AT_CACHE_PREFIX = 'start_probabilities.attempted_at.';

    private int $playersParsed = 0;

    /** @var array<string, int> FutbolFantasyLinkRule value => players linked by it */
    private array $linkedByRule = [];

    /** @var list<string> */
    private array $unlinked = [];

    /**
     * @throws Throwable
     */
    public function handle(FutbolFantasyConnector $connector, FutbolFantasyTeamPageParser $parser, FutbolFantasyPlayerLinker $linker): int
    {
        $season = Season::current();
        $force = (bool) $this->option('force');
        $counts = ['fetched' => 0, 'failed' => 0, 'not_due' => 0, 'no_match' => 0];

        /** @var list<string> $missingFromMap */
        $missingFromMap = [];
        $requests = 0;

        foreach ($season->teams()->orderBy('short_name')->get() as $team) {
            $slug = FutbolFantasyTeams::slugFor($team->fantasy_id);

            if ($slug === null) {
                $missingFromMap[] = $team->short_name;

                continue;
            }

            $nextFixture = $this->nextFixture($team, $season);

            if ($nextFixture === null) {
                $counts['no_match']++;

                continue;
            }

            if (!$force && !$this->isDue($team, $nextFixture)) {
                $counts['not_due']++;

                continue;
            }

            if ($requests > 0) {
                Sleep::for(random_int(self::MIN_PAUSE_SECONDS, self::MAX_PAUSE_SECONDS))->seconds();
            }

            $requests++;
            Cache::forever(self::ATTEMPTED_AT_CACHE_PREFIX.$team->id, now()->getTimestamp());

            try {
                $page = $parser->parse($connector->getTeamPage($slug)->throw()->body());
            } catch (FatalRequestException|RequestException|FutbolFantasyPageException $exception) {
                $counts['failed']++;
                $this->warnAndLog("Skipped {$team->short_name}: {$exception->getMessage()}");

                continue;
            }

            $counts['fetched']++;
            $this->playersParsed += count($page->players);
            $this->store($team, $season, $page, $linker);
        }

        $this->summarize($counts, $missingFromMap);

        return self::SUCCESS;
    }

    /**
     * The team's soonest match that hasn't kicked off — null once the season
     * has none left, which also means a team is never fetched after kickoff.
     */
    private function nextFixture(Team $team, Season $season): ?Fixture
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where('date', '>', now())
            ->where(fn ($query) => $query
                ->where('team_local_id', $team->id)
                ->orWhere('team_guest_id', $team->id))
            ->orderBy('date')
            ->first();
    }

    private function isDue(Team $team, Fixture $nextFixture): bool
    {
        if ($nextFixture->date->lessThanOrEqualTo(now()->addHours(self::EVERY_RUN_WITHIN_HOURS))) {
            return true;
        }

        $attemptedAt = Cache::get(self::ATTEMPTED_AT_CACHE_PREFIX.$team->id);

        return !is_int($attemptedAt) || $attemptedAt <= now()->subHours(self::FAR_AWAY_EVERY_HOURS)->getTimestamp();
    }

    /**
     * Upserts the page onto the team's fixture of that jornada. A confirmed
     * page ("Titular"/"Suplente", no %) only sets `confirmed_starter`: the
     * last predicted % and FF's probable XI stay for the "Sorpresa / Se cae
     * · era N %" marks. Nothing is written for a fixture that has already
     * kicked off — its rows are history.
     *
     * @throws Throwable
     */
    private function store(Team $team, Season $season, FutbolFantasyTeamPage $page, FutbolFantasyPlayerLinker $linker): void
    {
        $fixture = Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', $page->weekNumber)
            ->where(fn ($query) => $query
                ->where('team_local_id', $team->id)
                ->orWhere('team_guest_id', $team->id))
            ->with(['localTeam', 'guestTeam'])
            ->first();

        if ($fixture === null) {
            $this->warnAndLog("Skipped {$team->short_name}: no J{$page->weekNumber} fixture");

            return;
        }

        if ($fixture->state !== FixtureState::Scheduled || $fixture->date->lessThanOrEqualTo(now())) {
            $this->warnAndLog("Skipped {$team->short_name}: J{$page->weekNumber} has already kicked off, its rows stay as they are");

            return;
        }

        $opponent = $fixture->team_local_id === $team->id ? $fixture->guestTeam : $fixture->localTeam;
        $expectedRival = FutbolFantasyTeams::codeFor($opponent->fantasy_id);
        $pageRival = $page->rivalCode();

        if ($expectedRival !== null && $pageRival !== '' && $pageRival !== $expectedRival) {
            $this->warnAndLog("Skipped {$team->short_name}: the J{$page->weekNumber} page is against {$pageRival}, the fixture against {$expectedRival}");

            return;
        }

        $links = $linker->link($team, $season, $page->players);
        $fetchedAt = now();

        /** @var list<FutbolFantasyPlayer> $unlinked */
        $unlinked = [];

        DB::transaction(function () use ($page, $links, $fixture, $fetchedAt, &$unlinked): void {
            foreach ($page->players as $ffPlayer) {
                $link = $links[$ffPlayer->futbolfantasyId] ?? null;

                if ($link === null) {
                    $unlinked[] = $ffPlayer;

                    continue;
                }

                $rule = $link['rule']->value;
                $this->linkedByRule[$rule] = ($this->linkedByRule[$rule] ?? 0) + 1;

                PlayerStartProbability::query()->updateOrCreate(
                    ['player_id' => $link['player']->id, 'fixture_id' => $fixture->id],
                    $ffPlayer->confirmedStarter === null
                        ? [
                            'probability' => $ffPlayer->probability,
                            'predicted_starter' => $ffPlayer->predictedStarter,
                            'confirmed_starter' => null,
                            'fetched_at' => $fetchedAt,
                        ]
                        : [
                            'confirmed_starter' => $ffPlayer->confirmedStarter,
                            'fetched_at' => $fetchedAt,
                        ],
                );
            }
        });

        $names = array_map(
            fn (FutbolFantasyPlayer $ffPlayer): string => "{$ffPlayer->name} ({$team->short_name}, FF {$ffPlayer->futbolfantasyId})",
            $unlinked,
        );

        if ($names !== []) {
            $this->unlinked = [...$this->unlinked, ...$names];
            Log::warning('season:sync-start-probabilities — unlinked FútbolFantasy players: '.implode(', ', $names));
        }
    }

    /**
     * @param  array{fetched: int, failed: int, not_due: int, no_match: int}  $counts
     * @param  list<string>  $missingFromMap
     */
    private function summarize(array $counts, array $missingFromMap): void
    {
        $this->info("Teams: {$counts['fetched']} fetched, {$counts['failed']} failed, {$counts['not_due']} not due, {$counts['no_match']} without an upcoming match.");
        $this->info("Players: {$this->playersParsed} parsed.");
        $this->info(sprintf(
            'Linked: %d by stored id, %d by market value, %d by name, %d by manual map.',
            ...array_map(fn (FutbolFantasyLinkRule $rule): int => $this->linkedByRule[$rule->value] ?? 0, FutbolFantasyLinkRule::cases()),
        ));

        if ($this->unlinked !== []) {
            $this->warn('Unlinked ('.count($this->unlinked).'): '.implode(', ', $this->unlinked));
        }

        if ($missingFromMap !== []) {
            $this->warnAndLog('Missing from the FútbolFantasy team map: '.implode(', ', $missingFromMap));
        }
    }

    private function warnAndLog(string $message): void
    {
        $this->warn($message);
        Log::warning("season:sync-start-probabilities — {$message}");
    }
}
