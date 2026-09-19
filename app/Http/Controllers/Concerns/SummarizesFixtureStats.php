<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Fixture;
use App\Models\FixtureLineup;
use Illuminate\Database\Eloquent\Collection;

trait SummarizesFixtureStats
{
    /** @var array<string, string> */
    private static array $teamStatLabels = [
        'shotsOnTarget' => 'Tiros a puerta',
        'totalShots' => 'Tiros totales',
        'foulsCommitted' => 'Faltas cometidas',
        'offsides' => 'Fueras de juego',
        'saves' => 'Paradas',
        'goalAssists' => 'Asistencias',
        'yellowCards' => 'Tarjetas amarillas',
        'redCards' => 'Tarjetas rojas',
    ];

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

    /**
     * Shapes worldcup26's own per-player stats into the same JornadaStats
     * form as fantasy_stats, for a lineup entry with no Fantasy data to draw
     * from (player has no fantasy_id, or was never resolved to a Player at
     * all). Only covers what worldcup26 actually reports — no penalty
     * won/conceded/saved/missed, no second-yellow distinction, no minutes
     * played, so clean sheets and those badges just won't show for these
     * entries.
     *
     * @param  array<int, array<string, mixed>>  $stats
     * @return array<string, array{int, int}>
     */
    private function worldcup26StatsFallback(array $stats): array
    {
        /** @var array<string, string> $keyMap worldcup26 name => JornadaStats key */
        $keyMap = [
            'totalGoals' => 'goals',
            'ownGoals' => 'own_goals',
            'goalAssists' => 'goal_assist',
            'yellowCards' => 'yellow_card',
            'redCards' => 'red_card',
            'goalsConceded' => 'goals_conceded',
        ];

        $shaped = [];

        foreach ($keyMap as $worldcup26Key => $jornadaKey) {
            $shaped[$jornadaKey] = [$this->statValue($stats, $worldcup26Key), 0];
        }

        return $shaped;
    }

    /**
     * @param  Collection<int, FixtureLineup>  $fixtureLineups  Already loaded, scoped to this fixture.
     * @return list<array{stat: string, label: string, local: int, guest: int}>
     */
    private function teamStats(Collection $fixtureLineups, Fixture $fixture): array
    {
        $rows = [];

        foreach (self::$teamStatLabels as $key => $label) {
            $rows[] = [
                'stat' => $key,
                'label' => $label,
                'local' => $fixtureLineups->where('team_id', $fixture->team_local_id)
                    ->sum(fn (FixtureLineup $lineup): int => $this->statValue($lineup->stats, $key)),
                'guest' => $fixtureLineups->where('team_id', $fixture->team_guest_id)
                    ->sum(fn (FixtureLineup $lineup): int => $this->statValue($lineup->stats, $key)),
            ];

            if ($key === 'totalShots') {
                array_push($rows, ...$this->boxscoreTeamStats($fixture));
            }
        }

        return $rows;
    }

    /**
     * Corners and key passes only come from worldcup26's team-level boxscore,
     * not from any player's stats, so they're skipped while the fixture hasn't
     * had one synced yet.
     *
     * @return list<array{stat: string, label: string, local: int, guest: int}>
     */
    private function boxscoreTeamStats(Fixture $fixture): array
    {
        $rows = [];

        if ($fixture->local_corners !== null && $fixture->guest_corners !== null) {
            $rows[] = ['stat' => 'wonCorners', 'label' => 'Córners', 'local' => $fixture->local_corners, 'guest' => $fixture->guest_corners];
        }

        if ($fixture->local_key_passes !== null && $fixture->guest_key_passes !== null) {
            $rows[] = ['stat' => 'keyPasses', 'label' => 'Pases clave', 'local' => $fixture->local_key_passes, 'guest' => $fixture->guest_key_passes];
        }

        return $rows;
    }
}
