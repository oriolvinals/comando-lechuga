<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Season;
use Illuminate\Http\Request;

trait ResolvesRequestedWeek
{
    private function resolveWeek(Request $request, Season $season): int
    {
        $week = (int) $request->integer('week', $season->current_week);

        return max(1, min($week, $season->total_weeks));
    }
}
