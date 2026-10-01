<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\Season;
use App\Models\ValueForecast;
use App\Models\ValueForecastFit;
use Illuminate\Support\Facades\DB;

/**
 * Stores one day of the walk-forward: the forecast of every league player
 * of the season (never out-of-league ones) for the target date, replacing
 * that date's previous rows, and the fit with its input fingerprint.
 * Dates are written and matched as `Y-m-d` strings on DATE columns.
 */
final class ValueForecastWriter
{
    public function write(Season $season, ValueForecastDay $day, string $inputsHash): int
    {
        $eligible = Player::query()
            ->whereIn('team_id', $season->teams()->select('teams.id'))
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->pluck('id')
            ->flip();
        $now = now();
        $rows = [];

        foreach ($day->predictions as $prediction) {
            if (!$eligible->has($prediction->row->playerId)) {
                continue;
            }

            $rows[] = [
                'season_id' => $season->id,
                'player_id' => $prediction->row->playerId,
                'reference_date' => $day->referenceDate,
                'target_date' => $day->targetDate(),
                'value' => $prediction->row->value,
                'predicted_value' => $prediction->predictedValue(),
                'low' => $prediction->low(),
                'high' => $prediction->high(),
                'change_pct' => round($prediction->change * 100, 4),
                'up_probability' => round($prediction->upProbability, 4),
                'reasons' => (string) json_encode($prediction->reasons),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($season, $day, $inputsHash, $rows, $now): void {
            ValueForecastFit::query()->upsert([[
                'season_id' => $season->id,
                'reference_date' => $day->referenceDate,
                'inputs_hash' => $inputsHash,
                'coefficients' => (string) json_encode($day->model->coefficients),
                'quantiles' => (string) json_encode($day->model->quantiles()),
                'metrics' => (string) json_encode(['training_rows' => $day->trainingRows, 'forecasts' => count($rows)]),
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['season_id', 'reference_date'], ['inputs_hash', 'coefficients', 'quantiles', 'metrics', 'updated_at']);

            foreach (array_chunk($rows, 500) as $chunk) {
                ValueForecast::query()->upsert(
                    $chunk,
                    ['season_id', 'player_id', 'target_date'],
                    ['reference_date', 'value', 'predicted_value', 'low', 'high', 'change_pct', 'up_probability', 'reasons', 'updated_at'],
                );
            }

            ValueForecast::query()
                ->where('season_id', $season->id)
                ->where('target_date', $day->targetDate())
                ->whereNotIn('player_id', array_column($rows, 'player_id'))
                ->delete();
        });

        return count($rows);
    }
}
