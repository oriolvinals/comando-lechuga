<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FixtureState;
use App\Enums\FutbolFantasyLinkRule;
use App\Http\Integrations\FutbolFantasy\FutbolFantasyConnector;
use App\Models\Fixture;
use App\Models\FixtureLineupProbability;
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
use Symfony\Component\Console\Helper\ProgressBar;
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

    /** @var list<string> alternatives kept with FF's name only, no player of ours */
    private array $unlinkedAlternatives = [];

    /** One step per team; its message says what the command is doing right now (fetching, or pausing between requests). */
    private ?ProgressBar $progress = null;

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

        $teams = $season->teams()->orderBy('short_name')->get();
        $this->progress = $this->output->createProgressBar($teams->count());
        $this->progress->setFormat(' %current%/%max% [%bar%] %elapsed:6s% %message%');
        $this->progress->setMessage('');
        $this->progress->start();

        foreach ($teams as $team) {
            $this->progress->setMessage($team->short_name);
            $this->progress->display();

            $slug = FutbolFantasyTeams::slugFor($team->fantasy_id);

            if ($slug === null) {
                $missingFromMap[] = $team->short_name;
                $this->progress->advance();

                continue;
            }

            $nextFixture = $this->nextFixture($team, $season);

            if ($nextFixture === null) {
                $counts['no_match']++;
                $this->progress->advance();

                continue;
            }

            if (!$force && !$this->isDue($team, $nextFixture)) {
                $counts['not_due']++;
                $this->progress->advance();

                continue;
            }

            if ($requests > 0) {
                $pause = random_int(self::MIN_PAUSE_SECONDS, self::MAX_PAUSE_SECONDS);
                $this->progress->setMessage("{$team->short_name}: waiting {$pause}s before the next request");
                $this->progress->display();
                Sleep::for($pause)->seconds();
            }

            $this->progress->setMessage("{$team->short_name}: fetching its FútbolFantasy page");
            $this->progress->display();

            $requests++;
            Cache::forever(self::ATTEMPTED_AT_CACHE_PREFIX.$team->id, now()->getTimestamp());

            try {
                $page = $parser->parse($connector->getTeamPage($slug)->throw()->body());
            } catch (FatalRequestException|RequestException|FutbolFantasyPageException $exception) {
                $counts['failed']++;
                $this->warnAndLog("Skipped {$team->short_name}: {$exception->getMessage()}");
                $this->progress->advance();

                continue;
            }

            $counts['fetched']++;
            $this->playersParsed += count($page->players);
            $this->store($team, $season, $page, $linker);
            $this->progress->advance();
        }

        $this->progress->setMessage('done');
        $this->progress->finish();
        $this->progress = null;
        $this->newLine();

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

        return !is_numeric($attemptedAt) || (int) $attemptedAt <= now()->subHours(self::FAR_AWAY_EVERY_HOURS)->getTimestamp();
    }

    /**
     * Upserts the page onto the team's fixture of that jornada. A confirmed
     * page ("Titular"/"Suplente", no %) only sets `confirmed_starter`: the
     * last predicted % and FF's probable XI stay for the "Sorpresa / Se cae
     * · era N %" marks. Each predicted row's alternatives (the players FF
     * lists under him as the ones who could start instead) are replaced on
     * every sync and deleted once the lineup is confirmed. Nothing is written
     * for a fixture that has already kicked off — its rows are history.
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

        /** @var list<string> $unlinkedAlternatives */
        $unlinkedAlternatives = [];

        DB::transaction(function () use ($team, $page, $links, $linker, $fixture, $fetchedAt, &$unlinked, &$unlinkedAlternatives): void {
            foreach ($page->players as $ffPlayer) {
                $link = $links[$ffPlayer->futbolfantasyId] ?? null;

                if ($link === null) {
                    $unlinked[] = $ffPlayer;

                    continue;
                }

                $rule = $link['rule']->value;
                $this->linkedByRule[$rule] = ($this->linkedByRule[$rule] ?? 0) + 1;

                $row = FixtureLineupProbability::query()->updateOrCreate(
                    ['player_id' => $link['player']->id, 'fixture_id' => $fixture->id],
                    $ffPlayer->confirmedStarter === null
                        ? [
                            'probability' => $ffPlayer->probability,
                            'predicted_starter' => $ffPlayer->predictedStarter,
                            'confirmed_starter' => null,
                            'pitch_x' => $ffPlayer->pitchX,
                            'pitch_y' => $ffPlayer->pitchY,
                            'fetched_at' => $fetchedAt,
                        ]
                        : [
                            'confirmed_starter' => $ffPlayer->confirmedStarter,
                            'fetched_at' => $fetchedAt,
                        ],
                );

                $row->alternatives()->delete();

                if ($ffPlayer->confirmedStarter !== null) {
                    continue;
                }

                foreach ($ffPlayer->alternatives as $alternative) {
                    $player = $linker->linkAlternative($team, $alternative, $page->players, $links);

                    if ($player === null) {
                        $unlinkedAlternatives[] = "{$alternative->name} (alternative to {$ffPlayer->name}, {$team->short_name})";
                    }

                    $row->alternatives()->create([
                        'position' => $alternative->position,
                        'player_id' => $player?->id,
                        'name' => $alternative->name,
                        'futbolfantasy_slug' => $alternative->slug,
                    ]);
                }
            }
        });

        if ($unlinkedAlternatives !== []) {
            $this->unlinkedAlternatives = [...$this->unlinkedAlternatives, ...$unlinkedAlternatives];
            Log::warning('season:sync-start-probabilities — unlinked FútbolFantasy alternatives: '.implode(', ', $unlinkedAlternatives));
        }

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

        if ($this->unlinkedAlternatives !== []) {
            $this->warn('Unlinked alternatives ('.count($this->unlinkedAlternatives).'): '.implode(', ', $this->unlinkedAlternatives));
        }

        if ($missingFromMap !== []) {
            $this->warnAndLog('Missing from the FútbolFantasy team map: '.implode(', ', $missingFromMap));
        }
    }

    private function warnAndLog(string $message): void
    {
        $this->progress?->clear();
        $this->warn($message);
        $this->progress?->display();
        Log::warning("season:sync-start-probabilities — {$message}");
    }
}
