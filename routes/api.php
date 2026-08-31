<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\StandingsController;
use Illuminate\Support\Facades\Route;

Route::get('standings', [StandingsController::class, 'index'])->name('api.standings');
Route::get('activity', [ActivityController::class, 'index'])->name('api.activity');
