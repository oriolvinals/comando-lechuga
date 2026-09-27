<?php

declare(strict_types=1);

use App\Models\Fixture;
use App\Models\Player;
use App\Models\FixtureLineupProbability;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
});

test('belongs to a player and a fixture and casts its columns', function (): void {
    $row = FixtureLineupProbability::factory()->create([
        'probability' => 70,
        'predicted_starter' => true,
        'confirmed_starter' => null,
    ])->refresh();

    expect($row->player)->toBeInstanceOf(Player::class)
        ->and($row->fixture)->toBeInstanceOf(Fixture::class)
        ->and($row->probability)->toBe(70)
        ->and($row->predicted_starter)->toBeTrue()
        ->and($row->confirmed_starter)->toBeNull()
        ->and($row->fetched_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('a probability can be missing and a lineup confirmed without one', function (): void {
    $row = FixtureLineupProbability::factory()->create([
        'probability' => null,
        'confirmed_starter' => false,
    ])->refresh();

    expect($row->probability)->toBeNull()
        ->and($row->predicted_starter)->toBeFalse()
        ->and($row->confirmed_starter)->toBeFalse();
});

test('keeps a single row per player and fixture', function (): void {
    $row = FixtureLineupProbability::factory()->create();

    FixtureLineupProbability::factory()->create([
        'player_id' => $row->player_id,
        'fixture_id' => $row->fixture_id,
    ]);
})->throws(QueryException::class);

test('a player stores its FútbolFantasy id and lists its start probabilities', function (): void {
    $player = Player::factory()->create(['futbolfantasy_id' => 7257]);
    FixtureLineupProbability::factory()->for($player)->create();

    expect($player->refresh()->futbolfantasy_id)->toBe(7257)
        ->and($player->lineupProbabilities)->toHaveCount(1);
});

test('two players cannot share a FútbolFantasy id', function (): void {
    Player::factory()->create(['futbolfantasy_id' => 7257]);
    Player::factory()->create(['futbolfantasy_id' => 7257]);
})->throws(QueryException::class);
