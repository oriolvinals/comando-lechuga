<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\ValueForecastFit;
use App\Services\ValueForecast\ValueForecastFingerprint;
use App\Services\ValueForecast\ValueForecastWalkForward;
use App\Services\ValueForecast\ValueForecastWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The reference date is the latest published market day (a DATE column,
 * read as a `Y-m-d` string), never the database or app clock. The model is
 * fitted by walking the season forward one day at a time, so only the
 * residual window's rows are ever held in memory.
 *
 * It runs at the end of every season:sync-player-markets and every 15
 * minutes as a safety net. The input fingerprint only covers the reference
 * day's values, so a correction of an earlier day's value does not trigger
 * a refit on its own: run it with `--force`.
 */
#[Signature('season:forecast-values {--force : Refit even if the inputs did not change}')]
#[Description('Forecast tomorrow\'s value of every league player (god mode only) when the published values or the finished matches changed')]
class ForecastValues extends Command
{
    public function handle(ValueForecastWalkForward $walkForward, ValueForecastWriter $writer, ValueForecastFingerprint $fingerprint): int
    {
        try {
            $season = Season::current();
        } catch (ModelNotFoundException) {
            $this->info('No hay temporada activa.');

            return self::SUCCESS;
        }

        $latest = PlayerMarket::query()->max('date');

        if ($latest === null) {
            $this->info('No hay valores de mercado.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $reference = substr((string) $latest, 0, 10);
        $hash = $fingerprint->for($season, $reference);
        $unchanged = ValueForecastFit::query()
            ->where('season_id', $season->id)
            ->where('reference_date', $reference)
            ->where('inputs_hash', $hash)
            ->exists();

        if ($unchanged && !$this->option('force')) {
            $this->info("Sin cambios desde la última previsión del {$reference}.");

            return self::SUCCESS;
        }

        $day = null;

        foreach ($walkForward->days($season, $reference, $reference) as $day) {
            // one day only
        }

        if ($day === null) {
            $this->info('Sin datos suficientes para ajustar el modelo.');

            return self::SUCCESS;
        }

        $written = $writer->write($season, $day, $hash);
        $this->info("{$written} previsiones para el {$day->targetDate()}.");
        $this->line(sprintf('%.1f s, pico de memoria %d MB.', microtime(true) - $startedAt, intdiv(memory_get_peak_usage(true), 1024 * 1024)), verbosity: 'v');

        return self::SUCCESS;
    }
}
