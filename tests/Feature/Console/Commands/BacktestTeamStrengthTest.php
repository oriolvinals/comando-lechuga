<?php

use App\Console\Commands\BacktestTeamStrength;
use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Facades\Artisan;

/**
 * Four teams playing a round robin in three rounds: only the third round has
 * two previous matches for both sides, so it yields four backtest observations.
 */
function seedTeamStrengthBacktestWorld(): void
{
    test()->travelTo('2026-10-15 12:00:00');

    $season = Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);
    $teams = Team::factory()->count(4)->create();
    $season->teams()->attach($teams->pluck('id'));

    $players = [];

    foreach ($teams as $index => $team) {
        $defender = Player::factory()->create(['team_id' => $team->id, 'position' => PlayerPosition::Defender]);
        $striker = Player::factory()->create(['team_id' => $team->id, 'position' => PlayerPosition::Striker]);
        $players[$team->id] = [$defender, $striker];

        foreach ([$defender, $striker] as $player) {
            PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-09-01', 'value' => (4 - $index) * 20_000_000]);
        }
    }

    $rounds = [
        '2026-09-05' => [[0, 1, 2, 0], [2, 3, 1, 1]],
        '2026-09-12' => [[0, 2, 3, 1], [1, 3, 0, 2]],
        '2026-09-19' => [[0, 3, 2, 2], [1, 2, 1, 0]],
    ];

    foreach ($rounds as $day => $matches) {
        foreach ($matches as $slot => [$local, $guest, $localScore, $guestScore]) {
            $fixture = Fixture::factory()->create([
                'season_id' => $season->id,
                'date' => "{$day} ".(18 + $slot).':00:00',
                'state' => FixtureState::Finished,
                'team_local_id' => $teams[$local]->id,
                'team_guest_id' => $teams[$guest]->id,
                'local_score' => $localScore,
                'guest_score' => $guestScore,
            ]);

            foreach ([$local => $localScore, $guest => $guestScore] as $teamIndex => $goals) {
                foreach ($players[$teams[$teamIndex]->id] as $offset => $player) {
                    FixtureLineup::factory()->create([
                        'fixture_id' => $fixture->id,
                        'team_id' => $teams[$teamIndex]->id,
                        'player_id' => $player->id,
                        'starter' => true,
                        'fantasy_points' => $goals * 2 + $offset + $slot,
                        'stats' => [['name' => 'shotsOnTarget', 'value' => $goals + $offset]],
                    ]);
                }
            }
        }
    }
}

/**
 * @return array<string, int>
 */
function teamStrengthBacktestRowCounts(): array
{
    return [
        'seasons' => Season::query()->count(),
        'teams' => Team::query()->count(),
        'players' => Player::query()->count(),
        'player_seasons' => PlayerSeason::query()->count(),
        'player_markets' => PlayerMarket::query()->count(),
        'fixtures' => Fixture::query()->count(),
        'fixture_lineups' => FixtureLineup::query()->count(),
    ];
}

test('prints a Spearman table per variant and for the table position, writing nothing', function (): void {
    seedTeamStrengthBacktestWorld();
    $before = teamStrengthBacktestRowCounts();

    $this->artisan(BacktestTeamStrength::class)
        ->expectsOutputToContain('4 observaciones')
        ->assertSuccessful();

    Artisan::call('season:backtest-team-strength');

    expect(Artisan::output())->toContain('Puntos', 'Fantasy porteros y defensas', '| general', '| attack', '| defense', '| posición en la tabla', '| n ');

    expect(teamStrengthBacktestRowCounts())->toBe($before);
});

test('--grid reports the best parameter combination per target and writes nothing', function (): void {
    seedTeamStrengthBacktestWorld();
    $before = teamStrengthBacktestRowCounts();

    Artisan::call('season:backtest-team-strength', ['--grid' => true]);

    expect(Artisan::output())->toContain('Mejor combinación por objetivo', 'shrinkK', 'homeBonus', 'specificShare');

    expect(teamStrengthBacktestRowCounts())->toBe($before);
});

test('says so when no finished match has two previous matches for both teams', function (): void {
    $this->travelTo('2026-10-15 12:00:00');
    Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);

    $this->artisan(BacktestTeamStrength::class)
        ->expectsOutputToContain('No hay partidos')
        ->assertSuccessful();
});

test('spearman gives tied values their average rank', function (): void {
    $spearman = new ReflectionMethod(BacktestTeamStrength::class, 'spearman');

    expect($spearman->invoke(null, [1.0, 2.0, 2.0, 3.0], [1.0, 2.0, 3.0, 4.0]))->toEqualWithDelta(4.5 / sqrt(22.5), 1e-9)
        ->and($spearman->invoke(null, [1.0, 2.0, 3.0], [3.0, 2.0, 1.0]))->toEqualWithDelta(-1.0, 1e-9)
        ->and($spearman->invoke(null, [1.0, 1.0, 1.0], [1.0, 2.0, 3.0]))->toBeNull()
        ->and($spearman->invoke(null, [1.0, null, 3.0, 4.0], [1.0, 5.0, null, 4.0]))->toEqualWithDelta(1.0, 1e-9);
});
