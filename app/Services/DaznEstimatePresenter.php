<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;

/**
 * The one visibility rule for DAZN ratings, shared by the web and the API.
 * Official ratings only once the fixture is published; before that, the stored
 * estimate once the player has 15 minutes, or at any time after full time.
 */
final class DaznEstimatePresenter
{
    public const int MIN_VISIBLE_MINUTES = 15;

    /**
     * @return array{dazn_points: int|null, dazn_estimate: int|null, dazn_estimate_version: string, dazn_estimate_reasons: list<string>, dazn_estimate_source: string|null}
     */
    public static function present(FixtureLineup $lineup, Fixture $fixture): array
    {
        $published = $fixture->dazn_published;
        $official = $lineup->fantasy_stats['marca_points'][1] ?? null;
        $meta = $lineup->dazn_estimate_meta ?? [];
        $estimate = $lineup->dazn_estimate;

        $isProvisional = !$published && $estimate !== null;

        if ($isProvisional && $fixture->state !== FixtureState::Finished && (int) ($meta['minutes'] ?? 0) < self::MIN_VISIBLE_MINUTES) {
            $estimate = null;
            $isProvisional = false;
        }

        return [
            'dazn_points' => $published && is_numeric($official) ? (int) $official : null,
            'dazn_estimate' => $estimate,
            'dazn_estimate_version' => $estimate === null ? '' : $lineup->dazn_estimate_version,
            'dazn_estimate_reasons' => $isProvisional ? array_values($meta['reasons'] ?? []) : [],
            'dazn_estimate_source' => $isProvisional ? ($meta['source'] ?? null) : null,
        ];
    }
}
