<?php

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use App\Services\TeamStrength;
use App\Services\TeamStrengthInputs;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @return Collection<int, TeamStrengthInputs> keyed by team id
 */
function teamStrengthInputsByTeam(Season $season, CarbonInterface $at): Collection
{
    return collect(app(TeamStrength::class)->inputsAt($season, $at))->keyBy('teamId');
}

beforeEach(function (): void {
    $this->travelTo('2026-10-01 12:00:00');

    $this->season = Season::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2027-05-31']);
    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->teamC = Team::factory()->create();
    $this->season->teams()->attach([$this->teamA->id, $this->teamB->id, $this->teamC->id]);

    // Squad values: A has two players (100M + 200M on the reference day), B has
    // one (150M), C has none. A also has a stale value and a future one that
    // must not count.
    $a1 = Player::factory()->create(['team_id' => $this->teamA->id]);
    $a2 = Player::factory()->create(['team_id' => $this->teamA->id]);
    $b1 = Player::factory()->create(['team_id' => $this->teamB->id]);

    PlayerMarket::factory()->create(['player_id' => $a1->id, 'date' => '2026-09-20', 'value' => 50_000_000]);
    PlayerMarket::factory()->create(['player_id' => $a1->id, 'date' => '2026-09-30', 'value' => 100_000_000]);
    PlayerMarket::factory()->create(['player_id' => $a1->id, 'date' => '2026-10-05', 'value' => 999_000_000]);
    PlayerMarket::factory()->create(['player_id' => $a2->id, 'date' => '2026-09-30', 'value' => 200_000_000]);
    PlayerMarket::factory()->create(['player_id' => $b1->id, 'date' => '2026-09-30', 'value' => 150_000_000]);

    // Two finished matches within range (A vs B, both ways), plus a later one
    // between the same two teams that must not count.
    $this->fixture1 = Fixture::factory()->create([
        'season_id' => $this->season->id,
        'date' => '2026-09-28 20:00:00',
        'state' => FixtureState::Finished,
        'team_local_id' => $this->teamA->id,
        'team_guest_id' => $this->teamB->id,
        'local_score' => 2,
        'guest_score' => 1,
        'local_key_passes' => 10,
        'guest_key_passes' => 6,
    ]);
    $this->fixture2 = Fixture::factory()->create([
        'season_id' => $this->season->id,
        'date' => '2026-09-29 20:00:00',
        'state' => FixtureState::Finished,
        'team_local_id' => $this->teamB->id,
        'team_guest_id' => $this->teamA->id,
        'local_score' => 0,
        'guest_score' => 0,
        'local_key_passes' => null,
        'guest_key_passes' => null,
    ]);
    $this->futureFixture = Fixture::factory()->create([
        'season_id' => $this->season->id,
        'date' => '2026-10-03 20:00:00',
        'state' => FixtureState::Finished,
        'team_local_id' => $this->teamA->id,
        'team_guest_id' => $this->teamB->id,
        'local_score' => 5,
        'guest_score' => 0,
    ]);

    FixtureLineup::factory()->create(['fixture_id' => $this->fixture1->id, 'team_id' => $this->teamA->id, 'stats' => [['name' => 'shotsOnTarget', 'value' => 3]]]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture1->id, 'team_id' => $this->teamA->id, 'stats' => [['name' => 'shotsOnTarget', 'value' => 2]]]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture1->id, 'team_id' => $this->teamB->id, 'stats' => [['name' => 'shotsOnTarget', 'value' => 4]]]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture2->id, 'team_id' => $this->teamB->id, 'stats' => [['name' => 'shotsOnTarget', 'value' => 2]]]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture2->id, 'team_id' => $this->teamA->id, 'stats' => [['name' => 'shotsOnTarget', 'value' => 1]]]);
});

test('inputsAt averages goals, shots and key passes per match, counting key passes only where they exist', function (): void {
    $inputs = teamStrengthInputsByTeam($this->season, now());
    $a = $inputs[$this->teamA->id];
    $b = $inputs[$this->teamB->id];

    expect($a->matches)->toBe(2)
        ->and($a->goalDifference)->toEqualWithDelta(0.5, 1e-9)
        ->and($a->shotsOnTargetDifference)->toEqualWithDelta(0.0, 1e-9)
        ->and($a->keyPassesFor)->toEqualWithDelta(10.0, 1e-9)
        ->and($a->keyPassesAgainst)->toEqualWithDelta(6.0, 1e-9)
        ->and($a->goalsFor)->toEqualWithDelta(1.0, 1e-9)
        ->and($a->shotsOnTargetFor)->toEqualWithDelta(3.0, 1e-9)
        ->and($a->failedToScoreRate)->toEqualWithDelta(0.5, 1e-9)
        ->and($a->goalsAgainst)->toEqualWithDelta(0.5, 1e-9)
        ->and($a->shotsOnTargetAgainst)->toEqualWithDelta(3.0, 1e-9)
        ->and($a->logValue)->toEqualWithDelta(log(300_000_000 + 1), 1e-9);

    expect($b->matches)->toBe(2)
        ->and($b->goalDifference)->toEqualWithDelta(-0.5, 1e-9)
        ->and($b->shotsOnTargetDifference)->toEqualWithDelta(0.0, 1e-9)
        ->and($b->keyPassesFor)->toEqualWithDelta(6.0, 1e-9)
        ->and($b->keyPassesAgainst)->toEqualWithDelta(10.0, 1e-9)
        ->and($b->goalsFor)->toEqualWithDelta(0.5, 1e-9)
        ->and($b->shotsOnTargetFor)->toEqualWithDelta(3.0, 1e-9)
        ->and($b->failedToScoreRate)->toEqualWithDelta(0.5, 1e-9)
        ->and($b->goalsAgainst)->toEqualWithDelta(1.0, 1e-9)
        ->and($b->shotsOnTargetAgainst)->toEqualWithDelta(3.0, 1e-9)
        ->and($b->logValue)->toEqualWithDelta(log(150_000_000 + 1), 1e-9);
});

test('excludes a fixture and a market value dated after $at', function (): void {
    $inputs = teamStrengthInputsByTeam($this->season, now());
    $a = $inputs[$this->teamA->id];

    // Without the exclusion, matches would be 3 and goalsFor would be much
    // higher (the excluded fixture is a 5-0 win for A), and logValue would be
    // inflated by the 999M future value.
    expect($a->matches)->toBe(2)
        ->and($a->goalsFor)->toEqualWithDelta(1.0, 1e-9)
        ->and($a->logValue)->toEqualWithDelta(log(300_000_000 + 1), 1e-9);
});

test('a team with no matches has null averages and rates equal to its value z', function (): void {
    PlayerMarket::factory()->create([
        'player_id' => Player::factory()->create(['team_id' => $this->teamC->id])->id,
        'date' => '2026-09-30',
        'value' => 80_000_000,
    ]);

    $inputs = teamStrengthInputsByTeam($this->season, now());
    $c = $inputs[$this->teamC->id];

    expect($c->matches)->toBe(0)
        ->and($c->goalDifference)->toBeNull()
        ->and($c->shotsOnTargetDifference)->toBeNull()
        ->and($c->keyPassesFor)->toBeNull()
        ->and($c->keyPassesAgainst)->toBeNull()
        ->and($c->goalsFor)->toBeNull()
        ->and($c->shotsOnTargetFor)->toBeNull()
        ->and($c->failedToScoreRate)->toBeNull()
        ->and($c->goalsAgainst)->toBeNull()
        ->and($c->shotsOnTargetAgainst)->toBeNull()
        ->and($c->logValue)->toEqualWithDelta(log(80_000_000 + 1), 1e-9);

    $ratings = app(TeamStrength::class)->ratingsAt($this->season, now());

    expect($ratings[$this->teamC->id]->general)->toEqualWithDelta($ratings[$this->teamC->id]->valueZ, 1e-9);
});

test('memoizes ratingsAt per season and minute so repeated calls skip the queries', function (): void {
    $teamStrength = app(TeamStrength::class);

    DB::enableQueryLog();
    $first = $teamStrength->ratingsAt($this->season, now());
    $queriesAfterFirst = count(DB::getQueryLog());

    expect($queriesAfterFirst)->toBeGreaterThan(0);

    $second = $teamStrength->ratingsAt($this->season, now());

    expect(count(DB::getQueryLog()))->toBe($queriesAfterFirst)
        ->and($second)->toBe($first);
});

test('sums only the topPlayers highest squad values per team', function (): void {
    $season = Season::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create();
    $season->teams()->attach($team->id);

    foreach (range(1, 16) as $value) {
        $player = Player::factory()->create(['team_id' => $team->id]);
        PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-09-30', 'value' => $value * 1_000_000]);
    }

    $inputs = teamStrengthInputsByTeam($season, now());

    // 16 players valued 1M..16M: only the top 15 count, so the 1M player is excluded.
    $topFifteenSum = array_sum(range(2, 16)) * 1_000_000;

    expect($inputs[$team->id]->logValue)->toEqualWithDelta(log($topFifteenSum + 1), 1e-9);
});

test('a team with no market values has a null log value and a value z of 0', function (): void {
    $inputs = teamStrengthInputsByTeam($this->season, now());

    expect($inputs[$this->teamC->id]->logValue)->toBeNull();

    $ratings = app(TeamStrength::class)->ratingsAt($this->season, now());

    // A and B are ±1 on value between the two teams that have one; C is left
    // out of that z and sits at the mean instead of far below it.
    expect($ratings[$this->teamC->id]->valueZ)->toBe(0.0)
        ->and($ratings[$this->teamA->id]->valueZ)->toEqualWithDelta(1.0, 1e-9)
        ->and($ratings[$this->teamB->id]->valueZ)->toEqualWithDelta(-1.0, 1e-9);
});

test('caches the inputs for the current hour so a later request skips the lineup query', function (): void {
    Cache::flush();

    $first = app(TeamStrength::class)->ratingsAt($this->season, now());

    app()->forgetScopedInstances();
    $this->travel(20)->minutes();

    DB::enableQueryLog();
    $second = app(TeamStrength::class)->ratingsAt($this->season, now());

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'fixture_lineups')))->toBeEmpty()
        ->and($second)->toEqual($first);
});

test('does not cache the inputs at an explicit past time', function (): void {
    Cache::flush();

    app(TeamStrength::class)->ratingsAt($this->season, now()->subDays(2));

    app()->forgetScopedInstances();

    DB::enableQueryLog();
    app(TeamStrength::class)->ratingsAt($this->season, now()->subDays(2));

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'fixture_lineups')))->not->toBeEmpty();
});
