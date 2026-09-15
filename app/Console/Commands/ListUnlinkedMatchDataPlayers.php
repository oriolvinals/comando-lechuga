<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FixtureLineup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('season:list-unlinked-match-data-players')]
#[Description('List worldcup26.ir match roster players that never resolved to a Player, to look up and add to LinkMatchDataPlayers::PLAYER_MAP')]
class ListUnlinkedMatchDataPlayers extends Command
{
    public function handle(): int
    {
        $unresolved = FixtureLineup::query()
            ->whereNull('fixture_lineups.player_id')
            ->join('fixtures', 'fixtures.id', '=', 'fixture_lineups.fixture_id')
            ->with('team')
            ->orderByDesc('fixtures.date')
            ->get(['fixture_lineups.jersey', 'fixture_lineups.unresolved_name', 'fixture_lineups.wc26_id', 'fixture_lineups.team_id'])
            ->unique('wc26_id')
            ->sortBy('unresolved_name');

        if ($unresolved->isEmpty()) {
            $this->info('No unlinked match-data players.');

            return self::SUCCESS;
        }

        $this->table(
            ['jersey', 'nombre', 'equipo', 'wc26_id'],
            $unresolved->map(fn (FixtureLineup $lineup): array => [
                $lineup->jersey,
                $lineup->unresolved_name,
                $lineup->team?->name,
                $lineup->wc26_id,
            ]),
        );

        return self::SUCCESS;
    }
}
