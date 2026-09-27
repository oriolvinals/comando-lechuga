<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The match page's "Marcador fantasy": once a fixture is finished, each
 * side's total fantasy points (the sum of its players' points in this
 * match), each side's best fantasy player, and the managers whose jornada
 * LINEUP fielded players here, with the points this match gave them.
 *
 * @phpstan-type FantasySide array{points: int, best_lineup_id: int|null}
 * @phpstan-type FantasyManager array{id: int, name: string, primary_color: string|null, points: int}
 * @phpstan-type FantasyScoreboard array{local: FantasySide, guest: FantasySide, managers: list<FantasyManager>}
 */
class FixtureFantasyScoreboard
{
    public function __construct(private JornadaMatches $jornadaMatches) {}

    /**
     * `null` unless the fixture is finished and its players have fantasy points.
     *
     * @param  Collection<int, FixtureLineup>  $fixtureLineups  This fixture's lineup rows, already loaded.
     * @param  EloquentCollection<int, Fixture>  $weekFixtures  Every fixture of the fixture's week.
     * @return FantasyScoreboard|null
     */
    public function forFixture(Fixture $fixture, Collection $fixtureLineups, EloquentCollection $weekFixtures): ?array
    {
        if ($fixture->state !== FixtureState::Finished) {
            return null;
        }

        $scored = $fixtureLineups
            ->filter(fn (FixtureLineup $lineup): bool => $lineup->player_id !== null && $lineup->fantasy_points !== null)
            ->values();

        if ($scored->isEmpty()) {
            return null;
        }

        $managers = $this->jornadaMatches->managersByFixture(
            $fixture->season_id,
            $fixture->week_number,
            $weekFixtures,
            collect([$fixture]),
        )[$fixture->id] ?? [];

        return [
            'local' => $this->side($scored->where('team_id', $fixture->team_local_id)),
            'guest' => $this->side($scored->where('team_id', $fixture->team_guest_id)),
            'managers' => array_map(fn (array $manager): array => [
                'id' => $manager['id'],
                'name' => $manager['name'],
                'primary_color' => $manager['primary_color'],
                'points' => $manager['points'] ?? 0,
            ], $managers),
        ];
    }

    /**
     * A side's total and its best player: most points, then most minutes
     * played, then lowest jersey number.
     *
     * @param  Collection<int, FixtureLineup>  $lineups
     * @return FantasySide
     */
    private function side(Collection $lineups): array
    {
        $best = $lineups
            ->sort(fn (FixtureLineup $a, FixtureLineup $b): int => $b->fantasy_points <=> $a->fantasy_points
                ?: $this->minutesPlayed($b) <=> $this->minutesPlayed($a)
                ?: (int) $a->jersey <=> (int) $b->jersey)
            ->first();

        return [
            'points' => (int) $lineups->sum('fantasy_points'),
            'best_lineup_id' => $best?->id,
        ];
    }

    private function minutesPlayed(FixtureLineup $lineup): int
    {
        return (int) ($lineup->fantasy_stats['mins_played'][0] ?? 0);
    }
}
