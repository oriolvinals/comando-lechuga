<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FixtureState;
use App\Enums\MatchPositionLine;
use App\Enums\MatchPositionSide;
use App\Enums\PlayerPosition;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\PlayerScore;
use App\Models\Season;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class FixturesController extends Controller
{
    use AttachesCurrentPlayerSeason;
    use FiltersSeasonWeeks;

    private const array POSITION_ORDER = [
        'goalkeeper' => 0,
        'defender' => 1,
        'midfield' => 2,
        'striker' => 3,
        'coach' => 4,
    ];

    /** @var array<string, array{local: int, guest: int}> */
    private const array PITCH_LINE_DEPTH = [
        'goalkeeper' => ['local' => 6, 'guest' => 94],
        'defender' => ['local' => 18, 'guest' => 82],
        'midfielder' => ['local' => 30, 'guest' => 70],
        'forward' => ['local' => 40, 'guest' => 60],
    ];

    private const float PITCH_LINE_STEP = 76 / 3; // ≈ 25.333 — same per-player spacing a 4-player line already uses

    /** @var array<string, string> */
    private const array TEAM_STAT_LABELS = [
        'shotsOnTarget' => 'Tiros a puerta',
        'totalShots' => 'Tiros totales',
        'foulsCommitted' => 'Faltas cometidas',
        'saves' => 'Paradas',
        'goalAssists' => 'Asistencias',
        'yellowCards' => 'Tarjetas amarillas',
    ];

    public function index(): Response
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('week_number')
            ->orderBy('date')
            ->get();

        return Inertia::render('fixtures/index', [
            'season' => $season,
            'fixtures' => $fixtures,
            // Cast to object: PHP normalizes numeric string keys back to
            // int, so a plain array here could serialize as a sparse JSON
            // array instead of the {"1": "all", ...} object the frontend expects.
            'weekProgress' => (object) $this->weekProgress($season),
        ]);
    }

    public function show(Fixture $fixture): Response
    {
        $fixture->load(['localTeam', 'guestTeam']);

        $weekFixtures = Fixture::query()
            ->where('season_id', $fixture->season_id)
            ->where('week_number', $fixture->week_number)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->get();

        $scores = $fixture->playerScores()
            ->whereHas('player.seasons', fn ($query) => $query
                ->where('season_id', $fixture->season_id)
                ->where('position', '!=', PlayerPosition::Coach))
            ->with(['player', 'team'])
            ->get();

        $this->attachCurrentSeason($scores->pluck('player'), $fixture->season_id);

        $scores = $scores
            ->sortByDesc('points')
            ->sortBy(fn ($score): int => self::POSITION_ORDER[$score->player->position->value])
            ->values();

        $fixtureLineups = FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->with('player.team', 'counterpartPlayer')
            ->get();

        // Players loaded through the lineup relation are separate model
        // instances from $scores->pluck('player') above and never go through
        // attachCurrentSeason() otherwise, leaving their `position` accessor
        // blank on the pitch/bench. ->filter() drops null players from
        // unresolved lineup rows.
        $this->attachCurrentSeason($fixtureLineups->pluck('player')->filter(), $fixture->season_id);

        // Which manager fielded each player in their lineup this jornada — distinct
        // from ownership, since an owner can bench a player they still own. Covers
        // both scored players and lineup players who don't have a score yet.
        $lineupManagersByPlayer = ManagerLineupPlayer::query()
            ->whereIn('player_id', $scores->pluck('player_id')->merge($fixtureLineups->pluck('player_id'))->filter()->unique())
            ->whereHas('lineup', fn ($query) => $query
                ->where('week_number', $fixture->week_number)
                ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $fixture->season_id)))
            ->with('lineup.seasonManager')
            ->get()
            ->keyBy('player_id');

        $scores->each(function (PlayerScore $score) use ($lineupManagersByPlayer): void {
            $score->lineup_manager = $lineupManagersByPlayer->get($score->player_id)?->lineup?->seasonManager;
        });

        $scoresByPlayerId = $scores->keyBy('player_id');

        $lineups = $fixtureLineups
            ->map(fn (FixtureLineup $lineup): array => $this->presentLineup($lineup, $fixture, $scoresByPlayerId, $lineupManagersByPlayer, $fixtureLineups));

        $events = FixtureEvent::query()
            ->where('fixture_id', $fixture->id)
            ->with('player.team')
            ->orderBy('minute')
            ->get()
            ->map(fn (FixtureEvent $event): array => [
                'id' => $event->id,
                'minute' => $event->minute,
                'type' => $event->type,
                'team_id' => $event->team_id,
                'is_own_goal' => $event->is_own_goal,
                'is_penalty' => $event->is_penalty,
                'player' => $event->player,
            ]);

        return Inertia::render('fixtures/show', [
            'fixture' => $fixture,
            'weekFixtures' => $weekFixtures,
            'scores' => $scores,
            'lineups' => $lineups,
            'events' => $events,
            'team_stats' => $this->teamStats($fixtureLineups, $fixture),
        ]);
    }

    /**
     * @param  Collection<int, PlayerScore>  $scoresByPlayerId
     * @param  Collection<int, ManagerLineupPlayer>  $lineupManagersByPlayer
     * @param  Collection<int, FixtureLineup>  $fixtureLineups
     * @return array<string, mixed>
     */
    private function presentLineup(FixtureLineup $lineup, Fixture $fixture, Collection $scoresByPlayerId, Collection $lineupManagersByPlayer, Collection $fixtureLineups): array
    {
        $score = $lineup->player_id !== null ? $scoresByPlayerId->get($lineup->player_id) : null;
        $isLocal = $lineup->team_id === $fixture->team_local_id;

        // DAZN ratings are only meaningful once the match is over.
        $daznPoints = $fixture->state === FixtureState::Finished
            ? ($score?->stats['marca_points'][1] ?? null)
            : null;

        return [
            'id' => $lineup->id,
            'player' => $lineup->player,
            'team_id' => $lineup->team_id,
            'starter' => $lineup->starter,
            'position' => $lineup->position,
            'jersey' => $lineup->jersey,
            'subbed_in' => $lineup->subbed_in,
            'subbed_out' => $lineup->subbed_out,
            'sub_minute' => $lineup->sub_minute,
            'counterpart_player' => $lineup->counterpartPlayer,
            'goals' => $this->statValue($lineup->stats, 'totalGoals'),
            'assists' => $this->statValue($lineup->stats, 'goalAssists'),
            'yellow_cards' => $this->statValue($lineup->stats, 'yellowCards'),
            'red_cards' => $this->statValue($lineup->stats, 'redCards'),
            'points' => $score?->points,
            'dazn_points' => $daznPoints,
            'x' => $lineup->starter ? $this->pitchX($lineup->position, $isLocal) : null,
            'y' => $lineup->starter ? $this->pitchY($lineup, $fixtureLineups) : null,
            'lineup_manager' => $lineup->player_id !== null ? $lineupManagersByPlayer->get($lineup->player_id)?->lineup?->seasonManager : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $stats
     */
    private function statValue(array $stats, string $name): int
    {
        foreach ($stats as $stat) {
            if (($stat['name'] ?? null) === $name) {
                return (int) ($stat['value'] ?? 0);
            }
        }

        return 0;
    }

    private function pitchX(string $position, bool $isLocal): float
    {
        $line = MatchPositionLine::fromWorldcup26Text($position);
        $depths = self::PITCH_LINE_DEPTH[$line->value] ?? self::PITCH_LINE_DEPTH['midfielder'];

        return (float) ($isLocal ? $depths['local'] : $depths['guest']);
    }

    /**
     * @param  Collection<int, FixtureLineup>  $fixtureLineups  Already loaded, scoped to this fixture.
     */
    private function pitchY(FixtureLineup $lineup, Collection $fixtureLineups): float
    {
        $line = MatchPositionLine::fromWorldcup26Text($lineup->position);

        $lineupMates = $fixtureLineups
            ->filter(fn (FixtureLineup $mate): bool => $mate->team_id === $lineup->team_id
                && $mate->starter
                && MatchPositionLine::fromWorldcup26Text($mate->position) === $line)
            ->sortBy([
                fn (FixtureLineup $a, FixtureLineup $b): int => $this->sideOrder($a->position) <=> $this->sideOrder($b->position),
                fn (FixtureLineup $a, FixtureLineup $b): int => $a->jersey <=> $b->jersey,
            ])
            ->values();

        $index = $lineupMates->search(fn (FixtureLineup $mate): bool => $mate->id === $lineup->id);
        $count = $lineupMates->count();

        if ($count <= 1) {
            return 50.0;
        }

        $index = $index === false ? 0 : $index;

        $step = min(self::PITCH_LINE_STEP, 76 / ($count - 1));
        $span = $step * ($count - 1);
        $start = 50 - ($span / 2);

        return round($start + ($index * $step), 1);
    }

    /**
     * @param  Collection<int, FixtureLineup>  $fixtureLineups  Already loaded, scoped to this fixture.
     * @return list<array{label: string, local: int, guest: int}>
     */
    private function teamStats(Collection $fixtureLineups, Fixture $fixture): array
    {
        return array_values(collect(self::TEAM_STAT_LABELS)
            ->map(function (string $label, string $key) use ($fixtureLineups, $fixture): array {
                $local = $fixtureLineups->where('team_id', $fixture->team_local_id)
                    ->sum(fn (FixtureLineup $lineup): int => $this->statValue($lineup->stats, $key));
                $guest = $fixtureLineups->where('team_id', $fixture->team_guest_id)
                    ->sum(fn (FixtureLineup $lineup): int => $this->statValue($lineup->stats, $key));

                return ['label' => $label, 'local' => $local, 'guest' => $guest];
            })
            ->all());
    }

    /**
     * Explicit left-to-right numeric order for sorting, since comparing
     * `MatchPositionSide::value` directly would sort lexically
     * ("center" < "left" < "right"), not the left-to-right order the pitch needs.
     */
    private function sideOrder(string $position): int
    {
        return match (MatchPositionSide::fromWorldcup26Text($position)) {
            MatchPositionSide::Left => 0,
            MatchPositionSide::Center => 1,
            MatchPositionSide::Right => 2,
        };
    }
}
