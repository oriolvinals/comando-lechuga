<?php

use App\Console\Commands\SyncCurrentSeasonStartProbabilities;
use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Integrations\FutbolFantasy\FutbolFantasyConnector;
use App\Models\Fixture;
use App\Models\FixtureLineupProbability;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    Sleep::fake();
});

function futbolFantasyFixtureHtml(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/futbolfantasy/{$name}.html"));
}

/**
 * Real Madrid — the only season team — hosts Villarreal in J8, and four of
 * the five players on the fixture pages are already linked (Mastantuono,
 * FF 17000, isn't one of ours).
 *
 * @return array{season: Season, madrid: Team, villarreal: Team, fixture: Fixture, courtois: Player, dumfries: Player, vinicius: Player, endrick: Player}
 */
function madridHostsVillarrealInWeek8(?CarbonInterface $kickoff = null): array
{
    $season = Season::factory()->create(['start_date' => now()->subMonth(), 'end_date' => now()->addMonths(8)]);
    $madrid = Team::factory()->create(['fantasy_id' => 15, 'short_name' => 'RMA']);
    $villarreal = Team::factory()->create(['fantasy_id' => 20, 'short_name' => 'VIL']);
    $season->teams()->attach($madrid->id);

    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 8,
        'team_local_id' => $madrid->id,
        'team_guest_id' => $villarreal->id,
        'date' => $kickoff ?? now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);

    $linked = fn (int $futbolfantasyId, string $nickname): Player => Player::factory()->create([
        'team_id' => $madrid->id,
        'futbolfantasy_id' => $futbolfantasyId,
        'nickname' => $nickname,
        'status' => PlayerStatus::Ok,
    ]);

    return [
        'season' => $season,
        'madrid' => $madrid,
        'villarreal' => $villarreal,
        'fixture' => $fixture,
        'courtois' => $linked(59, 'Courtois'),
        'dumfries' => $linked(6055, 'Dumfries'),
        'vinicius' => $linked(5565, 'Vini Jr.'),
        'endrick' => $linked(13564, 'Endrick'),
    ];
}

/**
 * Binds a FútbolFantasy connector answering each team page slug with the given response.
 *
 * @param  array<string, MockResponse>  $pages  slug => response
 */
function fakeFutbolFantasyPages(array $pages): MockClient
{
    $responses = [];

    foreach ($pages as $slug => $response) {
        $responses["*laliga/equipos/{$slug}"] = $response;
    }

    $mockClient = new MockClient($responses);
    app()->instance(FutbolFantasyConnector::class, (new FutbolFantasyConnector)->withMockClient($mockClient));

    return $mockClient;
}

test('stores each linked player\'s probability on the team\'s fixture for the page\'s jornada', function (): void {
    $this->freezeTime();
    ['fixture' => $fixture, 'courtois' => $courtois, 'vinicius' => $vinicius, 'endrick' => $endrick] = madridHostsVillarrealInWeek8();
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    $rows = FixtureLineupProbability::query()->where('fixture_id', $fixture->id)->get()->keyBy('player_id');

    expect($rows)->toHaveCount(4)
        ->and($rows[$courtois->id]->probability)->toBe(95)
        ->and($rows[$courtois->id]->predicted_starter)->toBeTrue()
        ->and($rows[$courtois->id]->confirmed_starter)->toBeNull()
        ->and($rows[$courtois->id]->fetched_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($rows[$vinicius->id]->probability)->toBe(60)
        ->and($rows[$endrick->id]->probability)->toBe(10)
        ->and($rows[$endrick->id]->predicted_starter)->toBeFalse();
});

test('updates the existing rows instead of adding new ones', function (): void {
    ['fixture' => $fixture, 'courtois' => $courtois] = madridHostsVillarrealInWeek8();
    FixtureLineupProbability::factory()->create([
        'player_id' => $courtois->id,
        'fixture_id' => $fixture->id,
        'probability' => 40,
        'predicted_starter' => false,
    ]);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    $row = FixtureLineupProbability::query()->where('player_id', $courtois->id)->sole();

    expect(FixtureLineupProbability::query()->count())->toBe(4)
        ->and($row->probability)->toBe(95)
        ->and($row->predicted_starter)->toBeTrue();
});

test('prints a summary of the teams, the parsed and linked players and the unlinked ones', function (): void {
    madridHostsVillarrealInWeek8();
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('Teams: 1 fetched, 0 failed, 0 not due, 0 without an upcoming match.')
        ->expectsOutputToContain('Players: 5 parsed.')
        ->expectsOutputToContain('Linked: 4 by stored id, 0 by market value, 0 by name, 0 by manual map.')
        ->expectsOutputToContain('Unlinked (1): Mastantuono (RMA, FF 17000)')
        ->assertSuccessful();
});

test('reports the season teams missing from the FútbolFantasy team map', function (): void {
    ['season' => $season] = madridHostsVillarrealInWeek8();
    $season->teams()->attach(Team::factory()->create(['fantasy_id' => 999, 'short_name' => 'XYZ'])->id);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('Missing from the FútbolFantasy team map: XYZ')
        ->assertSuccessful();
});

test('keeps the last predicted % and XI when FútbolFantasy confirms the lineup', function (): void {
    ['courtois' => $courtois, 'vinicius' => $vinicius, 'endrick' => $endrick] = madridHostsVillarrealInWeek8();

    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);
    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-confirmada'))]);
    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    $rows = FixtureLineupProbability::query()->get()->keyBy('player_id');

    expect($rows[$courtois->id]->probability)->toBe(95)
        ->and($rows[$courtois->id]->confirmed_starter)->toBeTrue()
        ->and($rows[$vinicius->id]->probability)->toBe(60)
        ->and($rows[$vinicius->id]->predicted_starter)->toBeTrue()
        ->and($rows[$vinicius->id]->confirmed_starter)->toBeFalse()
        ->and($rows[$endrick->id]->probability)->toBe(10)
        ->and($rows[$endrick->id]->predicted_starter)->toBeFalse()
        ->and($rows[$endrick->id]->confirmed_starter)->toBeTrue();
});

test('leaves the team\'s rows untouched when its page fails', function (): void {
    ['fixture' => $fixture, 'courtois' => $courtois] = madridHostsVillarrealInWeek8();
    $row = FixtureLineupProbability::factory()->create(['player_id' => $courtois->id, 'fixture_id' => $fixture->id, 'probability' => 42]);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make('', 500)]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('Skipped RMA')
        ->expectsOutputToContain('Teams: 0 fetched, 1 failed')
        ->assertSuccessful();

    expect($row->refresh()->probability)->toBe(42)
        ->and(FixtureLineupProbability::query()->count())->toBe(1);
});

test('leaves the team\'s rows untouched when its page has no players', function (): void {
    ['fixture' => $fixture, 'courtois' => $courtois] = madridHostsVillarrealInWeek8();
    $row = FixtureLineupProbability::factory()->create(['player_id' => $courtois->id, 'fixture_id' => $fixture->id, 'probability' => 42]);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(
        '<html><body><section class="mod alineacion_wrapper"><span class="posible">Posible alineación</span><span class="jornada">8</span></section></body></html>',
    )]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('no players in the J8 lineup')
        ->assertSuccessful();

    expect($row->refresh()->probability)->toBe(42);
});

test('skips a page without a jornada in its lineup heading', function (): void {
    madridHostsVillarrealInWeek8();
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(
        '<html><body><section class="mod alineacion_wrapper"><span class="posible">Posible alineación</span><div class="jugador_59 tipo_lista" data-probabilidad="95%"></div></section></body></html>',
    )]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('jornada')
        ->assertSuccessful();

    expect(FixtureLineupProbability::query()->count())->toBe(0);
});

test('stores nothing when the page\'s rival is not the fixture\'s opponent', function (): void {
    ['fixture' => $fixture] = madridHostsVillarrealInWeek8();
    $atletico = Team::factory()->create(['fantasy_id' => 2, 'short_name' => 'ATM']);
    $fixture->update(['team_guest_id' => $atletico->id]);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('against VIL, the fixture against ATM')
        ->assertSuccessful();

    expect(FixtureLineupProbability::query()->count())->toBe(0);
});

test('keeps the rows of a jornada that has already kicked off', function (): void {
    ['season' => $season, 'madrid' => $madrid, 'villarreal' => $villarreal, 'fixture' => $fixture, 'courtois' => $courtois]
        = madridHostsVillarrealInWeek8(now()->subHour());
    $fixture->update(['state' => FixtureState::FirstHalf]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 9,
        'team_local_id' => $villarreal->id,
        'team_guest_id' => $madrid->id,
        'date' => now()->addDays(3),
        'state' => FixtureState::Scheduled,
    ]);
    $row = FixtureLineupProbability::factory()->create(['player_id' => $courtois->id, 'fixture_id' => $fixture->id, 'probability' => 42]);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('J8 has already kicked off')
        ->assertSuccessful();

    expect($row->refresh()->probability)->toBe(42)
        ->and(FixtureLineupProbability::query()->count())->toBe(1);
});

test('fetches a team on every run while its next match is within 48 hours', function (): void {
    madridHostsVillarrealInWeek8(now()->addHours(47));
    $mockClient = fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();
    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    $mockClient->assertSentCount(2);
});

test('fetches a team at most every 6 hours while its next match is further away', function (): void {
    madridHostsVillarrealInWeek8(now()->addDays(4));
    $mockClient = fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();
    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('Teams: 0 fetched, 0 failed, 1 not due')
        ->assertSuccessful();
    $mockClient->assertSentCount(1);

    $this->travel(361)->minutes();
    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    $mockClient->assertSentCount(2);
});

test('treats a numeric string attempt timestamp as due, matching a Redis-backed cache store', function (): void {
    ['madrid' => $madrid] = madridHostsVillarrealInWeek8(now()->addDays(4));
    Cache::forever('start_probabilities.attempted_at.'.$madrid->id, (string) now()->getTimestamp());
    $mockClient = fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('Teams: 0 fetched, 0 failed, 1 not due')
        ->assertSuccessful();

    $mockClient->assertNothingSent();
});

test('never fetches a team whose match has kicked off', function (): void {
    madridHostsVillarrealInWeek8(now()->subMinutes(5));
    $mockClient = fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('1 without an upcoming match')
        ->assertSuccessful();

    $mockClient->assertNothingSent();
});

test('--force fetches every team whether it is due or not', function (): void {
    madridHostsVillarrealInWeek8(now()->addDays(4));
    $mockClient = fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();
    $this->artisan(SyncCurrentSeasonStartProbabilities::class, ['--force' => true])->assertSuccessful();

    $mockClient->assertSentCount(2);
});

test('pauses 10 to 30 seconds between two page requests', function (): void {
    ['season' => $season, 'villarreal' => $villarreal] = madridHostsVillarrealInWeek8();
    $season->teams()->attach($villarreal->id);
    fakeFutbolFantasyPages([
        'real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible')),
        'villarreal' => MockResponse::make('', 500),
    ]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds >= 10 && $duration->totalSeconds <= 30);
});

test('is scheduled every ten minutes', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'season:sync-start-probabilities'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *');
});
