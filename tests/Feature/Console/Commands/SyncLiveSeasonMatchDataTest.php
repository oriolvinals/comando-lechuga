<?php

use App\Console\Commands\SyncLiveSeasonMatchData;
use App\Enums\FixtureState;
use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function liveMatchEventPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'header' => [
            'competitions' => [
                [
                    'status' => ['type' => ['name' => 'STATUS_FULL_TIME']],
                    'competitors' => [
                        ['homeAway' => 'home', 'score' => '2'],
                        ['homeAway' => 'away', 'score' => '1'],
                    ],
                ],
            ],
        ],
        'rosters' => [],
        'keyEvents' => [],
    ], $overrides);
}

test('updates the fixture state, score and formation from the live event', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subMinutes(30),
    ]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            ['homeAway' => 'home', 'team' => ['id' => 83], 'formation' => '4-3-3', 'roster' => []],
            ['homeAway' => 'away', 'team' => ['id' => 86], 'formation' => '3-5-2', 'roster' => []],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)
        ->expectsOutput('1 fixtures synced.')
        ->assertSuccessful();

    $fixture->refresh();
    expect($fixture->state)->toBe(FixtureState::Finished)
        ->and($fixture->local_score)->toBe(2)
        ->and($fixture->guest_score)->toBe(1)
        ->and($fixture->local_formation)->toBe('4-3-3')
        ->and($fixture->guest_formation)->toBe('3-5-2');
});

test('ignores fixtures outside the live window or without a match_data_id', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);

    $tooOld = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 111,
        'date' => now()->subHours(5),
    ]);
    $unlinked = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => null,
        'date' => now()->subMinutes(10),
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make(liveMatchEventPayload()),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)
        ->expectsOutput('0 fixtures synced.')
        ->assertSuccessful();

    expect($tooOld->refresh()->state)->toBe(FixtureState::Scheduled)
        ->and($unlinked->refresh()->state)->toBe(FixtureState::Scheduled);
});

test('upserts fixture_lineups from the rosters, including substitution minute and counterpart', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subMinutes(30),
    ]);
    $starter = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001]);
    $subOut = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5002]);
    $subIn = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5003]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    [
                        'athlete' => ['id' => 5001, 'displayName' => 'Starter One'],
                        'starter' => true,
                        'position' => ['displayName' => 'Goalkeeper'],
                        'jersey' => '1',
                        'subbedIn' => false,
                        'subbedOut' => false,
                        'stats' => [['name' => 'saves', 'value' => 2]],
                    ],
                    [
                        'athlete' => ['id' => 5002, 'displayName' => 'Sub Out'],
                        'starter' => true,
                        'position' => ['displayName' => 'Center Left Midfielder'],
                        'jersey' => '4',
                        'subbedIn' => false,
                        'subbedOut' => true,
                        'subbedOutFor' => ['athlete' => ['id' => 5003]],
                        'plays' => [['clock' => ['displayValue' => "57'"], 'substitution' => true]],
                        'stats' => [],
                    ],
                    [
                        'athlete' => ['id' => 5003, 'displayName' => 'Sub In'],
                        'starter' => false,
                        'position' => ['displayName' => 'Substitute'],
                        'jersey' => '18',
                        'subbedIn' => true,
                        'subbedOut' => false,
                        'subbedInFor' => ['athlete' => ['id' => 5002]],
                        'plays' => [['clock' => ['displayValue' => "57'"], 'substitution' => true]],
                        'stats' => [],
                    ],
                ],
            ],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $starterLineup = FixtureLineup::query()->where('player_id', $starter->id)->sole();
    $subOutLineup = FixtureLineup::query()->where('player_id', $subOut->id)->sole();
    $subInLineup = FixtureLineup::query()->where('player_id', $subIn->id)->sole();

    expect($starterLineup->stats)->toBe([['name' => 'saves', 'value' => 2]])
        ->and($subOutLineup->subbed_out)->toBeTrue()
        ->and($subOutLineup->counterpart_player_id)->toBe($subIn->id)
        ->and($subOutLineup->sub_minute)->toBe(57)
        ->and($subInLineup->subbed_in)->toBeTrue()
        ->and($subInLineup->counterpart_player_id)->toBe($subOut->id)
        ->and($subInLineup->sub_minute)->toBe(57);
});

test('running twice with the same payload does not duplicate lineups, and reports unresolved players', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subMinutes(30),
    ]);
    Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 5001, 'displayName' => 'Known'], 'starter' => true, 'position' => ['displayName' => 'GK'], 'jersey' => '1'],
                    ['athlete' => ['id' => 9999, 'displayName' => 'Unknown Player'], 'starter' => true, 'position' => ['displayName' => 'CB'], 'jersey' => '5'],
                ],
            ],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)
        ->expectsOutputToContain('Unknown Player')
        ->assertSuccessful();
    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    expect(FixtureLineup::query()->count())->toBe(1);
});

test('replaces fixture_events from keyEvents on every sync, mapped from the API flags', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subMinutes(30),
    ]);
    $scorer = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001]);

    $payload = liveMatchEventPayload([
        'keyEvents' => [
            [
                'type' => ['text' => 'Goal'],
                'clock' => ['displayValue' => "73'"],
                'team' => ['id' => 83],
                'scoringPlay' => true,
                'redCard' => false,
                'yellowCard' => false,
                'ownGoal' => false,
                'penaltyKick' => false,
                'athletesInvolved' => [['id' => 5001]],
            ],
            [
                'type' => ['text' => 'Yellow Card'],
                'clock' => ['displayValue' => "44'"],
                'team' => ['id' => 86],
                'scoringPlay' => false,
                'redCard' => false,
                'yellowCard' => true,
                'ownGoal' => false,
                'penaltyKick' => false,
                // no athletesInvolved — must still create the event, with a null player
            ],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $goal = FixtureEvent::query()->where('type', 'goal')->sole();
    $card = FixtureEvent::query()->where('type', 'yellow_card')->sole();

    expect($goal->minute)->toBe(73)
        ->and($goal->player_id)->toBe($scorer->id)
        ->and($goal->team_id)->toBe($home->id)
        ->and($card->minute)->toBe(44)
        ->and($card->player_id)->toBeNull()
        ->and($card->team_id)->toBe($away->id);

    // Second sync with a different payload replaces, not appends
    $connector2 = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make(liveMatchEventPayload()),
    ]));
    app()->instance(Worldcup26Connector::class, $connector2);
    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    expect(FixtureEvent::query()->where('fixture_id', $fixture->id)->count())->toBe(0);
});

test('skips a fixture whose getEvent call fails, without blocking the rest', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $failing = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 111,
        'date' => now()->subMinutes(10),
    ]);
    $ok = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 222,
        'date' => now()->subMinutes(20),
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => function ($pendingRequest) {
            if (str_contains($pendingRequest->getRequest()->resolveEndpoint(), '111')) {
                return MockResponse::make([], 500);
            }

            return MockResponse::make(liveMatchEventPayload());
        },
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)
        ->expectsOutput('1 fixtures synced.')
        ->assertSuccessful();

    expect($failing->refresh()->state)->toBe(FixtureState::Scheduled)
        ->and($ok->refresh()->state)->toBe(FixtureState::Finished);
});
