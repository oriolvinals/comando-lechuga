<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A manager's cash as a range.
 * - Certain: activity and the share of the daily bonus managers claim.
 * - Pessimistic end: also pays every inferred clause raise at half its amount.
 * - Optimistic end: pays only the certain raises.
 * The connected account has its real cash instead, and its residual (real
 * minus that model) is kept for diagnosis only: it is never applied to
 * anyone. PRIVATE: web /radar only.
 */
final readonly class ManagerBalance
{
    public function __construct(
        public int $seasonManagerId,
        public int $activity,
        public int $dailyBonus,
        public int $sureRaises,
        public int $possibleRaises,
        public ?int $real,
        public int $squadValue,
        public ?int $residual = null,
    ) {}

    public function isReal(): bool
    {
        return $this->real !== null;
    }

    public function low(): int
    {
        return $this->real ?? $this->base() - intdiv($this->sureRaises + $this->possibleRaises, 2);
    }

    public function high(): int
    {
        return $this->real ?? $this->base() - intdiv($this->sureRaises, 2);
    }

    public function mid(): int
    {
        return intdiv($this->low() + $this->high(), 2);
    }

    /**
     * @return array{cash: array{low: int, high: int, mid: int, is_real: bool}, squad_value: int, total: array{low: int, high: int, mid: int}}
     */
    public function toArray(): array
    {
        return [
            'cash' => ['low' => $this->low(), 'high' => $this->high(), 'mid' => $this->mid(), 'is_real' => $this->isReal()],
            'squad_value' => $this->squadValue,
            'total' => [
                'low' => $this->low() + $this->squadValue,
                'high' => $this->high() + $this->squadValue,
                'mid' => $this->mid() + $this->squadValue,
            ],
        ];
    }

    private function base(): int
    {
        return $this->activity + $this->dailyBonus;
    }
}
