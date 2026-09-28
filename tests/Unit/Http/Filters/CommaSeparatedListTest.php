<?php

declare(strict_types=1);

use App\Http\Filters\CommaSeparatedList;

test('splits a comma-separated value into trimmed, non-empty items', function (mixed $value, array $items): void {
    expect(CommaSeparatedList::items($value))->toBe($items);
})->with([
    'list' => ['defender, striker', ['defender', 'striker']],
    'empty items' => [',defender,, ,', ['defender']],
    'zero kept' => ['0,3', ['0', '3']],
    'empty string' => ['', []],
    'not a string' => [['defender'], []],
    'null' => [null, []],
]);
