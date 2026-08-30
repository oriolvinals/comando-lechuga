<?php

use App\Console\Commands\SyncLiveSeasonMatchData;
use App\Enums\FixtureState;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayerRequest;
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

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerRequest::class => MockResponse::make(['playerStats' => []]),
    ]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

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

test('resolves a player by match_data_id even when Player.team_id no longer matches the roster team, and writes the roster team onto the lineup', function (): void {
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
    // Player's *current* club (team_id) differs from the fixture roster's team (home),
    // simulating a transfer/loan since the match — the match-time team is $home.
    $transferred = Player::factory()->create(['team_id' => $away->id, 'match_data_id' => 5001]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    [
                        'athlete' => ['id' => 5001, 'displayName' => 'Transferred Player'],
                        'starter' => true,
                        'position' => ['displayName' => 'Goalkeeper'],
                        'jersey' => '1',
                        'subbedIn' => false,
                        'subbedOut' => false,
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

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerRequest::class => MockResponse::make(['playerStats' => []]),
    ]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $lineup = FixtureLineup::query()->where('player_id', $transferred->id)->sole();

    expect($lineup->team_id)->toBe($home->id)
        ->and($lineup->team_id)->not->toBe($transferred->team_id);
});

test('creates a fixture_lineups row with a null player_id for an unresolved athlete, and reports it', function (): void {
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
    $known = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 5001, 'displayName' => 'Known'], 'starter' => true, 'position' => ['displayName' => 'GK'], 'jersey' => '1', 'stats' => [['name' => 'saves', 'value' => 1]]],
                    ['athlete' => ['id' => 9999, 'displayName' => 'Unknown Player'], 'starter' => true, 'position' => ['displayName' => 'CB'], 'jersey' => '5', 'stats' => [['name' => 'foulsCommitted', 'value' => 2]]],
                ],
            ],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerRequest::class => MockResponse::make(['playerStats' => []]),
    ]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncLiveSeasonMatchData::class)
        ->expectsOutputToContain('Unknown Player')
        ->assertSuccessful();

    expect(FixtureLineup::query()->where('player_id', $known->id)->sole()->stats)
        ->toBe([['name' => 'saves', 'value' => 1]]);

    $unresolvedRow = FixtureLineup::query()->whereNull('player_id')->where('fixture_id', $fixture->id)->sole();
    expect($unresolvedRow->jersey)->toBe('5')
        ->and($unresolvedRow->position)->toBe('CB')
        ->and($unresolvedRow->team_id)->toBe($home->id)
        ->and($unresolvedRow->stats)->toBe([['name' => 'foulsCommitted', 'value' => 2]]);

    // Second sync with changed stats for the known player: the known player's row updates
    // in place (still 1 row, but with the new stats), and the unresolved row is replaced,
    // not duplicated (still 1 row with null).
    $secondPayload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 5001, 'displayName' => 'Known'], 'starter' => true, 'position' => ['displayName' => 'GK'], 'jersey' => '1', 'stats' => [['name' => 'saves', 'value' => 4]]],
                    ['athlete' => ['id' => 9999, 'displayName' => 'Unknown Player'], 'starter' => true, 'position' => ['displayName' => 'CB'], 'jersey' => '5', 'stats' => [['name' => 'foulsCommitted', 'value' => 2]]],
                ],
            ],
        ],
    ]);

    $secondConnector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($secondPayload),
    ]));
    app()->instance(Worldcup26Connector::class, $secondConnector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    expect(FixtureLineup::query()->where('player_id', $known->id)->count())->toBe(1)
        ->and(FixtureLineup::query()->whereNull('player_id')->where('fixture_id', $fixture->id)->count())->toBe(1)
        ->and(FixtureLineup::query()->where('player_id', $known->id)->sole()->stats)
        ->toBe([['name' => 'saves', 'value' => 4]]);
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

test('maps red_card and penalty_missed key events, and persists is_own_goal / is_penalty flags', function (): void {
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
    $ownGoalScorer = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001]);
    $penaltyScorer = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5002]);
    $sentOff = Player::factory()->create(['team_id' => $away->id, 'match_data_id' => 5003]);
    $penaltyMisser = Player::factory()->create(['team_id' => $away->id, 'match_data_id' => 5004]);

    $payload = liveMatchEventPayload([
        'keyEvents' => [
            // Own goal (scored)
            [
                'type' => ['text' => 'Goal - Own Goal'],
                'clock' => ['displayValue' => "12'"],
                'team' => ['id' => 83],
                'scoringPlay' => true,
                'redCard' => false,
                'yellowCard' => false,
                'ownGoal' => true,
                'penaltyKick' => false,
                'athletesInvolved' => [['id' => 5001]],
            ],
            // Penalty scored
            [
                'type' => ['text' => 'Goal - Penalty'],
                'clock' => ['displayValue' => "20'"],
                'team' => ['id' => 83],
                'scoringPlay' => true,
                'redCard' => false,
                'yellowCard' => false,
                'ownGoal' => false,
                'penaltyKick' => true,
                'athletesInvolved' => [['id' => 5002]],
            ],
            // Straight red card, no goal involved
            [
                'type' => ['text' => 'Red Card'],
                'clock' => ['displayValue' => "30'"],
                'team' => ['id' => 86],
                'scoringPlay' => false,
                'redCard' => true,
                'yellowCard' => false,
                'ownGoal' => false,
                'penaltyKick' => false,
                'athletesInvolved' => [['id' => 5003]],
            ],
            // Second yellow (both flags set) — must resolve to red_card, not yellow_card
            [
                'type' => ['text' => 'Yellow Card - Second Yellow'],
                'clock' => ['displayValue' => "35'"],
                'team' => ['id' => 86],
                'scoringPlay' => false,
                'redCard' => true,
                'yellowCard' => true,
                'ownGoal' => false,
                'penaltyKick' => false,
                'athletesInvolved' => [['id' => 5003]],
            ],
            // Penalty missed
            [
                'type' => ['text' => 'Penalty Missed'],
                'clock' => ['displayValue' => "40'"],
                'team' => ['id' => 86],
                'scoringPlay' => false,
                'redCard' => false,
                'yellowCard' => false,
                'ownGoal' => false,
                'penaltyKick' => true,
                'athletesInvolved' => [['id' => 5004]],
            ],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $ownGoal = FixtureEvent::query()->where('minute', 12)->sole();
    $penaltyGoal = FixtureEvent::query()->where('minute', 20)->sole();
    $redCards = FixtureEvent::query()->where('type', 'red_card')->orderBy('minute')->get();
    $penaltyMissed = FixtureEvent::query()->where('type', 'penalty_missed')->sole();

    expect($ownGoal->type)->toBe('goal')
        ->and($ownGoal->is_own_goal)->toBeTrue()
        ->and($ownGoal->is_penalty)->toBeFalse()
        ->and($ownGoal->player_id)->toBe($ownGoalScorer->id)
        ->and($penaltyGoal->type)->toBe('goal')
        ->and($penaltyGoal->is_penalty)->toBeTrue()
        ->and($penaltyGoal->is_own_goal)->toBeFalse()
        ->and($penaltyGoal->player_id)->toBe($penaltyScorer->id)
        ->and($redCards)->toHaveCount(2)
        ->and($redCards->pluck('minute')->all())->toBe([30, 35])
        ->and($redCards->pluck('player_id')->all())->toBe([$sentOff->id, $sentOff->id])
        ->and($penaltyMissed->is_penalty)->toBeTrue()
        ->and($penaltyMissed->is_own_goal)->toBeFalse()
        ->and($penaltyMissed->player_id)->toBe($penaltyMisser->id);
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
        ->expectsOutputToContain("Skipped fixture #{$failing->id}")
        ->expectsOutput('1 fixtures synced.')
        ->assertSuccessful();

    expect($failing->refresh()->state)->toBe(FixtureState::Scheduled)
        ->and($ok->refresh()->state)->toBe(FixtureState::Finished);
});

test('stores the worldcup26 display name for an unresolved lineup entry', function (): void {
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
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 9999, 'displayName' => 'Unknown Player'], 'starter' => true, 'position' => ['displayName' => 'CB'], 'jersey' => '5', 'stats' => []],
                ],
            ],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $unresolvedRow = FixtureLineup::query()->whereNull('player_id')->where('fixture_id', $fixture->id)->sole();
    expect($unresolvedRow->unresolved_name)->toBe('Unknown Player');
});

test('fills fantasy_points and fantasy_stats for a resolved lineup player from Fantasy live scores', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 3]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'week_number' => 3,
        'date' => now()->subMinutes(30),
    ]);
    $player = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001, 'fantasy_id' => 2759]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 5001, 'displayName' => 'Known Player'], 'starter' => true, 'position' => ['displayName' => 'GK'], 'jersey' => '1', 'stats' => []],
                ],
            ],
        ],
    ]);

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerRequest::class => MockResponse::make([
            'id' => 2759,
            'playerStats' => [
                ['weekNumber' => 3, 'totalPoints' => 7, 'stats' => ['mins_played' => [90, 2], 'goals' => [1, 5]]],
                ['weekNumber' => 2, 'totalPoints' => 2, 'stats' => ['mins_played' => [90, 2]]],
            ],
        ]),
    ]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $lineup = FixtureLineup::query()->where('player_id', $player->id)->sole();
    expect($lineup->fantasy_points)->toBe(7)
        ->and($lineup->fantasy_stats)->toBe(['mins_played' => [90, 2], 'goals' => [1, 5]]);
});

test('leaves fantasy_points/fantasy_stats null for an unresolved lineup entry', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 1]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'week_number' => 1,
        'date' => now()->subMinutes(30),
    ]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 9999, 'displayName' => 'Unknown Player'], 'starter' => true, 'position' => ['displayName' => 'CB'], 'jersey' => '5', 'stats' => []],
                ],
            ],
        ],
    ]);

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $lineup = FixtureLineup::query()->whereNull('player_id')->sole();
    expect($lineup->fantasy_points)->toBeNull()
        ->and($lineup->fantasy_stats)->toBeNull();
});
