<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait ValidatesApiQuery
{
    /**
     * Fails with a 422 naming the parameter when the query string has a
     * parameter this endpoint doesn't know, or a value its rules reject.
     * The public API never silently ignores a filter, so an AI advisor
     * learns its request was wrong instead of reading unfiltered data.
     * `page` is always allowed (a positive integer). Empty values count as
     * absent (ConvertEmptyStringsToNull + `nullable`).
     *
     * @param  array<string, list<mixed>>  $rules
     *
     * @throws ValidationException
     */
    private function validateApiQuery(Request $request, array $rules): void
    {
        $rules['page'] = ['sometimes', 'nullable', 'integer', 'min:1'];
        $query = $request->query->all();

        $unknown = array_values(array_diff(array_map(strval(...), array_keys($query)), array_keys($rules)));

        if ($unknown !== []) {
            $messages = [];

            foreach ($unknown as $parameter) {
                $messages[$parameter] = ["Parámetro desconocido: {$parameter}. Parámetros válidos: ".implode(', ', array_keys($rules)).'.'];
            }

            throw ValidationException::withMessages($messages);
        }

        Validator::make($query, $rules)->validate();
    }

    /**
     * A comma-separated list whose every item is one of `$allowed`. Spaces
     * and empty items are ignored.
     *
     * @param  list<string>  $allowed
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function commaSeparatedIn(array $allowed): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
            $invalid = array_values(array_diff($this->commaSeparatedItems($value), $allowed));

            if (!is_string($value) || $invalid !== []) {
                $fail("{$attribute}: valor no válido (".implode(', ', $invalid).'). Valores permitidos: '.implode(', ', $allowed).'.');
            }
        };
    }

    /**
     * A comma-separated list of positive integer ids. Spaces and empty items
     * are ignored.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function commaSeparatedIds(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $invalid = array_values(array_filter(
                $this->commaSeparatedItems($value),
                fn (string $item): bool => !ctype_digit($item) || (int) $item === 0,
            ));

            if (!is_string($value) || $invalid !== []) {
                $fail("{$attribute}: se esperaban IDs numéricos separados por comas (no válidos: ".implode(', ', $invalid).').');
            }
        };
    }

    /**
     * @return list<string>
     */
    private function commaSeparatedItems(mixed $value): array
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
