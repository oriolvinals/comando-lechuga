<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class PlayersController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('players/index');
    }
}
