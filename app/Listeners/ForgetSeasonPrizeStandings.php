<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Season;
use App\Services\SeasonPrizeStandings;
use Illuminate\Console\Events\CommandFinished;

/**
 * Forgets the cached prize standings of the current season(s) after a
 * successful sync that can change them.
 */
final class ForgetSeasonPrizeStandings
{
    /** @var list<string> */
    public const array COMMANDS = [
        'season:sync-activity',
        'season:sync-manager-lineups',
        'season:sync-manager-players',
        'season:sync-current-match-data',
        'season:sync-live-match-data',
        'season:sync-match-data-backfill',
        'season:sync-fixtures',
        'season:sync-week',
        'season:sync-player-markets',
    ];

    public function handle(CommandFinished $event): void
    {
        if ($event->exitCode !== 0 || !in_array($event->command, self::COMMANDS, true)) {
            return;
        }

        Season::query()
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->get()
            ->each(fn (Season $season) => SeasonPrizeStandings::forget($season));
    }
}
