<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Fixture;
use App\Models\FixtureLineup;

/**
 * The one visibility rule for DAZN ratings, shared by the web and the API.
 * Official ratings only once the fixture is published; before that, the
 * stored estimate as soon as it exists (the estimator itself returns null at
 * 0 minutes, so an unused substitute or a player before kickoff shows nothing).
 */
final class DaznEstimatePresenter
{
    /**
     * @return array{dazn_points: int|null, dazn_estimate: int|null, dazn_estimate_version: string, dazn_estimate_reasons: list<string>, dazn_estimate_source: string|null}
     */
    public static function present(FixtureLineup $lineup, Fixture $fixture): array
    {
        $published = $fixture->dazn_published;
        $official = $lineup->fantasy_stats['marca_points'][1] ?? null;
        $officialIsNumeric = is_numeric($official);
        $meta = $lineup->dazn_estimate_meta ?? [];
        $estimate = $lineup->dazn_estimate;

        if ($published && !$officialIsNumeric) {
            // Published fixture, but this player's own official rating never
            // arrived (failed Fantasy fetch, no fantasy_id, unresolved row...).
            // A numeric 0 still counts as official — only a genuinely missing
            // value hides the frozen estimate, which would otherwise render as
            // a provisional badge that can never resolve.
            $estimate = null;
        }

        $isProvisional = !$published && $estimate !== null;

        return [
            'dazn_points' => $published && $officialIsNumeric ? (int) $official : null,
            'dazn_estimate' => $estimate,
            'dazn_estimate_version' => $estimate === null ? '' : $lineup->dazn_estimate_version,
            'dazn_estimate_reasons' => $isProvisional ? ($meta['reasons'] ?? []) : [],
            'dazn_estimate_source' => $isProvisional ? ($meta['source'] ?? null) : null,
        ];
    }
}
