<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Enums\MarketTrend;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\ValueForecast;
use App\Services\MaxBidCalculator;

/**
 * A player's forecast for the ficha's god «Mercado» section: only the one
 * made on the latest published market day (an older one would mix
 * yesterday's forecast with today's value), with the market trend its value
 * would draw. God mode only — the caller checks it.
 */
final class ValueForecastPresenter
{
    /** Values before the forecast one that the market trend reads (it needs seven). */
    private const int TREND_VALUES = 6;

    public function __construct(
        private readonly MaxBidCalculator $maxBidCalculator,
        private readonly ValueForecastParameters $parameters = new ValueForecastParameters,
    ) {}

    /**
     * @return array{reference_date: string, target_date: string, value: int, predicted_value: int, change: int, change_pct: float, low: int, high: int, up_probability: float, direction: 'up'|'stable'|'down', trend: string|null, reasons: list<array{kind: string, label: string, impact_pct: float}>}|null
     */
    public function forPlayer(Player $player, Season $season): ?array
    {
        return $this->forReferenceDate($player, $season, $this->maxBidCalculator->latestMarketDate($season));
    }

    /**
     * The forecast made on `$reference` (the latest published market day,
     * Y-m-d — e.g. the max bid's own `referenceDate`, so the ficha doesn't
     * look it up twice); null without one.
     *
     * @return array{reference_date: string, target_date: string, value: int, predicted_value: int, change: int, change_pct: float, low: int, high: int, up_probability: float, direction: 'up'|'stable'|'down', trend: string|null, reasons: list<array{kind: string, label: string, impact_pct: float}>}|null
     */
    public function forReferenceDate(Player $player, Season $season, ?string $reference): ?array
    {
        if ($reference === null) {
            return null;
        }

        $forecast = ValueForecast::query()
            ->where('season_id', $season->id)
            ->where('player_id', $player->id)
            ->whereDate('reference_date', $reference)
            ->first();

        if ($forecast === null) {
            return null;
        }

        $recent = PlayerMarket::recentValues($player->id, $reference, self::TREND_VALUES);

        return [
            'reference_date' => $forecast->reference_date->toDateString(),
            'target_date' => $forecast->target_date->toDateString(),
            'value' => $forecast->value,
            'predicted_value' => $forecast->predicted_value,
            'change' => $forecast->predicted_value - $forecast->value,
            'change_pct' => round($forecast->change_pct, 2),
            'low' => $forecast->low,
            'high' => $forecast->high,
            'up_probability' => $forecast->up_probability,
            'direction' => ValueForecastPrediction::directionOf($forecast->change_pct / 100, $this->parameters->stableBand),
            'trend' => MarketTrend::fromDailyValues([...$recent, $forecast->predicted_value])?->value,
            'reasons' => $forecast->reasons,
        ];
    }
}
