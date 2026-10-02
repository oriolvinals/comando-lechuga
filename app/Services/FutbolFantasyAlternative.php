<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A player FútbolFantasy lists under a probable starter as one who could
 * start instead: `a.juggador.pos-N` (N ≥ 1) of the shirt's `div.juggadores`.
 * FF gives only his name and the link to his page.
 */
final readonly class FutbolFantasyAlternative
{
    /**
     * @param  int  $position  N of `pos-N`: 1 for the first alternative, 2 for the next…
     * @param  string  $name  FF's short name (`.truncate-name`)
     * @param  string  $slug  FF's player slug (`/jugadores/{slug}/…`), '' if none
     */
    public function __construct(
        public int $position,
        public string $name,
        public string $slug,
    ) {}
}
