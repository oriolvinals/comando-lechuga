<?php

declare(strict_types=1);

namespace App\Services\Prizes;

/**
 * One manager's standing in one prize. `value` is null when the prize does
 * not apply to him (e.g. he never owned the MostOwnedPlayer player);
 * `context` carries the prize-specific detail (a jornada, a player id…).
 */
final readonly class PrizeRow
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $seasonManagerId,
        public int|float|null $value,
        public array $context = [],
    ) {}
}
