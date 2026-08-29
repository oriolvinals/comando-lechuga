<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('season:link-match-data-teams')]
#[Description('Link teams to their worldcup26.ir id via a hardcoded fantasy_id map — safe to run repeatedly, e.g. after every team resync')]
class LinkMatchDataTeams extends Command
{
    /**
     * fantasy_id => worldcup26.ir team id — validated 1:1 against real data
     * (the same mapping as the old, now-stale `link_teams_to_match_data`
     * migration, re-keyed by fantasy_id instead of our local auto-incrementing
     * teams.id, which does not survive a data wipe — fantasy_id, sourced from
     * LaLiga Fantasy's own API, does).
     *
     * @var array<int, int>
     */
    private const array TEAM_MAP = [
        2 => 1068,
        3 => 93,
        4 => 83,
        5 => 244,
        6 => 85,
        7 => 3751,
        8 => 88,
        9 => 2922,
        11 => 1538,
        12 => 99,
        13 => 97,
        14 => 101,
        15 => 86,
        16 => 89,
        17 => 243,
        18 => 94,
        20 => 102,
        21 => 96,
        26 => 90,
        49 => 87,
    ];

    public function handle(): int
    {
        $linked = 0;

        foreach (self::TEAM_MAP as $fantasyId => $matchDataId) {
            $linked += Team::query()->where('fantasy_id', $fantasyId)->update(['match_data_id' => $matchDataId]);
        }

        $this->info($linked.' teams linked.');

        return self::SUCCESS;
    }
}
