<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use App\Services\StartProbabilities;

beforeEach(function (): void {
    $this->season = Season::factory()->create(['start_date' => now()->subMonth(), 'end_date' => now()->addMonths(8)]);
    $this->madrid = Team::factory()->create(['fantasy_id' => 15, 'short_name' => 'RMA']);
    $this->villarreal = Team::factory()->create(['fantasy_id' => 20, 'short_name' => 'VIL']);
    $this->fixture = Fixture::factory()->create([
        'season_id' => $this->season->id,
        'week_number' => 8,
        'team_local_id' => $this->madrid->id,
        'team_guest_id' => $this->villarreal->id,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);
});

function startPlayer(Team $team, string $nickname, PlayerPosition $position = PlayerPosition::Midfield): Player
{
    return Player::factory()->create([
        'team_id' => $team->id,
        'nickname' => $nickname,
        'status' => PlayerStatus::Ok,
        'position' => $position,
    ]);
}

function startRow(Player $player, Fixture $fixture, array $attributes = []): FixtureLineupProbability
{
    return FixtureLineupProbability::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'probability' => 70,
        'predicted_starter' => true,
        'confirmed_starter' => null,
        'fetched_at' => now(),
        ...$attributes,
    ]);
}

test('gives an upcoming fixture one block per side with each player\'s probability', function (): void {
    $courtois = startPlayer($this->madrid, 'Courtois', PlayerPosition::Goalkeeper);
    startRow($courtois, $this->fixture, ['probability' => 95]);
    startRow(startPlayer($this->villarreal, 'Parejo'), $this->fixture, ['probability' => 80]);

    $result = app(StartProbabilities::class)->forFixture($this->fixture);

    expect($result['local']['team']->id)->toBe($this->madrid->id)
        ->and($result['local']['week_number'])->toBe(8)
        ->and($result['local']['source_url'])->toBe('https://www.futbolfantasy.com/laliga/equipos/real-madrid')
        ->and($result['local']['confirmed_source'])->toBeNull()
        ->and($result['local']['is_stale'])->toBeFalse()
        ->and($result['local']['fetched_at'])->not->toBeNull()
        ->and($result['local']['players'])->toHaveCount(1)
        ->and($result['local']['players'][0]['player']->id)->toBe($courtois->id)
        ->and($result['local']['players'][0]['player']->position)->toBe(PlayerPosition::Goalkeeper)
        ->and($result['local']['players'][0]['probability'])->toBe(95)
        ->and($result['local']['players'][0]['confirmed_starter'])->toBeNull()
        ->and($result['guest']['players'][0]['probability'])->toBe(80);
});

test('flags data older than 48 hours as stale', function (): void {
    startRow(startPlayer($this->madrid, 'Courtois'), $this->fixture, ['fetched_at' => now()->subHours(49)]);

    expect(app(StartProbabilities::class)->forFixture($this->fixture)['local']['is_stale'])->toBeTrue();
});

test('keeps a side without data empty instead of failing', function (): void {
    startRow(startPlayer($this->madrid, 'Courtois'), $this->fixture);

    $result = app(StartProbabilities::class)->forFixture($this->fixture);

    expect($result['local'])->not->toBeNull()
        ->and($result['guest'])->toBeNull();
});

test('gives nothing for a fixture without data or already kicked off', function (): void {
    expect(app(StartProbabilities::class)->forFixture($this->fixture))->toBeNull();

    startRow(startPlayer($this->madrid, 'Courtois'), $this->fixture);
    $this->fixture->update(['state' => FixtureState::FirstHalf]);

    expect(app(StartProbabilities::class)->forFixture($this->fixture->refresh()))->toBeNull()
        ->and(FixtureLineupProbability::query()->count())->toBe(1);
});

test('uses FútbolFantasy\'s confirmed lineup while worldcup26 has none', function (): void {
    $endrick = startPlayer($this->madrid, 'Endrick');
    startRow($endrick, $this->fixture, ['probability' => 10, 'predicted_starter' => false, 'confirmed_starter' => true]);

    $local = app(StartProbabilities::class)->forFixture($this->fixture)['local'];

    expect($local['confirmed_source'])->toBe('futbolfantasy')
        ->and($local['players'][0]['confirmed_starter'])->toBeTrue()
        ->and($local['players'][0]['probability'])->toBe(10);
});

test('lets the worldcup26 lineup win over FútbolFantasy and adds its players FF never listed', function (): void {
    $vinicius = startPlayer($this->madrid, 'Vini Jr.');
    $endrick = startPlayer($this->madrid, 'Endrick');
    $gonzalo = startPlayer($this->madrid, 'Gonzalo');
    startRow($vinicius, $this->fixture, ['probability' => 60, 'confirmed_starter' => true]);
    startRow($endrick, $this->fixture, ['probability' => 10, 'predicted_starter' => false, 'confirmed_starter' => false]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => $endrick->id, 'starter' => true]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => $gonzalo->id, 'starter' => true]);

    $local = app(StartProbabilities::class)->forFixture($this->fixture)['local'];
    $byPlayer = collect($local['players'])->keyBy(fn (array $entry): int => $entry['player']->id);

    expect($local['confirmed_source'])->toBe('worldcup26')
        ->and($local['is_stale'])->toBeFalse()
        ->and($byPlayer[$vinicius->id]['confirmed_starter'])->toBeFalse()
        ->and($byPlayer[$vinicius->id]['probability'])->toBe(60)
        ->and($byPlayer[$endrick->id]['confirmed_starter'])->toBeTrue()
        ->and($byPlayer[$gonzalo->id]['confirmed_starter'])->toBeTrue()
        ->and($byPlayer[$gonzalo->id]['probability'])->toBeNull()
        ->and($byPlayer[$gonzalo->id]['predicted_starter'])->toBeFalse();
});

test('worldcup26 confirming a team with no FútbolFantasy rows gives plain facts, not a false surprise', function (): void {
    $vinicius = startPlayer($this->madrid, 'Vini Jr.');
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => $vinicius->id, 'starter' => true]);

    $local = app(StartProbabilities::class)->forFixture($this->fixture)['local'];

    expect($local['confirmed_source'])->toBe('worldcup26')
        ->and($local['players'][0]['probability'])->toBeNull()
        ->and($local['players'][0]['predicted_starter'])->toBeFalse()
        ->and($local['players'][0]['confirmed_starter'])->toBeTrue();
});

test('a worldcup26 lineup with no starters does not count as a confirmed lineup', function (): void {
    $vinicius = startPlayer($this->madrid, 'Vini Jr.');
    startRow($vinicius, $this->fixture, ['probability' => 60]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => Player::factory()->create(['team_id' => $this->madrid->id])->id, 'starter' => false]);

    $local = app(StartProbabilities::class)->forFixture($this->fixture)['local'];
    $byPlayer = collect($local['players'])->keyBy(fn (array $entry): int => $entry['player']->id);

    expect($local['confirmed_source'])->toBeNull()
        ->and($byPlayer[$vinicius->id]['confirmed_starter'])->toBeNull();
});

test('a worldcup26 lineup with no starters does not count as confirmed for a player\'s next start', function (): void {
    $vinicius = startPlayer($this->madrid, 'Vini Jr.');
    startRow($vinicius, $this->fixture, ['probability' => 60, 'confirmed_starter' => false]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => $vinicius->id, 'starter' => false]);

    $nextStarts = app(StartProbabilities::class)->forPlayersNextFixture(Player::query()->whereKey($vinicius->id)->with('team')->get(), $this->season);

    expect($nextStarts[$vinicius->id]['confirmed_source'])->toBe('futbolfantasy')
        ->and($nextStarts[$vinicius->id]['confirmed_starter'])->toBeFalse();
});

test('ignores a scheduled fixture whose date has already passed', function (): void {
    $this->fixture->update(['date' => now()->subHour()]);
    startRow(startPlayer($this->villarreal, 'Parejo'), $this->fixture, ['probability' => 80]);

    expect(app(StartProbabilities::class)->forTeamNextFixture($this->villarreal, $this->season))->toBeNull()
        ->and(app(StartProbabilities::class)->forPlayersNextFixture(
            Player::query()->where('team_id', $this->villarreal->id)->with('team')->get(),
            $this->season,
        ))->toBe([]);
});

test('gives a team the block of its next match with the opponent', function (): void {
    startRow(startPlayer($this->villarreal, 'Parejo'), $this->fixture, ['probability' => 80]);

    $block = app(StartProbabilities::class)->forTeamNextFixture($this->villarreal, $this->season);

    expect($block['fixture_id'])->toBe($this->fixture->id)
        ->and($block['opponent']->id)->toBe($this->madrid->id)
        ->and($block['is_home'])->toBeFalse()
        ->and($block['players'][0]['probability'])->toBe(80)
        ->and(app(StartProbabilities::class)->forTeamNextFixture($this->madrid, $this->season))->toBeNull();
});

test('gives each player the start of his team\'s next match', function (): void {
    $courtois = startPlayer($this->madrid, 'Courtois');
    $unlisted = startPlayer($this->madrid, 'Sin dato');
    $gone = Player::factory()->create(['team_id' => $this->madrid->id, 'status' => PlayerStatus::OutOfLeague]);
    startRow($courtois, $this->fixture, ['probability' => 95, 'fetched_at' => now()->subDays(3)]);
    startRow($gone, $this->fixture);

    $players = Player::query()->whereIn('id', [$courtois->id, $unlisted->id, $gone->id])->with('team')->get();
    $nextStarts = app(StartProbabilities::class)->forPlayersNextFixture($players, $this->season);

    expect($nextStarts)->toHaveKey($courtois->id)
        ->and($nextStarts)->not->toHaveKey($unlisted->id)
        ->and($nextStarts)->not->toHaveKey($gone->id)
        ->and($nextStarts[$courtois->id]['week_number'])->toBe(8)
        ->and($nextStarts[$courtois->id]['probability'])->toBe(95)
        ->and($nextStarts[$courtois->id]['is_stale'])->toBeTrue()
        ->and($nextStarts[$courtois->id]['confirmed_source'])->toBeNull()
        ->and($nextStarts[$courtois->id]['team_short_name'])->toBe('RMA')
        ->and($nextStarts[$courtois->id]['source_url'])->toBe('https://www.futbolfantasy.com/laliga/equipos/real-madrid');
});

test('a player\'s next start follows the worldcup26 lineup once there is one', function (): void {
    $courtois = startPlayer($this->madrid, 'Courtois');
    $lunin = startPlayer($this->madrid, 'Lunin');
    startRow($courtois, $this->fixture, ['probability' => 95]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => $lunin->id, 'starter' => true]);

    $players = Player::query()->whereIn('id', [$courtois->id, $lunin->id])->with('team')->get();
    $nextStarts = app(StartProbabilities::class)->forPlayersNextFixture($players, $this->season);

    expect($nextStarts[$courtois->id]['confirmed_source'])->toBe('worldcup26')
        ->and($nextStarts[$courtois->id]['confirmed_starter'])->toBeFalse()
        ->and($nextStarts[$courtois->id]['probability'])->toBe(95)
        ->and($nextStarts[$lunin->id]['confirmed_starter'])->toBeTrue()
        ->and($nextStarts[$lunin->id]['probability'])->toBeNull();
});

test('reads the probable XI\'s formation and each starter\'s position off where FútbolFantasy draws him', function (): void {
    $spots = [
        'Courtois' => [50, 87], 'Dumfries' => [89, 66], 'Konaté' => [68, 70], 'Rüdiger' => [32, 70], 'Cucurella' => [11, 66],
        'Bernardo' => [68, 48], 'Tchouaméni' => [32, 48], 'Bellingham' => [50, 34],
        'Güler' => [89, 27], 'Mbappé' => [50, 18], 'Vinicius' => [11, 27],
    ];
    $players = [];

    foreach ($spots as $nickname => [$pitchX, $pitchY]) {
        $players[$nickname] = startPlayer($this->madrid, $nickname);
        FixtureLineupProbability::factory()->onPitch($pitchX, $pitchY)->create(['player_id' => $players[$nickname]->id, 'fixture_id' => $this->fixture->id]);
    }

    $endrick = startPlayer($this->madrid, 'Endrick');
    startRow($endrick, $this->fixture, ['probability' => 10, 'predicted_starter' => false]);

    $local = app(StartProbabilities::class)->forFixture($this->fixture)['local'];
    $byPlayer = collect($local['players'])->keyBy(fn (array $entry): int => $entry['player']->id);

    expect($local['formation'])->toBe('4-3-3')
        ->and($byPlayer[$players['Courtois']->id]['pitch_position'])->toBe('Goalkeeper')
        ->and($byPlayer[$players['Dumfries']->id]['pitch_position'])->toBe('Right Back')
        ->and($byPlayer[$players['Bellingham']->id]['pitch_position'])->toBe('Attacking Midfielder')
        ->and($byPlayer[$players['Vinicius']->id]['pitch_position'])->toBe('Left Forward')
        ->and($byPlayer[$endrick->id]['pitch_position'])->toBeNull()
        ->and(app(StartProbabilities::class)->forTeamNextFixture($this->madrid, $this->season)['formation'])->toBe('4-3-3');
});

test('gives no formation or positions when FútbolFantasy drew no pitch', function (): void {
    startRow(startPlayer($this->madrid, 'Courtois'), $this->fixture);

    $local = app(StartProbabilities::class)->forFixture($this->fixture)['local'];

    expect($local['formation'])->toBeNull()
        ->and($local['players'][0]['pitch_position'])->toBeNull();
});

test('takes the formation and positions of the worldcup26 lineup once it confirms one', function (): void {
    $this->fixture->update(['local_formation' => '4-2-3-1']);
    $courtois = startPlayer($this->madrid, 'Courtois');
    $gonzalo = startPlayer($this->madrid, 'Gonzalo');
    FixtureLineupProbability::factory()->onPitch(50, 87)->create(['player_id' => $courtois->id, 'fixture_id' => $this->fixture->id]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => $courtois->id, 'starter' => true, 'position' => 'Goalkeeper']);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => $gonzalo->id, 'starter' => true, 'position' => 'Forward']);

    $local = app(StartProbabilities::class)->forFixture($this->fixture)['local'];
    $byPlayer = collect($local['players'])->keyBy(fn (array $entry): int => $entry['player']->id);

    expect($local['formation'])->toBe('4-2-3-1')
        ->and($byPlayer[$courtois->id]['pitch_position'])->toBe('Goalkeeper')
        ->and($byPlayer[$gonzalo->id]['pitch_position'])->toBe('Forward');
});
