<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Services\ResultsTicker;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Inertia\OnceProp;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'season' => Season::current(),
            'liveMatchday' => Fixture::query()
                ->whereIn('state', [
                    FixtureState::FirstHalf,
                    FixtureState::HalfTime,
                    FixtureState::SecondHalf,
                ])
                ->exists(),
            'godMode' => HandleGodMode::isEnabled($request),
        ];
    }

    /**
     * Define the props that are shared once and remembered across navigations.
     *
     * The shell's teletipo is remembered for a minute, so browsing between
     * pages doesn't recompute it on every visit.
     *
     * @return array<string, callable|OnceProp>
     */
    public function shareOnce(Request $request): array
    {
        return [
            ...parent::shareOnce($request),
            'ticker' => Inertia::once(fn (ResultsTicker $ticker): array => $ticker->forSeason(Season::current()))
                ->until(60),
        ];
    }
}
