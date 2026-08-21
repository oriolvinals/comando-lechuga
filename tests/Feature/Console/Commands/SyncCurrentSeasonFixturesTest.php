<?php

use App\Console\Commands\SyncCurrentSeasonFixtures;
use App\Enums\FixtureState;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetFixturesRequest;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('replaces the active season fixtures from every week', function () {
    $season = Season::factory()->create(['current' => true]);
    $localTeam = Team::factory()->create(['fantasy_id' => 18]);
    $guestTeam = Team::factory()->create(['fantasy_id' => 6]);
    $season->teams()->attach([$localTeam->id, $guestTeam->id]);
    Fixture::factory()->create([
        'fantasy_id' => 11,
        'season_id' => $season->id,
        'team_local_id' => $localTeam->id,
        'team_guest_id' => $guestTeam->id,
    ]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetFixturesRequest::class => function ($pendingRequest): MockResponse {
            if ($pendingRequest->getRequest()->query()->get('weekNumber') !== 1) {
                return MockResponse::make([]);
            }

            return MockResponse::make([
                [
                    'id' => '11',
                    'matchDate' => '2026-08-22T19:30:00+02:00',
                    'localId' => 18,
                    'visitorId' => 6,
                    'matchState' => 7,
                    'localScore' => 2,
                    'visitorScore' => 1,
                ],
            ]);
        },
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonFixtures::class)
        ->expectsOutput('1 fixtures synchronized.')
        ->assertSuccessful();

    $fixture = Fixture::query()->sole();

    expect($fixture->fantasy_id)->toBe(11)
        ->and($fixture->state)->toBe(FixtureState::Finished)
        ->and($fixture->date)->toEqual(CarbonImmutable::parse('2026-08-22T17:30:00+00:00'))
        ->and($fixture->local_score)->toBe(2)
        ->and($fixture->guest_score)->toBe(1);
});
