<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sorts of `/api/players`. The web keeps its own PlayerSort. `trend` orders
 * by MarketTrend::strength() and `points_per_million` by season points per
 * million of current value.
 */
enum ApiPlayerSort: string
{
    case Points = 'points';
    case Value = 'value';
    case Difference = 'difference';
    case Trend = 'trend';
    case PointsPerMillion = 'points_per_million';
}
