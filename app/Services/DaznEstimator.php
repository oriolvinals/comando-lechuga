<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\FixtureLineup;

/**
 * Estimates a player's DAZN rating (LaLiga Fantasy `marca_points`, 0–4) for one
 * match from the stats synced so far — baremo v1 (research "v2.5"). Fantasy's
 * per-match stats plus worldcup26 fouls when Fantasy has sent the player's stats;
 * worldcup26 alone otherwise. Pure: never queries, the caller passes the position.
 */
final class DaznEstimator
{
    public const string VERSION = 'v1';

    /** @var list<float> */
    private const array THRESHOLDS = [0.65, 1.5, 2.25, 3.0];

    /** @var list<array{0: float, 1: float}> Base + slope × m, by position index (GK, DF, MF, FW). */
    private const array FANTASY_BASE = [[0.25, 1.20], [0.80, 0.55], [0.80, 0.50], [0.75, 0.20]];

    /** @var list<array{0: float, 1: float}> */
    private const array WORLDCUP26_BASE = [[0.05, 1.75], [0.80, 1.25], [0.85, 1.05], [0.85, 0.70]];

    public function estimate(FixtureLineup $lineup, Fixture $fixture, ?PlayerPosition $position): ?DaznEstimate
    {
        $positionIndex = match ($position) {
            PlayerPosition::Goalkeeper => 0,
            PlayerPosition::Defender => 1,
            PlayerPosition::Midfield => 2,
            PlayerPosition::Striker => 3,
            default => null,
        };

        if ($positionIndex === null) {
            return null;
        }

        $fantasyStats = $lineup->fantasy_stats;
        $source = is_array($fantasyStats) ? 'fantasy' : 'worldcup26';
        $minutes = is_array($fantasyStats)
            ? (int) $this->fantasyValue($fantasyStats, 'mins_played')
            : $this->derivedMinutes($lineup, $fixture);

        if ($minutes <= 0) {
            return null;
        }

        $isLocal = $lineup->team_id === $fixture->team_local_id;
        $teamGoals = (int) ($isLocal ? $fixture->local_score : $fixture->guest_score);
        $rivalGoals = (int) ($isLocal ? $fixture->guest_score : $fixture->local_score);
        $won = $teamGoals > $rivalGoals;
        $cleanSheet = $rivalGoals === 0;
        $worldcup26 = $this->worldcup26Values($lineup->stats);

        [$base, $slope] = ($source === 'fantasy' ? self::FANTASY_BASE : self::WORLDCUP26_BASE)[$positionIndex];
        $raw = $base + $slope * min($minutes / 90, 1.0);

        if ($source === 'worldcup26' && $positionIndex > 0 && $minutes >= 30) {
            $raw += 0.10;
        }

        $terms = is_array($fantasyStats)
            ? $this->fantasyTerms($fantasyStats, $worldcup26, $won, $cleanSheet)
            : $this->worldcup26Terms($worldcup26, $won, $cleanSheet);

        $contributions = [];

        foreach ($terms as $term) {
            $contribution = $term['value'] * $term['weights'][$positionIndex];

            if ($contribution === 0.0) {
                continue;
            }

            $raw += $contribution;
            $contributions[] = ['reason' => $this->reasonLabel($term['label'], $term['value']), 'impact' => abs($contribution)];
        }

        $raw = round($raw, 4);

        return new DaznEstimate(
            points: $this->points($raw),
            raw: $raw,
            source: $source,
            minutes: $minutes,
            reasons: $this->reasons($minutes, $contributions),
        );
    }

    /**
     * @param  array<string, mixed>  $fantasyStats
     * @param  array<string, float>  $worldcup26
     * @return list<array{label: array{0: string, 1: string}|string, value: float, weights: list<float>}>
     */
    private function fantasyTerms(array $fantasyStats, array $worldcup26, bool $won, bool $cleanSheet): array
    {
        $value = fn (string $key): float => $this->fantasyValue($fantasyStats, $key);

        return [
            ['label' => ['gol', 'goles'], 'value' => $value('goals'), 'weights' => [0, 1.5, 1.2, 1.0]],
            ['label' => ['asistencia', 'asistencias'], 'value' => $value('goal_assist'), 'weights' => [1.5, 1.0, 1.0, 1.0]],
            ['label' => ['pase clave', 'pases clave'], 'value' => $value('offtarget_att_assist'), 'weights' => [0.6, 0.5, 0.4, 0.4]],
            ['label' => ['tiro', 'tiros'], 'value' => $value('total_scoring_att'), 'weights' => [0, 0.2, 0.2, 0.2]],
            ['label' => ['entrada al área', 'entradas al área'], 'value' => $value('pen_area_entries'), 'weights' => [0, 0.2, 0.2, 0.2]],
            ['label' => ['regate', 'regates'], 'value' => $value('won_contest'), 'weights' => [0, 0.15, 0.15, 0.15]],
            ['label' => ['recuperación', 'recuperaciones'], 'value' => $value('ball_recovery'), 'weights' => [0.05, 0.1, 0.1, 0.15]],
            ['label' => ['despeje', 'despejes'], 'value' => $value('effective_clearance'), 'weights' => [0.05, 0.08, 0.05, 0.08]],
            ['label' => ['parada', 'paradas'], 'value' => $value('saves'), 'weights' => [0.2, 0, 0, 0]],
            ['label' => ['penalti parado', 'penaltis parados'], 'value' => $value('penalty_save'), 'weights' => [0.9, 0, 0, 0]],
            ['label' => 'Victoria', 'value' => $won ? 1.0 : 0.0, 'weights' => [0.1, 0, 0, 0]],
            ['label' => 'Portería a cero', 'value' => $cleanSheet ? 1.0 : 0.0, 'weights' => [0.25, 0.25, 0, 0]],
            ['label' => ['falta', 'faltas'], 'value' => $worldcup26['foulsCommitted'] ?? 0.0, 'weights' => [-0.3, -0.15, -0.15, -0.15]],
            ['label' => ['amarilla', 'amarillas'], 'value' => $value('yellow_card'), 'weights' => [0, -0.4, -0.4, -0.4]],
            ['label' => ['roja', 'rojas'], 'value' => $value('red_card') + $value('second_yellow_card'), 'weights' => [0, -0.5, -0.5, -0.5]],
            ['label' => ['penalti fallado', 'penaltis fallados'], 'value' => $value('penalty_failed'), 'weights' => [0, -0.7, -0.7, -0.7]],
            ['label' => ['gol encajado', 'goles encajados'], 'value' => $value('goals_conceded'), 'weights' => [-0.5, -0.45, -0.3, -0.2]],
        ];
    }

    /**
     * @param  array<string, float>  $worldcup26
     * @return list<array{label: array{0: string, 1: string}|string, value: float, weights: list<float>}>
     */
    private function worldcup26Terms(array $worldcup26, bool $won, bool $cleanSheet): array
    {
        $value = fn (string $key): float => $worldcup26[$key] ?? 0.0;

        return [
            ['label' => ['gol', 'goles'], 'value' => $value('totalGoals'), 'weights' => [0, 1.5, 1.2, 1.0]],
            ['label' => ['asistencia', 'asistencias'], 'value' => $value('goalAssists'), 'weights' => [2.0, 1.5, 1.5, 1.3]],
            ['label' => ['tiro a puerta', 'tiros a puerta'], 'value' => $value('shotsOnTarget'), 'weights' => [0, 0.2, 0.2, 0.2]],
            ['label' => ['tiro fuera', 'tiros fuera'], 'value' => max($value('totalShots') - $value('shotsOnTarget'), 0.0), 'weights' => [0, 0.07, 0.07, 0.07]],
            ['label' => ['falta recibida', 'faltas recibidas'], 'value' => $value('foulsSuffered'), 'weights' => [0, 0.05, 0.05, 0.05]],
            ['label' => ['parada', 'paradas'], 'value' => $value('saves'), 'weights' => [0.3, 0, 0, 0]],
            ['label' => 'Victoria', 'value' => $won ? 1.0 : 0.0, 'weights' => [0.2, 0.2, 0, 0]],
            ['label' => 'Portería a cero', 'value' => $cleanSheet ? 1.0 : 0.0, 'weights' => [0.2, 0.2, 0, 0]],
            ['label' => ['falta', 'faltas'], 'value' => $value('foulsCommitted'), 'weights' => [-0.25, -0.15, -0.15, -0.15]],
            ['label' => ['fuera de juego', 'fueras de juego'], 'value' => $value('offsides'), 'weights' => [0, -0.15, -0.15, -0.15]],
            ['label' => ['amarilla', 'amarillas'], 'value' => $value('yellowCards'), 'weights' => [0, -0.35, -0.35, -0.35]],
            ['label' => ['roja', 'rojas'], 'value' => $value('redCards'), 'weights' => [0, -0.6, -0.6, -0.6]],
            ['label' => ['gol encajado', 'goles encajados'], 'value' => $value('goalsConceded'), 'weights' => [-0.55, -0.45, -0.3, -0.2]],
        ];
    }

    /**
     * The raw value of a Fantasy `[value, points]` stat pair; 0 when missing or non-numeric.
     *
     * @param  array<string, mixed>  $fantasyStats
     */
    private function fantasyValue(array $fantasyStats, string $key): float
    {
        $pair = $fantasyStats[$key] ?? null;

        return is_array($pair) && isset($pair[0]) && is_numeric($pair[0]) ? (float) $pair[0] : 0.0;
    }

    /**
     * worldcup26 roster stats (`[{name, value}]`) keyed by name; non-numeric values are dropped.
     *
     * @param  array<int, mixed>  $stats
     * @return array<string, float>
     */
    private function worldcup26Values(array $stats): array
    {
        $values = [];

        foreach ($stats as $stat) {
            if (is_array($stat) && isset($stat['name']) && is_numeric($stat['value'] ?? null)) {
                $values[(string) $stat['name']] = (float) $stat['value'];
            }
        }

        return $values;
    }

    /**
     * Minutes played so far from the lineup and the match clock, for the worldcup26-only fallback.
     */
    private function derivedMinutes(FixtureLineup $lineup, Fixture $fixture): int
    {
        $currentMinute = match ($fixture->state) {
            FixtureState::Finished => 90,
            FixtureState::HalfTime => 45,
            FixtureState::FirstHalf, FixtureState::SecondHalf => preg_match('/^(\d+)/', (string) $fixture->display_clock, $matches) === 1 ? (int) $matches[1] : 0,
            default => 0,
        };

        return match (true) {
            $lineup->starter && $lineup->subbed_out => (int) $lineup->sub_minute,
            $lineup->starter => $currentMinute,
            $lineup->subbed_in => max($currentMinute - (int) $lineup->sub_minute, 0),
            default => 0,
        };
    }

    private function points(float $raw): int
    {
        $points = 0;

        foreach (self::THRESHOLDS as $threshold) {
            if ($raw < $threshold) {
                break;
            }

            $points++;
        }

        return $points;
    }

    /**
     * @param  array{0: string, 1: string}|string  $label  A fixed text, or [singular, plural] prefixed by the count.
     */
    private function reasonLabel(array|string $label, float $value): string
    {
        if (is_string($label)) {
            return $label;
        }

        $count = (int) round($value);

        return $count === 1 ? "1 {$label[0]}" : "{$count} {$label[1]}";
    }

    /**
     * Minutes first, then the three actions that moved the rating most.
     *
     * @param  list<array{reason: string, impact: float}>  $contributions
     * @return list<string>
     */
    private function reasons(int $minutes, array $contributions): array
    {
        $minutesReason = $minutes === 1 ? '1 minuto jugado' : "{$minutes} minutos jugados";

        if ($contributions === []) {
            return [$minutesReason, 'Sin goles, asistencias ni tarjetas'];
        }

        usort($contributions, fn (array $a, array $b): int => $b['impact'] <=> $a['impact']);

        return [$minutesReason, ...array_map(fn (array $c): string => $c['reason'], array_slice($contributions, 0, 3))];
    }
}
