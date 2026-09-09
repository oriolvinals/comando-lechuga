# Ficha de equipo real y listado de equipos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `GET /equipos` (real LaLiga standings table) and `GET /equipos/{team}` (a club's ficha: sidebar with real standing + next fixtures, a jornada-selectable vertical pitch with the real starting XI, the full fantasy squad grouped by position, and a played/upcoming fixture calendar strip).

**Architecture:** New `TeamsController` (index + show), two new Inertia pages (`resources/js/pages/teams/{index,show}.tsx`), and a handful of new/extracted frontend components. Heavily reuses existing patterns: `HqLineupPitch` + `HqWeekScrollPicker` + `HqPlayerStatsModal` (already used by `season-managers/show.tsx` for a client-side, no-round-trip week picker), the `players/index.tsx` row design (extracted into a shared `PlayerRow` component), and the existing `Attaches*` controller concerns.

**Tech Stack:** Laravel 12 (PHP, Pest tests), Inertia + React + TypeScript, Tailwind, Laravel Wayfinder (auto-generates `resources/js/routes/*.ts` from PHP routes — run `php artisan wayfinder:generate` after adding/changing routes, before any frontend task that imports from `@/routes/teams`).

**Spec:** `docs/superpowers/specs/2026-09-09-team-ficha-design.md`

## Global Constraints

- `{team}` route parameter binds by numeric id (Laravel's default implicit binding), not by `slug` — consistent with `{player}` and `{seasonManager}` elsewhere in this app.
- No new database migrations — everything is derived from existing tables (`teams`, `fixtures`, `fixture_lineups`, `players`, `player_seasons`, `season_team`).
- No tabs inside the ficha — one scrolling column, matching `players/show.tsx`'s sidebar + main-column skeleton.
- The vertical pitch does **not** attempt to parse `Fixture.local_formation`/`guest_formation` into a `[def, mid, fwd]` count array — that string comes straight from the worldcup26 API in an unverified/unstructured format (unlike `ManagerLineup.tactical_formation`, which is a real stored `int[]` column for a *different* feature). Pass `tacticalFormation={null}` to `HqLineupPitch`; it already renders correctly with no formation label and no empty placeholder slots.
- Every new/edited TypeScript file must pass `npx tsc --noEmit` and `npx eslint <file>` before being considered done. Every new/edited PHP file must pass the relevant Pest test file. Do not start `npm run dev` (or leave it running) for verification — this project's convention is to verify with `tsc`/`eslint`/Pest, not a live dev server, unless a human explicitly asks for a browser check.
- After adding or changing any PHP route, run `php artisan wayfinder:generate` before relying on the corresponding `resources/js/routes/*.ts` helpers in frontend code — those files are generated, never hand-edited.
- Follow existing formatting/commit conventions: Prettier/ESLint defaults already configured in this repo, Pest `test()` style (not PHPUnit classes), one focused commit per task.

---

## Task 1: Real standings computation + `/equipos` listing page

**Files:**
- Create: `app/Http/Controllers/TeamsController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/components/main-nav.tsx`
- Modify: `resources/js/types/models.ts` (append `StandingsRow`)
- Create: `resources/js/pages/teams/index.tsx`
- Create: `resources/js/pages/teams/show.tsx` (minimal placeholder — identity only; fleshed out in Tasks 3–5)
- Test: `tests/Feature/Http/Controllers/TeamsControllerTest.php`

**Interfaces:**
- Produces: `TeamsController::standingsFor(Collection<int, Team> $teams, Collection<int, Fixture> $fixtures): list<array{position: int, team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int}>` — a private method, reused by Task 3's `show()`.
- Produces: routes `teams.index` (`GET /equipos`) and `teams.show` (`GET /equipos/{team}`), and their generated Wayfinder helpers `index`/`show` in `resources/js/routes/teams.ts`.
- Produces: TypeScript `StandingsRow` interface in `resources/js/types/models.ts`, consumed by both `teams/index.tsx` and (from Task 3) `teams/show.tsx`.

- [ ] **Step 1: Write the failing standings-ordering test**

Create `tests/Feature/Http/Controllers/TeamsControllerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

test('orders standings by points then goal difference', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $alpha = Team::factory()->create(['main_name' => 'Alpha FC']);
    $beta = Team::factory()->create(['main_name' => 'Beta FC']);
    $gamma = Team::factory()->create(['main_name' => 'Gamma FC']);
    $season->teams()->attach([$alpha->id, $beta->id, $gamma->id]);

    // Alpha beats Gamma 3-0: Alpha 3pts/+3GD, Gamma 0pts/-3GD.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $alpha->id,
        'team_guest_id' => $gamma->id,
        'local_score' => 3,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    // Beta beats Gamma 1-0: Beta 3pts/+1GD, Gamma another 0pts/-1GD (cumulative -4GD).
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $beta->id,
        'team_guest_id' => $gamma->id,
        'local_score' => 1,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    // A scheduled (not finished) fixture must not affect the table at all.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'team_local_id' => $gamma->id,
        'team_guest_id' => $alpha->id,
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $alpha->id) // 3pts, +3 GD
        ->where('standings.0.position', 1)
        ->where('standings.1.team.id', $beta->id) // 3pts, +1 GD — same points, worse GD
        ->where('standings.1.position', 2)
        ->where('standings.2.team.id', $gamma->id) // 0pts
        ->where('standings.2.position', 3)
        ->where('standings.2.played', 2)
        ->where('standings.2.goal_difference', -4)
    );
});

test('a team with no finished fixtures appears with every stat zeroed', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $team->id)
        ->where('standings.0.played', 0)
        ->where('standings.0.won', 0)
        ->where('standings.0.drawn', 0)
        ->where('standings.0.lost', 0)
        ->where('standings.0.goals_for', 0)
        ->where('standings.0.goals_against', 0)
        ->where('standings.0.goal_difference', 0)
        ->where('standings.0.points', 0)
    );
});

test('shows a team by id', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create(['main_name' => 'Rayo Vallecano']);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('team.id', $team->id)
        ->where('team.main_name', 'Rayo Vallecano')
    );
});

test('returns 404 for an unknown team', function (): void {
    $response = $this->get('/equipos/999999');

    $response->assertNotFound();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Http/Controllers/TeamsControllerTest.php`
Expected: FAIL — route `teams.index`/`teams.show` don't exist yet (`RouteNotFoundException` or similar).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/TeamsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TeamsController extends Controller
{
    public function index(): Response
    {
        $season = Season::current();
        $teams = $season->teams;
        $fixtures = $this->finishedFixtures($season);

        return Inertia::render('teams/index', [
            'standings' => $this->standingsFor($teams, $fixtures),
        ]);
    }

    public function show(Team $team): Response
    {
        return Inertia::render('teams/show', [
            'team' => $team,
        ]);
    }

    /**
     * @return Collection<int, Fixture>
     */
    private function finishedFixtures(Season $season): Collection
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->get(['team_local_id', 'team_guest_id', 'local_score', 'guest_score']);
    }

    /**
     * Real LaLiga standings computed from finished fixtures — points desc,
     * goal difference desc, goals for desc, then team name asc as a stable
     * final tiebreak (no head-to-head rule; good enough for display).
     *
     * @param  Collection<int, Team>  $teams
     * @param  Collection<int, Fixture>  $fixtures
     * @return list<array{position: int, team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int}>
     */
    private function standingsFor(Collection $teams, Collection $fixtures): array
    {
        $rows = $teams->map(function (Team $team) use ($fixtures): array {
            $played = 0;
            $won = 0;
            $drawn = 0;
            $lost = 0;
            $goalsFor = 0;
            $goalsAgainst = 0;

            foreach ($fixtures as $fixture) {
                $isLocal = $fixture->team_local_id === $team->id;
                $isGuest = $fixture->team_guest_id === $team->id;

                if (!$isLocal && !$isGuest) {
                    continue;
                }

                $played++;
                $for = ($isLocal ? $fixture->local_score : $fixture->guest_score) ?? 0;
                $against = ($isLocal ? $fixture->guest_score : $fixture->local_score) ?? 0;
                $goalsFor += $for;
                $goalsAgainst += $against;

                if ($for > $against) {
                    $won++;
                } elseif ($for === $against) {
                    $drawn++;
                } else {
                    $lost++;
                }
            }

            return [
                'team' => $team,
                'played' => $played,
                'won' => $won,
                'drawn' => $drawn,
                'lost' => $lost,
                'goals_for' => $goalsFor,
                'goals_against' => $goalsAgainst,
                'goal_difference' => $goalsFor - $goalsAgainst,
                'points' => $won * 3 + $drawn,
            ];
        });

        return $rows
            ->sort(fn (array $a, array $b): int => $b['points'] <=> $a['points']
                ?: $b['goal_difference'] <=> $a['goal_difference']
                ?: $b['goals_for'] <=> $a['goals_for']
                ?: $a['team']->main_name <=> $b['team']->main_name)
            ->values()
            ->map(fn (array $row, int $index): array => ['position' => $index + 1, ...$row])
            ->all();
    }
}
```

- [ ] **Step 4: Register the routes**

Modify `routes/web.php` — add the `TeamsController` import and the two routes (placed after the managers routes, before the players routes, to match the intended nav order):

```php
use App\Http\Controllers\TeamsController;
```

```php
Route::get('/equipos', [TeamsController::class, 'index'])->name('teams.index');
Route::get('/equipos/{team}', [TeamsController::class, 'show'])->name('teams.show');
```

- [ ] **Step 5: Generate the Wayfinder route helpers**

Run: `php artisan wayfinder:generate`
Expected: creates/updates `resources/js/routes/teams.ts` exporting `index` and `show`.

- [ ] **Step 6: Add `StandingsRow` to the shared TypeScript types**

Modify `resources/js/types/models.ts` — append at the end of the file:

```typescript
export interface StandingsRow {
    position: number;
    team: Team;
    played: number;
    won: number;
    drawn: number;
    lost: number;
    goals_for: number;
    goals_against: number;
    goal_difference: number;
    points: number;
}
```

- [ ] **Step 7: Create the standings listing page**

Create `resources/js/pages/teams/index.tsx`:

```tsx
import { Head, Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';
import { show as teamsShow } from '@/routes/teams';
import type { StandingsRow } from '@/types/models';

interface TeamsIndexProps {
    standings: StandingsRow[];
    [key: string]: unknown;
}

export default function TeamsIndex({ standings }: TeamsIndexProps) {
    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto max-w-5xl px-6 py-9">
                <Head title="Equipos" />

                <h1 className="mb-6 font-display text-3xl text-hq-paper uppercase">
                    Equipos
                </h1>

                <div className="hq-card-cut overflow-x-auto">
                    <table className="w-full min-w-[560px] border-collapse font-mono text-[12px]">
                        <thead>
                            <tr className="border-b border-hq-border text-left text-[10px] text-hq-moss-dim uppercase">
                                <th className="px-3 py-2 text-center">#</th>
                                <th className="px-3 py-2">Equipo</th>
                                <th className="px-2 py-2 text-center">PJ</th>
                                <th className="px-2 py-2 text-center">PG</th>
                                <th className="px-2 py-2 text-center">PE</th>
                                <th className="px-2 py-2 text-center">PP</th>
                                <th className="px-2 py-2 text-center">
                                    GF-GC
                                </th>
                                <th className="px-2 py-2 text-center">DG</th>
                                <th className="px-3 py-2 text-center">Pts</th>
                            </tr>
                        </thead>
                        <tbody>
                            {standings.map((row) => (
                                <tr
                                    key={row.team.id}
                                    className="border-b border-hq-ink last:border-b-0"
                                >
                                    <td className="px-3 py-2 text-center text-hq-moss-dim">
                                        {row.position}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={teamsShow(row.team.id).url}
                                            className="flex items-center gap-2 font-bold text-hq-paper hover:text-hq-lime"
                                        >
                                            <img
                                                src={row.team.logo}
                                                alt={row.team.main_name}
                                                className="h-5 w-5 object-contain"
                                            />
                                            {row.team.main_name}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.played}
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.won}
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.drawn}
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.lost}
                                    </td>
                                    <td className="px-2 py-2 text-center text-hq-moss">
                                        {row.goals_for}-{row.goals_against}
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.goal_difference > 0 ? '+' : ''}
                                        {row.goal_difference}
                                    </td>
                                    <td className="px-3 py-2 text-center font-bold text-hq-lime">
                                        {row.points}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

TeamsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
```

- [ ] **Step 8: Create the minimal team ficha page**

Create `resources/js/pages/teams/show.tsx` (Tasks 3–5 rewrite the body of this file; the shape of `TeamShowProps` and the exported default function stay the same):

```tsx
import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';
import type { Team } from '@/types/models';

interface TeamShowProps {
    team: Team;
    [key: string]: unknown;
}

export default function TeamShow({ team }: TeamShowProps) {
    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto max-w-7xl px-6 py-9">
                <Head title={team.main_name} />

                <div className="flex items-center gap-3">
                    <img
                        src={team.logo}
                        alt={team.main_name}
                        className="h-12 w-12 object-contain"
                    />
                    <h1 className="font-display text-3xl text-hq-paper uppercase">
                        {team.main_name}
                    </h1>
                </div>
            </div>
        </div>
    );
}

TeamShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
```

- [ ] **Step 9: Add the "Equipos" nav entry**

Modify `resources/js/components/main-nav.tsx`:

```diff
 import { Link, usePage } from '@inertiajs/react';
 import { Menu, X } from 'lucide-react';
 import { useState } from 'react';
 import { cn } from '@/lib/utils';
 import { index as activityIndex } from '@/routes/activity';
 import { index as fixturesIndex } from '@/routes/fixtures';
 import { index as playersIndex } from '@/routes/players';
 import { index as seasonManagersIndex } from '@/routes/season-managers';
+import { index as teamsIndex } from '@/routes/teams';

 const navItems = [
     { label: 'Managers', href: seasonManagersIndex().url },
+    { label: 'Equipos', href: teamsIndex().url },
     { label: 'Jugadores', href: playersIndex().url },
     { label: 'Partidos', href: fixturesIndex().url },
     { label: 'Actividad', href: activityIndex().url },
 ];
```

- [ ] **Step 10: Run the backend tests**

Run: `php artisan test tests/Feature/Http/Controllers/TeamsControllerTest.php`
Expected: PASS (all 4 tests).

- [ ] **Step 11: Type-check and lint the frontend**

Run: `npx tsc --noEmit && npx eslint resources/js/pages/teams/index.tsx resources/js/pages/teams/show.tsx resources/js/components/main-nav.tsx resources/js/types/models.ts`
Expected: no errors.

- [ ] **Step 12: Commit**

```bash
git add app/Http/Controllers/TeamsController.php routes/web.php resources/js/components/main-nav.tsx resources/js/types/models.ts resources/js/pages/teams resources/js/routes/teams.ts tests/Feature/Http/Controllers/TeamsControllerTest.php
git commit -m "feat: add real LaLiga standings listing at /equipos"
```

---

## Task 2: Extract `PlayerRow` into a shared component, link the team crest

**Files:**
- Create: `resources/js/components/hq-player-row.tsx`
- Modify: `resources/js/pages/players/index.tsx`
- Modify: `resources/js/pages/players/show.tsx`

**Interfaces:**
- Consumes: `teams.show` route/Wayfinder helper from Task 1 (`show as teamsShow` from `@/routes/teams`).
- Produces: `PlayerRow` component, exported from `resources/js/components/hq-player-row.tsx`, prop `{ player: Player }` — consumed by `players/index.tsx` (this task) and by `teams/show.tsx`'s squad list (Task 3).

- [ ] **Step 1: Create the shared `PlayerRow` component**

Create `resources/js/components/hq-player-row.tsx` — this is `players/index.tsx`'s current `PlayerRow` function moved verbatim, with one addition: the team crest+short-name block (in both the desktop and mobile layouts) becomes a click target that navigates to the team's ficha, using the exact same "clickable span inside an outer `<Link>`" technique already used a few lines below it for the owner-manager click (a real nested `<a>` isn't valid HTML, so this reuses that established pattern rather than introducing a new one):

```tsx
import { Link, router } from '@inertiajs/react';
import { Shield, User } from 'lucide-react';
import type { MouseEvent as ReactMouseEvent } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { formatCurrency } from '@/lib/format';
import { STATUS_BADGE_CLASS, STATUS_SHORT_LABELS } from '@/lib/player-labels';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import { show as seasonManagersShow } from '@/routes/season-managers';
import { show as teamsShow } from '@/routes/teams';
import type { Player } from '@/types/models';

export function PlayerRow({ player }: { player: Player }) {
    const ownerManager = player.owner_manager;
    const goToOwnerManager = (event: ReactMouseEvent) => {
        if (!ownerManager) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        router.visit(seasonManagersShow(ownerManager.id).url);
    };
    const goToTeam = (event: ReactMouseEvent) => {
        event.preventDefault();
        event.stopPropagation();
        router.visit(teamsShow(player.team.id).url);
    };

    return (
        <Link href={playersShow(player.id).url} className="block">
            {/* Desktop / tablet row */}
            <div className="hq-card-cut mb-1.5 hidden items-center justify-between px-3.5 py-2.5 transition-[filter] hover:brightness-125 xl:flex">
                <div className="flex min-w-0 items-center gap-3">
                    <EntityImage
                        src={player.image}
                        alt={player.nickname}
                        fallback={User}
                        className="h-11 w-11 shrink-0 bg-hq-border"
                    />
                    <div className="w-[190px] shrink-0">
                        <p className="truncate text-sm font-extrabold text-hq-paper">
                            {player.nickname}
                        </p>
                        <span
                            role="link"
                            tabIndex={0}
                            onClick={goToTeam}
                            className="mt-0.5 flex w-fit cursor-pointer items-center gap-1.5 hover:text-hq-paper"
                        >
                            <EntityImage
                                src={player.team.logo}
                                alt={player.team.main_name}
                                fallback={Shield}
                                shape="square"
                                className="h-3.5 w-3.5"
                            />
                            <span className="font-mono text-[10px] text-hq-moss-dim">
                                {player.team.short_name}
                            </span>
                        </span>
                    </div>
                    <div className="w-11 shrink-0 text-center">
                        <HqPositionTag position={player.position} />
                    </div>
                    <div className="w-16 shrink-0">
                        {player.status !== 'ok' && (
                            <span
                                className={cn(
                                    'border px-1.5 py-0.5 font-mono text-[9px] font-bold uppercase',
                                    STATUS_BADGE_CLASS[player.status],
                                )}
                            >
                                {STATUS_SHORT_LABELS[player.status]}
                            </span>
                        )}
                    </div>
                    <div className="flex w-[150px] shrink-0 items-center gap-1.5 font-mono text-[11px] text-hq-moss">
                        {ownerManager ? (
                            <span
                                role="link"
                                tabIndex={0}
                                onClick={goToOwnerManager}
                                className="flex min-w-0 cursor-pointer items-center gap-1.5 hover:text-hq-paper"
                            >
                                <span
                                    className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                                    style={{
                                        backgroundColor: managerColor(
                                            ownerManager.primary_color,
                                        ),
                                    }}
                                />
                                <span className="truncate">
                                    {ownerManager.name}
                                </span>
                            </span>
                        ) : (
                            <span className="text-hq-moss-dim">Libre</span>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-6">
                    <HqNextFixtures fixtures={player.next_fixtures} />
                    <HqRecentScores
                        scores={player.recent_scores}
                        finished={player.recent_scores_finished}
                        opponents={player.recent_scores_opponents}
                        className="w-[130px]"
                    />
                    <div className="w-[130px] shrink-0 text-right">
                        <p className="font-mono text-[13px] font-bold text-hq-paper">
                            {formatCurrency(player.market_value)}
                        </p>
                        {player.market_value_difference !== 0 && (
                            <p
                                className={cn(
                                    'font-mono text-[10px] font-bold',
                                    player.market_value_difference > 0
                                        ? 'text-hq-lime'
                                        : 'text-hq-live',
                                )}
                            >
                                {player.market_value_difference > 0 ? '▲' : '▼'}{' '}
                                {formatCurrency(
                                    Math.abs(player.market_value_difference),
                                )}
                            </p>
                        )}
                    </div>
                    <div className="w-[52px] shrink-0 text-center font-display text-xl text-hq-lime">
                        {player.points}
                    </div>
                </div>
            </div>

            {/* Mobile row */}
            <div className="hq-card-cut mb-2 px-3 py-2.5 transition-[filter] hover:brightness-125 xl:hidden">
                <div className="flex items-center gap-2.5">
                    <EntityImage
                        src={player.image}
                        alt={player.nickname}
                        fallback={User}
                        className="h-9 w-9 shrink-0 bg-hq-border"
                    />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-extrabold text-hq-paper">
                            {player.nickname}
                        </p>
                        <div className="mt-0.5 flex items-center gap-1.5">
                            <span
                                role="link"
                                tabIndex={0}
                                onClick={goToTeam}
                                className="flex w-fit cursor-pointer items-center gap-1.5 hover:text-hq-paper"
                            >
                                <EntityImage
                                    src={player.team.logo}
                                    alt={player.team.main_name}
                                    fallback={Shield}
                                    shape="square"
                                    className="h-[10px] w-[10px]"
                                />
                                <span className="font-mono text-[9px] text-hq-moss-dim">
                                    {player.team.short_name}
                                </span>
                            </span>
                            <HqPositionTag position={player.position} />
                            {player.status !== 'ok' && (
                                <span
                                    className={cn(
                                        'border px-1 py-0.5 font-mono text-[8px] font-bold uppercase',
                                        STATUS_BADGE_CLASS[player.status],
                                    )}
                                >
                                    {STATUS_SHORT_LABELS[player.status]}
                                </span>
                            )}
                        </div>
                    </div>
                    <span className="shrink-0 font-display text-lg text-hq-lime">
                        {player.points}
                    </span>
                </div>
                <div className="mt-2 flex items-center justify-between border-t border-hq-ink pt-2">
                    <p className="font-mono text-[11px] font-bold text-hq-paper">
                        {formatCurrency(player.market_value)}
                        {player.market_value_difference !== 0 && (
                            <span
                                className={cn(
                                    'ml-2 text-[10px]',
                                    player.market_value_difference > 0
                                        ? 'text-hq-lime'
                                        : 'text-hq-live',
                                )}
                            >
                                {player.market_value_difference > 0 ? '▲' : '▼'}{' '}
                                {formatCurrency(
                                    Math.abs(player.market_value_difference),
                                )}
                            </span>
                        )}
                    </p>
                    <div className="flex items-center gap-1.5 font-mono text-[10px] text-hq-moss">
                        {ownerManager ? (
                            <span
                                role="link"
                                tabIndex={0}
                                onClick={goToOwnerManager}
                                className="flex min-w-0 cursor-pointer items-center gap-1.5 hover:text-hq-paper"
                            >
                                <span
                                    className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                                    style={{
                                        backgroundColor: managerColor(
                                            ownerManager.primary_color,
                                        ),
                                    }}
                                />
                                <span className="max-w-[110px] truncate">
                                    {ownerManager.name}
                                </span>
                            </span>
                        ) : (
                            <span className="text-hq-moss-dim">Libre</span>
                        )}
                    </div>
                </div>
                <div className="mt-2 flex items-center justify-between border-t border-hq-ink pt-2">
                    <HqNextFixtures fixtures={player.next_fixtures} size="sm" />
                    <HqRecentScores
                        scores={player.recent_scores}
                        finished={player.recent_scores_finished}
                        opponents={player.recent_scores_opponents}
                        size="sm"
                    />
                </div>
            </div>
        </Link>
    );
}
```

- [ ] **Step 2: Remove the inline `PlayerRow` from `players/index.tsx` and import the shared one**

Modify `resources/js/pages/players/index.tsx` — replace the entire import block and delete the inline `PlayerRow` function:

```diff
-import { Head, Link, router } from '@inertiajs/react';
-import { ArrowDown, ArrowUp, Shield, User } from 'lucide-react';
-import type { MouseEvent as ReactMouseEvent, ReactElement } from 'react';
+import { Head, Link, router } from '@inertiajs/react';
+import { ArrowDown, ArrowUp } from 'lucide-react';
+import type { ReactElement } from 'react';
 import { useEffect, useRef, useState } from 'react';
-import { EntityImage } from '@/components/entity-image';
 import { HqMultiSelect } from '@/components/hq-multi-select';
-import { HqNextFixtures } from '@/components/hq-next-fixtures';
-import { HqPositionTag } from '@/components/hq-position-tag';
-import { HqRecentScores } from '@/components/hq-recent-scores';
+import { PlayerRow } from '@/components/hq-player-row';
 import AppLayout from '@/layouts/app-layout';
-import { formatCurrency } from '@/lib/format';
-import {
-    POSITION_LABELS,
-    STATUS_BADGE_CLASS,
-    STATUS_LABELS,
-    STATUS_SHORT_LABELS,
-} from '@/lib/player-labels';
-import { managerColor } from '@/lib/season-manager-colors';
+import { POSITION_LABELS, STATUS_LABELS } from '@/lib/player-labels';
 import { cn } from '@/lib/utils';
-import { index as playersIndex, show as playersShow } from '@/routes/players';
-import { show as seasonManagersShow } from '@/routes/season-managers';
+import { index as playersIndex } from '@/routes/players';
 import type {
     Paginated,
     Player,
     PlayerPosition,
     PlayerStatus,
 } from '@/types/models';
```

Then delete the entire `function PlayerRow({ player }: { player: Player }) { ... }` block (everything from `function PlayerRow` through its closing `}`, right before `export default function PlayersIndex`).

- [ ] **Step 3: Link the team crest in the player ficha sidebar**

Modify `resources/js/pages/players/show.tsx`:

```diff
-import { Head } from '@inertiajs/react';
+import { Head, Link } from '@inertiajs/react';
 import { User } from 'lucide-react';
 import type { ReactElement } from 'react';
 import { EntityImage } from '@/components/entity-image';
 import { HqNextFixtures } from '@/components/hq-next-fixtures';
 import { HqPlayerMatchTimeline } from '@/components/hq-player-match-timeline';
 import { HqPlayerPropertyCard } from '@/components/hq-player-property-card';
 import { HqPlayerValueChart } from '@/components/hq-player-value-chart';
 import { HqPositionTag } from '@/components/hq-position-tag';
 import AppLayout from '@/layouts/app-layout';
 import { formatAverage, formatCurrency } from '@/lib/format';
 import { buildOwnershipTimeline } from '@/lib/ownership-timeline';
 import {
     didNotPlayMatch,
     STATUS_BADGE_CLASS,
     STATUS_LABELS,
 } from '@/lib/player-labels';
 import { daznPointsBadgeClass, matchPointsBadgeClass } from '@/lib/points';
 import { cn } from '@/lib/utils';
+import { show as teamsShow } from '@/routes/teams';
 import type {
```

```diff
                             <div className="mb-3 flex items-center justify-center gap-2">
                                 <HqPositionTag position={player.position} />
-                                <img
-                                    src={player.team.logo}
-                                    alt={player.team.main_name}
-                                    className="h-7 w-7 object-contain"
-                                />
+                                <Link href={teamsShow(player.team.id).url}>
+                                    <img
+                                        src={player.team.logo}
+                                        alt={player.team.main_name}
+                                        className="h-7 w-7 object-contain"
+                                    />
+                                </Link>
                             </div>
```

- [ ] **Step 4: Type-check and lint**

Run: `npx tsc --noEmit && npx eslint resources/js/components/hq-player-row.tsx resources/js/pages/players/index.tsx resources/js/pages/players/show.tsx`
Expected: no errors.

- [ ] **Step 5: Run the existing backend tests to confirm no regression**

Run: `php artisan test tests/Feature/Http/Controllers/PlayersControllerTest.php`
Expected: PASS (unchanged — this task is frontend-only).

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/hq-player-row.tsx resources/js/pages/players/index.tsx resources/js/pages/players/show.tsx
git commit -m "refactor: extract PlayerRow into a shared component, link team crests to /equipos"
```

---

## Task 3: Squad list + sidebar (real standing, next fixtures) on the team ficha

**Files:**
- Modify: `app/Http/Controllers/TeamsController.php`
- Modify: `resources/js/pages/teams/show.tsx`
- Test: `tests/Feature/Http/Controllers/TeamsControllerTest.php`

**Interfaces:**
- Consumes: `TeamsController::standingsFor()` (Task 1), `PlayerRow` (Task 2), `AttachesOwnerManager::attachOwnerManager()`, `AttachesCurrentPlayerSeason::attachCurrentSeason()`, `AttachesRecentScores::attachRecentScores()`, `AttachesNextFixtures::attachNextFixtures()` (all existing traits, same signatures already used by `PlayersController`).
- Produces: `show()` Inertia props `squad: Player[]`, `standing: StandingsRow | null`, `nextFixtures: (NextFixtureSlot | null)[]` — `standing`/`nextFixtures` consumed by this task's sidebar; `squad` also informs Task 5 (which player entries appear on the pitch use the same underlying `Player` records).

- [ ] **Step 1: Write the failing tests**

Modify `tests/Feature/Http/Controllers/TeamsControllerTest.php` — add:

```php
test('the ficha squad includes the club players and excludes out-of-league ones', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);
    $keeper = Player::factory()->create([
        'team_id' => $team->id,
        'status' => PlayerStatus::Ok,
        'position' => PlayerPosition::Goalkeeper,
    ]);
    $outOfLeague = Player::factory()->create([
        'team_id' => $team->id,
        'status' => PlayerStatus::OutOfLeague,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('squad', 1)
        ->where('squad.0.id', $keeper->id)
    );
    expect($outOfLeague)->not->toBeNull(); // keeps the variable "used" for readability of intent
});

test('the ficha exposes this team\'s own position in the real standings', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'local_score' => 2,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standing.position', 1)
        ->where('standing.points', 3)
        ->where('standing.played', 1)
    );
});
```

Add the needed imports at the top of the test file:

```diff
 use App\Enums\FixtureState;
+use App\Enums\PlayerPosition;
+use App\Enums\PlayerStatus;
 use App\Models\Fixture;
+use App\Models\Player;
 use App\Models\Season;
 use App\Models\Team;
 use Inertia\Testing\AssertableInertia as Assert;
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Http/Controllers/TeamsControllerTest.php`
Expected: FAIL — `squad`/`standing` props don't exist on the `show()` response yet.

- [ ] **Step 3: Implement `show()`**

Modify `app/Http/Controllers/TeamsController.php`:

```diff
 use App\Enums\FixtureState;
+use App\Enums\PlayerStatus;
+use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
+use App\Http\Controllers\Concerns\AttachesNextFixtures;
+use App\Http\Controllers\Concerns\AttachesOwnerManager;
+use App\Http\Controllers\Concerns\AttachesRecentScores;
 use App\Models\Fixture;
+use App\Models\Player;
 use App\Models\Season;
 use App\Models\Team;
 use Illuminate\Support\Collection;
 use Inertia\Inertia;
 use Inertia\Response;

 class TeamsController extends Controller
 {
+    use AttachesCurrentPlayerSeason;
+    use AttachesNextFixtures;
+    use AttachesOwnerManager;
+    use AttachesRecentScores;
+
     public function index(): Response
```

```diff
     public function show(Team $team): Response
     {
-        return Inertia::render('teams/show', [
-            'team' => $team,
-        ]);
+        $season = Season::current();
+
+        $squad = Player::query()
+            ->select('players.*')
+            ->join('player_seasons', function ($join) use ($season): void {
+                $join->on('player_seasons.player_id', '=', 'players.id')
+                    ->where('player_seasons.season_id', $season->id);
+            })
+            ->where('team_id', $team->id)
+            ->whereNotNull('fantasy_id')
+            ->where('status', '!=', PlayerStatus::OutOfLeague)
+            ->with('team')
+            ->orderByDesc('player_seasons.points')
+            ->get();
+
+        $this->attachOwnerManager($squad, $season->id);
+        $this->attachCurrentSeason($squad, $season->id);
+        $this->attachRecentScores($squad, $season);
+        $this->attachNextFixtures($squad, $season);
+
+        $standing = collect($this->standingsFor($season->teams, $this->finishedFixtures($season)))
+            ->first(fn (array $row): bool => $row['team']->id === $team->id);
+
+        $nextFixtures = array_pad(
+            Fixture::query()
+                ->where('season_id', $season->id)
+                ->where('state', FixtureState::Scheduled)
+                ->where(fn ($query) => $query
+                    ->where('team_local_id', $team->id)
+                    ->orWhere('team_guest_id', $team->id))
+                ->with(['localTeam', 'guestTeam'])
+                ->orderBy('date')
+                ->take(3)
+                ->get()
+                ->map(fn (Fixture $fixture): array => [
+                    'week_number' => $fixture->week_number,
+                    'opponent' => $fixture->team_local_id === $team->id
+                        ? $fixture->guestTeam
+                        : $fixture->localTeam,
+                    'is_home' => $fixture->team_local_id === $team->id,
+                ])
+                ->values()
+                ->all(),
+            3,
+            null,
+        );
+
+        return Inertia::render('teams/show', [
+            'team' => $team,
+            'squad' => $squad,
+            'standing' => $standing,
+            'nextFixtures' => $nextFixtures,
+        ]);
     }
```

- [ ] **Step 4: Run the backend tests**

Run: `php artisan test tests/Feature/Http/Controllers/TeamsControllerTest.php`
Expected: PASS (all 6 tests).

- [ ] **Step 5: Build the sidebar + squad section**

Modify `resources/js/pages/teams/show.tsx` — replace the whole file:

```tsx
import { Head } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { PlayerRow } from '@/components/hq-player-row';
import { HqPositionTag } from '@/components/hq-position-tag';
import AppLayout from '@/layouts/app-layout';
import { POSITION_GROUP_LABELS } from '@/lib/player-labels';
import type {
    NextFixtureSlot,
    Player,
    PlayerPosition,
    StandingsRow,
    Team,
} from '@/types/models';

const GROUP_ORDER: PlayerPosition[] = [
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
    'coach',
];

interface TeamShowProps {
    team: Team;
    squad: Player[];
    standing: StandingsRow | null;
    nextFixtures: (NextFixtureSlot | null)[];
    [key: string]: unknown;
}

export default function TeamShow({
    team,
    squad,
    standing,
    nextFixtures,
}: TeamShowProps) {
    const groups = GROUP_ORDER.map((position) => ({
        position,
        players: squad.filter((player) => player.position === position),
    })).filter((group) => group.players.length > 0);

    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-6 py-9 lg:flex-row lg:items-start">
                <Head title={team.main_name} />

                <div className="w-full shrink-0 lg:w-64">
                    <div className="hq-card-cut p-4 text-center">
                        <EntityImage
                            src={team.logo}
                            alt={team.main_name}
                            fallback={Shield}
                            shape="square"
                            className="mx-auto mb-3 h-16 w-16"
                        />
                        <h1 className="mb-3 font-display text-xl text-hq-paper uppercase">
                            {team.main_name}
                        </h1>

                        {standing && (
                            <>
                                <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                    <span className="font-mono text-[11px] text-hq-moss">
                                        POSICIÓN
                                    </span>
                                    <span className="bg-hq-border px-1.5 font-mono font-bold text-hq-paper">
                                        {standing.position}º
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                    <span className="font-mono text-[11px] text-hq-moss">
                                        PJ / PG / PE / PP
                                    </span>
                                    <span className="font-mono font-bold text-hq-paper">
                                        {standing.played} / {standing.won} /{' '}
                                        {standing.drawn} / {standing.lost}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                    <span className="font-mono text-[11px] text-hq-moss">
                                        GF-GC
                                    </span>
                                    <span className="font-mono font-bold text-hq-paper">
                                        {standing.goals_for}-
                                        {standing.goals_against}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t border-hq-border-strong pt-1.5">
                                    <span className="font-mono text-[11px] text-hq-lime">
                                        PTS
                                    </span>
                                    <span className="font-mono font-bold text-hq-lime">
                                        {standing.points}
                                    </span>
                                </div>
                            </>
                        )}

                        <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                            <span className="font-mono text-[11px] text-hq-moss">
                                PRÓXIMOS
                            </span>
                            <HqNextFixtures fixtures={nextFixtures} size="sm" />
                        </div>
                    </div>
                </div>

                <div className="min-w-0 flex-1 space-y-8">
                    <div>
                        <h2 className="mb-3 font-display text-lg tracking-wide text-hq-paper uppercase">
                            Plantilla
                        </h2>
                        {groups.length === 0 ? (
                            <p className="font-mono text-[11px] text-hq-moss-dim">
                                Este equipo no tiene jugadores en la liga.
                            </p>
                        ) : (
                            groups.map((group) => (
                                <div
                                    key={group.position}
                                    className="mt-6 first:mt-0"
                                >
                                    <div className="mb-2 flex items-center gap-2">
                                        <HqPositionTag position={group.position} />
                                        <span className="font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                                            {POSITION_GROUP_LABELS[group.position]}
                                        </span>
                                    </div>
                                    {group.players.map((player) => (
                                        <PlayerRow
                                            key={player.id}
                                            player={player}
                                        />
                                    ))}
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

TeamShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
```

- [ ] **Step 6: Type-check and lint**

Run: `npx tsc --noEmit && npx eslint resources/js/pages/teams/show.tsx app/Http/Controllers/TeamsController.php`

(Note: `eslint` only checks the `.tsx` file; there is no PHP linter step configured beyond Pest — skip eslint for the `.php` path, it's listed only to make clear both files changed.)

Run: `npx eslint resources/js/pages/teams/show.tsx`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/TeamsController.php resources/js/pages/teams/show.tsx tests/Feature/Http/Controllers/TeamsControllerTest.php
git commit -m "feat: show squad and real standing on the team ficha"
```

---

## Task 4: Played/upcoming fixture calendar strip

**Files:**
- Modify: `app/Http/Controllers/TeamsController.php`
- Create: `resources/js/components/hq-team-fixture-strip.tsx`
- Modify: `resources/js/pages/teams/show.tsx`
- Test: `tests/Feature/Http/Controllers/TeamsControllerTest.php`

**Interfaces:**
- Produces: `show()` Inertia prop `fixtures: Fixture[]` — every fixture (past and future) for this team's season, ordered by `week_number`.
- Produces: `HqTeamFixtureStrip` component, props `{ fixtures: Fixture[], teamId: number }`.

- [ ] **Step 1: Write the failing test**

Modify `tests/Feature/Http/Controllers/TeamsControllerTest.php` — add:

```php
test('the ficha calendar includes both played and upcoming fixtures, ordered by week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $future = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Scheduled,
    ]);
    $past = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $rival->id,
        'team_guest_id' => $team->id,
        'local_score' => 1,
        'guest_score' => 1,
        'state' => FixtureState::Finished,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('fixtures', 2)
        ->where('fixtures.0.id', $past->id)
        ->where('fixtures.1.id', $future->id)
    );
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Http/Controllers/TeamsControllerTest.php`
Expected: FAIL — `fixtures` prop doesn't exist yet.

- [ ] **Step 3: Add the calendar query**

Modify `app/Http/Controllers/TeamsController.php` — inside `show()`, right before the `return Inertia::render(...)`:

```diff
+        $fixtures = Fixture::query()
+            ->where('season_id', $season->id)
+            ->where(fn ($query) => $query
+                ->where('team_local_id', $team->id)
+                ->orWhere('team_guest_id', $team->id))
+            ->with(['localTeam', 'guestTeam'])
+            ->orderBy('week_number')
+            ->get();
+
         return Inertia::render('teams/show', [
             'team' => $team,
             'squad' => $squad,
             'standing' => $standing,
             'nextFixtures' => $nextFixtures,
+            'fixtures' => $fixtures,
         ]);
```

- [ ] **Step 4: Run the backend tests**

Run: `php artisan test tests/Feature/Http/Controllers/TeamsControllerTest.php`
Expected: PASS (all 7 tests).

- [ ] **Step 5: Create the fixture strip component**

Create `resources/js/components/hq-team-fixture-strip.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { HqScrollRow } from '@/components/hq-scroll-row';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type { Fixture } from '@/types/models';

interface HqTeamFixtureStripProps {
    fixtures: Fixture[];
    teamId: number;
}

type Result = 'win' | 'draw' | 'loss' | null;

function resultFor(fixture: Fixture, teamId: number): Result {
    if (
        fixture.state !== 'finished' ||
        fixture.local_score === null ||
        fixture.guest_score === null
    ) {
        return null;
    }

    const isLocal = fixture.local_team.id === teamId;
    const ownScore = isLocal ? fixture.local_score : fixture.guest_score;
    const rivalScore = isLocal ? fixture.guest_score : fixture.local_score;

    if (ownScore > rivalScore) {
        return 'win';
    }

    if (ownScore === rivalScore) {
        return 'draw';
    }

    return 'loss';
}

const RESULT_LABEL: Record<'win' | 'draw' | 'loss', string> = {
    win: 'V',
    draw: 'E',
    loss: 'D',
};

const RESULT_CLASSES: Record<'win' | 'draw' | 'loss', string> = {
    win: 'border-hq-lime text-hq-lime',
    draw: 'border-hq-moss text-hq-moss',
    loss: 'border-hq-live text-hq-live',
};

export function HqTeamFixtureStrip({
    fixtures,
    teamId,
}: HqTeamFixtureStripProps) {
    return (
        <HqScrollRow contentClassName="px-1 py-1 pb-3">
            {fixtures.map((fixture) => {
                const opponent =
                    fixture.local_team.id === teamId
                        ? fixture.guest_team
                        : fixture.local_team;
                const result = resultFor(fixture, teamId);

                return (
                    <Link
                        key={fixture.id}
                        href={fixturesShow(fixture.id).url}
                        className={cn(
                            'relative flex h-14 w-14 shrink-0 flex-col items-center justify-center border-2 font-mono',
                            result
                                ? RESULT_CLASSES[result]
                                : 'border-dashed border-hq-border-strong text-hq-moss-dim',
                        )}
                    >
                        <span className="text-[10px] font-bold opacity-80">
                            J{fixture.week_number}
                        </span>
                        <span className="font-display text-lg leading-none">
                            {result ? RESULT_LABEL[result] : '—'}
                        </span>
                        <img
                            src={opponent.logo}
                            alt={opponent.main_name}
                            title={opponent.main_name}
                            className="absolute -bottom-2 left-1/2 h-3.5 w-3.5 -translate-x-1/2 object-contain drop-shadow-[0_1px_2px_rgba(0,0,0,0.9)]"
                        />
                    </Link>
                );
            })}
        </HqScrollRow>
    );
}
```

- [ ] **Step 6: Wire it into the ficha, after the squad section**

Modify `resources/js/pages/teams/show.tsx`:

```diff
 import { Head } from '@inertiajs/react';
 import { Shield } from 'lucide-react';
 import type { ReactElement } from 'react';
 import { EntityImage } from '@/components/entity-image';
 import { HqNextFixtures } from '@/components/hq-next-fixtures';
 import { PlayerRow } from '@/components/hq-player-row';
 import { HqPositionTag } from '@/components/hq-position-tag';
+import { HqTeamFixtureStrip } from '@/components/hq-team-fixture-strip';
 import AppLayout from '@/layouts/app-layout';
 import { POSITION_GROUP_LABELS } from '@/lib/player-labels';
 import type {
+    Fixture,
     NextFixtureSlot,
     Player,
     PlayerPosition,
     StandingsRow,
     Team,
 } from '@/types/models';
```

```diff
 interface TeamShowProps {
     team: Team;
     squad: Player[];
     standing: StandingsRow | null;
     nextFixtures: (NextFixtureSlot | null)[];
+    fixtures: Fixture[];
     [key: string]: unknown;
 }

 export default function TeamShow({
     team,
     squad,
     standing,
     nextFixtures,
+    fixtures,
 }: TeamShowProps) {
```

```diff
                             ))
                         )}
                     </div>
+
+                    <div>
+                        <h2 className="mb-3 font-display text-lg tracking-wide text-hq-paper uppercase">
+                            Calendario
+                        </h2>
+                        <HqTeamFixtureStrip
+                            fixtures={fixtures}
+                            teamId={team.id}
+                        />
+                    </div>
                 </div>
             </div>
         </div>
     );
 }
```

- [ ] **Step 7: Type-check and lint**

Run: `npx tsc --noEmit && npx eslint resources/js/components/hq-team-fixture-strip.tsx resources/js/pages/teams/show.tsx`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/TeamsController.php resources/js/components/hq-team-fixture-strip.tsx resources/js/pages/teams/show.tsx tests/Feature/Http/Controllers/TeamsControllerTest.php
git commit -m "feat: add played/upcoming fixture calendar to the team ficha"
```

---

## Task 5: Vertical pitch with a selectable jornada

**Files:**
- Modify: `app/Http/Controllers/TeamsController.php`
- Modify: `resources/js/pages/teams/show.tsx`
- Test: `tests/Feature/Http/Controllers/TeamsControllerTest.php`

**Interfaces:**
- Consumes: `HqLineupPitch`, `HqWeekScrollPicker`, `HqPlayerStatsModal` (existing components, unmodified — see `resources/js/pages/season-managers/show.tsx` for the exact reference wiring this mirrors), `ManagerLineupPlayerEntry` type (existing, reused as-is for pitch entries).
- Produces: `show()` Inertia props `season: Season`, `currentWeek: number` (the max of `season.current_week` and the latest jornada with a synced starting XI for this team — same lag-correction already shipped for `PlayersController::show()`), `weekProgress: WeekProgressMap`, `weeklyLineups: TeamWeekLineup[]` where `TeamWeekLineup = { week_number: number; fixture: Fixture; players: ManagerLineupPlayerEntry[] }` (new local type, declared in `teams/show.tsx`, not added to the shared `types/models.ts` since nothing else needs it yet).

- [ ] **Step 1: Write the failing tests**

Modify `tests/Feature/Http/Controllers/TeamsControllerTest.php` — add:

```php
test('the pitch defaults to the latest jornada with a synced lineup, even ahead of season.current_week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 3,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'local_score' => 2,
        'guest_score' => 1,
        'state' => FixtureState::Finished,
    ]);
    $starter = Player::factory()->create([
        'team_id' => $team->id,
        'position' => PlayerPosition::Striker,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $starter->id,
        'team_id' => $team->id,
        'starter' => true,
        'fantasy_points' => 9,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('currentWeek', 3)
        ->has('weeklyLineups', 1)
        ->where('weeklyLineups.0.week_number', 3)
        ->has('weeklyLineups.0.players', 1)
        ->where('weeklyLineups.0.players.0.player.id', $starter->id)
        ->where('weeklyLineups.0.players.0.points', 9)
        ->where('weeklyLineups.0.players.0.position', 'striker')
    );
});

test('a jornada with no synced starting XI is omitted from weeklyLineups', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('weeklyLineups', 0)
        ->where('currentWeek', 1)
    );
});

test('a bench player (starter=false) does not appear on the pitch', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Finished,
        'local_score' => 1,
        'guest_score' => 0,
    ]);
    $bench = Player::factory()->create(['team_id' => $team->id]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $bench->id,
        'team_id' => $team->id,
        'starter' => false,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page->has('weeklyLineups', 0));
});
```

Add the needed imports:

```diff
 use App\Enums\FixtureState;
 use App\Enums\PlayerPosition;
 use App\Enums\PlayerStatus;
 use App\Models\Fixture;
+use App\Models\FixtureLineup;
 use App\Models\Player;
 use App\Models\Season;
 use App\Models\Team;
 use Inertia\Testing\AssertableInertia as Assert;
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Http/Controllers/TeamsControllerTest.php`
Expected: FAIL — `currentWeek`/`weeklyLineups` props don't exist yet.

- [ ] **Step 3: Implement the pitch data in the controller**

Modify `app/Http/Controllers/TeamsController.php`:

```diff
 use App\Enums\FixtureState;
 use App\Enums\PlayerStatus;
 use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
 use App\Http\Controllers\Concerns\AttachesNextFixtures;
 use App\Http\Controllers\Concerns\AttachesOwnerManager;
 use App\Http\Controllers\Concerns\AttachesRecentScores;
+use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
 use App\Models\Fixture;
+use App\Models\FixtureLineup;
 use App\Models\Player;
 use App\Models\Season;
 use App\Models\Team;
 use Illuminate\Support\Collection;
 use Inertia\Inertia;
 use Inertia\Response;

 class TeamsController extends Controller
 {
     use AttachesCurrentPlayerSeason;
     use AttachesNextFixtures;
     use AttachesOwnerManager;
     use AttachesRecentScores;
+    use FiltersSeasonWeeks;
```

```diff
         $fixtures = Fixture::query()
             ->where('season_id', $season->id)
             ->where(fn ($query) => $query
                 ->where('team_local_id', $team->id)
                 ->orWhere('team_guest_id', $team->id))
             ->with(['localTeam', 'guestTeam'])
             ->orderBy('week_number')
             ->get();

+        $weeklyLineups = $this->weeklyLineupsFor($team, $season, $fixtures);
+        $latestLineupWeek = collect($weeklyLineups)->max('week_number') ?? 0;
+
         return Inertia::render('teams/show', [
             'team' => $team,
             'squad' => $squad,
             'standing' => $standing,
             'nextFixtures' => $nextFixtures,
             'fixtures' => $fixtures,
+            'season' => $season,
+            'currentWeek' => max($season->current_week, $latestLineupWeek),
+            'weekProgress' => (object) $this->weekProgress($season),
+            'weeklyLineups' => $weeklyLineups,
         ]);
     }
```

Then add the new private method (place it after `standingsFor()`):

```php
    /**
     * One entry per jornada that already has a synced starting XI for this
     * team — a jornada with no Fixture yet, or a Fixture with no starters
     * synced, is simply absent (the frontend shows an empty state for any
     * selected week that isn't in this list).
     *
     * `fixture_lineups.player_id` is nullable (an unresolved worldcup26
     * roster entry — see the match-data-linking design docs): a starter row
     * with no resolved `Player` is dropped here rather than crashing on
     * `$lineup->player->position`, since there's nothing displayable for it
     * on this pitch (no name/photo) anyway.
     *
     * @param  Collection<int, Fixture>  $fixtures  this team's fixtures for the season, with localTeam/guestTeam loaded
     * @return list<array{week_number: int, fixture: Fixture, players: list<array{id: int, points: int|null, stats: array<string, mixed>|null, position: string, player: Player, match_finished: bool, fixture: Fixture}>}>
     */
    private function weeklyLineupsFor(Team $team, Season $season, Collection $fixtures): array
    {
        $fixturesById = $fixtures->keyBy('id');

        $startersByFixture = FixtureLineup::query()
            ->whereIn('fixture_id', $fixturesById->keys())
            ->where('team_id', $team->id)
            ->where('starter', true)
            ->whereNotNull('player_id')
            ->with('player.team')
            ->get()
            ->groupBy('fixture_id');

        $this->attachCurrentSeason(
            $startersByFixture->flatten()->pluck('player')->unique('id'),
            $season->id,
        );

        $weeklyLineups = [];

        foreach ($startersByFixture as $fixtureId => $starters) {
            $fixture = $fixturesById->get($fixtureId);

            if ($fixture === null || $starters->isEmpty()) {
                continue;
            }

            $weeklyLineups[] = [
                'week_number' => $fixture->week_number,
                'fixture' => $fixture,
                'players' => $starters->map(fn (FixtureLineup $lineup): array => [
                    'id' => $lineup->id,
                    'points' => $lineup->fantasy_points,
                    'stats' => $lineup->fantasy_stats,
                    'position' => $lineup->player->position,
                    'player' => $lineup->player,
                    'match_finished' => $fixture->state === FixtureState::Finished,
                    'fixture' => $fixture,
                ])->values()->all(),
            ];
        }

        usort($weeklyLineups, fn (array $a, array $b): int => $a['week_number'] <=> $b['week_number']);

        return $weeklyLineups;
    }
```

- [ ] **Step 4: Run the backend tests**

Run: `php artisan test tests/Feature/Http/Controllers/TeamsControllerTest.php`
Expected: PASS (all 10 tests).

- [ ] **Step 5: Wire the pitch + week picker + player modal into the ficha**

Modify `resources/js/pages/teams/show.tsx` — replace the whole file:

```tsx
import { Head } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLineupPitch } from '@/components/hq-lineup-pitch';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { PlayerRow } from '@/components/hq-player-row';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqTeamFixtureStrip } from '@/components/hq-team-fixture-strip';
import { HqWeekScrollPicker } from '@/components/hq-week-scroll-picker';
import AppLayout from '@/layouts/app-layout';
import { POSITION_GROUP_LABELS } from '@/lib/player-labels';
import type {
    Fixture,
    ManagerLineupPlayerEntry,
    NextFixtureSlot,
    Player,
    PlayerPosition,
    Season,
    StandingsRow,
    Team,
    WeekProgressMap,
} from '@/types/models';

const GROUP_ORDER: PlayerPosition[] = [
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
    'coach',
];

interface TeamWeekLineup {
    week_number: number;
    fixture: Fixture;
    players: ManagerLineupPlayerEntry[];
}

interface TeamShowProps {
    team: Team;
    squad: Player[];
    standing: StandingsRow | null;
    nextFixtures: (NextFixtureSlot | null)[];
    fixtures: Fixture[];
    season: Season;
    currentWeek: number;
    weekProgress: WeekProgressMap;
    weeklyLineups: TeamWeekLineup[];
    [key: string]: unknown;
}

export default function TeamShow({
    team,
    squad,
    standing,
    nextFixtures,
    fixtures,
    season,
    currentWeek,
    weekProgress,
    weeklyLineups,
}: TeamShowProps) {
    const [selectedWeek, setSelectedWeek] = useState(currentWeek);
    const [selectedPlayer, setSelectedPlayer] =
        useState<ManagerLineupPlayerEntry | null>(null);

    const lineupForWeek = weeklyLineups.find(
        (lineup) => lineup.week_number === selectedWeek,
    );

    const groups = GROUP_ORDER.map((position) => ({
        position,
        players: squad.filter((player) => player.position === position),
    })).filter((group) => group.players.length > 0);

    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-6 py-9 lg:flex-row lg:items-start">
                <Head title={team.main_name} />

                <div className="w-full shrink-0 lg:w-64">
                    <div className="hq-card-cut p-4 text-center">
                        <EntityImage
                            src={team.logo}
                            alt={team.main_name}
                            fallback={Shield}
                            shape="square"
                            className="mx-auto mb-3 h-16 w-16"
                        />
                        <h1 className="mb-3 font-display text-xl text-hq-paper uppercase">
                            {team.main_name}
                        </h1>

                        {standing && (
                            <>
                                <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                    <span className="font-mono text-[11px] text-hq-moss">
                                        POSICIÓN
                                    </span>
                                    <span className="bg-hq-border px-1.5 font-mono font-bold text-hq-paper">
                                        {standing.position}º
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                    <span className="font-mono text-[11px] text-hq-moss">
                                        PJ / PG / PE / PP
                                    </span>
                                    <span className="font-mono font-bold text-hq-paper">
                                        {standing.played} / {standing.won} /{' '}
                                        {standing.drawn} / {standing.lost}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                    <span className="font-mono text-[11px] text-hq-moss">
                                        GF-GC
                                    </span>
                                    <span className="font-mono font-bold text-hq-paper">
                                        {standing.goals_for}-
                                        {standing.goals_against}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t border-hq-border-strong pt-1.5">
                                    <span className="font-mono text-[11px] text-hq-lime">
                                        PTS
                                    </span>
                                    <span className="font-mono font-bold text-hq-lime">
                                        {standing.points}
                                    </span>
                                </div>
                            </>
                        )}

                        <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                            <span className="font-mono text-[11px] text-hq-moss">
                                PRÓXIMOS
                            </span>
                            <HqNextFixtures fixtures={nextFixtures} size="sm" />
                        </div>
                    </div>
                </div>

                <div className="min-w-0 flex-1 space-y-8">
                    <div>
                        <h2 className="mb-3 font-display text-lg tracking-wide text-hq-paper uppercase">
                            Alineación de la jornada
                        </h2>
                        <div className="mb-4 min-w-0">
                            <HqWeekScrollPicker
                                week={selectedWeek}
                                maxWeek={season.total_weeks}
                                playedThroughWeek={season.current_week}
                                weekProgress={weekProgress}
                                onChange={setSelectedWeek}
                            />
                        </div>
                        <div className="mx-auto max-w-[360px]">
                            {lineupForWeek ? (
                                <HqLineupPitch
                                    players={lineupForWeek.players}
                                    tacticalFormation={null}
                                    onSelectPlayer={setSelectedPlayer}
                                />
                            ) : (
                                <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                                    <p className="font-mono text-[11px] text-hq-moss-dim">
                                        Alineación aún no disponible esa
                                        jornada.
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>

                    <div>
                        <h2 className="mb-3 font-display text-lg tracking-wide text-hq-paper uppercase">
                            Plantilla
                        </h2>
                        {groups.length === 0 ? (
                            <p className="font-mono text-[11px] text-hq-moss-dim">
                                Este equipo no tiene jugadores en la liga.
                            </p>
                        ) : (
                            groups.map((group) => (
                                <div
                                    key={group.position}
                                    className="mt-6 first:mt-0"
                                >
                                    <div className="mb-2 flex items-center gap-2">
                                        <HqPositionTag position={group.position} />
                                        <span className="font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                                            {POSITION_GROUP_LABELS[group.position]}
                                        </span>
                                    </div>
                                    {group.players.map((player) => (
                                        <PlayerRow
                                            key={player.id}
                                            player={player}
                                        />
                                    ))}
                                </div>
                            ))
                        )}
                    </div>

                    <div>
                        <h2 className="mb-3 font-display text-lg tracking-wide text-hq-paper uppercase">
                            Calendario
                        </h2>
                        <HqTeamFixtureStrip
                            fixtures={fixtures}
                            teamId={team.id}
                        />
                    </div>
                </div>
            </div>

            <HqPlayerStatsModal
                entry={
                    selectedPlayer
                        ? {
                              player: selectedPlayer.player,
                              team: selectedPlayer.player.team,
                              points: selectedPlayer.points ?? 0,
                              stats: selectedPlayer.stats ?? {},
                              fixture: selectedPlayer.fixture,
                          }
                        : null
                }
                onClose={() => setSelectedPlayer(null)}
            />
        </div>
    );
}

TeamShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
```

- [ ] **Step 6: Type-check and lint**

Run: `npx tsc --noEmit && npx eslint resources/js/pages/teams/show.tsx`
Expected: no errors.

- [ ] **Step 7: Run the full backend suite**

Run: `php artisan test`
Expected: PASS (every test in the project, confirming no regression anywhere else).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/TeamsController.php resources/js/pages/teams/show.tsx tests/Feature/Http/Controllers/TeamsControllerTest.php
git commit -m "feat: add jornada-selectable vertical pitch to the team ficha"
```

---

## After all 5 tasks

The spec's "Riesgos / decisiones abiertas" are resolved: the player-stats modal *is* wired up (Task 5, same shape as `season-managers/show.tsx`'s), and the standings aggregation lives in one shared private method (`TeamsController::standingsFor()`) used by both `index()` and `show()` — no duplication. The `local_formation`/`guest_formation` parsing mentioned in the spec was dropped as an unverified assumption (see Global Constraints); `HqLineupPitch` renders correctly without it.
