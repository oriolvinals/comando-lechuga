<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PlayersController;
use App\Http\Controllers\SeasonTeamsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/equipos', [SeasonTeamsController::class, 'index'])->name('season-teams.index');
Route::get('/jugadores', [PlayersController::class, 'index'])->name('players.index');
Route::get('/actividad', [ActivityController::class, 'index'])->name('activity.index');
