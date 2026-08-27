<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\FixturesController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PlayersController;
use App\Http\Controllers\SeasonManagersController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/equipos', [SeasonManagersController::class, 'index'])->name('season-managers.index');
Route::get('/equipos/{seasonManager}', [SeasonManagersController::class, 'show'])->name('season-managers.show');
Route::get('/jugadores', [PlayersController::class, 'index'])->name('players.index');
Route::get('/jugadores/{player}', [PlayersController::class, 'show'])->name('players.show');
Route::get('/actividad', [ActivityController::class, 'index'])->name('activity.index');
Route::get('/partidos/{fixture}', [FixturesController::class, 'show'])->name('fixtures.show');
