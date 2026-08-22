# Base Layout & Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the stock Laravel starter "welcome" page with the four top-level routes the site needs (Home, Equipos, Jugadores, Actividad), each rendering a placeholder Inertia page wrapped in one shared layout with a working navigation bar. (Mercado has no dedicated page — see the ruling below.)

**Architecture:** One thin controller per top-level section (`index()` returning `Inertia::render(...)` with no props yet — real data props are added by each section's own plan). A single `AppLayout` React component owns the header and nav; every page assigns it via the static `Component.layout` convention so Inertia keeps it persistent across visits. Navigation links use Wayfinder-generated route helpers (`@/routes/...`), never hand-written URL strings. No new npm/composer dependencies. No visual design system yet — plain Tailwind utility classes; visual polish is a later pass.

**Tech Stack:** Laravel 13 + Inertia (`inertiajs/inertia-laravel` ^3.0) + React 19 (`@inertiajs/react` ^3.7) + Tailwind v4, Pest 5 for backend tests, Laravel Wayfinder for typed route/action generation.

**Spec:** This plan implements the "site map" and "shared navigation" decisions from the domain-modeling/grilling session recorded in `CONTEXT.md` (repo root) — see the **Ficha**, **Clasificación general**, **Clasificación de la jornada**, **Mercado**, **Actividad** entries. Each of the four pages built here gets its real content in its own later plan; this plan only builds the shell.

**Ruling (2026-08-22, mid-execution, after Task 2):** the user decided `/mercado` does not warrant its own page — the daily market is a short list (~10 players) and fits entirely on Home, where it will live per the "Deferred to Later Plans" section below. `/actividad` keeps its own page (it will grow past 500 entries over time, so a filterable dedicated view earns its place). This plan originally had a Task 4 building a `market.index` route/controller/page; that task is replaced below with a cleanup task that removes the now-unused `market.index` route Task 1 registered (no controller or page for it was ever built, so there is nothing else to remove). Task 6's nav is trimmed from 5 to 4 items accordingly. See also the ledger's "Ruling: drop the dedicated Mercado page" entry and the `site_ia_decisions.md` memory note.

## Global Constraints

- No auth, no login — this is a public, read-only site (confirmed in grilling session).
- Only the current season is shown anywhere — no season switcher UI yet.
- Do not reuse anything from the `design` git branch — this plan builds the shell from scratch.
- Do not add new composer or npm dependencies without the user's approval (per `AGENTS.md`).
- Follow existing PHP conventions: `declare(strict_types=1);`, explicit return types, constructor property promotion, curly braces always (per `AGENTS.md`).
- Run `vendor/bin/pint --dirty --format agent` after any PHP change, before committing.
- Run all Artisan/PHP commands through Herd (`herd php artisan ...`), per `AGENTS.md`.
- Never hand-edit files under `resources/js/actions/**` or `resources/js/routes/**` — Wayfinder regenerates them automatically from `routes/web.php` whenever `npm run dev` or `npm run build` runs.

---

## File Structure

**Backend (create):**
- `app/Http/Controllers/HomeController.php` — `index()` → `Inertia::render('home')`
- `app/Http/Controllers/SeasonTeamsController.php` — `index()` → `Inertia::render('season-teams/index')`
- `app/Http/Controllers/PlayersController.php` — `index()` → `Inertia::render('players/index')`
- `app/Http/Controllers/ActivityController.php` — `index()` → `Inertia::render('activity/index')`

**Backend (modify):**
- `routes/web.php` — replace the single `welcome` route with the four named routes above, then (Task 4) remove the unused `market.index` route Task 1 also registered.

**Backend (test):**
- `tests/Feature/Http/Controllers/HomeControllerTest.php`
- `tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php`
- `tests/Feature/Http/Controllers/PlayersControllerTest.php`
- `tests/Feature/Http/Controllers/ActivityControllerTest.php`

**Frontend (create):**
- `resources/js/layouts/app-layout.tsx` — shared header + `<main>` shell
- `resources/js/components/main-nav.tsx` — the 4-link nav, active-link aware
- `resources/js/pages/home.tsx`
- `resources/js/pages/season-teams/index.tsx`
- `resources/js/pages/players/index.tsx`
- `resources/js/pages/activity/index.tsx`

**Frontend (delete):**
- `resources/js/pages/welcome.tsx` — no route points at it once Task 1 lands.

---

### Task 1: Home route, controller, placeholder page

**Files:**
- Modify: `routes/web.php`
- Create: `app/Http/Controllers/HomeController.php`
- Create: `resources/js/pages/home.tsx`
- Test: `tests/Feature/Http/Controllers/HomeControllerTest.php`

**Interfaces:**
- Produces: named route `home` (`GET /`) rendering Inertia component `'home'` with no props.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('renders the home page', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('home'));
});
```

Save as `tests/Feature/Http/Controllers/HomeControllerTest.php`.

- [ ] **Step 2: Run the test and confirm it fails**

Run: `herd php artisan test --compact --filter=HomeControllerTest`
Expected: FAIL — `route('home')` currently renders `welcome`, so `$page->component('home')` fails, or the route/controller doesn't resolve yet as described below.

- [ ] **Step 3: Replace the welcome route and add the controller**

Replace the full contents of `routes/web.php` with:

```php
<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\PlayersController;
use App\Http\Controllers\SeasonTeamsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/equipos', [SeasonTeamsController::class, 'index'])->name('season-teams.index');
Route::get('/jugadores', [PlayersController::class, 'index'])->name('players.index');
Route::get('/mercado', [MarketController::class, 'index'])->name('market.index');
Route::get('/actividad', [ActivityController::class, 'index'])->name('activity.index');
```

(This registers all five routes now so later tasks only add controllers/pages, not routes.)

Create `app/Http/Controllers/HomeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('home');
    }
}
```

Create `resources/js/pages/home.tsx`:

```tsx
import { Head } from '@inertiajs/react';

export default function Home() {
    return (
        <>
            <Head title="Inicio" />
            <p className="text-neutral-500">Próximamente: clasificación general y partidos de la jornada.</p>
        </>
    );
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `herd php artisan test --compact --filter=HomeControllerTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php app/Http/Controllers/HomeController.php resources/js/pages/home.tsx tests/Feature/Http/Controllers/HomeControllerTest.php
git commit -m "feat: add home route with placeholder page"
```

---

### Task 2: Equipos (`season-teams.index`) route, controller, placeholder page

**Files:**
- Create: `app/Http/Controllers/SeasonTeamsController.php`
- Create: `resources/js/pages/season-teams/index.tsx`
- Test: `tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php`

**Interfaces:**
- Consumes: route `season-teams.index` already registered in Task 1's `routes/web.php`.
- Produces: Inertia component `'season-teams/index'` with no props.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('renders the season teams index page', function (): void {
    $response = $this->get(route('season-teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('season-teams/index'));
});
```

Save as `tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php`.

- [ ] **Step 2: Run the test and confirm it fails**

Run: `herd php artisan test --compact --filter=SeasonTeamsControllerTest`
Expected: FAIL — `App\Http\Controllers\SeasonTeamsController` does not exist yet.

- [ ] **Step 3: Add the controller and page**

Create `app/Http/Controllers/SeasonTeamsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class SeasonTeamsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('season-teams/index');
    }
}
```

Create `resources/js/pages/season-teams/index.tsx`:

```tsx
import { Head } from '@inertiajs/react';

export default function SeasonTeamsIndex() {
    return (
        <>
            <Head title="Equipos" />
            <p className="text-neutral-500">Próximamente: clasificación de la jornada seleccionada.</p>
        </>
    );
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `herd php artisan test --compact --filter=SeasonTeamsControllerTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SeasonTeamsController.php resources/js/pages/season-teams/index.tsx tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php
git commit -m "feat: add season teams index route with placeholder page"
```

---

### Task 3: Jugadores (`players.index`) route, controller, placeholder page

**Files:**
- Create: `app/Http/Controllers/PlayersController.php`
- Create: `resources/js/pages/players/index.tsx`
- Test: `tests/Feature/Http/Controllers/PlayersControllerTest.php`

**Interfaces:**
- Consumes: route `players.index` already registered in Task 1's `routes/web.php`.
- Produces: Inertia component `'players/index'` with no props.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('renders the players index page', function (): void {
    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('players/index'));
});
```

Save as `tests/Feature/Http/Controllers/PlayersControllerTest.php`.

- [ ] **Step 2: Run the test and confirm it fails**

Run: `herd php artisan test --compact --filter=PlayersControllerTest`
Expected: FAIL — `App\Http\Controllers\PlayersController` does not exist yet.

- [ ] **Step 3: Add the controller and page**

Create `app/Http/Controllers/PlayersController.php`:

```php
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
```

Create `resources/js/pages/players/index.tsx`:

```tsx
import { Head } from '@inertiajs/react';

export default function PlayersIndex() {
    return (
        <>
            <Head title="Jugadores" />
            <p className="text-neutral-500">Próximamente: buscador de jugadores con filtros por posición, equipo y estado.</p>
        </>
    );
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `herd php artisan test --compact --filter=PlayersControllerTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/PlayersController.php resources/js/pages/players/index.tsx tests/Feature/Http/Controllers/PlayersControllerTest.php
git commit -m "feat: add players index route with placeholder page"
```

---

### Task 4: Remove the abandoned Mercado route

**Files:**
- Modify: `routes/web.php`

**Interfaces:**
- Removes: named route `market.index` (`GET /mercado`), registered in Task 1 but never given a controller or page (see the plan-level "Ruling" note above — the user decided Mercado will live entirely on Home instead of its own page).

There is no controller, page, or test to remove for this route — Task 1 only ever registered the route line itself; no later task built anything behind it.

- [ ] **Step 1: Confirm nothing depends on the route**

Run: `grep -rn "market.index\|market\\.index" app resources/js routes tests 2>/dev/null`
Expected: only one match, the route registration itself in `routes/web.php` (no controller, no test, no frontend link references it — Tasks 2/3's work is unrelated, and Tasks 5/6 haven't run yet).

- [ ] **Step 2: Remove the route**

Edit `routes/web.php` to remove the `market.index` line and its now-unused `MarketController` import, so the file reads:

```php
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
```

- [ ] **Step 3: Run the full test suite and confirm nothing broke**

Run: `herd php artisan test --compact`
Expected: PASS — no test referenced `market.index`, so the count only reflects removing dead code, not removing coverage.

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php
git commit -m "refactor: remove unused market.index route (mercado has no dedicated page)"
```

---

### Task 5: Actividad (`activity.index`) route, controller, placeholder page

**Files:**
- Create: `app/Http/Controllers/ActivityController.php`
- Create: `resources/js/pages/activity/index.tsx`
- Test: `tests/Feature/Http/Controllers/ActivityControllerTest.php`

**Interfaces:**
- Consumes: route `activity.index` already registered in Task 1's `routes/web.php`.
- Produces: Inertia component `'activity/index'` with no props.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('renders the activity index page', function (): void {
    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('activity/index'));
});
```

Save as `tests/Feature/Http/Controllers/ActivityControllerTest.php`.

- [ ] **Step 2: Run the test and confirm it fails**

Run: `herd php artisan test --compact --filter=ActivityControllerTest`
Expected: FAIL — `App\Http\Controllers\ActivityController` does not exist yet.

- [ ] **Step 3: Add the controller and page**

Create `app/Http/Controllers/ActivityController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('activity/index');
    }
}
```

Create `resources/js/pages/activity/index.tsx`:

```tsx
import { Head } from '@inertiajs/react';

export default function ActivityIndex() {
    return (
        <>
            <Head title="Actividad" />
            <p className="text-neutral-500">Próximamente: feed global de fichajes, ventas, blindajes y premios.</p>
        </>
    );
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `herd php artisan test --compact --filter=ActivityControllerTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/ActivityController.php resources/js/pages/activity/index.tsx tests/Feature/Http/Controllers/ActivityControllerTest.php
git commit -m "feat: add activity index route with placeholder page"
```

---

### Task 6: Shared `AppLayout` and navigation, wired into every page

**Files:**
- Create: `resources/js/components/main-nav.tsx`
- Create: `resources/js/layouts/app-layout.tsx`
- Modify: `resources/js/pages/home.tsx`
- Modify: `resources/js/pages/season-teams/index.tsx`
- Modify: `resources/js/pages/players/index.tsx`
- Modify: `resources/js/pages/activity/index.tsx`
- Delete: `resources/js/pages/welcome.tsx`

**Interfaces:**
- Consumes: the four named routes still standing after Task 4 (`home`, `season-teams.index`, `players.index`, `activity.index`), via their Wayfinder-generated helpers. `market.index` no longer exists — do not reference it.
- Produces: `AppLayout` (default export from `@/layouts/app-layout`), a React component taking `{ children: ReactNode }`, meant to be assigned to `PageComponent.layout`.

There is no backend test for this task — it is pure frontend wiring, verified by hand in a browser (no JS test runner is configured in this repo; see `Global Constraints`).

- [ ] **Step 1: Regenerate Wayfinder route helpers**

Run: `npm run build`
Expected: exits 0. This regenerates `resources/js/routes/**` from the routes currently registered (after Task 4 removed `market.index`), so `resources/js/routes/season-teams/index.ts`, `resources/js/routes/players/index.ts`, and `resources/js/routes/activity/index.ts` now exist (alongside the existing `resources/js/routes/index.ts`, which gains a `home` export). There is no `resources/js/routes/market/` — do not import from it. Confirm with:

Run: `git status --porcelain resources/js/routes resources/js/actions`
Expected: no output — these paths are gitignored, so regenerated files won't show as untracked.

- [ ] **Step 2: Write the navigation component**

Create `resources/js/components/main-nav.tsx`:

```tsx
import { Link, usePage } from '@inertiajs/react';
import { home } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import { index as playersIndex } from '@/routes/players';
import { index as seasonTeamsIndex } from '@/routes/season-teams';
import { cn } from '@/lib/utils';

const navItems = [
    { label: 'Inicio', href: home().url },
    { label: 'Equipos', href: seasonTeamsIndex().url },
    { label: 'Jugadores', href: playersIndex().url },
    { label: 'Actividad', href: activityIndex().url },
];

export function MainNav() {
    const { url } = usePage();

    return (
        <nav aria-label="Principal" className="flex gap-1">
            {navItems.map((item) => {
                const isActive = item.href === '/' ? url === '/' : url.startsWith(item.href);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={isActive ? 'page' : undefined}
                        className={cn(
                            'rounded-md px-3 py-2 text-sm font-medium transition-colors',
                            isActive ? 'bg-neutral-900 text-white' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900',
                        )}
                    >
                        {item.label}
                    </Link>
                );
            })}
        </nav>
    );
}
```

- [ ] **Step 3: Write the layout**

Create `resources/js/layouts/app-layout.tsx`:

```tsx
import type { PropsWithChildren } from 'react';
import { MainNav } from '@/components/main-nav';

export default function AppLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-white text-neutral-900">
            <header className="border-b border-neutral-200">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                    <span className="text-lg font-semibold">Comando Lechuga</span>
                    <MainNav />
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-6 py-10">{children}</main>
        </div>
    );
}
```

- [ ] **Step 4: Wire the layout into every page**

For each of the four page files, add the import and the trailing `.layout` assignment. Example for `resources/js/pages/home.tsx` (apply the same pattern — same two additions, different component name — to `season-teams/index.tsx`, `players/index.tsx`, `activity/index.tsx`):

```tsx
import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';

export default function Home() {
    return (
        <>
            <Head title="Inicio" />
            <p className="text-neutral-500">Próximamente: clasificación general y partidos de la jornada.</p>
        </>
    );
}

Home.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
```

- [ ] **Step 5: Delete the now-unused welcome page**

```bash
git rm resources/js/pages/welcome.tsx
```

- [ ] **Step 6: Run the full backend test suite**

Run: `herd php artisan test --compact`
Expected: PASS (all tests, including the four controller tests from Tasks 1, 2, 3, 5, plus the pre-existing `tests/Feature/ExampleTest.php` which also hits `route('home')`).

- [ ] **Step 7: Manually verify in the browser**

Run: `npm run build` (if not already run in Step 1 after the layout/nav changes)

Then, using the `run` skill or a browser, visit each of the four routes on the Herd URL for this project and confirm for each one:
- The header and nav are present and identical across all four pages.
- The nav item for the current page is visually highlighted (dark pill) and has `aria-current="page"`.
- Clicking each of the other three nav links navigates there via Inertia (no full page reload) and updates the highlighted item.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/components/main-nav.tsx resources/js/layouts/app-layout.tsx resources/js/pages/home.tsx resources/js/pages/season-teams/index.tsx resources/js/pages/players/index.tsx resources/js/pages/activity/index.tsx
git commit -m "feat: add shared app layout and navigation across all pages"
```

---

## Self-Review Notes

- **Spec coverage:** all four top-level routes/pages from the (revised) site map are created (`home`, `season-teams.index`, `players.index`, `activity.index`), each reachable from one shared, tested nav; the abandoned `market.index` route is cleaned up rather than left dead. Ficha (detail) pages for equipos/jugadores/partidos are explicitly out of scope — each belongs to its own later plan.
- **Placeholder scan:** every step has runnable code; no "TBD"/"add validation" left as prose.
- **Type consistency:** `AppLayout` takes `{ children: ReactNode }` (via `PropsWithChildren`) in Task 6 and every page assigns `Component.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>` — same shape used everywhere. Route helper imports (`home`, `index as seasonTeamsIndex`, etc.) match the names Wayfinder generates from the route names registered in Task 1.

## Deferred to Later Plans (out of scope here)

Raised by the user while this plan was mid-execution. None of these change this plan's six tasks (still placeholder shells + nav) — they scope the *content* plans that come after this one:

- **Home:** in addition to clasificación general + partidos de la jornada, Home should also show today's Mercado listing (`MarketPlayer`) and the latest Actividad entries (`SeasonActivity`) directly — not just links to those pages. **Update:** Mercado ended up with no dedicated page at all (see the plan-level Ruling above) — Home is its only home, since it's a short daily list (~10 players). `/actividad` still keeps its own page for the full filterable history (it will grow past 500 entries).
- **Equipos:** for whichever jornada is selected, also show each team's lineup for that week (`SeasonTeamLineup` + `SeasonTeamLineupPlayer`), not just the points ranking.
- **Ficha de equipo:** show a submenu/tabs inside a team's page, at least "Plantilla actual" and "Puntuaciones de cada jornada" (the per-week lineup history), rather than a bare week-selector.

See also the memory note `site_ia_decisions.md` for the same content.
