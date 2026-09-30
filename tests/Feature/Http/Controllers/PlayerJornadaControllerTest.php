<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;

test('returns the jornada sheet of one match: the historical team, stats, fixture with both teams and the lineup manager', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $oldClub = Team::factory()->create();
    $player = Player::factory()->create();
    PlayerSeason::query()->where('player_id', $player->id)->update(['position' => PlayerPosition::Striker]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id, 'week_number' => 7, 'state' => FixtureState::Finished,
        'team_guest_id' => $oldClub->id, 'local_score' => 1, 'guest_score' => 3,
    ]);
    $stats = ['mins_played' => [90, 2], 'goals' => [1, 5]];
    FixtureLineup::factory()->create([
        'player_id' => $player->id, 'fixture_id' => $fixture->id, 'team_id' => $oldClub->id,
        'fantasy_points' => 11, 'fantasy_stats' => $stats, 'starter' => true,
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 7]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $response = $this->getJson(route('players.jornada', [$player, $fixture]));

    $response->assertOk()
        ->assertJsonPath('player.id', $player->id)
        ->assertJsonPath('player.nickname', $player->nickname)
        ->assertJsonPath('player.position', 'striker')
        ->assertJsonPath('score.points', 11)
        ->assertJsonPath('score.stats', $stats)
        ->assertJsonPath('score.starter', true)
        ->assertJsonPath('score.team.id', $oldClub->id)
        ->assertJsonPath('score.fixture.id', $fixture->id)
        ->assertJsonPath('score.fixture.guest_score', 3)
        ->assertJsonPath('score.fixture.local_team.id', $fixture->team_local_id)
        ->assertJsonPath('score.fixture.guest_team.id', $oldClub->id)
        ->assertJsonPath('score.lineup_manager.id', $seasonManager->id)
        ->assertJsonPath('score.dazn_points', null);
    expect($response->json('player'))->toHaveKeys(['id', 'nickname', 'image', 'position'])
        ->and($response->json('player'))->not->toHaveKey('market_value');
});

test('returns 404 when the player has no lineup row for that match', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'team_local_id' => $player->team_id]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id]);

    $this->getJson(route('players.jornada', [$player, $fixture]))->assertNotFound();
});

test('returns 404 for a match of another season', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $otherSeason = Season::factory()->create(['start_date' => now()->subYears(2), 'end_date' => now()->subYear()]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $otherSeason->id]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $this->getJson(route('players.jornada', [$player, $fixture]))->assertNotFound();
});
