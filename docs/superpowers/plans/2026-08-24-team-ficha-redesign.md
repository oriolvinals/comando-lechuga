# Team Ficha (season-teams/show) Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild `resources/js/pages/season-teams/show.tsx` (the team "ficha") to match the approved dark-HQ mockup — hero with podium medal/rank chip, weekly points evolution bars, a roster list with clause status + últimas-3-jornadas "forma" + total points grouped on the right, a simplified activity timeline rail, and a per-team "alineación de la jornada" section reusing the existing week picker and pitch.

**Architecture:** Two small backend additions (a reusable "attach recent scores" concern, and a `season` field on the show payload) feed data that already exists everywhere else in the app into this one page. On the frontend, extract a couple of small shared pieces (clause-status decision logic, activity body/color helpers) out of existing components so the new UI reuses them instead of re-deriving them, add two new small presentational components (points bar chart, activity timeline entry), and split the page into a `team-hero.tsx` / `roster-list.tsx` pair alongside `show.tsx`, mirroring how `pages/home/*.tsx` is already split into per-section files.

**Tech Stack:** Laravel 12 + Pest (backend), Inertia + React 19 + TypeScript + Tailwind v4 (frontend). No frontend test runner exists in this repo — frontend "tests" are `npm run types:check`, `npm run lint:check`, and manual verification via the dev server, matching how the rest of the app is verified.

**Spec:** Two published mockups this plan implements pixel-for-pixel where feasible:
- Ficha layout: `docs/design/equipo-mockup.html` (superseded working copy) — canonical version is the one iterated live in this conversation and captured in full in the "Reference markup" sections of Task 8 and Task 9 below.
- Activity rail: `docs/design/actividad-rail-mockup.html` — Option C ("línea de tiempo"), final version with the value chip and time-over-value layout.

## Global Constraints

- Follow the existing `Hq*` naming convention for new shared components (per project memory: dark HQ redesign, `Hq*` prefix) — plain descriptive names for page-local files.
- Reuse existing design tokens/utility classes only: `hq-card-cut`, `hq-crest-cut`, `hq-tag-cut`, `hq-panel-cut`, and the `--color-hq-*` CSS custom properties already defined in `resources/css/app.css`. Do not invent new hex colors.
- Do not delete or modify `resources/js/components/activity-entry.tsx`, `buyout-status-badge.tsx`, `lineup-pitch.tsx`, `player-stats-modal.tsx`, or `position-badge.tsx` — they are still used by `resources/js/pages/fixtures/show.tsx`. Only stop importing them from `season-teams/show.tsx`.
- No new backend endpoints or query params for the "alineación de la jornada" section — `lineupHistory` is already fetched in full for the team; the week picker there is client-side state over the already-loaded array.
- Money renders via `formatCurrency` (`resources/js/lib/format.ts`), never hand-formatted.
- Backend tests: Pest, run via `./vendor/bin/pest <path>`. Frontend verification: `npm run types:check` and `npm run lint:check` (no component test runner exists in this repo — do not add one).

---

## File Structure

**Backend — new/modified:**
- Create: `app/Http/Controllers/Concerns/AttachesRecentScores.php` — extracted from `PlayersController`, generalized to any `Collection<int, Player>`.
- Modify: `app/Http/Controllers/PlayersController.php` — use the new concern instead of its private method.
- Modify: `app/Http/Controllers/SeasonTeamsController.php` — attach recent scores to the roster's players, add `season` to the `show` payload.

**Frontend — shared logic extracted:**
- Create: `resources/js/lib/clause-status.ts` — `resolveClauseStatus`, shared between `HqPlayerPropertyCard` and the new roster row.
- Modify: `resources/js/components/hq-player-property-card.tsx` — export `ClauseDifference`, use `resolveClauseStatus`.
- Modify: `resources/js/components/hq-recent-scores.tsx` — add a `size` prop (`'md' | 'sm'`).
- Modify: `resources/js/lib/player-labels.ts` — add `POSITION_GROUP_LABELS`.
- Modify: `resources/js/components/activity-card.tsx` — export `TYPE_COLORS`, `describeActivityBody`, `isFavorableDifference`; add `TYPE_BAR_CLASSES`.

**Frontend — new components:**
- Create: `resources/js/components/hq-team-points-chart.tsx` — weekly points bar chart.
- Create: `resources/js/components/hq-activity-timeline-entry.tsx` — the "Option C" simplified activity row.

**Frontend — page:**
- Create: `resources/js/pages/season-teams/team-hero.tsx` — crest/medal/rank/stat tiles hero.
- Create: `resources/js/pages/season-teams/roster-list.tsx` — grouped roster rows.
- Modify (full rewrite): `resources/js/pages/season-teams/show.tsx` — composes everything, drops the old tabbed table UI.

**Tests:**
- Modify: `tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php` — add coverage for `season` payload and roster `recent_scores`.
- No change expected to `tests/Feature/Http/Controllers/PlayersControllerTest.php` (regression check only).

---

### Task 1: Extract `AttachesRecentScores` concern

**Files:**
- Create: `app/Http/Controllers/Concerns/AttachesRecentScores.php`
- Modify: `app/Http/Controllers/PlayersController.php:236-272` (the `attachRecentScores` private method and its call site)
- Test: `tests/Feature/Http/Controllers/PlayersControllerTest.php` (existing tests, regression only)

**Interfaces:**
- Produces: `AttachesRecentScores::attachRecentScores(Collection<int, Player> $players, Season $season): void` — sets `$player->recent_scores` (an `array<int, int|null>` of length 3) on every `Player` in the collection, in place. Used by Task 2.

- [ ] **Step 1: Create the trait with the generalized method**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use Illuminate\Support\Collection;

trait AttachesRecentScores
{
    /**
     * Attaches each player's points for their last 3 played matches (oldest first,
     * left to right), ordered by fixture date — every fixture a player's team plays
     * produces a PlayerScore row (even a benched player scores 0), so this is never
     * sparse because of a skipped jornada. It only comes back shorter than 3 — padded
     * with null at the end — for a player without 3 matches of history yet.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachRecentScores(Collection $players, Season $season): void
    {
        $playerIds = $players->pluck('id')->all();

        $scoresByPlayer = PlayerScore::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->with('fixture:id,date')
            ->get()
            ->groupBy('player_id');

        $players->each(function (Player $player) use ($scoresByPlayer): void {
            $points = ($scoresByPlayer->get($player->id) ?? collect())
                ->sortByDesc(fn (PlayerScore $score) => $score->fixture->date)
                ->take(3)
                ->sortBy(fn (PlayerScore $score) => $score->fixture->date)
                ->values()
                ->map(fn (PlayerScore $score): int => $score->points)
                ->all();

            /** @var array<int, int|null> $padded */
            $padded = array_pad($points, 3, null);

            $player->recent_scores = $padded;
        });
    }
}
```

- [ ] **Step 2: Use the trait from `PlayersController`, removing the old private method**

In `app/Http/Controllers/PlayersController.php`:
- Add `use App\Http\Controllers\Concerns\AttachesRecentScores;` to the imports.
- Add `use AttachesRecentScores;` inside the class body (alongside the class declaration, same style as `SeasonTeamsController` already does for its two concerns).
- Delete the private `attachRecentScores(LengthAwarePaginator $players, Season $season)` method (lines 236-272) — the trait now provides it, but the paginator must be unwrapped to a `Collection` at the call site.
- Change the call site in `index()` from:
  ```php
  $this->attachRecentScores($players, $season);
  ```
  to:
  ```php
  $this->attachRecentScores($players->getCollection(), $season);
  ```
- Remove the now-unused `use Illuminate\Database\Eloquent\Collection;` import if nothing else in the file uses it (check first — `attachOwnership` also type-hints `Collection<int, Player>`, so keep the import; only the `LengthAwarePaginator` import stays needed for `index()`'s return type elsewhere too, so leave it).

- [ ] **Step 3: Run the existing recent-scores tests to confirm no regression**

Run: `./vendor/bin/pest tests/Feature/Http/Controllers/PlayersControllerTest.php`
Expected: PASS (all existing tests, including the four `recent_scores` tests around line 300-380, unchanged in behavior).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Concerns/AttachesRecentScores.php app/Http/Controllers/PlayersController.php
git commit -m "refactor: extract AttachesRecentScores concern from PlayersController"
```

---

### Task 2: Attach recent scores and season to the season-team show payload

**Files:**
- Modify: `app/Http/Controllers/SeasonTeamsController.php:42-72`
- Test: `tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php`

**Interfaces:**
- Consumes: `AttachesRecentScores::attachRecentScores(Collection $players, Season $season): void` (Task 1).
- Produces: the Inertia `season-teams/show` payload now also includes `season: Season` and each `roster[].player.recent_scores: array<int, int|null>`. Consumed by Task 10 (`show.tsx`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php` (add `use App\Models\Fixture;` and `use App\Models\PlayerScore;` to the top imports alongside the existing ones):

```php
test('includes the current season in the show payload', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 12,
        'total_weeks' => 38,
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);

    $response = $this->get(route('season-teams.show', $seasonTeam));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('season.current_week', 12)
        ->where('season.total_weeks', 38)
    );
});

test('attaches recent scores to each roster player', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();
    SeasonTeamPlayer::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'player_id' => $player->id,
    ]);
    $earliest = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(20)]);
    $latest = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(10)]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $earliest->id, 'points' => 4]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $latest->id, 'points' => 9]);

    $response = $this->get(route('season-teams.show', $seasonTeam));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('roster.0.player.recent_scores', [4, 9, null])
    );
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php --filter="includes the current season|attaches recent scores"`
Expected: FAIL — `season` key missing from the payload, `recent_scores` missing on `roster.0.player`.

- [ ] **Step 3: Implement**

Replace `app/Http/Controllers/SeasonTeamsController.php` in full:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\AttachesRecentScores;
use App\Http\Controllers\Concerns\ResolvesRequestedWeek;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamLineup;
use App\Models\SeasonTeamPlayer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeasonTeamsController extends Controller
{
    use AttachesActivityValueDifference;
    use AttachesRecentScores;
    use ResolvesRequestedWeek;

    public function index(Request $request): Response
    {
        $season = Season::current();
        $week = $this->resolveWeek($request, $season);

        $lineups = SeasonTeamLineup::query()
            ->where('week_number', $week)
            ->whereHas('seasonTeam', fn ($query) => $query->where('season_id', $season->id))
            ->with(['seasonTeam', 'players.player.team'])
            ->orderByDesc('points')
            ->get();

        return Inertia::render('season-teams/index', [
            'season' => $season,
            'filters' => ['week' => $week],
            'lineups' => $lineups,
        ]);
    }

    public function show(SeasonTeam $seasonTeam): Response
    {
        $season = Season::current();

        $roster = SeasonTeamPlayer::query()
            ->where('season_team_id', $seasonTeam->id)
            ->with('player.team')
            ->get();

        $this->attachRecentScores($roster->pluck('player'), $season);

        $lineupHistory = SeasonTeamLineup::query()
            ->where('season_team_id', $seasonTeam->id)
            ->with('players.player.team')
            ->orderByDesc('week_number')
            ->get();

        $activity = SeasonActivity::query()
            ->where(fn ($query) => $query
                ->where('source_season_team_id', $seasonTeam->id)
                ->orWhere('target_season_team_id', $seasonTeam->id))
            ->with(['sourceSeasonTeam', 'targetSeasonTeam', 'player'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $this->attachValueDifferences($activity);

        return Inertia::render('season-teams/show', [
            'season' => $season,
            'seasonTeam' => $seasonTeam,
            'roster' => $roster,
            'lineupHistory' => $lineupHistory,
            'activity' => $activity,
        ]);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php`
Expected: PASS (all tests in the file, old and new).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/SeasonTeamsController.php tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php
git commit -m "feat: expose season and roster recent scores on the team ficha payload"
```

---

### Task 3: Shared clause-status resolver + export `ClauseDifference`

**Files:**
- Create: `resources/js/lib/clause-status.ts`
- Modify: `resources/js/components/hq-player-property-card.tsx:19-79`

**Interfaces:**
- Produces: `resolveClauseStatus(shielded: boolean, buyoutClauseLockedUntil: string, now: number): 'open' | 'locked' | 'shielded'`, and an exported `ClauseDifference({ clause, marketValue }: { clause: number; marketValue: number })` component. Both consumed by Task 9 (`roster-list.tsx`).

- [ ] **Step 1: Create the resolver**

```typescript
export type ClauseStatus = 'open' | 'locked' | 'shielded';

/**
 * Which of the three clause states a player's ownership is in right now —
 * shared by every place that shows clause status (the player ficha's
 * OwnedStatus card, the team ficha's roster rows) so the branching logic
 * can't drift between them.
 */
export function resolveClauseStatus(
    shielded: boolean,
    buyoutClauseLockedUntil: string,
    now: number,
): ClauseStatus {
    if (shielded) {
        return 'shielded';
    }

    if (new Date(buyoutClauseLockedUntil).getTime() > now) {
        return 'locked';
    }

    return 'open';
}
```

- [ ] **Step 2: Export `ClauseDifference` and use the resolver in `hq-player-property-card.tsx`**

In `resources/js/components/hq-player-property-card.tsx`:
- Add `import { resolveClauseStatus } from '@/lib/clause-status';` to the imports.
- Change `function ClauseDifference(...)` to `export function ClauseDifference(...)` (no other change to its body).
- In `OwnedStatus`, replace:
  ```typescript
  const now = useNow();
  const locked =
      !owner.shielded &&
      new Date(owner.buyout_clause_locked_until).getTime() > now;
  const shielded = owner.shielded;
  ```
  with:
  ```typescript
  const now = useNow();
  const status = resolveClauseStatus(
      owner.shielded,
      owner.buyout_clause_locked_until,
      now,
  );
  ```
- Replace the `shielded ? ... : locked ? ... : ...` ternary chain's conditions with `status === 'shielded' ? ... : status === 'locked' ? ... : ...` (same three branches, same JSX, only the condition changes).

- [ ] **Step 3: Verify with a type check**

Run: `npm run types:check`
Expected: PASS, no new errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/lib/clause-status.ts resources/js/components/hq-player-property-card.tsx
git commit -m "refactor: extract shared clause-status resolver, export ClauseDifference"
```

---

### Task 4: `POSITION_GROUP_LABELS` and `HqRecentScores` size variant

**Files:**
- Modify: `resources/js/lib/player-labels.ts:8-14`
- Modify: `resources/js/components/hq-recent-scores.tsx`

**Interfaces:**
- Produces: `POSITION_GROUP_LABELS: Record<PlayerPosition, string>` and `HqRecentScores({ scores, className, size }: { scores: (number | null)[]; className?: string; size?: 'md' | 'sm' })`. Both consumed by Task 9 (`roster-list.tsx`); `HqRecentScores` without `size` keeps its current (`'md'`) look everywhere else it's already used (`resources/js/pages/players/index.tsx`).

- [ ] **Step 1: Add the plural group labels**

In `resources/js/lib/player-labels.ts`, add after `POSITION_LABELS`:

```typescript
/** Plural group headers for a roster grouped by position (e.g. the team ficha's "Plantilla actual"). */
export const POSITION_GROUP_LABELS: Record<PlayerPosition, string> = {
    goalkeeper: 'Porteros',
    defender: 'Defensas',
    midfield: 'Centrocampistas',
    striker: 'Delanteros',
    coach: 'Entrenadores',
};
```

- [ ] **Step 2: Add the `size` prop to `HqRecentScores`**

Replace `resources/js/components/hq-recent-scores.tsx` in full:

```tsx
import { matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';

interface HqRecentScoresProps {
    scores: (number | null)[];
    className?: string;
    size?: 'md' | 'sm';
}

const SIZE_CLASSES: Record<'md' | 'sm', string> = {
    md: 'h-8 w-8 text-[13px]',
    sm: 'h-6 w-6 text-[11px]',
};

/** Points for the last 3 played matches — a dash where the player has no match history yet. */
export function HqRecentScores({
    scores,
    className,
    size = 'md',
}: HqRecentScoresProps) {
    return (
        <div className={cn('flex shrink-0 gap-1', className)}>
            {scores.map((points, index) => (
                <span
                    key={index}
                    className={cn(
                        'flex shrink-0 items-center justify-center border font-mono font-bold',
                        SIZE_CLASSES[size],
                        points !== null
                            ? matchPointsBadgeClass(points)
                            : 'border-dashed border-hq-border-strong text-hq-moss-dim',
                    )}
                >
                    {points ?? '–'}
                </span>
            ))}
        </div>
    );
}
```

- [ ] **Step 3: Verify with a type check**

Run: `npm run types:check`
Expected: PASS — `resources/js/pages/players/index.tsx` still calls `HqRecentScores` without `size`, which now defaults to `'md'` (identical rendered output to before).

- [ ] **Step 4: Commit**

```bash
git add resources/js/lib/player-labels.ts resources/js/components/hq-recent-scores.tsx
git commit -m "feat: add position group labels and a compact size variant to HqRecentScores"
```

---

### Task 5: Export activity helpers from `activity-card.tsx`

**Files:**
- Modify: `resources/js/components/activity-card.tsx:36-128`

**Interfaces:**
- Produces: `TYPE_COLORS`, `TYPE_BAR_CLASSES: Record<SeasonActivityType, string>`, `describeActivityBody(activity: SeasonActivity): ReactNode`, `isFavorableDifference(activity: SeasonActivity): boolean` — all exported. `TYPE_ICONS` and `TYPE_LABELS` are already exported (no change needed). Consumed by Task 7 (`hq-activity-timeline-entry.tsx`).

- [ ] **Step 1: Export the existing helpers and add `TYPE_BAR_CLASSES`**

In `resources/js/components/activity-card.tsx`:
- Change `const TYPE_COLORS` to `export const TYPE_COLORS`.
- Change `function describeActivityBody(` to `export function describeActivityBody(`.
- Change `function isFavorableDifference(` to `export function isFavorableDifference(`.
- Add, right after `TYPE_COLORS`:

```typescript
/** Solid background classes for the activity timeline's left accent bar — same palette as TYPE_COLORS. */
export const TYPE_BAR_CLASSES: Record<SeasonActivityType, string> = {
    signing: 'bg-hq-lime',
    sale: 'bg-hq-ember',
    buyout: 'bg-hq-med',
    shield: 'bg-hq-def',
    weekly_prize: 'bg-hq-gold',
    joined_league: 'bg-hq-moss',
};
```

- [ ] **Step 2: Verify with a type check**

Run: `npm run types:check`
Expected: PASS, no new errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/activity-card.tsx
git commit -m "refactor: export activity type helpers for reuse by the timeline entry"
```

---

### Task 6: `HqTeamPointsChart` component

**Files:**
- Create: `resources/js/components/hq-team-points-chart.tsx`

**Interfaces:**
- Consumes: `SeasonTeamLineup` type (`resources/js/types/models.ts`, already has `id`, `points`, `week_number`).
- Produces: `HqTeamPointsChart({ lineupHistory }: { lineupHistory: SeasonTeamLineup[] })`. Consumed by Task 10 (`show.tsx`).

- [ ] **Step 1: Implement the component**

```tsx
import type { SeasonTeamLineup } from '@/types/models';

interface HqTeamPointsChartProps {
    lineupHistory: SeasonTeamLineup[];
}

/** Weekly points as a simple bar chart, oldest week first — no interactivity, this is a trend-at-a-glance, not a drill-down (that's what the lineup-of-the-week section below it is for). */
export function HqTeamPointsChart({ lineupHistory }: HqTeamPointsChartProps) {
    if (lineupHistory.length === 0) {
        return (
            <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                <p className="font-mono text-[11px] text-hq-moss-dim">
                    Todavía no hay jornadas jugadas.
                </p>
            </div>
        );
    }

    const weeks = [...lineupHistory].sort(
        (a, b) => a.week_number - b.week_number,
    );
    const maxPoints = Math.max(...weeks.map((week) => week.points), 1);

    return (
        <div className="hq-card-cut p-4">
            <div className="flex h-24 items-end gap-1.5">
                {weeks.map((week) => (
                    <div
                        key={week.id}
                        className="flex h-full flex-1 flex-col justify-end"
                    >
                        <span className="mb-1 text-center font-display text-[11px] text-hq-lime">
                            {week.points}
                        </span>
                        <div
                            className="mx-auto w-3/5 bg-hq-lime/40"
                            style={{
                                height: `${Math.max(4, (week.points / maxPoints) * 100)}%`,
                            }}
                        />
                    </div>
                ))}
            </div>
            <div className="mt-1.5 flex gap-1.5">
                {weeks.map((week) => (
                    <span
                        key={week.id}
                        className="flex-1 text-center font-mono text-[8px] text-hq-moss-dim"
                    >
                        J{week.week_number}
                    </span>
                ))}
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Verify with a type check**

Run: `npm run types:check`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/hq-team-points-chart.tsx
git commit -m "feat: add HqTeamPointsChart for the team ficha's weekly points trend"
```

---

### Task 7: `HqActivityTimelineEntry` component (rail Option C)

**Files:**
- Create: `resources/js/components/hq-activity-timeline-entry.tsx`

**Interfaces:**
- Consumes: `TYPE_LABELS`, `TYPE_BAR_CLASSES`, `describeActivityBody`, `isFavorableDifference` from `resources/js/components/activity-card.tsx` (Task 5); `SeasonActivity` type.
- Produces: `HqActivityTimelineEntry({ activity }: { activity: SeasonActivity })`. Consumed by Task 10 (`show.tsx`).

- [ ] **Step 1: Implement the component**

```tsx
import {
    describeActivityBody,
    isFavorableDifference,
    TYPE_BAR_CLASSES,
    TYPE_LABELS,
} from '@/components/activity-card';
import { formatCurrency, formatRelativeTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SeasonActivity } from '@/types/models';

interface HqActivityTimelineEntryProps {
    activity: SeasonActivity;
}

/**
 * Simplified activity row for narrow contexts (the team ficha's activity
 * rail) — a colored accent bar replaces the crest+photo pair ActivityCard
 * uses, but the value keeps ActivityCard's exact language: a khaki chip for
 * the amount, lime/red for the difference.
 */
export function HqActivityTimelineEntry({
    activity,
}: HqActivityTimelineEntryProps) {
    return (
        <div className="flex gap-2.5 border-b border-hq-ink py-2.5 last:border-b-0">
            <div
                className={cn(
                    'w-[3px] shrink-0 rounded-sm',
                    TYPE_BAR_CLASSES[activity.type],
                )}
            />
            <div className="min-w-0 flex-1">
                <span className="font-mono text-[8.5px] font-bold tracking-wide text-hq-moss uppercase">
                    {TYPE_LABELS[activity.type]}
                </span>
                <p className="mt-0.5 text-[11.5px] leading-snug text-hq-paper/90">
                    {describeActivityBody(activity)}
                </p>
            </div>
            {activity.amount !== null ? (
                <div className="flex shrink-0 flex-col items-end gap-1">
                    <time
                        dateTime={activity.occurred_at}
                        className="font-mono text-[8.5px] text-hq-moss-dim"
                    >
                        {formatRelativeTime(activity.occurred_at)}
                    </time>
                    <span className="hq-tag-cut inline-block bg-hq-khaki px-1.5 py-0.5 font-mono text-[10px] font-bold text-hq-ink">
                        {formatCurrency(activity.amount)}
                    </span>
                    {activity.value_difference !== null && (
                        <p
                            className={cn(
                                'font-mono text-[8.5px] font-bold',
                                isFavorableDifference(activity)
                                    ? 'text-hq-lime'
                                    : 'text-hq-live',
                            )}
                        >
                            {activity.value_difference >= 0 ? '+' : ''}
                            {formatCurrency(activity.value_difference)}
                        </p>
                    )}
                </div>
            ) : (
                <time
                    dateTime={activity.occurred_at}
                    className="shrink-0 self-start font-mono text-[8.5px] text-hq-moss-dim"
                >
                    {formatRelativeTime(activity.occurred_at)}
                </time>
            )}
        </div>
    );
}
```

- [ ] **Step 2: Verify with a type check**

Run: `npm run types:check`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/hq-activity-timeline-entry.tsx
git commit -m "feat: add HqActivityTimelineEntry, the simplified activity rail row"
```

---

### Task 8: `TeamHero` component

**Files:**
- Create: `resources/js/pages/season-teams/team-hero.tsx`

**Interfaces:**
- Consumes: `SeasonTeam` type (has `position`, `last_position`, `total_points`, `live_points`, `value`, `logo`, `primary_color`, `secondary_color`, `name`).
- Produces: `TeamHero({ seasonTeam }: { seasonTeam: SeasonTeam })`. Consumed by Task 10 (`show.tsx`).

**Reference markup (mockup, for visual parity):**

```html
<div class="hero" style="--pc:#d81f2a; --sc:#f2c230;">
  <div class="hero-crest-wrap" style="--medal:#c9793f;">
    <div class="hero-crest-outer"><div class="hero-crest crest">🛡️</div></div>
    <div class="rank-chip">3.º <span class="trend">▲</span></div>
  </div>
  <h1 class="hero-name">Atlético Lechuga</h1>
  <div class="stat-tiles">
    <div class="stat-tile"><div class="k">Puntos</div><div class="v">742</div></div>
    <div class="stat-tile"><div class="k">En directo</div><div class="v lime">+38</div></div>
    <div class="stat-tile"><div class="k">Valor</div><div class="v">312,4M</div></div>
  </div>
</div>
```

- [ ] **Step 1: Implement the component**

```tsx
import { ArrowDown, ArrowUp, Minus, Shield } from 'lucide-react';
import type { CSSProperties } from 'react';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency } from '@/lib/format';
import { crestTintStyle } from '@/lib/season-team-colors';
import type { SeasonTeam } from '@/types/models';

/** Medal color per podium spot — same palette as the home hero's PODIUM_SIZES. Outside the podium the crest keeps a neutral border. */
const MEDAL_COLOR_VARS: Record<number, string> = {
    1: 'var(--color-hq-gold)',
    2: 'var(--color-hq-silver)',
    3: 'var(--color-hq-bronze)',
};

function RankTrend({
    position,
    lastPosition,
}: {
    position: number;
    lastPosition: number;
}) {
    if (position < lastPosition) {
        return (
            <ArrowUp
                className="h-[11px] w-[11px] text-hq-lime"
                strokeWidth={3.5}
            />
        );
    }

    if (position > lastPosition) {
        return (
            <ArrowDown
                className="h-[11px] w-[11px] text-hq-live"
                strokeWidth={3.5}
            />
        );
    }

    return (
        <Minus className="h-[11px] w-[11px] text-hq-moss" strokeWidth={3.5} />
    );
}

interface TeamHeroProps {
    seasonTeam: SeasonTeam;
}

export function TeamHero({ seasonTeam }: TeamHeroProps) {
    const medal = MEDAL_COLOR_VARS[seasonTeam.position];
    const borderColor = medal ?? 'var(--color-hq-border-strong)';
    const chipTextColor = medal ?? 'var(--color-hq-paper)';

    return (
        <div
            className="relative overflow-hidden p-6"
            style={
                {
                    '--pc': seasonTeam.primary_color ?? 'transparent',
                    '--sc': seasonTeam.secondary_color ?? 'transparent',
                } as CSSProperties
            }
        >
            <div
                className="pointer-events-none absolute inset-0 opacity-15"
                style={{
                    background:
                        'linear-gradient(115deg, var(--pc) 0%, transparent 38%), linear-gradient(-65deg, var(--sc) 0%, transparent 45%)',
                }}
            />
            <div className="relative flex flex-wrap items-center gap-5">
                <div className="relative h-[84px] w-[84px] shrink-0">
                    <div
                        className="h-full w-full p-[3px]"
                        style={{
                            backgroundColor: borderColor,
                            clipPath:
                                'polygon(15% 0, 100% 0, 100% 85%, 85% 100%, 0 100%, 0 15%)',
                        }}
                    >
                        <EntityImage
                            src={seasonTeam.logo}
                            alt={seasonTeam.name}
                            fallback={Shield}
                            shape="square"
                            style={crestTintStyle(seasonTeam.primary_color)}
                            className="hq-crest-cut h-full w-full bg-hq-border p-2 text-hq-khaki"
                        />
                    </div>
                    <div
                        className="absolute -right-2 -bottom-2 flex items-center gap-1 border-2 bg-hq-ink px-1.5 py-0.5 font-display text-sm"
                        style={{ borderColor, color: chipTextColor }}
                    >
                        {seasonTeam.position}.º
                        <RankTrend
                            position={seasonTeam.position}
                            lastPosition={seasonTeam.last_position}
                        />
                    </div>
                </div>

                <h1 className="font-display text-3xl text-hq-paper uppercase">
                    {seasonTeam.name}
                </h1>

                <div className="ml-auto flex flex-wrap gap-2.5">
                    <div className="border border-hq-border bg-hq-panel-alt/80 px-4 py-2 text-center">
                        <div className="font-mono text-[9px] text-hq-moss uppercase">
                            Puntos
                        </div>
                        <div className="mt-0.5 font-display text-xl text-hq-paper">
                            {seasonTeam.total_points}
                        </div>
                    </div>
                    <div className="border border-hq-border bg-hq-panel-alt/80 px-4 py-2 text-center">
                        <div className="font-mono text-[9px] text-hq-moss uppercase">
                            En directo
                        </div>
                        <div className="mt-0.5 font-display text-xl text-hq-lime">
                            +{seasonTeam.live_points}
                        </div>
                    </div>
                    <div className="border border-hq-border bg-hq-panel-alt/80 px-4 py-2 text-center">
                        <div className="font-mono text-[9px] text-hq-moss uppercase">
                            Valor
                        </div>
                        <div className="mt-0.5 font-display text-xl text-hq-paper">
                            {formatCurrency(seasonTeam.value)}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Verify with a type check**

Run: `npm run types:check`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/season-teams/team-hero.tsx
git commit -m "feat: add TeamHero section for the team ficha"
```

---

### Task 9: `RosterList` component

**Files:**
- Create: `resources/js/pages/season-teams/roster-list.tsx`

**Interfaces:**
- Consumes: `resolveClauseStatus`, `ClauseDifference` (Task 3); `POSITION_GROUP_LABELS` (Task 4); `HqRecentScores` with `size="sm"` (Task 4); `HqPositionTag`, `useLockCountdown`, `useNow`, `SeasonTeamPlayer` type.
- Produces: `RosterList({ roster }: { roster: SeasonTeamPlayer[] })`. Consumed by Task 10 (`show.tsx`).

**Reference markup (mockup, for visual parity — one row):**

```html
<div class="roster-row">
  <div class="r-avatar">👤</div>
  <div class="r-id"><div class="r-name">Tarugo</div><div class="r-club">🛡 CD Repollo</div></div>
  <div class="r-clause cl-locked">
    <div class="cl-label">🔒 Bloqueada · 2D 4H</div>
    <div class="cl-value">14,2M (+1,4M)</div>
  </div>
  <div class="r-scores">
    <div class="r-scores-col"><div class="r-form">1 3 5</div><span>Forma</span></div>
    <div class="r-scores-div"></div>
    <div class="r-scores-col"><div class="r-pts">38</div><span>Pts</span></div>
  </div>
</div>
```

- [ ] **Step 1: Implement the roster row's clause status (unboxed variant of `HqPlayerPropertyCard`'s `OwnedStatus`)**

```tsx
import { Lock, Shield, ShieldCheck, User } from 'lucide-react';
import { ClauseDifference } from '@/components/hq-player-property-card';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { EntityImage } from '@/components/entity-image';
import { resolveClauseStatus } from '@/lib/clause-status';
import { useLockCountdown } from '@/lib/use-lock-countdown';
import { useNow } from '@/lib/use-now';
import { POSITION_GROUP_LABELS } from '@/lib/player-labels';
import type { PlayerPosition, SeasonTeamPlayer } from '@/types/models';

const GROUP_ORDER: PlayerPosition[] = [
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
    'coach',
];

function RosterClauseStatus({ entry }: { entry: SeasonTeamPlayer }) {
    const now = useNow();
    const status = resolveClauseStatus(
        entry.shielded,
        entry.buyout_clause_locked_until,
        now,
    );
    const countdown = useLockCountdown(entry.buyout_clause_locked_until);

    if (status === 'shielded') {
        return (
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1 font-mono text-[10px] font-bold text-hq-def uppercase">
                    <ShieldCheck className="h-[13px] w-[13px]" />
                    Blindado
                    <span className="text-hq-paper normal-case">
                        · {countdown}
                    </span>
                </div>
                <ClauseDifference
                    clause={entry.buyout_clause}
                    marketValue={entry.player.market_value}
                />
            </div>
        );
    }

    if (status === 'locked') {
        return (
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1 font-mono text-[10px] font-bold text-hq-moss uppercase">
                    <Lock className="h-[13px] w-[13px]" />
                    Bloqueada
                    <span className="text-hq-gold normal-case">
                        · {countdown}
                    </span>
                </div>
                <ClauseDifference
                    clause={entry.buyout_clause}
                    marketValue={entry.player.market_value}
                />
            </div>
        );
    }

    return (
        <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1 font-mono text-[10px] font-bold text-hq-lime uppercase">
                <Lock className="h-[13px] w-[13px] rotate-45" />
                Cláusula abierta
            </div>
            <ClauseDifference
                clause={entry.buyout_clause}
                marketValue={entry.player.market_value}
            />
        </div>
    );
}
```

- [ ] **Step 2: Implement the row and the grouped list**

Append to the same file:

```tsx
function RosterRow({ entry }: { entry: SeasonTeamPlayer }) {
    return (
        <div className="hq-card-cut mb-1.5 flex items-center gap-3 px-3.5 py-2.5">
            <EntityImage
                src={entry.player.image}
                alt={entry.player.nickname}
                fallback={User}
                className="h-10 w-10 shrink-0 bg-hq-border"
            />
            <div className="w-40 min-w-0 shrink-0">
                <p className="truncate text-sm font-extrabold text-hq-paper">
                    {entry.player.nickname}
                </p>
                <div className="mt-0.5 flex items-center gap-1.5">
                    <EntityImage
                        src={entry.player.team.logo}
                        alt={entry.player.team.name}
                        fallback={Shield}
                        shape="square"
                        className="h-3.5 w-3.5"
                    />
                    <span className="truncate font-mono text-[10px] text-hq-moss-dim">
                        {entry.player.team.short_name}
                    </span>
                </div>
            </div>

            <RosterClauseStatus entry={entry} />

            <div className="flex shrink-0 items-center gap-4">
                <div className="flex flex-col items-center gap-1">
                    <HqRecentScores
                        scores={entry.player.recent_scores}
                        size="sm"
                    />
                    <span className="font-mono text-[8px] font-bold tracking-wide text-hq-moss-dim uppercase">
                        Forma
                    </span>
                </div>
                <div className="h-8 w-px bg-hq-border" />
                <div className="flex flex-col items-center gap-1">
                    <span className="font-display text-lg text-hq-lime">
                        {entry.player.points}
                    </span>
                    <span className="font-mono text-[8px] font-bold tracking-wide text-hq-moss-dim uppercase">
                        Pts
                    </span>
                </div>
            </div>
        </div>
    );
}

interface RosterListProps {
    roster: SeasonTeamPlayer[];
}

export function RosterList({ roster }: RosterListProps) {
    if (roster.length === 0) {
        return (
            <p className="font-mono text-[11px] text-hq-moss-dim">
                Este equipo no tiene jugadores en plantilla.
            </p>
        );
    }

    const groups = GROUP_ORDER.map((position) => ({
        position,
        entries: roster.filter((entry) => entry.player.position === position),
    })).filter((group) => group.entries.length > 0);

    return (
        <div>
            {groups.map((group) => (
                <div key={group.position}>
                    <div className="mt-5 mb-2 flex items-center gap-2 first:mt-0">
                        <HqPositionTag position={group.position} />
                        <span className="font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                            {POSITION_GROUP_LABELS[group.position]}
                        </span>
                    </div>
                    {group.entries.map((entry) => (
                        <RosterRow key={entry.id} entry={entry} />
                    ))}
                </div>
            ))}
        </div>
    );
}
```

- [ ] **Step 3: Verify with a type check**

Run: `npm run types:check`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/season-teams/roster-list.tsx
git commit -m "feat: add RosterList section for the team ficha"
```

---

### Task 10: Rewrite `season-teams/show.tsx`

**Files:**
- Modify (full rewrite): `resources/js/pages/season-teams/show.tsx`

**Interfaces:**
- Consumes: `TeamHero` (Task 8), `RosterList` (Task 9), `HqTeamPointsChart` (Task 6), `HqActivityTimelineEntry` (Task 7), `HqWeekPicker`, `HqLineupPitch`, `HqPlayerStatsModal` (all pre-existing), the `season` + roster-with-`recent_scores` payload (Task 2).

**Reference markup (mockup, for the lineup-of-the-week section and page shell):**

```html
<div class="lineup-section">
  <div class="lineup-head">
    <h2>Alineación de la jornada</h2>
    <button class="week-btn">JORNADA 14 ▾</button>
  </div>
  <div class="lineup-wrap"><!-- HqLineupPitch --></div>
</div>
```

- [ ] **Step 1: Replace the page**

Replace `resources/js/pages/season-teams/show.tsx` in full:

```tsx
import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { HqActivityTimelineEntry } from '@/components/hq-activity-timeline-entry';
import { HqLineupPitch } from '@/components/hq-lineup-pitch';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import { HqTeamPointsChart } from '@/components/hq-team-points-chart';
import { HqWeekPicker } from '@/components/hq-week-picker';
import AppLayout from '@/layouts/app-layout';
import { RosterList } from '@/pages/season-teams/roster-list';
import { TeamHero } from '@/pages/season-teams/team-hero';
import type {
    Season,
    SeasonActivity,
    SeasonTeam,
    SeasonTeamLineup,
    SeasonTeamLineupPlayerEntry,
    SeasonTeamPlayer,
} from '@/types/models';

interface SeasonTeamShowProps {
    season: Season;
    seasonTeam: SeasonTeam;
    roster: SeasonTeamPlayer[];
    lineupHistory: SeasonTeamLineup[];
    activity: SeasonActivity[];
    [key: string]: unknown;
}

export default function SeasonTeamShow({
    season,
    seasonTeam,
    roster,
    lineupHistory,
    activity,
}: SeasonTeamShowProps) {
    const [selectedWeek, setSelectedWeek] = useState(
        lineupHistory[0]?.week_number ?? season.current_week,
    );
    const [selectedPlayer, setSelectedPlayer] =
        useState<SeasonTeamLineupPlayerEntry | null>(null);

    const lineupForWeek = lineupHistory.find(
        (lineup) => lineup.week_number === selectedWeek,
    );

    return (
        <div className="hq-texture hq-bleed min-h-[calc(100vh-95px)] border-y border-hq-border">
            <Head title={seasonTeam.name} />

            <div className="mx-auto max-w-6xl">
                <TeamHero seasonTeam={seasonTeam} />

                <div className="p-6">
                    <h2 className="mb-3 font-display text-lg text-hq-paper uppercase">
                        Evolución de puntos
                    </h2>
                    <HqTeamPointsChart lineupHistory={lineupHistory} />

                    <div className="mt-8 flex flex-col gap-5 lg:flex-row lg:items-start">
                        <div className="min-w-0 flex-1">
                            <h2 className="mb-3 font-display text-lg text-hq-paper uppercase">
                                Plantilla actual
                            </h2>
                            <RosterList roster={roster} />
                        </div>

                        <div className="w-full shrink-0 lg:w-[280px]">
                            <h2 className="mb-3 font-display text-lg text-hq-paper uppercase">
                                Actividad
                            </h2>
                            {activity.length === 0 ? (
                                <p className="font-mono text-[11px] text-hq-moss-dim">
                                    Todavía no hay actividad de este equipo.
                                </p>
                            ) : (
                                <div className="hq-card-cut px-4 py-1">
                                    {activity.map((entry) => (
                                        <HqActivityTimelineEntry
                                            key={entry.id}
                                            activity={entry}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="mt-10 border-t border-dashed border-hq-border pt-6">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-2.5">
                            <h2 className="font-display text-lg text-hq-paper uppercase">
                                Alineación de la jornada
                            </h2>
                            <HqWeekPicker
                                week={selectedWeek}
                                maxWeek={season.total_weeks}
                                playedThroughWeek={season.current_week}
                                onChange={setSelectedWeek}
                            />
                        </div>

                        <div className="mx-auto max-w-[300px]">
                            {lineupForWeek ? (
                                <HqLineupPitch
                                    players={lineupForWeek.players}
                                    onSelectPlayer={setSelectedPlayer}
                                />
                            ) : (
                                <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                                    <p className="font-mono text-[11px] text-hq-moss-dim">
                                        Sin alineación registrada esa jornada.
                                    </p>
                                </div>
                            )}
                        </div>
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
                          }
                        : null
                }
                onClose={() => setSelectedPlayer(null)}
            />
        </div>
    );
}

SeasonTeamShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
```

- [ ] **Step 2: Type-check and lint**

Run: `npm run types:check`
Expected: PASS.

Run: `npm run lint:check`
Expected: PASS (fix anything Prettier/ESLint flags — mainly import ordering — with `npm run lint` and `npm run format` if needed).

- [ ] **Step 3: Run the backend feature tests for this page**

Run: `./vendor/bin/pest tests/Feature/Http/Controllers/SeasonTeamsControllerTest.php`
Expected: PASS.

- [ ] **Step 4: Manual verification in the browser**

Start the dev server (`composer dev` or `npm run dev` alongside `php artisan serve`, whichever this repo's README/CLAUDE.md prescribes), open a team's `/equipos/{id}` page, and check against the two mockups:
- Hero shows the crest, medal border + rank chip only for podium positions 1-3, stat tiles for puntos/en directo/valor.
- Evolution bars render one per played week, tallest bar reaching the top.
- Roster rows are grouped by position, clause status shows the right icon/color/countdown per player, forma + puntos are grouped together at the row's far right.
- Activity rail shows the Option C timeline rows with the value chip.
- The week picker changes the pitch below it to that week's lineup (or the "sin alineación" empty state for a week this team didn't play), without a page navigation.

Fix anything that doesn't match before proceeding.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/season-teams/show.tsx
git commit -m "feat: rebuild the team ficha page with the dark-HQ layout"
```

---

## Self-Review Notes

- **Spec coverage:** Hero/medal/rank (Task 8), evolution chart (Task 6), roster with clause+forma+pts (Tasks 3, 4, 9), activity rail Option C (Tasks 5, 7), lineup-of-the-week reusing `HqWeekPicker`/`HqLineupPitch` with no new backend (Task 10) — all covered.
- **No placeholders:** every step has real code; no "add appropriate styling" or "similar to Task N" shortcuts.
- **Type consistency checked:** `resolveClauseStatus` return type (`ClauseStatus`) matches its three call-site branches in both `hq-player-property-card.tsx` and `roster-list.tsx`; `HqRecentScores`'s `size` prop default (`'md'`) preserves every existing caller's rendered output; `SeasonTeamPlayer.player.recent_scores` is populated server-side in Task 2 before `roster-list.tsx` (Task 9) reads it in Task 10 — ordering between tasks matters, execute in numeric order.
