<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Services\BuyoutClausePresenter;

test('a clause locked until the future is locked, with ISO dates', function (): void {
    $lockedUntil = now()->addDays(3)->startOfSecond();
    $entry = ManagerPlayer::factory()->create([
        'buyout_clause' => 12_500_000,
        'buyout_clause_locked_until' => $lockedUntil,
        'shielded' => false,
        'shielded_until' => null,
    ]);

    expect(BuyoutClausePresenter::clause($entry))->toBe([
        'amount' => 12_500_000,
        'locked_until' => $lockedUntil->toIso8601String(),
        'is_locked' => true,
        'shielded' => false,
        'shielded_until' => null,
    ]);
});

test('a clause whose lock is in the past is open, and a shield keeps its end date', function (): void {
    $shieldedUntil = now()->addDay()->startOfSecond();
    $entry = ManagerPlayer::factory()->create([
        'buyout_clause_locked_until' => now()->subMinute(),
        'shielded' => true,
        'shielded_until' => $shieldedUntil,
    ]);

    $clause = BuyoutClausePresenter::clause($entry);

    expect($clause['is_locked'])->toBeFalse()
        ->and($clause['shielded'])->toBeTrue()
        ->and($clause['shielded_until'])->toBe($shieldedUntil->toIso8601String());
});

test('the purchase is the signing or buyout amount, type and date, or null without one', function (): void {
    $occurredAt = now()->subWeek()->startOfSecond();
    $activity = Activity::factory()->create([
        'type' => SeasonActivityType::Buyout,
        'amount' => 9_000_000,
        'occurred_at' => $occurredAt,
    ]);

    expect(BuyoutClausePresenter::purchase($activity))->toBe([
        'amount' => 9_000_000,
        'type' => 'buyout',
        'occurred_at' => $occurredAt->toIso8601String(),
    ])->and(BuyoutClausePresenter::purchase(null))->toBeNull();
});
