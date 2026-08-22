<?php

declare(strict_types=1);

namespace App\Http\Filters;

use BackedEnum;

abstract class BaseRequestFilter
{
    /**
     * @return int[]
     */
    protected function parseIntList(?string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item): int => (int) $item,
            $this->parseStringList($value),
        )));
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enumClass
     * @return T[]
     */
    protected function parseEnumList(string $enumClass, ?string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $item) => $enumClass::tryFrom($item),
            $this->parseStringList($value),
        )));
    }

    /**
     * @return string[]
     */
    private function parseStringList(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
