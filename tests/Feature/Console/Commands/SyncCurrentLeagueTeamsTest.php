<?php

use App\Console\Commands\SyncCurrentLeagueTeams;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetAssetRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetTeamInfoRequest;
use App\Models\League;
use App\Models\Team;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('creates and updates the active league teams', function () {
    Storage::fake('public');

    $league = League::factory()->create(['current' => true]);
    $existingTeam = Team::factory()->create([
        'fantasy_id' => 2,
        'main_name' => 'Old name',
    ]);
    $league->teams()->attach($existingTeam);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetTeamInfoRequest::class => MockResponse::make([
            [
                'id' => 2,
                'mainName' => 'Atlético de Madrid',
                'name' => 'Club Atlético de Madrid SAD',
                'shortName' => 'ATM',
                'slug' => 'atletico-de-madrid',
                'badgeColor' => 'https://assets-fantasy.llt-services.com/teambadge/atletico.png',
                'players' => [['id' => 2332]],
            ],
            [
                'id' => 3,
                'mainName' => 'Athletic Club',
                'name' => 'Athletic Club',
                'shortName' => 'ATH',
                'slug' => 'athletic-club',
                'badgeColor' => 'https://assets-fantasy.llt-services.com/teambadge/athletic.png',
                'players' => [],
            ],
        ]),
        GetAssetRequest::class => MockResponse::make('team badge'),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentLeagueTeams::class)
        ->expectsOutput('2 teams synchronized.')
        ->assertSuccessful();

    expect(Team::query()->count())->toBe(2)
        ->and($existingTeam->refresh())
        ->main_name->toBe('Atlético de Madrid')
        ->and($existingTeam->logo)->toBe('images/team/2.png')
        ->and($existingTeam->toArray()['logo'])->toBe(asset('storage/images/team/2.png'))
        ->and($league->teams()->count())->toBe(2);

    Storage::disk('public')->assertExists([
        'images/team/2.png',
        'images/team/3.png',
    ]);
});
