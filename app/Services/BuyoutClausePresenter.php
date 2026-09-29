<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\ManagerPlayer;

/**
 * The one public shape of a buyout clause and of the purchase behind it,
 * shared by the manager API roster and the player comparator.
 */
final class BuyoutClausePresenter
{
    /**
     * @return array{amount: int, locked_until: string, is_locked: bool, shielded: bool, shielded_until: string|null}
     */
    public static function clause(ManagerPlayer $entry): array
    {
        return [
            'amount' => $entry->buyout_clause,
            'locked_until' => $entry->buyout_clause_locked_until->toIso8601String(),
            'is_locked' => $entry->buyout_clause_locked_until->isFuture(),
            'shielded' => $entry->shielded,
            'shielded_until' => $entry->shielded_until?->toIso8601String(),
        ];
    }

    /**
     * @return array{amount: int, type: string, occurred_at: string}|null
     */
    public static function purchase(?Activity $purchase): ?array
    {
        if (!$purchase instanceof Activity || $purchase->amount === null) {
            return null;
        }

        return [
            'amount' => (int) $purchase->amount,
            'type' => $purchase->type->value,
            'occurred_at' => $purchase->occurred_at->toIso8601String(),
        ];
    }
}
