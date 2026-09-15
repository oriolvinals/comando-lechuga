<?php

use App\Models\Team;
use Illuminate\Database\QueryException;

test('wc26_id cannot be null', function (): void {
    Team::factory()->create(['wc26_id' => null]);
})->throws(QueryException::class);
