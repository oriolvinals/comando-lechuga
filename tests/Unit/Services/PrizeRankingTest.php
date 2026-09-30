<?php

declare(strict_types=1);

use App\Enums\SeasonPrize;
use App\Services\Prizes\PrizeRanking;
use App\Services\Prizes\PrizeRow;

test('ties share the better place and follow the league order', function (): void {
    $ranked = PrizeRanking::rank([
        new PrizeRow(1, 2),
        new PrizeRow(2, 5),
        new PrizeRow(3, 5),
        new PrizeRow(4, null),
        new PrizeRow(5, 0),
    ], [1 => 1, 2 => 4, 3 => 2, 4 => 3, 5 => 5]);

    expect(array_map(fn (array $entry): array => [$entry['row']->seasonManagerId, $entry['place']], $ranked))
        ->toBe([[3, 1], [2, 1], [1, 3], [5, 4], [4, null]])
        ->and(PrizeRanking::leaders($ranked))->toBe([3, 2]);
});

test('nobody leads while the best value is zero', function (): void {
    $ranked = PrizeRanking::rank([new PrizeRow(1, 0), new PrizeRow(2, 0)], [1 => 1, 2 => 2]);

    expect(PrizeRanking::leaders($ranked))->toBe([]);
});

test('a prize is split evenly between tied leaders', function (): void {
    expect(PrizeRanking::shares(SeasonPrize::ElPupas, [7, 9]))->toBe([7 => 2.5, 9 => 2.5])
        ->and(PrizeRanking::shares(SeasonPrize::ElPupas, []))->toBe([]);
});

test('a prize with a single leader is paid in full as a float', function (): void {
    expect(PrizeRanking::shares(SeasonPrize::ElPupas, [7]))->toBe([7 => 5.0]);
});
