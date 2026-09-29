<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Services\DaznEstimatePresenter;

/**
 * @param  array<string, mixed>  $lineup
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function presentDazn(array $lineup = [], array $fixture = []): array
{
    return DaznEstimatePresenter::present(
        new FixtureLineup(['fantasy_stats' => null, 'dazn_estimate' => null, 'dazn_estimate_version' => '', 'dazn_estimate_meta' => null, ...$lineup]),
        new Fixture(['state' => FixtureState::SecondHalf, 'dazn_published' => false, ...$fixture]),
    );
}

$estimated = fn (int $minutes): array => ['dazn_estimate' => 2, 'dazn_estimate_version' => 'v1', 'dazn_estimate_meta' => ['source' => 'fantasy', 'minutes' => $minutes, 'reasons' => ["{$minutes} minutos jugados"]]];

test('shows a provisional estimate as soon as it exists', function () use ($estimated): void {
    expect(presentDazn($estimated(15)))->toBe([
        'dazn_points' => null,
        'dazn_estimate' => 2,
        'dazn_estimate_version' => 'v1',
        'dazn_estimate_reasons' => ['15 minutos jugados'],
        'dazn_estimate_source' => 'fantasy',
    ]);
});

test('shows a live estimate under what used to be the 15-minute threshold', function () use ($estimated): void {
    expect(presentDazn($estimated(1))['dazn_estimate'])->toBe(2)
        ->and(presentDazn($estimated(1))['dazn_estimate_reasons'])->toBe(['1 minutos jugados']);
});

test('shows any estimate once the match is finished and still unpublished', function () use ($estimated): void {
    $presented = presentDazn($estimated(4), ['state' => FixtureState::Finished]);

    expect($presented['dazn_estimate'])->toBe(2)
        ->and($presented['dazn_points'])->toBeNull();
});

test('shows the official rating and the frozen estimate once published, without reasons', function () use ($estimated): void {
    $presented = presentDazn([...$estimated(90), 'fantasy_stats' => ['marca_points' => [-1, 3]]], ['state' => FixtureState::Finished, 'dazn_published' => true]);

    expect($presented)->toBe([
        'dazn_points' => 3,
        'dazn_estimate' => 2,
        'dazn_estimate_version' => 'v1',
        'dazn_estimate_reasons' => [],
        'dazn_estimate_source' => null,
    ]);
});

test('hides the frozen estimate once published if the player has no official rating', function () use ($estimated): void {
    $presented = presentDazn($estimated(90), ['state' => FixtureState::Finished, 'dazn_published' => true]);

    expect($presented)->toBe([
        'dazn_points' => null,
        'dazn_estimate' => null,
        'dazn_estimate_version' => '',
        'dazn_estimate_reasons' => [],
        'dazn_estimate_source' => null,
    ]);
});

test('treats an official rating of 0 as official and keeps the frozen estimate', function () use ($estimated): void {
    $presented = presentDazn([...$estimated(90), 'fantasy_stats' => ['marca_points' => [-1, 0]]], ['state' => FixtureState::Finished, 'dazn_published' => true]);

    expect($presented)->toBe([
        'dazn_points' => 0,
        'dazn_estimate' => 2,
        'dazn_estimate_version' => 'v1',
        'dazn_estimate_reasons' => [],
        'dazn_estimate_source' => null,
    ]);
});

test('an unused substitute has neither an official rating nor an estimate', function (): void {
    expect(presentDazn([], ['state' => FixtureState::Finished, 'dazn_published' => true]))->toBe([
        'dazn_points' => null,
        'dazn_estimate' => null,
        'dazn_estimate_version' => '',
        'dazn_estimate_reasons' => [],
        'dazn_estimate_source' => null,
    ]);
});
