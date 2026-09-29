<?php

use App\Enums\DifficultyVariant;
use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use App\Services\MatchDifficulty;
use App\Services\MatchDifficultyResult;
use Illuminate\Support\Facades\DB;

/**
 * A team of the test season whose squad value on the reference day is `$value`.
 */
function difficultyTeam(Season $season, int $value): Team
{
    $team = Team::factory()->create();
    $season->teams()->attach($team->id);

    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-09-30', 'value' => $value]);

    return $team;
}

function difficultyFixture(Season $season, Team $local, Team $guest, string $date, FixtureState $state = FixtureState::Scheduled): Fixture
{
    return Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => $date,
        'state' => $state,
        'team_local_id' => $local->id,
        'team_guest_id' => $guest->id,
        'local_score' => $state === FixtureState::Finished ? 1 : null,
        'guest_score' => $state === FixtureState::Finished ? 1 : null,
    ]);
}

function matchDifficulty(): MatchDifficulty
{
    return app(MatchDifficulty::class);
}

beforeEach(function (): void {
    $this->travelTo('2026-10-01 12:00:00');

    $this->season = Season::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2027-05-31']);
});

test('playing away is 2.0 harder than playing at home against the same rival', function (): void {
    $teamA = difficultyTeam($this->season, 100_000_000);
    $teamB = difficultyTeam($this->season, 200_000_000);
    difficultyTeam($this->season, 50_000_000);

    $home = difficultyFixture($this->season, $teamA, $teamB, '2026-10-04 18:00:00');
    $away = difficultyFixture($this->season, $teamB, $teamA, '2026-10-11 18:00:00');

    $atHome = matchDifficulty()->for($home, $teamA->id, DifficultyVariant::General);
    $awayResult = matchDifficulty()->for($away, $teamA->id, DifficultyVariant::General);

    expect($awayResult->difficulty - $atHome->difficulty)->toEqualWithDelta(2.0, 0.1)
        ->and($atHome->components['home'])->toBe(0.4)
        ->and($awayResult->components['home'])->toBe(-0.4)
        ->and($atHome->components['rival_strength'])->toBe($awayResult->components['rival_strength'])
        ->and($atHome->variant)->toBe(DifficultyVariant::General)
        ->and($atHome->rivalPosition)->toBeInt();
});

test('a clearly stronger rival is harder than a weaker one', function (): void {
    $teamA = difficultyTeam($this->season, 100_000_000);
    $strong = difficultyTeam($this->season, 900_000_000);
    $weak = difficultyTeam($this->season, 10_000_000);

    $againstStrong = matchDifficulty()->for(difficultyFixture($this->season, $teamA, $strong, '2026-10-04 18:00:00'), $teamA->id, DifficultyVariant::General);
    $againstWeak = matchDifficulty()->for(difficultyFixture($this->season, $teamA, $weak, '2026-10-11 18:00:00'), $teamA->id, DifficultyVariant::General);

    expect($againstStrong->difficulty)->toBeGreaterThan($againstWeak->difficulty + 2.0)
        ->and($againstStrong->difficulty)->toBeGreaterThan(5.0)
        ->and($againstWeak->difficulty)->toBeLessThan(5.0);
});

test('the difficulty is clamped to 0 and 10 in extreme cases', function (): void {
    // Neither extreme can leave the 0–10 scale with three teams (a z-score
    // stays within ±√2), so this world has six: one giant, one minnow and
    // four equal mid-table sides, putting the outliers at z ≈ ±√3.
    $giant = difficultyTeam($this->season, 4_000_000_000);
    $minnow = difficultyTeam($this->season, 1_000);
    $middle = collect(range(1, 4))->map(fn (): Team => difficultyTeam($this->season, 2_000_000));

    $awayAtGiant = difficultyFixture($this->season, $giant, $middle[0], '2026-10-04 18:00:00');
    $homeAgainstMinnow = difficultyFixture($this->season, $middle[1], $minnow, '2026-10-04 20:00:00');

    $hardest = matchDifficulty()->for($awayAtGiant, $middle[0]->id, DifficultyVariant::General);
    $easiest = matchDifficulty()->for($homeAgainstMinnow, $middle[1]->id, DifficultyVariant::General);

    expect($hardest->difficulty)->toBe(10.0)
        ->and($hardest->rivalEase)->toBe(-1.0)
        ->and($easiest->difficulty)->toBe(0.0)
        ->and($easiest->rivalEase)->toBe(1.0);
});

test('the rival ease is (5 − difficulty) / 5', function (): void {
    $teamA = difficultyTeam($this->season, 100_000_000);
    $teamB = difficultyTeam($this->season, 300_000_000);
    $teamC = difficultyTeam($this->season, 20_000_000);

    $results = matchDifficulty()->forMany([
        [difficultyFixture($this->season, $teamA, $teamB, '2026-10-04 18:00:00'), $teamA->id, DifficultyVariant::General],
        [difficultyFixture($this->season, $teamC, $teamA, '2026-10-11 18:00:00'), $teamA->id, DifficultyVariant::Attack],
        [difficultyFixture($this->season, $teamB, $teamC, '2026-10-18 18:00:00'), $teamC->id, DifficultyVariant::Defense],
    ]);

    foreach ($results as $result) {
        expect($result->rivalEase)->toBe(round((5 - $result->difficulty) / 5, 3));
    }
});

describe('absences', function (): void {
    beforeEach(function (): void {
        $this->teamA = difficultyTeam($this->season, 100_000_000);
        $this->rival = difficultyTeam($this->season, 150_000_000);
        $this->other = difficultyTeam($this->season, 120_000_000);

        // The rival's regulars: an expensive star and a cheap one, both
        // playing the whole of the rival's two finished matches.
        $this->star = Player::factory()->create(['team_id' => $this->rival->id, 'status' => PlayerStatus::Ok, 'market_value' => 90_000_000]);
        $cheap = Player::factory()->create(['team_id' => $this->rival->id, 'status' => PlayerStatus::Ok, 'market_value' => 10_000_000]);

        foreach (['2026-09-20 18:00:00', '2026-09-27 18:00:00'] as $date) {
            $finished = difficultyFixture($this->season, $this->rival, $this->other, $date, FixtureState::Finished);

            foreach ([$this->star, $cheap] as $player) {
                FixtureLineup::factory()->create([
                    'fixture_id' => $finished->id,
                    'team_id' => $this->rival->id,
                    'player_id' => $player->id,
                    'fantasy_stats' => ['mins_played' => [90, 1]],
                ]);
            }
        }

        $this->nextMatch = difficultyFixture($this->season, $this->teamA, $this->rival, '2026-10-04 18:00:00');
        $this->laterMatch = difficultyFixture($this->season, $this->rival, $this->teamA, '2026-10-11 18:00:00');
        difficultyFixture($this->season, $this->other, Team::factory()->create(), '2026-10-04 20:00:00');
    });

    test('an injured expensive regular of the rival lowers the general and attack difficulty, never the defense one', function (): void {
        $before = [
            DifficultyVariant::General->value => matchDifficulty()->for($this->nextMatch, $this->teamA->id, DifficultyVariant::General),
            DifficultyVariant::Attack->value => matchDifficulty()->for($this->nextMatch, $this->teamA->id, DifficultyVariant::Attack),
            DifficultyVariant::Defense->value => matchDifficulty()->for($this->nextMatch, $this->teamA->id, DifficultyVariant::Defense),
        ];

        $this->star->update(['status' => PlayerStatus::Injured]);

        $general = matchDifficulty()->for($this->nextMatch, $this->teamA->id, DifficultyVariant::General);
        $attack = matchDifficulty()->for($this->nextMatch, $this->teamA->id, DifficultyVariant::Attack);
        $defense = matchDifficulty()->for($this->nextMatch, $this->teamA->id, DifficultyVariant::Defense);

        expect($before['general']->absenceAdjusted)->toBeFalse()
            ->and($general->difficulty)->toBeLessThan($before['general']->difficulty)
            ->and($general->absenceAdjusted)->toBeTrue()
            ->and($general->components['absences'])->toBeGreaterThan(0.0)
            ->and($attack->difficulty)->toBeLessThan($before['attack']->difficulty)
            ->and($attack->absenceAdjusted)->toBeTrue()
            ->and($defense->difficulty)->toBe($before['defense']->difficulty)
            ->and($defense->absenceAdjusted)->toBeFalse()
            ->and($defense->components['absences'])->toBe(0.0);
    });

    test('FútbolFantasy\'s probable XI decides who is out when there is one', function (): void {
        $before = matchDifficulty()->for($this->nextMatch, $this->teamA->id, DifficultyVariant::General);

        FixtureLineupProbability::factory()->create([
            'fixture_id' => $this->nextMatch->id,
            'player_id' => $this->star->id,
            'predicted_starter' => false,
            'probability' => 10,
        ]);

        $after = matchDifficulty()->for($this->nextMatch, $this->teamA->id, DifficultyVariant::General);

        expect($after->absenceAdjusted)->toBeTrue()
            ->and($after->difficulty)->toBeLessThan($before->difficulty);
    });

    test('there is no absence adjustment for a match that is not the rival\'s next one', function (): void {
        $this->star->update(['status' => PlayerStatus::Injured]);

        $result = matchDifficulty()->for($this->laterMatch, $this->teamA->id, DifficultyVariant::General);

        expect($result->absenceAdjusted)->toBeFalse()
            ->and($result->components['absences'])->toBe(0.0);
    });

    test('there is no absence adjustment at an explicit past date', function (): void {
        $this->star->update(['status' => PlayerStatus::Injured]);

        $result = matchDifficulty()->for($this->nextMatch, $this->teamA->id, DifficultyVariant::General, now()->subDay());

        expect($result->absenceAdjusted)->toBeFalse()
            ->and($result->components['absences'])->toBe(0.0);
    });
});

test('a fixture without a date or a team outside it gives null', function (): void {
    $teamA = difficultyTeam($this->season, 100_000_000);
    $teamB = difficultyTeam($this->season, 200_000_000);
    $stranger = difficultyTeam($this->season, 50_000_000);

    $fixture = difficultyFixture($this->season, $teamA, $teamB, '2026-10-04 18:00:00');
    $undated = Fixture::factory()->make([
        'season_id' => $this->season->id,
        'team_local_id' => $teamA->id,
        'team_guest_id' => $teamB->id,
        'date' => null,
    ]);

    expect(matchDifficulty()->for($undated, $teamA->id, DifficultyVariant::General))->toBeNull()
        ->and(matchDifficulty()->for($fixture, $stranger->id, DifficultyVariant::General))->toBeNull();
});

test('forMany matches for item by item in a constant number of queries', function (): void {
    $teams = collect(range(1, 6))->map(fn (int $index): Team => difficultyTeam($this->season, $index * 30_000_000));
    $variants = DifficultyVariant::cases();

    $items = [];

    for ($index = 0; $index < 10; $index++) {
        $local = $teams[$index % 6];
        $guest = $teams[($index + 1) % 6];
        $fixture = difficultyFixture($this->season, $local, $guest, now()->addDays($index + 1)->toDateTimeString());
        $items[] = [$fixture, $index % 2 === 0 ? $local->id : $guest->id, $variants[$index % 3]];
    }

    $one = array_map(fn (array $item): ?MatchDifficultyResult => matchDifficulty()->for(...$item), $items);

    expect(matchDifficulty()->forMany($items))->toEqual($one);

    DB::enableQueryLog();
    matchDifficulty()->forMany([$items[0]]);
    $queriesForOne = count(DB::getQueryLog());

    DB::flushQueryLog();
    matchDifficulty()->forMany($items);
    $queriesForTen = count(DB::getQueryLog());

    expect($queriesForTen)->toBe($queriesForOne);
});

test('the result serializes the difficulty, its variant and its components', function (): void {
    $teamA = difficultyTeam($this->season, 100_000_000);
    $teamB = difficultyTeam($this->season, 200_000_000);

    $result = matchDifficulty()->for(difficultyFixture($this->season, $teamA, $teamB, '2026-10-04 18:00:00'), $teamA->id, DifficultyVariant::Attack);

    expect($result->toArray())->toBe([
        'difficulty' => $result->difficulty,
        'difficulty_variant' => 'attack',
        'difficulty_components' => $result->components,
    ]);
});
