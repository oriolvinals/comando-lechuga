<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;

/**
 * A player's lineup rows of one season as the ficha and the jornada sheet
 * show them: that match's points and fantasy stats, the club he played it
 * for (never his current one), the fixture with both teams, the manager who
 * fielded him that jornada, his starter/substitution facts and the DAZN
 * fields under the shared visibility rule.
 *
 * @phpstan-type PlayerFichaScore array{id: int, team_id: int, team: Team, points: int|null, stats: array<string, mixed>|null, fixture: Fixture, lineup_manager: SeasonManager|null, starter: bool, subbed_in: bool, subbed_out: bool, sub_minute: int|null, dazn_points: int|null, dazn_estimate: int|null, dazn_estimate_version: string, dazn_estimate_reasons: list<string>, dazn_estimate_source: string|null}
 */
final class PlayerFichaScores
{
    /**
     * In jornada order; only the given fixture's row when `$fixtureId` is set.
     *
     * @return list<PlayerFichaScore>
     */
    public function forPlayer(Player $player, Season $season, ?int $fixtureId = null): array
    {
        $lineups = $player->fixtureLineups()
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->when($fixtureId !== null, fn ($query) => $query->where('fixture_id', $fixtureId))
            ->with(['fixture.localTeam', 'fixture.guestTeam', 'team'])
            ->get()
            ->sortBy(fn (FixtureLineup $lineup) => $lineup->fixture->week_number)
            ->values();

        // Which manager fielded this player in their lineup each jornada — distinct
        // from ownership, since an owner can bench a player they still own.
        $lineupManagersByFixture = ManagerLineupPlayer::query()
            ->where('player_id', $player->id)
            ->whereIn('fixture_id', $lineups->pluck('fixture_id'))
            ->whereHas('lineup.seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->with('lineup.seasonManager')
            ->get()
            ->keyBy('fixture_id');

        return array_values($lineups->map(fn (FixtureLineup $lineup): array => [
            'id' => $lineup->id,
            'team_id' => $lineup->team_id,
            'team' => $lineup->team,
            'points' => $lineup->fantasy_points,
            'stats' => $lineup->fantasy_stats,
            'fixture' => $lineup->fixture,
            'lineup_manager' => $lineupManagersByFixture->get($lineup->fixture_id)?->lineup?->seasonManager,
            'starter' => $lineup->starter,
            'subbed_in' => $lineup->subbed_in,
            'subbed_out' => $lineup->subbed_out,
            'sub_minute' => $lineup->sub_minute,
            ...DaznEstimatePresenter::present($lineup, $lineup->fixture),
        ])->all());
    }
}
