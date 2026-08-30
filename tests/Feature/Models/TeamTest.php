<?php

use App\Models\Team;
use Illuminate\Database\QueryException;

test('match_data_id cannot be null', function (): void {
    Team::factory()->create(['match_data_id' => null]);
})->throws(QueryException::class);
