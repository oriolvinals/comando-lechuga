<?php

declare(strict_types=1);

namespace App\Http\Filters;

/**
 * Parses a comma-separated query value such as `?position=defender,striker`,
 * shared by the request filters and the public API's query validation.
 */
final class CommaSeparatedList
{
    /**
     * The trimmed, non-empty items of a comma-separated string. Anything
     * other than a string has no items.
     *
     * @return list<string>
     */
    public static function items(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            fn (string $item): bool => $item !== '',
        ));
    }
}
