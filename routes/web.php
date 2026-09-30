<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\FixturesController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PlayerComparisonController;
use App\Http\Controllers\PlayerJornadaController;
use App\Http\Controllers\PlayersController;
use App\Http\Controllers\PrizesController;
use App\Http\Controllers\SeasonManagersController;
use App\Http\Controllers\TeamsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/api-docs', [ApiDocsController::class, 'show'])->name('api-docs');
Route::get('/managers', [SeasonManagersController::class, 'index'])->name('season-managers.index');
Route::get('/managers/{seasonManager}', [SeasonManagersController::class, 'show'])->name('season-managers.show');
Route::get('/equipos', [TeamsController::class, 'index'])->name('teams.index');
Route::get('/equipos/{team}', [TeamsController::class, 'show'])->name('teams.show');
Route::get('/jugadores', [PlayersController::class, 'index'])->name('players.index');
Route::get('/jugadores/comparar', [PlayerComparisonController::class, 'show'])->name('players.compare');
Route::get('/jugadores/{player}', [PlayersController::class, 'show'])->whereNumber('player')->name('players.show');
Route::get('/jugadores/{player}/jornadas/{fixture}', PlayerJornadaController::class)->whereNumber(['player', 'fixture'])->name('players.jornada');
Route::get('/actividad', [ActivityController::class, 'index'])->name('activity.index');
Route::get('/premios', [PrizesController::class, 'index'])->name('prizes.index');
Route::get('/partidos', [FixturesController::class, 'index'])->name('fixtures.index');
Route::get('/partidos/{fixture}', [FixturesController::class, 'show'])->name('fixtures.show');
