<?php

use App\Console\Commands\LinkMatchDataTeams;
use App\Models\Team;

test('links teams to their worldcup26 id by fantasy_id, and is safe to run repeatedly', function (): void {
    $team = Team::factory()->create(['fantasy_id' => 4, 'match_data_id' => null]);
    $secondTeam = Team::factory()->create(['fantasy_id' => 5, 'match_data_id' => null]);
    $unrelatedTeam = Team::factory()->create(['fantasy_id' => 999999, 'match_data_id' => null]);

    $this->artisan(LinkMatchDataTeams::class)
        ->expectsOutputToContain('2 teams linked.')
        ->assertSuccessful();

    expect($team->fresh()->match_data_id)->toBe(83)
        ->and($secondTeam->fresh()->match_data_id)->toBe(244)
        ->and($unrelatedTeam->fresh()->match_data_id)->toBeNull();

    // Running it again must not fail or double-count — every fantasy_id in
    // the map still resolves to the same teams, so the update count is
    // identical the second time.
    $this->artisan(LinkMatchDataTeams::class)
        ->expectsOutputToContain('2 teams linked.')
        ->assertSuccessful();

    expect($team->fresh()->match_data_id)->toBe(83);
});
