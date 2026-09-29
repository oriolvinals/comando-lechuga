<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One player's estimated DAZN rating for one match, with what it was built from.
 */
final readonly class DaznEstimate
{
    /**
     * @param  'fantasy'|'worldcup26'  $source
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $points,
        public float $raw,
        public string $source,
        public int $minutes,
        public array $reasons,
    ) {}

    /**
     * @return array{source: string, minutes: int, reasons: list<string>}
     */
    public function toMeta(): array
    {
        return ['source' => $this->source, 'minutes' => $this->minutes, 'reasons' => $this->reasons];
    }
}
