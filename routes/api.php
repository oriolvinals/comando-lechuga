<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\FixturesController;
use App\Http\Controllers\Api\ManagerController;
use App\Http\Controllers\Api\PlayersController;
use App\Http\Controllers\Api\StandingsController;
use Illuminate\Support\Facades\Route;

Route::get('standings', [StandingsController::class, 'index'])->name('api.standings');
Route::get('activity', [ActivityController::class, 'index'])->name('api.activity');
Route::get('managers/{seasonManager}', [ManagerController::class, 'show'])->name('api.managers.show');
Route::get('fixtures', [FixturesController::class, 'index'])->name('api.fixtures');
Route::get('fixtures/{fixture}', [FixturesController::class, 'show'])->name('api.fixtures.show');
Route::get('players', [PlayersController::class, 'index'])->name('api.players');
Route::get('players/{player}', [PlayersController::class, 'show'])->name('api.players.show');
