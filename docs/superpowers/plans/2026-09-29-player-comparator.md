# Plan de implementación: comparador de jugadores

> **Para agentes:** SUB-SKILL OBLIGATORIA: usa superpowers:subagent-driven-development (elegido por el usuario) para implementar este plan tarea a tarea. Los pasos usan casillas (`- [ ]`) para el seguimiento.

**Objetivo:** comparar 2 o 3 jugadores en `/jugadores/comparar?ids=…&vista=a|b|c`, con tres vistas (A · Cara a cara, B · Pistas, C · Carriles), una selección que se hace desde las listas con una casilla y una bandeja fija abajo, y un veredicto (Fichar / Vender / Alinear) visible solo en modo god.

**Arquitectura:**
- Backend: un controlador nuevo `PlayerComparisonController` (el de jugadores ya pasa de 400 líneas) que valida los ids y entrega props perezosas (closures), así los cambios de selección y de vista son recargas parciales (`only`).
  - `App\Services\ComparedPlayers` reúne, en lote y reutilizando los traits y servicios de la ficha, el detalle de cada jugador comparado.
  - `App\Services\LeagueCloud` da una fila ligera por jugador listado de la temporada (la "nube" de B, la búsqueda del modal, las tarjetas), cacheada 15 min.
  - `App\Services\BuyoutClausePresenter` es el formato de cláusula que hoy vive dentro de `Api\ManagerController`, extraído y compartido.
- Frontend: la selección vive en un store de `localStorage` (`cmp-ids`) con `useSyncExternalStore`; la bandeja (`HqCompareTray`) se monta una vez en `AppLayout`. La página `players/compare.tsx` monta un contexto con los jugadores y sus derivados (`derive.ts`, funciones puras), un tooltip compartido (`HqChartTooltip`), el modal selector y las tres vistas en SVG propio.

**Stack:** Laravel 13 / PHP 8.5, Pest, Inertia v3 + React 19 + TypeScript + Tailwind v4, Wayfinder, lucide-react.

**Spec:** `docs/superpowers/specs/2026-09-29-player-comparator-design.md` (vinculante; léela entera antes de cada tarea, manda sobre este plan). Referencia visual e interactiva: `C:/Users/Uri/code/comando-lechuga-research/comparador/generator/src/final.html` (con `a.html`, `b.html`, `c.html`, `d.html`, `kit.js`, `kit.css`) y su copia viva `public/_comparador-final.html`. **No modifiques esos archivos.**

## Decisiones del usuario (2026-09-29): mandan sobre el texto de las tareas

1. **Añadir o cambiar jugador en las vistas B y C:** se usa **el mismo modal centrado de la vista A** (`picker-dialog`). Los chips con buscador de B y el "añadir carril" de C se sustituyen por un botón que abre ese modal. Las tres vistas se mantienen tal cual en todo lo demás.
2. **Bandeja (`HqCompareTray`) y casilla "comparar":** **solo** en el listado de jugadores (`players/index.tsx`), la ficha del equipo (`teams/show.tsx`), la plantilla del mánager (`season-managers`) y el inicio, en el mercado (punto 4). **No** se monta en `AppLayout` ni aparece en el resto de la web. La bandeja se monta en cada una de esas páginas.
3. **Con 3 jugadores ya elegidos**, "Comparar" (en la ficha del jugador o en el modal del jugador) **sustituye al tercero**.
4. **Mercado del inicio** (`pages/home/market-panel.tsx`): cada tarjeta de jugador en venta lleva **en la esquina superior derecha** el botón "comparar", igual que la casilla del listado.
   - Mide 22 px y tiene una zona clicable de 44 px.
   - No navega a la ficha y se marca en lima con `aria-pressed`. La tarjeta seleccionada lleva además un fondo lima suave.
   - Con 3 elegidos, el resto se desactiva ("Máximo 3 jugadores").
   - La **bandeja también se monta en la página de inicio** cuando hay selección. En móvil va por encima de la barra de navegación inferior.
   - Referencia visual aprobada por el usuario: `public/_mercado-comparar.html`, sin commitear.
   - **Ámbito final de la bandeja y la casilla:** el listado de jugadores, la ficha del equipo, la plantilla del mánager y el inicio (mercado).
5. **Botón "Comparar" en la ficha del jugador y en el modal del jugador:** se mantiene. Añade el jugador a la selección (`cmp-ids`). Si ya hay otro elegido, abre el comparador directamente; si no, navega al listado de jugadores con la bandeja visible.

## Restricciones globales

- **Dependencia previa:** la rama `feature/player-comparator` se crea desde `main` **después** de mergear `feature/team-strength`. Este plan asume sus interfaces tal y como las define `docs/superpowers/plans/2026-09-29-team-strength.md`:
  - `NextFixtureSlot` (web, `AttachesNextFixtures`) con `week_number`, `opponent`, `is_home`, `rival_position`, `date` (ISO 8601), `difficulty` (0–10, 10 = difícil; puede ser `null` para un partido sin fecha), `difficulty_variant` y `difficulty_components`;
  - `resources/js/lib/rival-difficulty.ts` con `rivalDifficultyLevel(d)` (< 3,5 fácil, < 6,5 media, resto difícil), `rivalDifficultyBars(d)`, `RIVAL_DIFFICULTY_LABELS`, clases por nivel y `formatDifficulty(d)` ("7,4");
  - `resources/js/components/hq-difficulty-bars.tsx` (`HqDifficultyBars`, opción B: 5 barras + número en mono, tooltip "Dificultad X,X / 10 · rival N.º Y · casa|fuera").
- **Dificultad en el comparador (requisito del usuario):** todo lo que pinte dificultad de partidos o rivales (A "Próximos 3", tarjetas de B, celdas futuras de C, tooltips, veredicto) **reutiliza exactamente** esos helpers, `HqDifficultyBars` y su tooltip, para que se vea igual que en el calendario, las fichas y las listas. **No se crea otro componente ni otra escala.** Nada de los umbrales ±0,45 ni de `diffLevel`/`diffBars` del mock: la escala es 0–10 (10 = difícil) y "mejor calendario" = **media más baja**. Si falta algo pequeño (por ejemplo un mapa de clases de texto por nivel), se añade en `rival-difficulty.ts`, no en el comparador.
- **Rutas y estado:** `GET /jugadores/comparar?ids=646,745,746&vista=a|b|c`, nombre `players.compare`, registrada **antes** de `/jugadores/{player}`. Hasta 3 ids distintos; los inexistentes, repetidos o mal formados se descartan en silencio; `ids` vacío es válido; `vista` por defecto `a`. Selección en `localStorage` `cmp-ids`; vista recordada en `localStorage` `cmp-vista`; todo acceso a `localStorage` va en `try/catch`.
- **Datos:** Media DAZN (A y C) **solo con notas oficiales** (`dazn_points !== null`). Pts/M€, subida 30 d y titularidad salen de `PlayerMarketMetrics` y `StartProbabilities`, sin duplicar lógica. `LeagueCloud` se cachea con `Cache::remember("league-cloud:{season}:{fecha-hora}", 15 min)`.
- **Veredicto:** solo en modo god, con el mecanismo existente (`HandleGodMode`, `?god_mode=<GOD_MODE_KEY>` + cookie). La prop compartida `godMode` de `HandleInertiaRequests` ya es exactamente "true solo con la clave correcta o su cookie": se reutiliza, no se añade otra.
- **Frontend:** sin librerías nuevas (SVG propio como el mock). Colores por hueco iguales en todas las vistas: hueco 0 `--color-hq-paper`, 1 `--color-hq-azure`, 2 `--color-hq-ember` (los `--s0/--s1/--s2` del mock). Se respeta `prefers-reduced-motion`. **Todo lo clicable lleva `cursor-pointer`.** Rutas en el frontend siempre con Wayfinder (`@/routes/players`); tras añadir la ruta, `php artisan wayfinder:generate` (los generados están en `.gitignore`).
- **PHP:** `declare(strict_types=1)`, tipos de retorno explícitos, llaves siempre, PHPDoc con array shapes, clases nuevas con `php artisan make:class --no-interaction` / `make:controller` / `make:test --pest`. Strings no-enum de base de datos: NOT NULL default `''` (este plan no crea columnas).
- **Commits:** un commit por tarea en `feature/player-comparator`, después de `vendor/bin/pint --dirty --format agent` y de `npm run format` sobre lo tocado; mensaje terminado en `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Nunca merge a `main` sin aceptación explícita del usuario.
- **Verificación:** cada tarea ejecuta sus tests (`php artisan test --compact <archivos>`) y, si toca frontend, `npm run types:check`, `npm run lint:check`, `npx prettier --check <archivos>` y `npm run build`. **No arranques `npm run dev`** (lo arranca el usuario). La verificación en navegador usa http://comando-lechuga.test con el build. Al final: suite completa (`php artisan test --compact`) y `composer analyze`.

## Review Focus

1. **Selección guardada obsoleta o corrupta** (`cmp-ids` con JSON roto, ids de jugadores que ya no existen o salieron de la liga, más de 3 entradas): la bandeja no revienta y el comparador descarta los ids malos y reescribe la URL y el store con los válidos. Test PHP en Task 1 (ids descartados); comprobación en navegador en Task 5 (JSON roto) y Task 7 (URL normalizada).
2. **Jornada 1 (`currentWeek = 1`)**: no hay jornadas pasadas; nada divide entre 0 ("% posibles" de minutos, medias), C muestra solo HOY + próximas y A "Últimas" queda en "—". Test PHP en Task 1 (`currentWeek`) y guardas en `derive.ts` (Task 7) comprobadas en navegador con una temporada en J1 simulada vía tinker **solo si el usuario lo aprueba**; si no, revisión de código.
3. **Jugador con datos ausentes**: sin histórico de valor (0 o 1 punto), sin próximos partidos, `difficulty` null, sin `next_start`, valor 0 (Pts/M€ null). Ninguna vista rompe: el gráfico muestra "Sin histórico suficiente", las pistas muestran "sin dato", los próximos muestran "–". Tests PHP en Task 3 y Task 4; revisión en Tasks 8–10.
4. **Enlace compartido raro** (`ids` con >3, repetidos, letras, `ids[]=`; `vista` inválida): se normaliza en silencio. Tests PHP en Task 1.
5. **Cambios rápidos de selección** (quitar y añadir seguidos, cambiar de vista mientras carga): cada visita parcial parte de las props actuales y usa `replace: true`; Inertia cancela la visita anterior. Comprobación manual en Task 7 y Task 8.

---

## Mapa de archivos

| Archivo | Responsabilidad | Tarea |
|---|---|---|
| `routes/web.php` | ruta `players.compare` antes de `/jugadores/{player}` | 1 |
| `app/Http/Controllers/PlayerComparisonController.php` | validación de ids/vista, props perezosas | 1, 3, 4 |
| `app/Services/BuyoutClausePresenter.php` | formato de cláusula y compra (API y comparador) | 2 |
| `app/Http/Controllers/Api/ManagerController.php` | usa el presenter | 2 |
| `app/Services/ComparedPlayers.php` | detalle en lote de 0–3 jugadores | 3 |
| `app/Services/LeagueCloud.php` | filas de la liga, cacheadas | 4 |
| `resources/js/types/models.ts` | `CompareView`, `ComparedPlayer`, `LeagueCloudRow`, `CompareManager` | 3, 4 |
| `resources/js/lib/compare-selection.ts` | store `cmp-ids`/`cmp-vista`, colores de hueco, `compareUrl`, `compareWith` | 5 |
| `resources/js/lib/use-media-query.ts` | hook `useMediaQuery` | 7 |
| `resources/js/components/compare/compare-toggle.tsx` | casilla "comparar" | 5 |
| `resources/js/components/compare/tray.tsx` | bandeja fija abajo | 5 |
| `resources/js/components/compare/compare-button.tsx` | botón "Comparar" de ficha y modal | 6 |
| `resources/js/components/hq-player-row.tsx` | prop `comparable` | 5 |
| `resources/js/pages/players/index.tsx`, `pages/teams/show.tsx`, `pages/season-managers/roster-list.tsx`, `pages/home/market-panel.tsx`, `pages/players/show.tsx`, `components/hq-player-stats-modal.tsx`, `layouts/app-layout.tsx` | entradas | 5, 6 |
| `resources/js/pages/players/compare.tsx` | página: cabecera, selector de vista, vacío, modal, veredicto | 1, 7, 11 |
| `resources/js/components/compare/derive.ts` | derivados puros, métricas de B, búsqueda, `verdict()` | 7, 11 |
| `resources/js/components/compare/compare-context.tsx` | contexto de la página | 7 |
| `resources/js/components/compare/use-comparison.ts` | visitas parciales, espejo en el store | 7 |
| `resources/js/components/compare/chart-tooltip.tsx` | `HqChartTooltip` + resaltado de jugador | 7 |
| `resources/js/components/compare/view-switch.tsx` | selector de 3 iconos | 7 |
| `resources/js/components/compare/picker-dialog.tsx` | modal selector | 7 |
| `resources/js/components/compare/property.tsx` | bloque y línea de propiedad | 7 |
| `resources/js/components/compare/value-chart.tsx` | gráfico de valor interactivo | 8 |
| `resources/js/components/compare/view-a.tsx` | vista A | 8 |
| `resources/js/components/compare/view-b.tsx` | vista B | 9 |
| `resources/js/components/compare/view-c.tsx` | vista C | 10 |
| `resources/js/components/compare/verdict.tsx` | veredicto (god) | 11 |
| `resources/css/app.css` | reglas del comparador (resaltado, animación del modal) | 7 |

---

### Task 1: Ruta, controlador y validación de ids

**Files:**
- Crear rama: `git switch main && git pull && git switch -c feature/player-comparator` (comprueba antes con `git log --oneline -5` que `feature/team-strength` ya está en `main`; si no, para y avisa).
- Crear: `app/Http/Controllers/PlayerComparisonController.php` (`php artisan make:controller PlayerComparisonController --no-interaction`)
- Modificar: `routes/web.php`
- Crear: `resources/js/pages/players/compare.tsx` (esqueleto; `ensure_pages_exist` está activo)
- Test: `tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php` (`php artisan make:test --pest PlayerComparisonControllerTest --no-interaction` y muévelo a `tests/Feature/Http/Controllers/`)

**Interfaces:**
- Consumes: `Season::current()`, `SeasonClock::weekState()`, `SeasonClock::NOT_STARTED`.
- Produces: la página Inertia `players/compare` con props `currentWeek: int`, `view: 'a'|'b'|'c'`, `ids: list<int>` (normalizados, en orden), `players: []`, `league: []` (se rellenan en Tasks 3 y 4), `managers: list<array{id:int,name:string,logo:string,color:string|null}>`. Constantes `PlayerComparisonController::MAX_PLAYERS = 3` y `VIEWS = ['a','b','c']`.

- [ ] **Step 1: tests que fallan**

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\SeasonManager;
use Inertia\Testing\AssertableInertia as Assert;

function comparisonSeason(array $attributes = []): Season
{
    return Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        ...$attributes,
    ]);
}

test('the comparator route does not collide with the player ficha route', function (): void {
    comparisonSeason();

    expect(route('players.compare', absolute: false))->toBe('/jugadores/comparar');

    $this->get('/jugadores/comparar')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('players/compare'));
});

test('without ids it renders the empty comparator on view a', function (): void {
    comparisonSeason();

    $this->get(route('players.compare'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('players/compare')
            ->where('ids', [])
            ->where('view', 'a')
            ->where('players', []));
});

test('keeps valid ids in the requested order and silently drops repeated, unknown and malformed ones', function (): void {
    comparisonSeason();
    $first = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $second = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $withoutFantasyId = Player::factory()->create(['fantasy_id' => null, 'status' => PlayerStatus::Ok]);
    $withoutSeason = Player::factory()->create(['status' => PlayerStatus::Ok]);
    PlayerSeason::query()->where('player_id', $withoutSeason->id)->delete();

    $ids = implode(',', [$second->id, 'abc', $first->id, $second->id, 999_999, $withoutFantasyId->id, $withoutSeason->id, '-3', '']);

    $this->get(route('players.compare', ['ids' => $ids]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('ids', [$second->id, $first->id]));
});

test('an ids array instead of a string is ignored', function (): void {
    comparisonSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);

    $this->get('/jugadores/comparar?ids[]='.$player->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('ids', []));
});

test('takes at most three players, the first three valid ones', function (): void {
    comparisonSeason();
    $players = Player::factory()->count(4)->create(['status' => PlayerStatus::Ok]);

    $this->get(route('players.compare', ['ids' => $players->pluck('id')->implode(',')]))
        ->assertInertia(fn (Assert $page): Assert => $page->where('ids', $players->take(3)->pluck('id')->all()));
});

test('honours vista b and c and falls back to a for anything else', function (string $vista, string $expected): void {
    comparisonSeason();

    $this->get(route('players.compare', ['vista' => $vista]))
        ->assertInertia(fn (Assert $page): Assert => $page->where('view', $expected));
})->with([
    ['b', 'b'],
    ['c', 'c'],
    ['a', 'a'],
    ['z', 'a'],
    ['B', 'a'],
]);

test('lists the active season managers with their colour', function (): void {
    $season = comparisonSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Gauchitos F.C', 'primary_color' => '#ff0000']);
    SeasonManager::factory()->create(['season_id' => Season::factory()->create()->id]);

    $this->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('managers', 1)
            ->where('managers.0.id', $manager->id)
            ->where('managers.0.name', 'Gauchitos F.C')
            ->where('managers.0.color', '#ff0000'));
});

test('currentWeek is the current jornada before it kicks off and the next one once it has', function (): void {
    $season = comparisonSeason(['current_week' => 5]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);

    $this->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('currentWeek', 5));

    $fixture->update(['date' => now()->subHour(), 'state' => FixtureState::FirstHalf]);

    $this->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('currentWeek', 6));
});
```

Nota: si `SeasonManager` no tiene `primary_color` en el factory o en `$fillable`, pásalo igualmente por `create()`: es una columna existente (la usa `AttachesOwnerManager`).

- [ ] **Step 2: ejecuta y comprueba que falla**

Run: `php artisan test --compact tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php`
Expected: FAIL (`Route [players.compare] not defined`).

- [ ] **Step 3: implementa**

`routes/web.php` (la ruta nueva va **antes** de `/jugadores/{player}`, y blindamos esa con `whereNumber`):

```php
use App\Http\Controllers\PlayerComparisonController;
// …
Route::get('/jugadores', [PlayersController::class, 'index'])->name('players.index');
Route::get('/jugadores/comparar', [PlayerComparisonController::class, 'show'])->name('players.compare');
Route::get('/jugadores/{player}', [PlayersController::class, 'show'])->whereNumber('player')->name('players.show');
```

`app/Http/Controllers/PlayerComparisonController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\SeasonClock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The player comparator (`/jugadores/comparar?ids=…&vista=a|b|c`). Every
 * heavy prop is a closure, so switching view or changing the selection is a
 * partial reload (`only`) that never recomputes what it doesn't need.
 */
class PlayerComparisonController extends Controller
{
    public const int MAX_PLAYERS = 3;

    /** @var list<string> */
    public const array VIEWS = ['a', 'b', 'c'];

    public function show(Request $request, SeasonClock $clock): Response
    {
        $season = Season::current();
        $ids = $this->comparableIds($this->requestedIds($request->query('ids')), $season);

        return Inertia::render('players/compare', [
            'currentWeek' => $this->comparisonWeek($season, $clock),
            'view' => $this->requestedView($request->query('vista')),
            'ids' => $ids,
            'players' => fn (): array => [],
            'league' => fn (): array => [],
            'managers' => fn (): array => $this->managers($season),
        ]);
    }

    /**
     * Positive integers from a comma-separated `ids`, first occurrence only.
     * Anything that is not a string (e.g. `ids[]=1`) counts as no ids.
     *
     * @return list<int>
     */
    private function requestedIds(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $id = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id === false || in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The requested ids that are league players of this season (a fantasy
     * id and season figures), in the requested order, at most three.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function comparableIds(array $ids, Season $season): array
    {
        if ($ids === []) {
            return [];
        }

        $existing = Player::query()
            ->whereIn('id', $ids)
            ->whereNotNull('fantasy_id')
            ->whereHas('seasons', fn ($query) => $query->where('season_id', $season->id))
            ->pluck('id')
            ->all();

        $valid = array_values(array_filter($ids, fn (int $id): bool => in_array($id, $existing, true)));

        return array_slice($valid, 0, self::MAX_PLAYERS);
    }

    private function requestedView(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, self::VIEWS, true) ? $raw : 'a';
    }

    /**
     * The first jornada that hasn't kicked off: past columns are 1…N−1
     * (a live jornada counts as past, so its scores and DAZN estimates show)
     * and the upcoming ones start at N — the "Titularidad J{N}" of the mock.
     */
    private function comparisonWeek(Season $season, SeasonClock $clock): int
    {
        return $clock->weekState($season, $season->current_week) === SeasonClock::NOT_STARTED
            ? $season->current_week
            : $season->current_week + 1;
    }

    /**
     * @return list<array{id: int, name: string, logo: string, color: string|null}>
     */
    private function managers(Season $season): array
    {
        return SeasonManager::query()
            ->where('season_id', $season->id)
            ->orderBy('name')
            ->get()
            ->map(fn (SeasonManager $manager): array => [
                'id' => $manager->id,
                'name' => $manager->name,
                'logo' => $manager->logo ? asset($manager->logo) : '',
                'color' => $manager->primary_color,
            ])
            ->values()
            ->all();
    }
}
```

`resources/js/pages/players/compare.tsx` (esqueleto, lo completa la Task 7):

```tsx
import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';

export default function PlayersCompare() {
    return (
        <div className="flex-1">
            <Head title="Comparador" />
        </div>
    );
}

PlayersCompare.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
```

- [ ] **Step 4: ejecuta y comprueba que pasa**

Run: `php artisan test --compact tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php tests/Feature/Http/Controllers/PlayersControllerTest.php`
Expected: PASS (la segunda comprueba que `players.show` sigue funcionando con `whereNumber`).

- [ ] **Step 5: Wayfinder, formato y commit**

```bash
php artisan wayfinder:generate
npm run types:check
vendor/bin/pint --dirty --format agent
git add routes/web.php app/Http/Controllers/PlayerComparisonController.php resources/js/pages/players/compare.tsx tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php
git commit -m "feat: add the player comparator route and id validation

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Helper compartido de cláusula (`BuyoutClausePresenter`)

**Files:**
- Crear: `app/Services/BuyoutClausePresenter.php` (`php artisan make:class Services/BuyoutClausePresenter --no-interaction`)
- Modificar: `app/Http/Controllers/Api/ManagerController.php` (`attachRoster`, L108-127)
- Test: `tests/Feature/Services/BuyoutClausePresenterTest.php`
- Test existente que debe seguir en verde: `tests/Feature/Http/Controllers/Api/ManagerControllerTest.php`, `ApiDocsDriftTest.php`

**Interfaces:**
- Produces:

```php
final class BuyoutClausePresenter {
    /** @return array{amount: int, locked_until: string, is_locked: bool, shielded: bool, shielded_until: string|null} */
    public static function clause(ManagerPlayer $entry): array;
    /** @return array{amount: int, type: string, occurred_at: string}|null */
    public static function purchase(?Activity $purchase): ?array;
}
```

- [ ] **Step 1: test que falla**

```php
<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Services\BuyoutClausePresenter;

test('a clause locked until the future is locked, with ISO dates', function (): void {
    $lockedUntil = now()->addDays(3)->startOfSecond();
    $entry = ManagerPlayer::factory()->create([
        'buyout_clause' => 12_500_000,
        'buyout_clause_locked_until' => $lockedUntil,
        'shielded' => false,
        'shielded_until' => null,
    ]);

    expect(BuyoutClausePresenter::clause($entry))->toBe([
        'amount' => 12_500_000,
        'locked_until' => $lockedUntil->toIso8601String(),
        'is_locked' => true,
        'shielded' => false,
        'shielded_until' => null,
    ]);
});

test('a clause whose lock is in the past is open, and a shield keeps its end date', function (): void {
    $shieldedUntil = now()->addDay()->startOfSecond();
    $entry = ManagerPlayer::factory()->create([
        'buyout_clause_locked_until' => now()->subMinute(),
        'shielded' => true,
        'shielded_until' => $shieldedUntil,
    ]);

    $clause = BuyoutClausePresenter::clause($entry);

    expect($clause['is_locked'])->toBeFalse()
        ->and($clause['shielded'])->toBeTrue()
        ->and($clause['shielded_until'])->toBe($shieldedUntil->toIso8601String());
});

test('the purchase is the signing or buyout amount, type and date, or null without one', function (): void {
    $occurredAt = now()->subWeek()->startOfSecond();
    $activity = Activity::factory()->create([
        'type' => SeasonActivityType::Buyout,
        'amount' => 9_000_000,
        'occurred_at' => $occurredAt,
    ]);

    expect(BuyoutClausePresenter::purchase($activity))->toBe([
        'amount' => 9_000_000,
        'type' => 'buyout',
        'occurred_at' => $occurredAt->toIso8601String(),
    ])->and(BuyoutClausePresenter::purchase(null))->toBeNull();
});
```

- [ ] **Step 2: ejecuta y comprueba que falla**

Run: `php artisan test --compact tests/Feature/Services/BuyoutClausePresenterTest.php`
Expected: FAIL (`Class "App\Services\BuyoutClausePresenter" not found`).

- [ ] **Step 3: implementa**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\ManagerPlayer;

/**
 * The one public shape of a buyout clause and of the purchase behind it,
 * shared by the manager API roster and the player comparator.
 */
final class BuyoutClausePresenter
{
    /**
     * @return array{amount: int, locked_until: string, is_locked: bool, shielded: bool, shielded_until: string|null}
     */
    public static function clause(ManagerPlayer $entry): array
    {
        return [
            'amount' => $entry->buyout_clause,
            'locked_until' => $entry->buyout_clause_locked_until->toIso8601String(),
            'is_locked' => $entry->buyout_clause_locked_until->isFuture(),
            'shielded' => $entry->shielded,
            'shielded_until' => $entry->shielded_until?->toIso8601String(),
        ];
    }

    /**
     * @return array{amount: int, type: string, occurred_at: string}|null
     */
    public static function purchase(?Activity $purchase): ?array
    {
        if (!$purchase instanceof Activity || $purchase->amount === null) {
            return null;
        }

        return [
            'amount' => (int) $purchase->amount,
            'type' => $purchase->type->value,
            'occurred_at' => $purchase->occurred_at->toIso8601String(),
        ];
    }
}
```

En `Api/ManagerController::attachRoster()` sustituye los dos arrays literales:

```php
            return [
                'player' => (new PlayerResource($entry->player))->resolve(),
                'purchase' => BuyoutClausePresenter::purchase($purchases->get($entry->player_id)),
                'buyout_clause' => BuyoutClausePresenter::clause($entry),
            ];
```

(y elimina la variable `$purchase` que queda sin uso; añade el `use App\Services\BuyoutClausePresenter;`).

- [ ] **Step 4: ejecuta y comprueba que pasa**

Run: `php artisan test --compact tests/Feature/Services/BuyoutClausePresenterTest.php tests/Feature/Http/Controllers/Api/ManagerControllerTest.php tests/Feature/Http/Controllers/Api/ApiDocsDriftTest.php tests/Feature/Http/Controllers/Api/ApiConsistencyTest.php`
Expected: PASS, sin cambios en la forma de la API.

- [ ] **Step 5: commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/BuyoutClausePresenter.php app/Http/Controllers/Api/ManagerController.php tests/Feature/Services/BuyoutClausePresenterTest.php
git commit -m "refactor: share the buyout clause format

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Detalle de los comparados (`ComparedPlayers`)

**Files:**
- Crear: `app/Services/ComparedPlayers.php` (`php artisan make:class Services/ComparedPlayers --no-interaction`)
- Modificar: `app/Http/Controllers/PlayerComparisonController.php` (prop `players`)
- Modificar: `resources/js/types/models.ts` (tipos nuevos)
- Test: `tests/Feature/Services/ComparedPlayersTest.php`, y un caso más en `PlayerComparisonControllerTest.php`

**Interfaces:**
- Consumes: `AttachesCurrentPlayerSeason`, `AttachesOwnerManager`, `AttachesNextFixtures` (con la dificultad 0–10 de team-strength; el slot trae `date`, `difficulty`, `difficulty_variant`, `difficulty_components`), `StartProbabilities::forPlayersNextFixture()`, `PlayerMarketMetrics::valueTrend()` y `::pointsPerMillionForPlayers()`, `DaznEstimatePresenter::present()`, `BuyoutClausePresenter` (Task 2), `MarketPlayer::SELLER_LEAGUE`.
- Produces: `ComparedPlayers::forIds(list<int> $ids, Season $season): list<ComparedPlayerShape>` con este shape (y su tipo TS `ComparedPlayer`):

```
id, name, image, position, status, team (Team),
value, difference, trend, value_trend_30d {multiple, value, date}|null,
market_history: list<[date 'Y-m-d', value]> (últimos 31, del más antiguo al más reciente),
points, average_points (float), points_per_million {value, rank, ranked}|null,
scores: list<{fixture_id, week_number, fixture_state, opponent (Team), is_home, points|null, minutes, starter,
              dazn_points, dazn_estimate, dazn_estimate_version, dazn_estimate_reasons, dazn_estimate_source}>,
next_fixtures: 3 × NextFixtureSlot|null, next_start: PlayerNextStart|null,
owner {id, name, logo, color}|null,
clause {amount, locked_until, is_locked, shielded, shielded_until, purchase {amount, type, occurred_at}|null}|null,
listing {sale_price, bids, expires_at, seller}|null
```

- [ ] **Step 1: tests que fallan**

`tests/Feature/Services/ComparedPlayersTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ComparedPlayers;
use App\Services\PlayerMarketMetrics;
use Illuminate\Support\Facades\DB;

function comparedSeason(): Season
{
    return Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
}

test('returns the players in the requested order with identity, market and performance figures', function (): void {
    $season = comparedSeason();
    $first = Player::factory()->create(['status' => PlayerStatus::Ok, 'points' => 80, 'average_points' => 6.15, 'market_value' => 20_000_000]);
    $second = Player::factory()->create(['status' => PlayerStatus::Injured, 'points' => 40, 'market_value' => 10_000_000]);

    $players = app(ComparedPlayers::class)->forIds([$second->id, $first->id], $season);

    expect($players)->toHaveCount(2)
        ->and($players[0]['id'])->toBe($second->id)
        ->and($players[0]['status'])->toBe('injured')
        ->and($players[1]['name'])->toBe($first->nickname)
        ->and($players[1]['value'])->toBe(20_000_000)
        ->and($players[1]['points'])->toBe(80)
        ->and($players[1]['average_points'])->toBe(6.15)
        ->and($players[1]['points_per_million']['value'])->toBe(4.0)
        ->and($players[1]['team']->id)->toBe($first->team_id);
});

test('the market history is the last 31 snapshots as date-value pairs, and the 30-day trend matches the ficha', function (): void {
    $season = comparedSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 14_000_000]);
    foreach (range(40, 0) as $daysAgo) {
        PlayerMarket::factory()->create([
            'player_id' => $player->id,
            'date' => now()->subDays($daysAgo)->toDateString(),
            'value' => 10_000_000 + (40 - $daysAgo) * 100_000,
        ]);
    }

    $compared = app(ComparedPlayers::class)->forIds([$player->id], $season)[0];
    $history = PlayerMarket::query()->where('player_id', $player->id)->orderBy('date')->get();

    expect($compared['market_history'])->toHaveCount(31)
        ->and($compared['market_history'][0])->toBe([now()->subDays(30)->toDateString(), 11_000_000])
        ->and($compared['market_history'][30])->toBe([now()->toDateString(), 14_000_000])
        ->and($compared['value_trend_30d'])->toBe(app(PlayerMarketMetrics::class)->valueTrend(14_000_000, $history));
});

test('a player without history, fixtures or start data gets empty and null figures', function (): void {
    $season = comparedSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 0, 'points' => 0]);

    $compared = app(ComparedPlayers::class)->forIds([$player->id], $season)[0];

    expect($compared['market_history'])->toBe([])
        ->and($compared['value_trend_30d'])->toBeNull()
        ->and($compared['points_per_million'])->toBeNull()
        ->and($compared['scores'])->toBe([])
        ->and($compared['next_fixtures'])->toBe([null, null, null])
        ->and($compared['next_start'])->toBeNull()
        ->and($compared['owner'])->toBeNull()
        ->and($compared['clause'])->toBeNull()
        ->and($compared['listing'])->toBeNull();
});

test('scores cover the season in jornada order, with minutes, rival side and DAZN only official once published', function (): void {
    $season = comparedSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $published = Fixture::factory()->daznPublished()->create([
        'season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Finished,
        'team_local_id' => $player->team_id,
    ]);
    $live = Fixture::factory()->create([
        'season_id' => $season->id, 'week_number' => 3, 'state' => FixtureState::SecondHalf,
        'team_guest_id' => $player->team_id,
    ]);
    $otherSeason = Fixture::factory()->create(['season_id' => Season::factory()->create()->id, 'week_number' => 1]);

    FixtureLineup::factory()->withDaznEstimate(points: 2)->create([
        'player_id' => $player->id, 'fixture_id' => $live->id, 'team_id' => $player->team_id,
        'starter' => false, 'fantasy_points' => 1, 'fantasy_stats' => ['mins_played' => [25, 1]],
    ]);
    FixtureLineup::factory()->withDaznEstimate(points: 1)->create([
        'player_id' => $player->id, 'fixture_id' => $published->id, 'team_id' => $player->team_id,
        'starter' => true, 'fantasy_points' => 9, 'fantasy_stats' => ['mins_played' => [90, 2], 'marca_points' => [3, 4]],
    ]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $otherSeason->id]);

    $scores = app(ComparedPlayers::class)->forIds([$player->id], $season)[0]['scores'];

    expect($scores)->toHaveCount(2)
        ->and($scores[0]['week_number'])->toBe(2)
        ->and($scores[0]['is_home'])->toBeTrue()
        ->and($scores[0]['opponent']->id)->toBe($published->team_guest_id)
        ->and($scores[0]['minutes'])->toBe(90)
        ->and($scores[0]['starter'])->toBeTrue()
        ->and($scores[0]['points'])->toBe(9)
        ->and($scores[0]['dazn_points'])->toBe(4)
        ->and($scores[1]['week_number'])->toBe(3)
        ->and($scores[1]['is_home'])->toBeFalse()
        ->and($scores[1]['fixture_state'])->toBe('second_half')
        ->and($scores[1]['minutes'])->toBe(25)
        ->and($scores[1]['dazn_points'])->toBeNull()
        ->and($scores[1]['dazn_estimate'])->toBe(2);
});

test('an owned player carries the owner, the clause and the owner purchase; a listed one the listing', function (): void {
    $season = comparedSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'primary_color' => '#00ff00']);
    $owned = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $listed = Player::factory()->create(['status' => PlayerStatus::Ok]);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $manager->id, 'player_id' => $owned->id,
        'buyout_clause' => 15_000_000, 'buyout_clause_locked_until' => now()->addDay(),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id, 'player_id' => $owned->id, 'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => SeasonManager::factory()->create(['season_id' => $season->id])->id,
        'amount' => 1_000_000, 'occurred_at' => now()->subWeeks(2),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id, 'player_id' => $owned->id, 'type' => SeasonActivityType::Buyout,
        'source_season_manager_id' => $manager->id, 'amount' => 8_000_000, 'occurred_at' => now()->subWeek(),
    ]);
    MarketPlayer::factory()->create(['player_id' => $listed->id, 'sale_price' => 5_000_000, 'bids' => 2]);

    [$ownedShape, $listedShape] = app(ComparedPlayers::class)->forIds([$owned->id, $listed->id], $season);

    expect($ownedShape['owner'])->toMatchArray(['id' => $manager->id, 'name' => $manager->name, 'color' => '#00ff00'])
        ->and($ownedShape['clause']['amount'])->toBe(15_000_000)
        ->and($ownedShape['clause']['is_locked'])->toBeTrue()
        ->and($ownedShape['clause']['purchase'])->toMatchArray(['amount' => 8_000_000, 'type' => 'buyout'])
        ->and($ownedShape['listing'])->toBeNull()
        ->and($listedShape['owner'])->toBeNull()
        ->and($listedShape['listing'])->toMatchArray(['sale_price' => 5_000_000, 'bids' => 2, 'seller' => 'league']);
});

test('the next fixtures carry the 0-10 difficulty with the variant of the player position', function (): void {
    $season = comparedSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => 'striker']);
    Fixture::factory()->create([
        'season_id' => $season->id, 'week_number' => 2, 'date' => now()->addDays(2),
        'state' => FixtureState::Scheduled, 'team_local_id' => $player->team_id,
    ]);

    $slot = app(ComparedPlayers::class)->forIds([$player->id], $season)[0]['next_fixtures'][0];

    expect($slot['week_number'])->toBe(2)
        ->and($slot['is_home'])->toBeTrue()
        ->and($slot)->toHaveKeys(['date', 'difficulty', 'difficulty_variant', 'difficulty_components', 'rival_position']);
});

test('runs the same number of queries for one player as for three', function (): void {
    $season = comparedSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $players = Player::factory()->count(3)->create(['status' => PlayerStatus::Ok]);
    foreach ($players as $player) {
        ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $player->id]);
        PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->toDateString()]);
        $fixture = Fixture::factory()->create(['season_id' => $season->id, 'state' => FixtureState::Finished, 'team_local_id' => $player->team_id]);
        FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'team_id' => $player->team_id]);
    }
    $service = app(ComparedPlayers::class);

    DB::enableQueryLog();
    $service->forIds([$players[0]->id], $season);
    $single = count(DB::getQueryLog());
    DB::flushQueryLog();
    $service->forIds($players->pluck('id')->all(), $season);
    $triple = count(DB::getQueryLog());

    expect($triple)->toBe($single);
});
```

En `PlayerComparisonControllerTest.php` añade:

```php
test('sends the compared players in the order of ids', function (): void {
    comparisonSeason();
    $first = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $second = Player::factory()->create(['status' => PlayerStatus::Ok]);

    $this->get(route('players.compare', ['ids' => "{$second->id},{$first->id}"]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('players', 2)
            ->where('players.0.id', $second->id)
            ->where('players.1.id', $first->id)
            ->has('players.0.scores')
            ->has('players.0.next_fixtures', 3));
});
```

- [ ] **Step 2: ejecuta y comprueba que falla**

Run: `php artisan test --compact tests/Feature/Services/ComparedPlayersTest.php tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php`
Expected: FAIL (`Class "App\Services\ComparedPlayers" not found`).

- [ ] **Step 3: implementa el servicio**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesNextFixtures;
use App\Http\Controllers\Concerns\AttachesOwnerManager;
use App\Models\Activity;
use App\Models\FixtureLineup;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Everything the comparator shows about each compared player, batched in a
 * fixed number of queries whatever the number of players. Reuses the ficha's
 * pieces rather than re-deriving them: the season figures and next fixtures
 * traits, StartProbabilities, PlayerMarketMetrics, DaznEstimatePresenter and
 * BuyoutClausePresenter.
 *
 * @phpstan-import-type PlayerNextStart from StartProbabilities
 */
final class ComparedPlayers
{
    use AttachesCurrentPlayerSeason;
    use AttachesNextFixtures;
    use AttachesOwnerManager;

    /** Snapshots sent for the value chart: today and the 30 days before. */
    public const int MARKET_HISTORY_DAYS = 31;

    public function __construct(
        private readonly PlayerMarketMetrics $marketMetrics,
        private readonly StartProbabilities $startProbabilities,
    ) {}

    /**
     * @param  list<int>  $ids  validated ids, in display order
     * @return list<array<string, mixed>>
     */
    public function forIds(array $ids, Season $season): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, Player> $players */
        $players = Player::query()
            ->with('team')
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Player $player): int => (int) array_search($player->id, $ids, true))
            ->values();

        $this->attachCurrentSeason($players, $season->id);
        $this->attachOwnerManager($players, $season->id);
        $this->attachNextFixtures($players, $season);

        $nextStarts = $this->startProbabilities->forPlayersNextFixture($players, $season);
        $pointsPerMillion = $this->marketMetrics->pointsPerMillionForPlayers(
            $players->filter(fn (Player $player): bool => $player->status !== PlayerStatus::OutOfLeague),
            $season,
        );

        $historyByPlayer = PlayerMarket::query()
            ->whereIn('player_id', $ids)
            ->orderBy('date')
            ->get()
            ->groupBy('player_id');

        $clauses = ManagerPlayer::query()
            ->whereIn('player_id', $ids)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get()
            ->keyBy('player_id');

        $purchasesByPlayer = Activity::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', $ids)
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Buyout])
            ->whereNotNull('amount')
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('player_id');

        $listings = MarketPlayer::query()->whereIn('player_id', $ids)->get()->keyBy('player_id');

        $lineupsByPlayer = FixtureLineup::query()
            ->whereIn('player_id', $ids)
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->with(['fixture.localTeam', 'fixture.guestTeam'])
            ->get()
            ->groupBy('player_id');

        return $players
            ->map(function (Player $player) use ($nextStarts, $pointsPerMillion, $historyByPlayer, $clauses, $purchasesByPlayer, $listings, $lineupsByPlayer): array {
                /** @var SupportCollection<int, PlayerMarket> $history */
                $history = $historyByPlayer->get($player->id) ?? new Collection;
                $clause = $clauses->get($player->id);
                $purchase = $clause === null ? null : ($purchasesByPlayer->get($player->id) ?? new Collection)
                    ->filter(fn (Activity $activity): bool => $activity->source_season_manager_id === $clause->season_manager_id)
                    ->last();
                $listing = $listings->get($player->id);
                $owner = $player->owner_manager;

                return [
                    'id' => $player->id,
                    'name' => $player->nickname,
                    'image' => $player->image !== '' ? asset('storage/'.$player->image) : '',
                    'position' => $player->position?->value,
                    'status' => $player->status->value,
                    'team' => $player->team,
                    'value' => $player->market_value,
                    'difference' => $player->market_value_difference,
                    'trend' => $player->market_trend?->value,
                    'value_trend_30d' => $this->marketMetrics->valueTrend($player->market_value, $history),
                    'market_history' => $history
                        ->slice(-self::MARKET_HISTORY_DAYS)
                        ->map(fn (PlayerMarket $snapshot): array => [$snapshot->date->toDateString(), $snapshot->value])
                        ->values()
                        ->all(),
                    'points' => $player->points,
                    'average_points' => (float) $player->average_points,
                    'points_per_million' => $pointsPerMillion[$player->id] ?? null,
                    'scores' => $this->scores($lineupsByPlayer->get($player->id) ?? new Collection),
                    'next_fixtures' => $player->next_fixtures,
                    'next_start' => $nextStarts[$player->id] ?? null,
                    'owner' => $owner === null ? null : [
                        'id' => $owner['id'],
                        'name' => $owner['name'],
                        'logo' => $owner['logo'],
                        'color' => $owner['primary_color'],
                    ],
                    'clause' => $clause === null ? null : [
                        ...BuyoutClausePresenter::clause($clause),
                        'purchase' => BuyoutClausePresenter::purchase($purchase),
                    ],
                    'listing' => $listing === null ? null : [
                        'sale_price' => $listing->sale_price,
                        'bids' => $listing->bids,
                        'expires_at' => $listing->expires_at->toIso8601String(),
                        'seller' => MarketPlayer::SELLER_LEAGUE,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One entry per lineup row of the season, in jornada order (kickoff
     * breaks a tie), with the same DAZN visibility rule as the ficha.
     *
     * @param  SupportCollection<int, FixtureLineup>  $lineups
     * @return list<array<string, mixed>>
     */
    private function scores(SupportCollection $lineups): array
    {
        return $lineups
            ->sortBy(fn (FixtureLineup $lineup): string => sprintf('%03d-%s', $lineup->fixture->week_number, $lineup->fixture->date->toIso8601String()))
            ->map(function (FixtureLineup $lineup): array {
                $fixture = $lineup->fixture;
                $isHome = $fixture->team_local_id === $lineup->team_id;

                return [
                    'fixture_id' => $fixture->id,
                    'week_number' => $fixture->week_number,
                    'fixture_state' => $fixture->state->value,
                    'opponent' => $isHome ? $fixture->guestTeam : $fixture->localTeam,
                    'is_home' => $isHome,
                    'points' => $lineup->fantasy_points,
                    'minutes' => (int) ($lineup->fantasy_stats['mins_played'][0] ?? 0),
                    'starter' => $lineup->starter,
                    ...DaznEstimatePresenter::present($lineup, $fixture),
                ];
            })
            ->values()
            ->all();
    }
}
```

Si phpstan se queja del tipo de `owner_manager` (el docblock de `Player` no declara `primary_color`), amplía ese docblock en `app/Models/Player.php` a `array{id: int, name: string, logo: string, primary_color: string|null}|null`.

- [ ] **Step 4: conecta el controlador**

```php
    public function show(Request $request, SeasonClock $clock, ComparedPlayers $comparedPlayers): Response
    {
        // …
            'players' => fn (): array => $comparedPlayers->forIds($ids, $season),
```

- [ ] **Step 5: tipos TS** en `resources/js/types/models.ts` (al final del archivo):

```ts
export type CompareView = 'a' | 'b' | 'c';

/** A fantasy manager as the comparator shows it (owner chip, league cloud colours). */
export interface CompareManager {
    id: number;
    name: string;
    logo: string;
    color: string | null;
}

/** One lineup row of a compared player this season (ComparedPlayers::scores). */
export interface ComparedPlayerScore extends DaznFields {
    fixture_id: number;
    week_number: number;
    fixture_state: FixtureState;
    opponent: Team;
    is_home: boolean;
    points: number | null;
    /** `fantasy_stats.mins_played[0]`, 0 when missing. */
    minutes: number;
    starter: boolean;
}

export interface ComparedPlayerClause {
    amount: number;
    locked_until: string;
    is_locked: boolean;
    shielded: boolean;
    shielded_until: string | null;
    /** The current owner's latest signing/buyout — null when he already had him on joining. */
    purchase: {
        amount: number;
        type: Extract<SeasonActivityType, 'signing' | 'buyout'>;
        occurred_at: string;
    } | null;
}

export interface ComparedPlayerListing {
    sale_price: number;
    bids: number;
    expires_at: string;
    seller: string;
}

/** Everything the comparator shows about one compared player (App\Services\ComparedPlayers). */
export interface ComparedPlayer {
    id: number;
    name: string;
    image: string;
    position: PlayerPosition;
    status: PlayerStatus;
    team: Team;
    value: number;
    difference: number;
    trend: MarketTrend | null;
    value_trend_30d: PlayerValueTrend | null;
    /** Up to 31 `[Y-m-d, value]` snapshots, oldest first. */
    market_history: [string, number][];
    points: number;
    average_points: number;
    points_per_million: PlayerPointsPerMillion | null;
    scores: ComparedPlayerScore[];
    next_fixtures: (NextFixtureSlot | null)[];
    next_start: PlayerNextStart | null;
    owner: CompareManager | null;
    clause: ComparedPlayerClause | null;
    listing: ComparedPlayerListing | null;
}
```

- [ ] **Step 6: ejecuta y comprueba que pasa**

Run: `php artisan test --compact tests/Feature/Services/ComparedPlayersTest.php tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php` y `npm run types:check`
Expected: PASS.

- [ ] **Step 7: commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/ComparedPlayers.php app/Http/Controllers/PlayerComparisonController.php app/Models/Player.php resources/js/types/models.ts tests/Feature/Services/ComparedPlayersTest.php tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php
git commit -m "feat: gather the compared players detail in batch

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Nube de la liga (`LeagueCloud`) con caché

**Files:**
- Crear: `app/Services/LeagueCloud.php`
- Modificar: `app/Http/Controllers/PlayerComparisonController.php` (prop `league`)
- Modificar: `resources/js/types/models.ts` (`LeagueCloudRow`)
- Test: `tests/Feature/Services/LeagueCloudTest.php`

**Interfaces:**
- Consumes: `AttachesCurrentPlayerSeason`, `PlayerMarketMetrics::pointsPerMillionForPlayers()` y `::valueTrend()`, `StartProbabilities::forPlayersNextFixture()`.
- Produces:

```php
final class LeagueCloud {
    public const int CACHE_MINUTES = 15;
    public const int VALUE_HISTORY_DAYS = 60;
    /** @return list<LeagueCloudRow> sorted by points desc */
    public function rows(Season $season): array;
    public function cacheKey(Season $season): string; // "league-cloud:{id}:{Y-m-d-H}"
    /** @param PlayerNextStart|null $start */
    public static function startValue(?array $start): ?int; // confirmed → 100/0, else probability, else null
}
```

`LeagueCloudRow` = `{id, name, image, position, team_short, owner_id|null, points, average_points (float), ppm (float|null), start_probability (int|null), value_trend_30d (float|null, el múltiplo), value, difference}`.

- [ ] **Step 1: tests que fallan**

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineupProbability;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\LeagueCloud;
use Illuminate\Support\Facades\DB;

function cloudSeason(): Season
{
    return Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
}

test('one row per listed league player of the season, sorted by points', function (): void {
    $season = cloudSeason();
    $low = Player::factory()->create(['status' => PlayerStatus::Ok, 'points' => 10]);
    $high = Player::factory()->create(['status' => PlayerStatus::Doubtful, 'points' => 90]);
    Player::factory()->create(['status' => PlayerStatus::OutOfLeague]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'fantasy_id' => null]);
    $noSeason = Player::factory()->create(['status' => PlayerStatus::Ok]);
    PlayerSeason::query()->where('player_id', $noSeason->id)->delete();

    $rows = app(LeagueCloud::class)->rows($season);

    expect(array_column($rows, 'id'))->toBe([$high->id, $low->id]);
});

test('a row carries the figures the tracks, search and hover card need', function (): void {
    $season = cloudSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create([
        'status' => PlayerStatus::Ok, 'points' => 50, 'average_points' => 5.5,
        'market_value' => 25_000_000, 'market_value_difference' => -120_000, 'position' => 'midfield',
    ]);
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $player->id]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(30)->toDateString(), 'value' => 20_000_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->toDateString(), 'value' => 25_000_000]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id, 'state' => FixtureState::Scheduled, 'date' => now()->addDay(),
        'team_local_id' => $player->team_id,
    ]);
    FixtureLineupProbability::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'probability' => 75]);

    $row = app(LeagueCloud::class)->rows($season)[0];

    expect($row)->toMatchArray([
        'id' => $player->id,
        'name' => $player->nickname,
        'position' => 'midfield',
        'team_short' => $player->team->short_name,
        'owner_id' => $manager->id,
        'points' => 50,
        'average_points' => 5.5,
        'ppm' => 2.0,
        'start_probability' => 75,
        'value_trend_30d' => 1.25,
        'value' => 25_000_000,
        'difference' => -120_000,
    ]);
});

test('a free player without value, history or start data has null metrics', function (): void {
    $season = cloudSeason();
    Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 0, 'points' => 0]);

    $row = app(LeagueCloud::class)->rows($season)[0];

    expect($row['owner_id'])->toBeNull()
        ->and($row['ppm'])->toBeNull()
        ->and($row['start_probability'])->toBeNull()
        ->and($row['value_trend_30d'])->toBeNull();
});

test('a confirmed lineup counts as 100 or 0 and wins over the probability', function (): void {
    expect(LeagueCloud::startValue(null))->toBeNull()
        ->and(LeagueCloud::startValue(['probability' => 40, 'confirmed_starter' => null]))->toBe(40)
        ->and(LeagueCloud::startValue(['probability' => 40, 'confirmed_starter' => true]))->toBe(100)
        ->and(LeagueCloud::startValue(['probability' => 95, 'confirmed_starter' => false]))->toBe(0);
});

test('the rows are cached for fifteen minutes', function (): void {
    $season = cloudSeason();
    Player::factory()->create(['status' => PlayerStatus::Ok]);
    $cloud = app(LeagueCloud::class);

    expect($cloud->rows($season))->toHaveCount(1);

    Player::factory()->create(['status' => PlayerStatus::Ok]);
    DB::enableQueryLog();
    expect($cloud->rows($season))->toHaveCount(1);
    expect(DB::getQueryLog())->toBe([]);

    $this->travel(16)->minutes();
    expect($cloud->rows($season))->toHaveCount(2);
});
```

Nota: `startValue` recibe el shape `PlayerNextStart` completo en producción; el test le pasa solo las dos claves que lee (anótalo con `@param array{probability: int|null, confirmed_starter: bool|null, ...}|null` o ajusta el test a un shape completo si phpstan lo exige).

- [ ] **Step 2: ejecuta y comprueba que falla**

Run: `php artisan test --compact tests/Feature/Services/LeagueCloudTest.php`
Expected: FAIL (`Class "App\Services\LeagueCloud" not found`).

- [ ] **Step 3: implementa**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerStatus;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * One light row per listed league player of the season (~556): the dot
 * clouds of the comparator's "Pistas", the picker's search and the hover
 * cards. Ranks, medians and "supera al X %" are computed on the client over
 * these rows. Loading the whole league's value trend is expensive, so the
 * rows are cached for 15 minutes.
 *
 * @phpstan-import-type PlayerNextStart from StartProbabilities
 *
 * @phpstan-type LeagueCloudRow array{id: int, name: string, image: string, position: string|null, team_short: string, owner_id: int|null, points: int, average_points: float, ppm: float|null, start_probability: int|null, value_trend_30d: float|null, value: int, difference: int}
 */
final class LeagueCloud
{
    use AttachesCurrentPlayerSeason;

    public const int CACHE_MINUTES = 15;

    /** Snapshots loaded per player: enough for the 30-day multiple, without the whole season. */
    public const int VALUE_HISTORY_DAYS = 60;

    public function __construct(
        private readonly PlayerMarketMetrics $marketMetrics,
        private readonly StartProbabilities $startProbabilities,
    ) {}

    /**
     * @return list<LeagueCloudRow>
     */
    public function rows(Season $season): array
    {
        /** @var list<LeagueCloudRow> */
        return Cache::remember(
            $this->cacheKey($season),
            now()->addMinutes(self::CACHE_MINUTES),
            fn (): array => $this->build($season),
        );
    }

    public function cacheKey(Season $season): string
    {
        return sprintf('league-cloud:%d:%s', $season->id, now()->format('Y-m-d-H'));
    }

    /**
     * A confirmed lineup is the truth (100 or 0); before that, FútbolFantasy's
     * probability; null without data (never 0 %).
     *
     * @param  PlayerNextStart|null  $start
     */
    public static function startValue(?array $start): ?int
    {
        if ($start === null) {
            return null;
        }

        if ($start['confirmed_starter'] !== null) {
            return $start['confirmed_starter'] ? 100 : 0;
        }

        return $start['probability'];
    }

    /**
     * @return list<LeagueCloudRow>
     */
    private function build(Season $season): array
    {
        /** @var Collection<int, Player> $players */
        $players = Player::query()
            ->with('team')
            ->whereNotNull('fantasy_id')
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->whereHas('seasons', fn ($query) => $query->where('season_id', $season->id))
            ->get();

        $this->attachCurrentSeason($players, $season->id);

        $players = $players->sortByDesc(fn (Player $player): int => $player->points)->values();
        $ids = $players->pluck('id')->all();

        $owners = ManagerPlayer::query()
            ->whereIn('player_id', $ids)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->pluck('season_manager_id', 'player_id');

        $pointsPerMillion = $this->marketMetrics->pointsPerMillionForPlayers($players, $season);
        $starts = $this->startProbabilities->forPlayersNextFixture($players, $season);

        $historyByPlayer = PlayerMarket::query()
            ->whereIn('player_id', $ids)
            ->where('date', '>=', now()->subDays(self::VALUE_HISTORY_DAYS)->toDateString())
            ->orderBy('date')
            ->get()
            ->groupBy('player_id');

        return $players
            ->map(fn (Player $player): array => [
                'id' => $player->id,
                'name' => $player->nickname,
                'image' => $player->image !== '' ? asset('storage/'.$player->image) : '',
                'position' => $player->position?->value,
                'team_short' => $player->team->short_name,
                'owner_id' => $owners->has($player->id) ? (int) $owners->get($player->id) : null,
                'points' => $player->points,
                'average_points' => (float) $player->average_points,
                'ppm' => $pointsPerMillion[$player->id]['value'] ?? null,
                'start_probability' => self::startValue($starts[$player->id] ?? null),
                'value_trend_30d' => $this->marketMetrics->valueTrend(
                    $player->market_value,
                    $historyByPlayer->get($player->id) ?? new Collection,
                )['multiple'] ?? null,
                'value' => $player->market_value,
                'difference' => $player->market_value_difference,
            ])
            ->values()
            ->all();
    }
}
```

Controlador:

```php
    public function show(Request $request, SeasonClock $clock, ComparedPlayers $comparedPlayers, LeagueCloud $leagueCloud): Response
    // …
            'league' => fn (): array => $leagueCloud->rows($season),
```

Tipo TS en `models.ts`:

```ts
/** One listed league player for the comparator's clouds, search and hover cards (App\Services\LeagueCloud). */
export interface LeagueCloudRow {
    id: number;
    name: string;
    image: string;
    position: PlayerPosition;
    team_short: string;
    owner_id: number | null;
    points: number;
    average_points: number;
    /** Points per million of value; null without a value. */
    ppm: number | null;
    /** Confirmed lineup as 100/0, else FútbolFantasy's %, else null. */
    start_probability: number | null;
    /** The 30-day value multiple (×1,25), null without 30 days of history. */
    value_trend_30d: number | null;
    value: number;
    difference: number;
}
```

- [ ] **Step 4: ejecuta y comprueba que pasa**

Run: `php artisan test --compact tests/Feature/Services/LeagueCloudTest.php tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php` y `npm run types:check`
Expected: PASS.

- [ ] **Step 5: commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/LeagueCloud.php app/Http/Controllers/PlayerComparisonController.php resources/js/types/models.ts tests/Feature/Services/LeagueCloudTest.php
git commit -m "feat: add the cached league cloud for the comparator

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Selección, casilla y bandeja (listado de jugadores)

**Files:**
- Crear: `resources/js/lib/compare-selection.ts`
- Crear: `resources/js/components/compare/compare-toggle.tsx`
- Crear: `resources/js/components/compare/tray.tsx`
- Modificar: `resources/js/components/hq-player-row.tsx` (prop `comparable`)
- Modificar: `resources/js/pages/players/index.tsx`
- Modificar: `resources/js/layouts/app-layout.tsx` (monta la bandeja)

**Interfaces:**
- Consumes: `compare` de `@/routes/players` (Wayfinder, Task 1), `PLAYER_SEARCH_INPUT_ID`, `HqTooltip`, `EntityImage`, `CompareView` (Task 3).
- Produces (en `@/lib/compare-selection`):

```ts
export const COMPARE_MAX = 3;
export const COMPARE_SLOT_COLORS: readonly [string, string, string];
export interface CompareEntry { id: number; name: string; image: string }
export function useCompareSelection(): CompareEntry[];
export function toggleCompare(entry: CompareEntry): void;
export function removeFromCompare(id: number): void;
export function clearCompare(): void;
export function replaceCompare(entries: CompareEntry[]): void;
export function rememberedCompareView(): CompareView | null;
export function rememberCompareView(view: CompareView): void;
export function compareUrl(ids: number[], view?: CompareView): string;
export function compareWith(entry: CompareEntry): 'opened' | 'waiting';
```
  y los componentes `HqCompareToggle({ player: CompareEntry; className?: string })` y `HqCompareTray()`.

**Decisión de este plan:** `cmp-ids` guarda entradas `{id, name, image}` (no solo ids): la bandeja pinta foto y nombre en cualquier página sin pedir nada al servidor. La bandeja se monta una vez en `AppLayout` (se ve en cualquier página mientras haya selección) y se oculta en la propia página del comparador.

- [ ] **Step 1: el store** `resources/js/lib/compare-selection.ts`

```ts
import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { compare as playersCompare } from '@/routes/players';
import type { CompareView } from '@/types/models';

/** Most players the comparator takes at once. */
export const COMPARE_MAX = 3;

/** One colour per comparator slot, the same in the tray and every view (mock `--s0/--s1/--s2`). */
export const COMPARE_SLOT_COLORS = [
    'var(--color-hq-paper)',
    'var(--color-hq-azure)',
    'var(--color-hq-ember)',
] as const;

const SELECTION_KEY = 'cmp-ids';
const VIEW_KEY = 'cmp-vista';
const CHANGE_EVENT = 'cmp-selection-change';

/** What the tray needs to draw a chosen player without asking the server. */
export interface CompareEntry {
    id: number;
    name: string;
    image: string;
}

const EMPTY: CompareEntry[] = [];
let current: CompareEntry[] | null = null;

/** Tolerant of anything a previous version, another tab or a user left behind. */
function parse(raw: string | null): CompareEntry[] {
    if (raw === null) {
        return EMPTY;
    }

    try {
        const value: unknown = JSON.parse(raw);

        if (!Array.isArray(value)) {
            return EMPTY;
        }

        const entries: CompareEntry[] = [];

        for (const item of value) {
            if (typeof item !== 'object' || item === null) {
                continue;
            }

            const { id, name, image } = item as Record<string, unknown>;

            if (
                typeof id !== 'number' ||
                !Number.isInteger(id) ||
                id <= 0 ||
                typeof name !== 'string' ||
                typeof image !== 'string' ||
                entries.some((entry) => entry.id === id)
            ) {
                continue;
            }

            entries.push({ id, name, image });
        }

        return entries.slice(0, COMPARE_MAX);
    } catch {
        return EMPTY;
    }
}

function readStorage(key: string): string | null {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeStorage(key: string, value: string): void {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Private mode or blocked storage: the selection lives in memory only.
    }
}

function snapshot(): CompareEntry[] {
    if (current === null) {
        current = parse(readStorage(SELECTION_KEY));
    }

    return current;
}

function commit(next: CompareEntry[]): void {
    current = next.slice(0, COMPARE_MAX);
    writeStorage(SELECTION_KEY, JSON.stringify(current));
    window.dispatchEvent(new Event(CHANGE_EVENT));
}

function subscribe(onChange: () => void): () => void {
    const onStorage = (event: StorageEvent) => {
        if (event.key === SELECTION_KEY) {
            current = parse(event.newValue);
            onChange();
        }
    };

    window.addEventListener(CHANGE_EVENT, onChange);
    window.addEventListener('storage', onStorage);

    return () => {
        window.removeEventListener(CHANGE_EVENT, onChange);
        window.removeEventListener('storage', onStorage);
    };
}

/** The players chosen for the comparator, shared by every row, the tray and the comparator itself. */
export function useCompareSelection(): CompareEntry[] {
    return useSyncExternalStore(subscribe, snapshot, () => EMPTY);
}

export function toggleCompare(entry: CompareEntry): void {
    const entries = snapshot();

    if (entries.some((item) => item.id === entry.id)) {
        commit(entries.filter((item) => item.id !== entry.id));

        return;
    }

    if (entries.length < COMPARE_MAX) {
        commit([...entries, entry]);
    }
}

export function removeFromCompare(id: number): void {
    commit(snapshot().filter((entry) => entry.id !== id));
}

export function clearCompare(): void {
    commit([]);
}

/** Mirrors the comparator's players into the selection — a no-op when nothing changed. */
export function replaceCompare(entries: CompareEntry[]): void {
    const before = snapshot();
    const same =
        before.length === entries.length &&
        before.every(
            (entry, index) =>
                entry.id === entries[index].id &&
                entry.name === entries[index].name &&
                entry.image === entries[index].image,
        );

    if (!same) {
        commit(entries);
    }
}

export function rememberedCompareView(): CompareView | null {
    const view = readStorage(VIEW_KEY);

    return view === 'a' || view === 'b' || view === 'c' ? view : null;
}

export function rememberCompareView(view: CompareView): void {
    writeStorage(VIEW_KEY, view);
}

export function compareUrl(ids: number[], view?: CompareView): string {
    return playersCompare.url({
        query: {
            ids: ids.join(','),
            vista: view ?? rememberedCompareView() ?? undefined,
        },
    });
}

/**
 * The ficha's and the jornada modal's "Comparar": adds the player (taking
 * the last slot when all three are used) and opens the comparator as soon
 * as there are two; otherwise the tray stays up asking for another one.
 */
export function compareWith(entry: CompareEntry): 'opened' | 'waiting' {
    const entries = snapshot();
    const next = entries.some((item) => item.id === entry.id)
        ? entries
        : entries.length >= COMPARE_MAX
          ? [...entries.slice(0, COMPARE_MAX - 1), entry]
          : [...entries, entry];

    commit(next);

    if (next.length >= 2) {
        router.visit(compareUrl(next.map((item) => item.id)));

        return 'opened';
    }

    return 'waiting';
}
```

(Comprueba con `npm run types:check` la firma real de `playersCompare.url({ query })` en `resources/js/routes/players/index.ts`; Wayfinder omite las claves `undefined`.)

- [ ] **Step 2: la casilla** `resources/js/components/compare/compare-toggle.tsx`

```tsx
import { Check, Plus } from 'lucide-react';
import type { CSSProperties, MouseEvent } from 'react';
import { HqTooltip } from '@/components/hq-tooltip';
import {
    COMPARE_MAX,
    COMPARE_SLOT_COLORS,
    toggleCompare,
    useCompareSelection,
} from '@/lib/compare-selection';
import type { CompareEntry } from '@/lib/compare-selection';
import { cn } from '@/lib/utils';

interface HqCompareToggleProps {
    player: CompareEntry;
    className?: string;
}

/**
 * The "comparar" box of a player row (mock `.cmpbtn`). It never lets the
 * row's own click (open the ficha) fire, and it is disabled — with a tooltip
 * saying why — once three other players are chosen.
 */
export function HqCompareToggle({ player, className }: HqCompareToggleProps) {
    const selection = useCompareSelection();
    const slot = selection.findIndex((entry) => entry.id === player.id);
    const selected = slot >= 0;
    const full = !selected && selection.length >= COMPARE_MAX;

    const handleClick = (event: MouseEvent<HTMLButtonElement>) => {
        event.stopPropagation();
        event.preventDefault();

        if (!full) {
            toggleCompare(player);
        }
    };

    const button = (
        <button
            type="button"
            onClick={handleClick}
            aria-pressed={selected}
            aria-disabled={full || undefined}
            aria-label={`Comparar ${player.name}`}
            style={
                selected
                    ? ({ '--slot': COMPARE_SLOT_COLORS[slot] } as CSSProperties)
                    : undefined
            }
            className={cn(
                'relative flex size-8 shrink-0 items-center justify-center before:absolute before:-inset-1.5 before:content-[""]',
                full ? 'cursor-not-allowed opacity-40' : 'cursor-pointer',
                className,
            )}
        >
            <span
                className={cn(
                    'flex size-[22px] items-center justify-center border transition-colors',
                    selected
                        ? 'border-(--slot) bg-(--slot) text-hq-ink'
                        : 'border-hq-border-bright text-hq-moss hover:border-hq-lime hover:text-hq-lime',
                )}
            >
                {selected ? (
                    <Check aria-hidden="true" className="size-3.5" strokeWidth={3} />
                ) : (
                    <Plus aria-hidden="true" className="size-3" strokeWidth={2.4} />
                )}
            </span>
        </button>
    );

    return full ? (
        <HqTooltip label="Máximo 3 jugadores">{button}</HqTooltip>
    ) : (
        button
    );
}
```

- [ ] **Step 3: la bandeja** `resources/js/components/compare/tray.tsx`

```tsx
import { router } from '@inertiajs/react';
import { ArrowRight, Plus, User, X } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useEffect, useRef, useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import {
    COMPARE_MAX,
    COMPARE_SLOT_COLORS,
    clearCompare,
    compareUrl,
    removeFromCompare,
    useCompareSelection,
} from '@/lib/compare-selection';
import { PLAYER_SEARCH_INPUT_ID } from '@/lib/player-search';
import { cn } from '@/lib/utils';

/**
 * The fixed bottom tray of the comparator's entry (mock D `.tray`): up to
 * three chosen players, each with its own ×, "Vaciar" and "Comparar N →".
 * Hidden without a selection; above the phone bottom bar. Removing a player
 * moves focus to the next × or, when none is left, to the players search.
 */
export function HqCompareTray() {
    const selection = useCompareSelection();
    const [announcement, setAnnouncement] = useState('');
    const removeRefs = useRef<(HTMLButtonElement | null)[]>([]);
    const focusAfterRemove = useRef<number | null>(null);
    const announced = useRef(false);

    useEffect(() => {
        if (focusAfterRemove.current === null) {
            return;
        }

        const index = Math.min(focusAfterRemove.current, selection.length - 1);
        focusAfterRemove.current = null;
        const target =
            index >= 0
                ? removeRefs.current[index]
                : document.getElementById(PLAYER_SEARCH_INPUT_ID);
        target?.focus({ preventScroll: true });
    }, [selection]);

    useEffect(() => {
        // The first render only restores a saved selection: nothing to announce.
        if (!announced.current) {
            announced.current = true;

            return;
        }

        setAnnouncement(
            selection.length > 0
                ? `${selection.length} de ${COMPARE_MAX} en el comparador: ${selection.map((entry) => entry.name).join(', ')}`
                : 'Comparador vacío',
        );
    }, [selection]);

    const remove = (index: number, id: number) => {
        focusAfterRemove.current = index;
        removeFromCompare(id);
    };

    return (
        <>
            <p className="sr-only" aria-live="polite">
                {announcement}
            </p>
            {selection.length > 0 && (
                <>
                    <div aria-hidden="true" className="h-16" />
                    <div
                        role="region"
                        aria-label="Jugadores para comparar"
                        className="fixed inset-x-0 bottom-[calc(64px+env(safe-area-inset-bottom))] z-[60] border-t border-hq-border-bright bg-hq-ink/96 backdrop-blur-[6px] lg:bottom-0"
                    >
                        <div className="mx-auto flex max-w-[1440px] items-center gap-1.5 px-2.5 py-2 sm:gap-3 sm:px-4">
                            <span className="hidden hq-label sm:inline">
                                Comparador
                            </span>
                            <ul className="flex min-w-0 gap-1 sm:gap-2">
                                {Array.from({ length: COMPARE_MAX }, (_, index) => {
                                    const entry = selection[index];

                                    if (!entry) {
                                        return (
                                            <li
                                                key={`empty-${index}`}
                                                aria-hidden="true"
                                                className="hidden size-9 items-center justify-center border border-dashed border-hq-border-strong text-hq-led-off sm:flex"
                                            >
                                                <Plus className="size-3" />
                                            </li>
                                        );
                                    }

                                    return (
                                        <li
                                            key={entry.id}
                                            style={{ '--slot': COMPARE_SLOT_COLORS[index] } as CSSProperties}
                                            className="flex min-w-0 items-center border border-l-2 border-hq-border-strong border-l-(--slot) bg-hq-panel"
                                        >
                                            <EntityImage
                                                src={entry.image}
                                                alt=""
                                                fallback={User}
                                                shape="square"
                                                className="ml-1 size-7 shrink-0 rounded-none object-cover object-top sm:ml-0 sm:size-8"
                                            />
                                            <b className="hidden max-w-[16ch] truncate px-2 text-xs font-extrabold text-hq-paper uppercase sm:block">
                                                {entry.name}
                                            </b>
                                            <button
                                                ref={(element) => {
                                                    removeRefs.current[index] = element;
                                                }}
                                                type="button"
                                                onClick={() => remove(index, entry.id)}
                                                aria-label={`Quitar ${entry.name}`}
                                                className="flex h-11 w-9 cursor-pointer items-center justify-center self-stretch border-l border-hq-border text-hq-moss-dim hover:bg-hq-panel-alt hover:text-hq-live sm:h-auto sm:w-8"
                                            >
                                                <X aria-hidden="true" className="size-3.5" />
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                            {selection.length === 1 && (
                                <span className="min-w-0 flex-1 truncate font-mono text-[11px] text-hq-moss">
                                    Añade otro jugador para comparar
                                </span>
                            )}
                            <button
                                type="button"
                                onClick={() => {
                                    clearCompare();
                                    document.getElementById(PLAYER_SEARCH_INPUT_ID)?.focus({ preventScroll: true });
                                }}
                                className="ml-auto hidden h-11 cursor-pointer items-center px-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase hover:text-hq-paper min-[480px]:inline-flex sm:h-9"
                            >
                                Vaciar
                            </button>
                            <button
                                type="button"
                                disabled={selection.length < 2}
                                title={selection.length < 2 ? 'Elige al menos 2 jugadores' : undefined}
                                onClick={() => router.visit(compareUrl(selection.map((entry) => entry.id)))}
                                className={cn(
                                    'inline-flex h-11 shrink-0 items-center gap-2 px-3.5 font-mono text-[11.5px] font-bold tracking-[0.06em] uppercase sm:h-9',
                                    selection.length < 2
                                        ? 'cursor-not-allowed border border-hq-border-strong text-hq-led-off'
                                        : 'ml-auto cursor-pointer bg-hq-lime text-hq-ink hover:brightness-110 min-[480px]:ml-0',
                                )}
                            >
                                Comparar {selection.length}
                                <ArrowRight aria-hidden="true" className="size-3.5" strokeWidth={2.6} />
                            </button>
                        </div>
                    </div>
                </>
            )}
        </>
    );
}
```

Si `react-hooks` (v7) se queja de `set-state-in-effect` por el `setAnnouncement`, calcula el texto en render (`const announcement = …`) y deja en el efecto solo el flag "ya anunciado" con un `useRef` + una key en el `<p>`; el objetivo es que un cambio de selección se anuncie y la carga inicial no.

- [ ] **Step 4: montar la bandeja** en `resources/js/layouts/app-layout.tsx`:

```tsx
import { HqCompareTray } from '@/components/compare/tray';
// …
    const { season, godMode } = usePage().props;
    const { component } = usePage();
// … justo después de </main>:
            {component !== 'players/compare' && <HqCompareTray />}
```

- [ ] **Step 5: `PlayerRow` con `comparable`** en `resources/js/components/hq-player-row.tsx`. Añade la columna de la casilla antes de la foto, en escritorio y en móvil:

```tsx
import { HqCompareToggle } from '@/components/compare/compare-toggle';

interface PlayerRowLayout {
    showTeam?: boolean;
    showPosition?: boolean;
    /** Shows the "comparar" box before the photo (players list, team roster). */
    comparable?: boolean;
}

/** Same columns as ROW_GRID with the 32px "comparar" box in front. Literal strings for Tailwind. */
const COMPARABLE_ROW_GRID = {
    full: 'lg:grid-cols-[32px_46px_minmax(140px,1.4fr)_44px_76px_minmax(110px,1fr)_118px_120px_minmax(124px,0.9fr)_48px]',
    noPosition:
        'lg:grid-cols-[32px_46px_minmax(140px,1.4fr)_76px_minmax(110px,1fr)_118px_120px_minmax(124px,0.9fr)_48px]',
    noTeam: 'lg:grid-cols-[32px_46px_minmax(140px,1.4fr)_44px_76px_118px_120px_minmax(124px,0.9fr)_48px]',
    compact:
        'lg:grid-cols-[32px_46px_minmax(140px,1.4fr)_76px_118px_120px_minmax(124px,0.9fr)_48px]',
} as const;

function rowGrid({
    showTeam = true,
    showPosition = true,
    comparable = false,
}: PlayerRowLayout): string {
    const grid = comparable ? COMPARABLE_ROW_GRID : ROW_GRID;

    if (showTeam) {
        return showPosition ? grid.full : grid.noPosition;
    }

    return showPosition ? grid.noTeam : grid.compact;
}
```

- `PlayerRowHeader` acepta `comparable` y, si lo es, pinta un `<span />` extra al principio.
- `PlayerRow` acepta `comparable = false`:
  - la clase base del contenedor pasa a `comparable ? 'grid-cols-[32px_40px_minmax(0,1fr)_auto]' : 'grid-cols-[40px_minmax(0,1fr)_auto]'` (el resto de clases igual);
  - primer hijo, si `comparable`: `<HqCompareToggle player={{ id: player.id, name: player.nickname, image: player.image }} className="self-center" />`;
  - la celda de puntos pasa de `col-start-3` a `comparable ? 'col-start-4' : 'col-start-3'`;
  - "Últimas 3" pasa de `col-span-2` a `comparable ? 'col-span-3' : 'col-span-2'`.
- Pásale `comparable` a `PlayerRowHeader` y `PlayerRow` en `pages/players/index.tsx`.

- [ ] **Step 6: comprobaciones**

Run: `npm run types:check && npm run lint:check && npx prettier --check resources/js/lib/compare-selection.ts resources/js/components/compare resources/js/components/hq-player-row.tsx resources/js/pages/players/index.tsx resources/js/layouts/app-layout.tsx && npm run build`
Expected: todo en verde.

- [ ] **Step 7: navegador** (http://comando-lechuga.test/jugadores, escritorio y 390 px):
  - marcar 1, 2, 3 jugadores: la casilla toma el color del hueco, la cuarta queda desactivada con tooltip "Máximo 3 jugadores";
  - la fila sigue abriendo la ficha, la casilla no;
  - paginar y filtrar conserva la selección; la bandeja muestra foto (y nombre desde `sm`), × quita y desmarca, el foco pasa al siguiente × o al buscador; "Vaciar" se oculta por debajo de 480 px; "Comparar 1" desactivado con "Añade otro jugador para comparar";
  - la bandeja no tapa la barra inferior del móvil ni la última fila;
  - **Review Focus 1:** en consola, `localStorage.setItem('cmp-ids', '{roto')` y recarga: la bandeja no aparece y no hay errores.

- [ ] **Step 8: commit**

```bash
git add resources/js/lib/compare-selection.ts resources/js/components/compare resources/js/components/hq-player-row.tsx resources/js/pages/players/index.tsx resources/js/layouts/app-layout.tsx
git commit -m "feat: pick players to compare from the players list

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Resto de entradas (mercado, plantilla del mánager, ficha del equipo, ficha del jugador y modal)

**Files:**
- Crear: `resources/js/components/compare/compare-button.tsx`
- Modificar: `resources/js/pages/teams/show.tsx` (L406-425)
- Modificar: `resources/js/pages/season-managers/roster-list.tsx` (`ROW_GRID`, cabecera, `RosterRow`)
- Modificar: `resources/js/pages/home/market-panel.tsx` (`MarketCard`, L187-288)
- Modificar: `resources/js/pages/players/show.tsx` (cabecera, L261-297)
- Modificar: `resources/js/components/hq-player-stats-modal.tsx` (pie, L244)

**Interfaces:**
- Consumes: `HqCompareToggle`, `compareWith`, `useCompareSelection`, `COMPARE_MAX` (Task 5).
- Produces: `HqCompareButton({ player: CompareEntry; onWaiting?: () => void; className?: string })`.

- [ ] **Step 1: el botón** `resources/js/components/compare/compare-button.tsx`

```tsx
import { Columns3 } from 'lucide-react';
import { compareWith, COMPARE_MAX, useCompareSelection } from '@/lib/compare-selection';
import type { CompareEntry } from '@/lib/compare-selection';
import { cn } from '@/lib/utils';

interface HqCompareButtonProps {
    player: CompareEntry;
    /** Called when the player was added but there is no second one yet (the modal closes itself so the tray shows). */
    onWaiting?: () => void;
    className?: string;
}

/**
 * "Comparar" on the player ficha and the jornada modal: adds the player and
 * opens the comparator once there are two; with three already chosen, the
 * player takes the last slot.
 */
export function HqCompareButton({ player, onWaiting, className }: HqCompareButtonProps) {
    const selection = useCompareSelection();
    const others = selection.filter((entry) => entry.id !== player.id).length;
    const label = others > 0 ? `Comparar (${Math.min(others + 1, COMPARE_MAX)})` : 'Comparar';

    return (
        <button
            type="button"
            onClick={() => {
                if (compareWith(player) === 'waiting') {
                    onWaiting?.();
                }
            }}
            className={cn(
                'inline-flex h-11 cursor-pointer items-center gap-2 border border-hq-border-strong px-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase transition-colors hover:border-hq-lime hover:text-hq-lime sm:h-9',
                className,
            )}
        >
            <Columns3 aria-hidden="true" className="size-3.5" />
            {label}
        </button>
    );
}
```

- [ ] **Step 2: ficha del jugador** (`pages/players/show.tsx`): en el bloque de identidad de la cabecera (tras el `div` con posición, club y estado), añade `<HqCompareButton player={{ id: player.id, name: player.nickname, image: player.image }} className="mt-3" />`. La bandeja ya se monta en el layout, así que con un solo jugador queda visible con "Añade otro jugador para comparar".

- [ ] **Step 3: modal del jugador** (`hq-player-stats-modal.tsx`): convierte el pie en una fila con dos acciones: `HqCompareButton` (con `onWaiting={onClose}`) a la izquierda y el enlace "Ver ficha completa" a la derecha, ambos de 44 px de alto en móvil.

- [ ] **Step 4: ficha del equipo** (`pages/teams/show.tsx`): pasa `comparable` a `PlayerRowHeader` y a cada `PlayerRow` de la plantilla.

- [ ] **Step 5: plantilla del mánager** (`roster-list.tsx`), la misma columna que `PlayerRow`:

```tsx
const ROW_GRID =
    'lg:grid-cols-[32px_46px_minmax(130px,1.1fr)_minmax(170px,1.25fr)_96px_104px_minmax(118px,0.9fr)_50px] lg:gap-3';
```
  - la cabecera gana un `<span />` inicial;
  - `RosterRow`: la clase base pasa a `grid-cols-[32px_40px_minmax(0,1fr)_auto]`; primer hijo `<HqCompareToggle player={{ id: entry.player.id, name: entry.player.nickname, image: entry.player.image }} className="self-center" />`; la celda de puntos pasa de `col-start-3` a `col-start-4`; el bloque "Próximos" pasa de `col-span-2` a `col-span-3`.

- [ ] **Step 6: mercado** (`market-panel.tsx`): una `<Link>` no puede contener un `<button>`. Envuelve cada tarjeta:

```tsx
        <div className={cn(CELL_CLASS, 'relative')}>
            <Link /* …igual que antes, sin CELL_CLASS y con h-full… */ />
            <HqCompareToggle
                player={{ id: player.id, name: player.nickname, image: player.image }}
                className="absolute top-1 left-1 z-10"
            />
        </div>
```
  Las reglas `[&>*:nth-child(…)]` del grid apuntan ahora al envoltorio, que es quien lleva los bordes de `CELL_CLASS`. Comprueba en el navegador que la casilla no tapa la etiqueta de posición ni el badge de titularidad; si lo hace, muévela a `top-1 right-1` y deja el badge como está.

- [ ] **Step 7: comprobaciones**

Run: `npm run types:check && npm run lint:check && npx prettier --check <archivos tocados> && npm run build`
Expected: verde.

- [ ] **Step 8: navegador** (escritorio y 390 px): la casilla en el mercado (Inicio), en la plantilla de un mánager y en la ficha de un equipo; la selección es la misma en todas; "Comparar" de la ficha con 0 elegidos deja la bandeja con el aviso y con 1 elegido abre el comparador; el botón del modal (desde un partido) cierra el modal si falta otro jugador y abre el comparador si no.

- [ ] **Step 9: commit**

```bash
git add resources/js/components/compare/compare-button.tsx resources/js/pages/teams/show.tsx resources/js/pages/season-managers/roster-list.tsx resources/js/pages/home/market-panel.tsx resources/js/pages/players/show.tsx resources/js/components/hq-player-stats-modal.tsx
git commit -m "feat: add compare entry points across the app

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Página del comparador (cabecera, selector de vista, tooltip, modal y `derive.ts`)

**Files:**
- Crear: `resources/js/lib/use-media-query.ts`
- Crear: `resources/js/components/compare/derive.ts`
- Crear: `resources/js/components/compare/compare-context.tsx`
- Crear: `resources/js/components/compare/use-comparison.ts`
- Crear: `resources/js/components/compare/chart-tooltip.tsx`
- Crear: `resources/js/components/compare/view-switch.tsx`
- Crear: `resources/js/components/compare/picker-dialog.tsx`
- Crear: `resources/js/components/compare/property.tsx`
- Modificar: `resources/js/pages/players/compare.tsx`
- Modificar: `resources/css/app.css` (reglas del comparador al final)

**Interfaces:**
- Consumes: props de Tasks 1, 3 y 4; `compare-selection` (Task 5); `resolveClauseStatus`, `startTone`, `useNow`, formateadores de `@/lib/format`; `rivalDifficultyLevel`, `formatDifficulty` de `@/lib/rival-difficulty` (team-strength).
- Produces (usados por Tasks 8–11):
  - `derive.ts`: `WeekCell`, `DerivedPlayer`, `Acquire`, `derivePlayer(player, currentWeek, now)`, `startProbabilityOf(player)`, `winner(values, lowerIsBetter?)`, `formatCountdown(iso, now)`, `formatLockLeft(iso, now)`, `formatPercentChange(value)`, `percentSeries(history)`, `searchLeague(league, query, excludeIds, limit)`, `suggestPlayers(league, base, excludeIds)`, `TrackMetric`, `trackMetrics(currentWeek)`, `trackValues(league, metric, scope, positions)`, `rankIn(values, value)`, `beatsPercent(values, value)`, `medianOf(values)`, `scaleOf(scale)`, `trackPosition(value, domain, scale)`, `jitter(id, salt)`;
  - `useCompare(): CompareContextValue` (`players`, `derived`, `league`, `managersById`, `currentWeek`, `now`, `add(id, focusSelector?)`, `remove(index)`, `openPicker(replaceIndex: number | null)`, `announce(text)`);
  - `useChartTooltip(): { show(content, anchor, owner), hide(owner?) }`, `useTipTarget(content, options?)`, `useSlotHighlight()`, `mergeProps(...)`;
  - `CompareProperty({ player, derived, slot })` y `ComparePropertyLine({ player, derived })`;
  - `useMediaQuery(query): boolean`.

- [ ] **Step 1: `use-media-query.ts`**

```ts
import { useSyncExternalStore } from 'react';

/** Whether a CSS media query matches, kept in sync with the viewport. False on the server. */
export function useMediaQuery(query: string): boolean {
    return useSyncExternalStore(
        (onChange) => {
            const list = window.matchMedia(query);
            list.addEventListener('change', onChange);

            return () => list.removeEventListener('change', onChange);
        },
        () => window.matchMedia(query).matches,
        () => false,
    );
}
```

- [ ] **Step 2: `derive.ts`** (funciones puras; porta `kit.js` `derive`/`winner` y las métricas de `final.html` L694-701, con la dificultad nueva)

```ts
import { resolveClauseStatus } from '@/lib/clause-status';
import type { ClauseStatus } from '@/lib/clause-status';
import { formatAverage, formatDecimal, formatMillions } from '@/lib/format';
import { startTone } from '@/lib/start-probability';
import type { StartTone } from '@/lib/start-probability';
import type {
    ComparedPlayer,
    ComparedPlayerScore,
    LeagueCloudRow,
    NextFixtureSlot,
    PlayerPosition,
} from '@/types/models';

export interface WeekCell {
    week: number;
    /** Null = no lineup row that jornada ("NC"). */
    score: ComparedPlayerScore | null;
}

export type AcquireKind = 'market' | 'clause' | 'locked' | 'shielded' | 'owned' | 'free';

/** What it costs to get him today, and how (mock `derive().acquire`). */
export interface Acquire {
    kind: AcquireKind;
    amount: number | null;
    /** Listing expiry, clause lock or shield end. */
    until: string | null;
}

export interface DerivedPlayer {
    /** J1 … J(currentWeek − 1), oldest first. */
    weeks: WeekCell[];
    last3: WeekCell[];
    last3Points: number;
    last3Minutes: number;
    starts: number;
    minutes: number;
    /** Share of the possible minutes so far (0–100), null in jornada 1. */
    minutesShare: number | null;
    /** Mean of official DAZN ratings only (dazn_points), null without one. */
    daznAverage: number | null;
    clauseState: ClauseStatus | null;
    upcoming: NextFixtureSlot[];
    /** Mean 0–10 difficulty of the next fixtures that have one (lower = easier). */
    nextAverageDifficulty: number | null;
    nextAverageRivalPosition: number | null;
    nextHomeCount: number;
    startProbability: number | null;
    startTone: StartTone;
    acquire: Acquire;
}

/** Same rule as LeagueCloud::startValue(): confirmed lineup 100/0, else the %, else null. */
export function startProbabilityOf(player: ComparedPlayer): number | null {
    const start = player.next_start;

    if (start === null) {
        return null;
    }

    if (start.confirmed_starter !== null) {
        return start.confirmed_starter ? 100 : 0;
    }

    return start.probability;
}

function mean(values: number[]): number | null {
    return values.length === 0 ? null : values.reduce((sum, value) => sum + value, 0) / values.length;
}

function acquireOf(player: ComparedPlayer, clauseState: ClauseStatus | null): Acquire {
    if (player.listing) {
        return { kind: 'market', amount: player.listing.sale_price, until: player.listing.expires_at };
    }

    if (player.clause && clauseState === 'open') {
        return { kind: 'clause', amount: player.clause.amount, until: null };
    }

    if (player.clause && clauseState === 'locked') {
        return { kind: 'locked', amount: player.clause.amount, until: player.clause.locked_until };
    }

    if (player.clause && clauseState === 'shielded') {
        return { kind: 'shielded', amount: player.clause.amount, until: player.clause.shielded_until };
    }

    return { kind: player.owner ? 'owned' : 'free', amount: null, until: null };
}

export function derivePlayer(player: ComparedPlayer, currentWeek: number, now: number): DerivedPlayer {
    const byWeek = new Map(player.scores.map((score) => [score.week_number, score]));
    const weeks: WeekCell[] = [];

    for (let week = 1; week < currentWeek; week++) {
        weeks.push({ week, score: byWeek.get(week) ?? null });
    }

    const last3 = weeks.slice(-3);
    const played = player.scores.filter((score) => score.minutes > 0);
    const minutes = player.scores.reduce((sum, score) => sum + score.minutes, 0);
    const possibleMinutes = (currentWeek - 1) * 90;
    const upcoming = player.next_fixtures.filter((slot): slot is NextFixtureSlot => slot !== null);
    const difficulties = upcoming
        .map((slot) => slot.difficulty)
        .filter((difficulty): difficulty is number => typeof difficulty === 'number');
    const clauseState = player.clause
        ? resolveClauseStatus(player.clause.shielded, player.clause.locked_until, now)
        : null;
    const startProbability = startProbabilityOf(player);

    return {
        weeks,
        last3,
        last3Points: last3.reduce((sum, cell) => sum + (cell.score?.points ?? 0), 0),
        last3Minutes: last3.reduce((sum, cell) => sum + (cell.score?.minutes ?? 0), 0),
        starts: player.scores.filter((score) => score.starter && score.minutes > 0).length,
        minutes,
        minutesShare: possibleMinutes > 0 ? Math.round((minutes / possibleMinutes) * 100) : null,
        daznAverage: mean(
            played.map((score) => score.dazn_points).filter((value): value is number => value !== null),
        ),
        clauseState,
        upcoming,
        nextAverageDifficulty: mean(difficulties),
        nextAverageRivalPosition: mean(upcoming.map((slot) => slot.rival_position)),
        nextHomeCount: upcoming.filter((slot) => slot.is_home).length,
        startProbability,
        startTone: startTone(startProbability, player.status),
        acquire: acquireOf(player, clauseState),
    };
}

/** Index of the unique best value — null on a tie or with fewer than two values (kit.js `winner`). */
export function winner(values: (number | null)[], lowerIsBetter = false): number | null {
    let best: number | null = null;
    let index: number | null = null;
    let tie = false;
    let count = 0;

    values.forEach((value, position) => {
        if (value === null || Number.isNaN(value)) {
            return;
        }

        count++;

        if (best === null || (lowerIsBetter ? value < best : value > best)) {
            best = value;
            index = position;
            tie = false;
        } else if (value === best) {
            tie = true;
        }
    });

    return count < 2 || tie ? null : index;
}

/** "9d 18h" or "18h"; null once it is over. */
export function formatLockLeft(iso: string, now: number): string | null {
    const ms = Date.parse(iso) - now;

    if (ms <= 0) {
        return null;
    }

    const days = Math.floor(ms / 86_400_000);
    const hours = Math.floor((ms % 86_400_000) / 3_600_000);

    return days > 0 ? `${days}d ${hours}h` : `${hours}h`;
}

/** "2d 07h" or "07:12:09" — a market listing's time left. */
export function formatCountdown(iso: string, now: number): string {
    const ms = Date.parse(iso) - now;

    if (ms <= 0) {
        return '00:00:00';
    }

    const pad = (value: number) => String(value).padStart(2, '0');
    const days = Math.floor(ms / 86_400_000);
    const hours = Math.floor((ms % 86_400_000) / 3_600_000);
    const minutes = Math.floor((ms % 3_600_000) / 60_000);
    const seconds = Math.floor((ms % 60_000) / 1000);

    return days > 0 ? `${days}d ${pad(hours)}h` : `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
}

/** "+3,2 %", "−1 %", "0 %". */
export function formatPercentChange(value: number): string {
    const rounded = Math.round(value * 10) / 10;
    const sign = rounded > 0 ? '+' : rounded < 0 ? '−' : '';

    return `${sign}${String(Math.abs(rounded)).replace('.', ',')} %`;
}

/** % change of each snapshot over the first one. */
export function percentSeries(history: [string, number][]): number[] {
    const base = history[0]?.[1] ?? 0;

    return base > 0 ? history.map(([, value]) => (value / base - 1) * 100) : history.map(() => 0);
}

function fold(text: string): string {
    return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

/** Accent-insensitive name match, or a team short-name prefix (kit.js `search`). League rows come sorted by points. */
export function searchLeague(league: LeagueCloudRow[], query: string, excludeIds: number[], limit: number): LeagueCloudRow[] {
    const needle = fold(query.trim());

    return league
        .filter((row) => !excludeIds.includes(row.id))
        .filter((row) => needle === '' || fold(row.name).includes(needle) || fold(row.team_short).startsWith(needle))
        .slice(0, limit);
}

/** Six of the same position with the closest value to the base player; the top scorers without one. */
export function suggestPlayers(league: LeagueCloudRow[], base: ComparedPlayer | null, excludeIds: number[]): LeagueCloudRow[] {
    if (base === null) {
        return searchLeague(league, '', excludeIds, 8);
    }

    return league
        .filter((row) => !excludeIds.includes(row.id) && row.position === base.position)
        .sort((a, b) => Math.abs(a.value - base.value) - Math.abs(b.value - base.value))
        .slice(0, 6);
}

export type TrackScale = 'linear' | 'sqrt' | 'log';
export type TrackScope = 'all' | 'position';

/** One strip of view B. `league` returns null for a row outside the "toda la liga" population. */
export interface TrackMetric {
    key: 'points' | 'average' | 'ppm' | 'start' | 'rise' | 'value';
    label: string;
    note: string;
    scale: TrackScale;
    noBest?: boolean;
    fixed?: [number, number];
    league: (row: LeagueCloudRow) => number | null;
    player: (player: ComparedPlayer, derived: DerivedPlayer) => number | null;
    format: (value: number) => string;
}

export function trackMetrics(currentWeek: number): TrackMetric[] {
    return [
        { key: 'points', label: 'Puntos', note: 'total de la temporada', scale: 'linear', league: (row) => (row.points > 0 ? row.points : null), player: (player) => player.points, format: (value) => String(value) },
        { key: 'average', label: 'Media', note: 'puntos por partido', scale: 'linear', league: (row) => (row.points > 0 ? row.average_points : null), player: (player) => player.average_points, format: formatAverage },
        { key: 'ppm', label: 'Pts / M€', note: 'puntos por millón de valor', scale: 'sqrt', league: (row) => (row.points > 0 ? row.ppm : null), player: (player) => player.points_per_million?.value ?? null, format: formatDecimal },
        { key: 'start', label: `Titularidad J${currentWeek}`, note: 'probabilidad FútbolFantasy', scale: 'linear', fixed: [0, 100], league: (row) => row.start_probability, player: (_, derived) => (derived.startTone === 'out' ? 0 : derived.startProbability), format: (value) => `${value} %` },
        { key: 'rise', label: 'Subida 30 días', note: 'valor hoy ÷ hace 30 días', scale: 'log', league: (row) => (row.value_trend_30d !== null && row.value_trend_30d > 0 ? row.value_trend_30d : null), player: (player) => player.value_trend_30d?.multiple ?? null, format: (value) => `×${formatDecimal(value)}` },
        { key: 'value', label: 'Valor', note: 'sin ganador: caro no es mejor', scale: 'sqrt', noBest: true, league: (row) => (row.value > 0 ? row.value : null), player: (player) => player.value, format: formatMillions },
    ];
}

/** The population's values for a track: the whole league, or only the compared players' positions. */
export function trackValues(league: LeagueCloudRow[], metric: TrackMetric, scope: TrackScope, positions: PlayerPosition[]): number[] {
    return league
        .filter((row) => scope === 'all' || positions.includes(row.position))
        .map(metric.league)
        .filter((value): value is number => value !== null);
}

export function rankIn(values: number[], value: number): number {
    return values.filter((other) => other > value).length + 1;
}

export function beatsPercent(values: number[], value: number): number {
    return values.length === 0 ? 0 : Math.floor((values.filter((other) => other < value).length / values.length) * 100);
}

export function medianOf(values: number[]): number | null {
    if (values.length === 0) {
        return null;
    }

    const sorted = [...values].sort((a, b) => a - b);

    return sorted[Math.floor(sorted.length / 2)];
}

export function scaleOf(scale: TrackScale): (value: number) => number {
    if (scale === 'sqrt') {
        return (value) => Math.sqrt(Math.max(0, value));
    }

    if (scale === 'log') {
        return (value) => Math.log(Math.max(0.05, value));
    }

    return (value) => value;
}

/** 0–100 along the track. */
export function trackPosition(value: number, [low, high]: [number, number], scale: TrackScale): number {
    const transform = scaleOf(scale);
    const span = transform(high) - transform(low) || 1;

    return Math.max(0, Math.min(100, ((transform(value) - transform(low)) / span) * 100));
}

/** Stable pseudo-random 0–1 for a dot's vertical jitter (mock `hash`). */
export function jitter(id: number, salt: number): number {
    const x = Math.sin((id + salt) * 9301 + 49297) * 233280;

    return x - Math.floor(x);
}
```

(`formatMillions` ya se usa en `trackMetrics`. La Task 11 añade a este archivo `import { describeMarketTrend } from '@/components/hq-market-trend-icon';` y los helpers de `@/lib/rival-difficulty` que usa `verdict()`.)

- [ ] **Step 3: tooltip compartido y resaltado** `chart-tooltip.tsx` (porta `final.html` L425-492)

```tsx
import type { FocusEvent, PointerEvent, ReactNode } from 'react';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

export interface TipAnchor {
    left: number;
    right: number;
    top: number;
    bottom: number;
    /** Beside the anchor instead of above it (view C cells, on screens wider than 560 px). */
    side?: boolean;
}

interface TipState {
    content: ReactNode;
    anchor: () => TipAnchor | null;
    owner: Element;
}

interface ChartTooltipApi {
    show: (content: ReactNode, anchor: () => TipAnchor | null, owner: Element) => void;
    hide: (owner?: Element) => void;
}

const ChartTooltipContext = createContext<ChartTooltipApi | null>(null);

/**
 * One interactive tooltip for the three views (mock `.cmptip`): fixed
 * position, clamped to the viewport, following hover, keyboard focus and
 * touch — a tap shows it and the next tap elsewhere hides it.
 */
export function HqChartTooltip({ children }: { children: ReactNode }) {
    const [tip, setTip] = useState<TipState | null>(null);
    const bubbleRef = useRef<HTMLDivElement>(null);

    const place = useCallback(() => {
        const bubble = bubbleRef.current;
        const rect = tip?.anchor();

        if (!bubble || !rect || rect.bottom < 0 || rect.top > window.innerHeight) {
            if (bubble) {
                bubble.style.visibility = 'hidden';
            }

            return;
        }

        const width = bubble.offsetWidth;
        const height = bubble.offsetHeight;
        let x = Math.max(8, Math.min(window.innerWidth - width - 8, (rect.left + rect.right) / 2 - width / 2));
        let y = rect.top - height - 10;

        if (y < 8) {
            y = Math.min(window.innerHeight - height - 8, rect.bottom + 10);
        }

        if (rect.side && window.innerWidth > 560) {
            x = rect.right + 10 + width > window.innerWidth - 8 ? rect.left - width - 10 : rect.right + 10;
            y = Math.max(8, Math.min(window.innerHeight - height - 8, (rect.top + rect.bottom) / 2 - height / 2));
        }

        bubble.style.left = `${Math.round(x)}px`;
        bubble.style.top = `${Math.round(y)}px`;
        bubble.style.visibility = 'visible';
    }, [tip]);

    useEffect(() => {
        if (!tip) {
            return;
        }

        place();
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setTip(null);
            }
        };
        const onPointerDown = (event: globalThis.PointerEvent) => {
            if (!(event.target instanceof Element) || !event.target.closest('[data-cmp-tip],[data-hl-slot],[data-cmp-chart]')) {
                setTip(null);
            }
        };

        window.addEventListener('scroll', place, { passive: true, capture: true });
        window.addEventListener('resize', place);
        window.addEventListener('keydown', onKeyDown);
        document.addEventListener('pointerdown', onPointerDown, true);

        return () => {
            window.removeEventListener('scroll', place, { capture: true });
            window.removeEventListener('resize', place);
            window.removeEventListener('keydown', onKeyDown);
            document.removeEventListener('pointerdown', onPointerDown, true);
        };
    }, [tip, place]);

    const api = useMemo<ChartTooltipApi>(() => ({
        show: (content, anchor, owner) => setTip({ content, anchor, owner }),
        hide: (owner) => setTip((currentTip) => (owner === undefined || currentTip?.owner === owner ? null : currentTip)),
    }), []);

    return (
        <ChartTooltipContext.Provider value={api}>
            {children}
            {tip &&
                createPortal(
                    <div
                        ref={bubbleRef}
                        aria-hidden="true"
                        className="pointer-events-none invisible fixed top-0 left-0 z-[90] flex max-w-[min(300px,calc(100vw-16px))] min-w-[150px] flex-col border border-hq-border-bright bg-hq-ink px-[11px] pt-[9px] pb-2.5 font-mono text-[11px] leading-[1.35] text-hq-paper shadow-[0_14px_30px_rgba(0,0,0,0.62)]"
                    >
                        {tip.content}
                    </div>,
                    document.body,
                )}
        </ChartTooltipContext.Provider>
    );
}

export function useChartTooltip(): ChartTooltipApi {
    const api = useContext(ChartTooltipContext);

    if (!api) {
        throw new Error('useChartTooltip needs <HqChartTooltip>.');
    }

    return api;
}

/** Props that show `content()` over (or beside) the element on hover, focus and tap. */
export function useTipTarget(content: () => ReactNode, options: { side?: boolean } = {}) {
    const { show, hide } = useChartTooltip();

    const open = (element: Element) =>
        show(content(), () => {
            if (!element.isConnected) {
                return null;
            }

            const rect = element.getBoundingClientRect();

            return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom, side: options.side };
        }, element);

    return {
        'data-cmp-tip': '',
        onPointerEnter: (event: PointerEvent<Element>) => open(event.currentTarget),
        onPointerLeave: (event: PointerEvent<Element>) => {
            if (event.pointerType !== 'touch') {
                hide(event.currentTarget);
            }
        },
        onFocus: (event: FocusEvent<Element>) => open(event.currentTarget),
        onBlur: (event: FocusEvent<Element>) => hide(event.currentTarget),
    };
}

/** `data-hl` on the view root dims every other player's `[data-slot]` to 30 % (rules in app.css). */
export function useSlotHighlight() {
    const [highlighted, setHighlighted] = useState<number | null>(null);

    const bind = (slot: number) => ({
        'data-hl-slot': slot,
        onPointerEnter: () => setHighlighted(slot),
        onPointerLeave: () => setHighlighted(null),
        onFocus: () => setHighlighted(slot),
        onBlur: () => setHighlighted(null),
    });

    return {
        highlighted,
        setHighlighted,
        bind,
        rootProps: { className: 'cmp-view', 'data-hl': highlighted ?? undefined },
    };
}

type Handler = ((...args: never[]) => void) | undefined;

/** Merges prop objects, chaining handlers with the same name (tip + highlight on one element). */
export function mergeProps<T extends Record<string, unknown>>(...sources: T[]): T {
    const merged: Record<string, unknown> = {};

    for (const source of sources) {
        for (const [key, value] of Object.entries(source)) {
            const previous = merged[key];

            merged[key] =
                typeof previous === 'function' && typeof value === 'function'
                    ? (...args: never[]) => {
                          (previous as NonNullable<Handler>)(...args);
                          (value as NonNullable<Handler>)(...args);
                      }
                    : value;
        }
    }

    return merged as T;
}

/** One row of a tooltip: slot colour, name, value and an optional extra (rank, % …); the hovered one in bold. */
export function TipRow({ slotColor, name, value, extra, me }: { slotColor: string; name: string; value: string; extra?: string; me?: boolean }) {
    return (
        <span className={`grid grid-cols-[4px_minmax(0,1fr)_auto_auto] items-center gap-2 ${me ? 'text-hq-paper' : 'text-hq-moss'}`}>
            <i className="min-h-3 self-stretch" style={{ background: slotColor }} />
            <span className="truncate font-sans text-[11px] font-extrabold uppercase">{name}</span>
            <b className="text-right font-bold tabular-nums">{value}</b>
            {extra !== undefined && <em className="min-w-[3ch] text-right text-hq-moss-dim not-italic tabular-nums">{extra}</em>}
        </span>
    );
}
```

- [ ] **Step 4: CSS** al final de `resources/css/app.css`:

```css
/* Comparador: resaltado de jugador (data-hl en la raíz de la vista) y modal selector */
.cmp-view [data-slot] {
    transition: opacity 0.16s ease-out;
}

.cmp-view[data-hl='0'] [data-slot]:not([data-slot='0']),
.cmp-view[data-hl='1'] [data-slot]:not([data-slot='1']),
.cmp-view[data-hl='2'] [data-slot]:not([data-slot='2']) {
    opacity: 0.3;
}

@keyframes cmp-pick-in {
    from {
        opacity: 0;
        transform: translateY(8px) scale(0.985);
    }
}

@media (prefers-reduced-motion: reduce) {
    .cmp-view [data-slot] {
        transition: none;
    }
}
```

- [ ] **Step 5: propiedad** `property.tsx` (porta `kit.js` `property`/`propertyLine`): `CompareProperty` (bloque de celda con el tinte del dueño, estado de cláusula Abierta/Bloqueada/Blindado con icono `LockOpen`/`Lock`/`ShieldCheck`, tiempo con `formatLockLeft`, importe con `formatCurrency` y `formatMillions` en contenedores estrechos vía `@container`/`@max-[220px]:`; "En el mercado · N pujas" con `formatCountdown` y `useNow(1000)`; "Libre · sin manager fantasy") y `ComparePropertyLine` (una línea: quién · estado+tiempo · importe `formatMillions`). El dueño enlaza a su ficha con `show` de `@/routes/season-managers` y `cursor-pointer`. Los textos exactos y la disposición están en `kit.js` L417-450 y el CSS en `kit.css` (`.prop`, `.clause`, `.propline`).

```tsx
import { Link } from '@inertiajs/react';
import { Lock, LockOpen, ShieldCheck, Tag, UserX } from 'lucide-react';
import type { CSSProperties } from 'react';
import { EntityImage } from '@/components/entity-image';
import type { DerivedPlayer } from '@/components/compare/derive';
import { formatCountdown, formatLockLeft } from '@/components/compare/derive';
import { formatCurrency, formatMillions } from '@/lib/format';
import { managerColor } from '@/lib/season-manager-colors';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { ComparedPlayer } from '@/types/models';

const CLAUSE_DISPLAY = {
    open: { icon: LockOpen, label: 'Cláusula abierta', short: 'Abierta', className: 'text-hq-lime border-hq-lime/40' },
    locked: { icon: Lock, label: 'Bloqueada', short: 'Bloqueada', className: 'text-hq-gold border-hq-gold/40' },
    shielded: { icon: ShieldCheck, label: 'Blindado', short: 'Blindado', className: 'text-hq-azure border-hq-azure/40' },
} as const;

export function CompareProperty({ player, derived }: { player: ComparedPlayer; derived: DerivedPlayer }) {
    const now = useNow(1000);

    if (player.listing) {
        return (
            <div className="@container flex flex-col gap-1.5">
                <span className="hq-label">
                    En el mercado
                    {player.listing.bids > 0 && <b className="text-hq-ember"> · {player.listing.bids} {player.listing.bids === 1 ? 'puja' : 'pujas'}</b>}
                </span>
                <span className="font-mono text-sm font-bold text-hq-gold tabular-nums">{formatCountdown(player.listing.expires_at, now)}</span>
                <b className="font-mono text-sm text-hq-paper tabular-nums">
                    <span className="@max-[220px]:hidden">{formatCurrency(player.listing.sale_price)}</span>
                    <span className="hidden @max-[220px]:inline">{formatMillions(player.listing.sale_price)}</span>
                </b>
            </div>
        );
    }

    if (player.owner) {
        const display = derived.clauseState ? CLAUSE_DISPLAY[derived.clauseState] : null;
        const time = derived.acquire.until ? formatLockLeft(derived.acquire.until, now) : null;

        return (
            <div className="@container flex flex-col gap-2" style={{ '--tint': managerColor(player.owner.color) } as CSSProperties}>
                <Link href={seasonManagersShow(player.owner.id).url} className="inline-flex min-w-0 cursor-pointer items-center gap-2 border-l-2 border-(--tint) pl-2 font-mono text-xs font-bold text-hq-khaki hover:text-hq-paper">
                    <EntityImage src={player.owner.logo} alt="" shape="square" className="size-5 rounded-none" />
                    <span className="truncate">{player.owner.name}</span>
                </Link>
                {display && player.clause && (
                    <div className={cn('flex flex-col gap-1 border px-2 py-1.5', display.className)}>
                        <span className="flex items-center gap-1.5 font-mono text-[10.5px] font-bold tracking-[0.04em] uppercase">
                            <display.icon aria-hidden="true" className="size-3.5" />
                            {display.label}
                            {time && <span className="ml-auto whitespace-nowrap">{time}</span>}
                        </span>
                        <b className="font-mono text-sm text-hq-paper tabular-nums">
                            <span className="@max-[220px]:hidden">{formatCurrency(player.clause.amount)}</span>
                            <span className="hidden @max-[220px]:inline">{formatMillions(player.clause.amount)}</span>
                        </b>
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="flex items-center gap-2 font-mono text-xs text-hq-moss">
            <UserX aria-hidden="true" className="size-4" />
            <span>Libre</span>
            <small className="text-hq-moss-dim">sin manager fantasy</small>
        </div>
    );
}

export function ComparePropertyLine({ player, derived }: { player: ComparedPlayer; derived: DerivedPlayer }) {
    const now = useNow(1000);
    const row = 'grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-2.5 font-mono text-xs';

    if (player.listing) {
        return (
            <div className={row}>
                <span className="flex min-w-0 items-center gap-1.5 truncate text-hq-paper"><Tag aria-hidden="true" className="size-3.5" />Mercado{player.listing.bids > 0 && ` · ${player.listing.bids} ${player.listing.bids === 1 ? 'puja' : 'pujas'}`}</span>
                <span className="whitespace-nowrap text-hq-gold tabular-nums">{formatCountdown(player.listing.expires_at, now)}</span>
                <b className="text-hq-paper tabular-nums">{formatMillions(player.listing.sale_price)}</b>
            </div>
        );
    }

    if (player.owner) {
        const display = derived.clauseState ? CLAUSE_DISPLAY[derived.clauseState] : null;
        const time = derived.acquire.until ? formatLockLeft(derived.acquire.until, now) : null;

        return (
            <div className={row} style={{ '--tint': managerColor(player.owner.color) } as CSSProperties}>
                <span className="flex min-w-0 items-center gap-1.5 border-l-2 border-(--tint) pl-1.5 text-hq-paper">
                    <EntityImage src={player.owner.logo} alt="" shape="square" className="size-4 rounded-none" />
                    <span className="truncate">{player.owner.name}</span>
                </span>
                {display && player.clause ? (
                    <>
                        <span className={cn('flex items-center gap-1 whitespace-nowrap', display.className)} title={display.label}>
                            <display.icon aria-hidden="true" className="size-3.5" />
                            {time ?? display.short}
                        </span>
                        <b className="text-hq-paper tabular-nums">{formatMillions(player.clause.amount)}</b>
                    </>
                ) : (
                    <>
                        <span />
                        <span />
                    </>
                )}
            </div>
        );
    }

    return (
        <div className={cn(row, 'text-hq-moss-dim')}>
            <span className="flex items-center gap-1.5 text-hq-moss"><UserX aria-hidden="true" className="size-3.5" />Libre</span>
            <span>sin manager</span>
            <b>—</b>
        </div>
    );
}
```

(Comprueba los nombres reales de `EntityImage` — su prop `fallback` es obligatoria o no — y de `managerColor`; si `title` choca con el tooltip compartido, usa `useTipTarget` en su lugar.)

- [ ] **Step 6: contexto y navegación** `compare-context.tsx` y `use-comparison.ts`

```tsx
// compare-context.tsx
import { createContext, useContext } from 'react';
import type { DerivedPlayer } from '@/components/compare/derive';
import type { CompareManager, ComparedPlayer, LeagueCloudRow } from '@/types/models';

export interface CompareContextValue {
    players: ComparedPlayer[];
    derived: DerivedPlayer[];
    league: LeagueCloudRow[];
    managersById: Map<number, CompareManager>;
    currentWeek: number;
    now: number;
    /** Adds a player; `focusSelector` is focused once the new props arrive. */
    add: (id: number, focusSelector?: string) => void;
    remove: (index: number) => void;
    /** Opens the picker to add (null) or to replace the player in that slot. */
    openPicker: (replaceIndex: number | null) => void;
    announce: (text: string) => void;
}

export const CompareContext = createContext<CompareContextValue | null>(null);

export function useCompare(): CompareContextValue {
    const value = useContext(CompareContext);

    if (!value) {
        throw new Error('useCompare needs the comparator page.');
    }

    return value;
}
```

```ts
// use-comparison.ts
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    COMPARE_MAX,
    rememberCompareView,
    rememberedCompareView,
    replaceCompare,
} from '@/lib/compare-selection';
import { compare as playersCompare } from '@/routes/players';
import type { CompareView, ComparedPlayer } from '@/types/models';

/**
 * The comparator's selection and view live in the URL (the shareable link).
 * Every change is a partial reload with `replace: true`, starting from the
 * current props, so quick successive changes never mix two selections.
 */
export function useComparison({ ids, view, players }: { ids: number[]; view: CompareView; players: ComparedPlayer[] }) {
    const [activeView, setActiveView] = useState<CompareView>(view);
    const focusAfterLoad = useRef<string | null>(null);
    const normalized = useRef(false);

    const visit = (nextIds: number[], nextView: CompareView, only: string[]) => {
        router.get(
            playersCompare.url(),
            { ids: nextIds.length > 0 ? nextIds.join(',') : undefined, vista: nextView },
            {
                only,
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: () => {
                    const selector = focusAfterLoad.current;
                    focusAfterLoad.current = null;

                    if (selector) {
                        requestAnimationFrame(() => document.querySelector<HTMLElement>(selector)?.focus({ preventScroll: true }));
                    }
                },
            },
        );
    };

    // The tray and the lists show the comparator's players once you go back.
    useEffect(() => {
        replaceCompare(players.map((player) => ({ id: player.id, name: player.name, image: player.image })));
    }, [players]);

    // Once per visit: drop invalid ids from the URL, and open the remembered view when the link has none.
    useEffect(() => {
        if (normalized.current) {
            return;
        }

        normalized.current = true;
        const params = new URLSearchParams(window.location.search);
        const remembered = params.has('vista') ? null : rememberedCompareView();
        const nextView = remembered ?? view;

        if (params.get('ids') !== (ids.join(',') || null) || nextView !== view) {
            if (nextView !== view) {
                setActiveView(nextView);
            }

            visit(ids, nextView, ['ids', 'view']);
        }
    });

    return {
        view: activeView,
        setView: (next: CompareView) => {
            setActiveView(next);
            rememberCompareView(next);
            visit(ids, next, ['view']);
        },
        add: (id: number, focusSelector?: string) => {
            if (ids.includes(id) || ids.length >= COMPARE_MAX) {
                return;
            }

            focusAfterLoad.current = focusSelector ?? null;
            visit([...ids, id], activeView, ['ids', 'players', 'view']);
        },
        replace: (index: number, id: number, focusSelector?: string) => {
            if (ids.includes(id)) {
                return;
            }

            focusAfterLoad.current = focusSelector ?? null;
            visit(ids.map((current, position) => (position === index ? id : current)), activeView, ['ids', 'players', 'view']);
        },
        remove: (index: number) => visit(ids.filter((_, position) => position !== index), activeView, ['ids', 'players', 'view']),
    };
}
```

(Si el linter de hooks exige deps en el efecto de normalización, dale `[ids, view]` y conserva la guarda `normalized`.)

- [ ] **Step 7: selector de vista** `view-switch.tsx` (porta `final.html` L214-230 y L405-409; iconos SVG tal cual del mock)

```tsx
import type { ReactNode } from 'react';
import { useRef } from 'react';
import { HqTooltip } from '@/components/hq-tooltip';
import { useMediaQuery } from '@/lib/use-media-query';
import { cn } from '@/lib/utils';
import type { CompareView } from '@/types/models';

const ICON = 'size-[18px]';

export const COMPARE_VIEWS: { key: CompareView; name: string; description: string; icon: ReactNode }[] = [
    {
        key: 'a',
        name: 'Cara a cara',
        description: 'Fila a fila, quién gana cada dato',
        icon: (
            <svg className={ICON} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <rect x="3" y="4" width="6" height="16" rx="1" /><rect x="15" y="4" width="6" height="16" rx="1" /><path d="M11 8h2" /><path d="M11 12h2" /><path d="M11 16h2" />
            </svg>
        ),
    },
    {
        key: 'b',
        name: 'Pistas',
        description: 'Dónde está cada uno en la liga',
        icon: (
            <svg className={ICON} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M3 6h.01M6.5 6h.01M10 6h.01" /><circle cx="17" cy="6" r="2.5" /><path d="M14 12h.01M17.5 12h.01M21 12h.01" /><circle cx="7" cy="12" r="2.5" /><path d="M3 18h.01M8 18h.01M20.5 18h.01" /><circle cx="14" cy="18" r="2.5" />
            </svg>
        ),
    },
    {
        key: 'c',
        name: 'Carriles',
        description: 'Jornada a jornada, pasado y futuro',
        icon: (
            <svg className={ICON} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                <path d="M3 7h2.5M8 7h2.5M3 17h2.5M8 17h2.5" /><path d="M14.5 3v18" strokeDasharray="2 2.5" /><path d="M18 7h3M18 17h3" />
            </svg>
        ),
    },
];

/** Segmented control with three drawn icons; names from 1100 px, below that the icon + tooltip and the active name beside it. */
export function CompareViewSwitch({ view, onChange }: { view: CompareView; onChange: (view: CompareView) => void }) {
    const wide = useMediaQuery('(min-width: 1100px)');
    const tabs = useRef<Record<CompareView, HTMLButtonElement | null>>({ a: null, b: null, c: null });
    const index = COMPARE_VIEWS.findIndex((item) => item.key === view);
    const active = COMPARE_VIEWS[index];

    const move = (next: number) => {
        const target = COMPARE_VIEWS[(next + COMPARE_VIEWS.length) % COMPARE_VIEWS.length].key;
        onChange(target);
        tabs.current[target]?.focus();
    };

    return (
        <div className="flex min-w-0 flex-1 items-center gap-3 sm:flex-none">
            <div
                role="tablist"
                aria-label="Vista del comparador"
                className="inline-flex border border-hq-border-strong bg-hq-well"
                onKeyDown={(event) => {
                    const keys: Record<string, number> = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: COMPARE_VIEWS.length - 1 };

                    if (event.key in keys) {
                        event.preventDefault();
                        move(keys[event.key]);
                    }
                }}
            >
                {COMPARE_VIEWS.map((item) => {
                    const selected = item.key === view;
                    const tab = (
                        <button
                            ref={(element) => {
                                tabs.current[item.key] = element;
                            }}
                            role="tab"
                            type="button"
                            id={`cmp-tab-${item.key}`}
                            aria-controls="cmp-panel"
                            aria-selected={selected}
                            aria-label={item.name}
                            aria-describedby={`cmp-desc-${item.key}`}
                            tabIndex={selected ? 0 : -1}
                            onClick={() => onChange(item.key)}
                            className={cn(
                                'inline-flex h-10 min-w-11 cursor-pointer items-center justify-center gap-2 border-r border-hq-border-strong px-3 font-mono text-[11px] font-bold tracking-[0.06em] uppercase transition-colors last:border-r-0 max-[1099px]:w-12 max-[1099px]:px-0',
                                selected ? 'bg-hq-lime text-hq-ink' : 'text-hq-moss hover:bg-hq-panel-alt hover:text-hq-paper',
                            )}
                        >
                            {item.icon}
                            {wide && <span>{item.name}</span>}
                        </button>
                    );

                    return wide ? <span key={item.key} className="contents">{tab}</span> : <HqTooltip key={item.key} label={item.name}>{tab}</HqTooltip>;
                })}
            </div>
            {!wide && (
                <p aria-hidden="true" className="m-0 min-w-0">
                    <b className="block font-sans text-sm leading-none font-black text-hq-paper uppercase">{active.name}</b>
                    <span className="mt-[5px] block truncate font-mono text-[11px] leading-[1.3] text-hq-moss-dim">{active.description}</span>
                </p>
            )}
            {COMPARE_VIEWS.map((item) => (
                <span key={item.key} id={`cmp-desc-${item.key}`} className="sr-only">{item.description}</span>
            ))}
        </div>
    );
}
```

- [ ] **Step 8: modal selector** `picker-dialog.tsx` (porta `final.html` L130-141 y L656-690). El padre lo monta solo mientras está abierto, así su búsqueda arranca vacía sin `setState` en efectos.

```tsx
import { Search, User, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { searchLeague, suggestPlayers } from '@/components/compare/derive';
import type { CompareManager, ComparedPlayer, LeagueCloudRow } from '@/types/models';

interface ComparePickerDialogProps {
    title: string;
    league: LeagueCloudRow[];
    managersById: Map<number, CompareManager>;
    excludeIds: number[];
    /** Suggestions are "same position, closest value" to this player. */
    base: ComparedPlayer | null;
    onPick: (id: number) => void;
    /** Escape, ×, backdrop or after a pick. */
    onClose: () => void;
}

/**
 * The centred picker (mock `dialog.pick`): native showModal() — focus trap,
 * Escape and backdrop click — with a fixed height so the list scrolls inside.
 */
export function ComparePickerDialog({ title, league, managersById, excludeIds, base, onPick, onClose }: ComparePickerDialogProps) {
    const dialogRef = useRef<HTMLDialogElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const [query, setQuery] = useState('');
    const titleId = useId();
    const rows = query.trim() !== '' ? searchLeague(league, query, excludeIds, 30) : suggestPlayers(league, base, excludeIds);

    useEffect(() => {
        const dialog = dialogRef.current;

        if (dialog && !dialog.open) {
            dialog.showModal();
            inputRef.current?.focus();
        }
    }, []);

    return (
        <dialog
            ref={dialogRef}
            aria-labelledby={titleId}
            onClose={onClose}
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    dialogRef.current?.close();
                }
            }}
            className="m-auto h-[min(560px,calc(100dvh-48px))] w-[min(560px,calc(100%-32px))] max-w-none overflow-hidden border border-hq-border-bright bg-hq-ink p-0 text-hq-paper backdrop:bg-[rgba(4,5,3,0.74)] open:flex open:flex-col motion-safe:open:animate-[cmp-pick-in_0.22s_cubic-bezier(0.16,1,0.3,1)] max-[480px]:h-[min(560px,calc(100dvh-32px))] max-[480px]:w-[calc(100%-20px)]"
        >
            <header className="flex shrink-0 items-center gap-2 border-b border-hq-border px-4 py-2.5">
                <span id={titleId} className="hq-label">{title}</span>
                <kbd aria-hidden="true" className="ml-auto border border-hq-border-strong px-[5px] py-0.5 font-mono text-[10px] text-hq-moss-dim max-[480px]:hidden">Esc</kbd>
                <button type="button" aria-label="Cerrar" onClick={() => dialogRef.current?.close()} className="flex size-11 cursor-pointer items-center justify-center border border-hq-border-strong text-hq-moss hover:text-hq-paper sm:size-8">
                    <X aria-hidden="true" className="size-3.5" />
                </button>
            </header>
            <label className="flex h-12 shrink-0 items-center gap-2 border-b border-hq-border px-4 text-hq-moss focus-within:text-hq-lime">
                <Search aria-hidden="true" className="size-4" />
                <input
                    ref={inputRef}
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && rows[0]) {
                            event.preventDefault();
                            onPick(rows[0].id);
                        }
                    }}
                    placeholder="Buscar jugador o equipo…"
                    autoComplete="off"
                    aria-label="Buscar jugador"
                    className="min-w-0 flex-1 bg-transparent font-mono text-base text-hq-paper placeholder-hq-moss-dim outline-none md:text-[13px]"
                />
            </label>
            <div className="min-h-0 flex-1 overflow-y-auto">
                {query.trim() === '' && (
                    <p className="px-4 pt-3 pb-1 hq-label">{base ? `Mismo puesto y valor parecido a ${base.name}` : 'Más puntos'}</p>
                )}
                {rows.length === 0 ? (
                    <p className="p-4 font-mono text-xs text-hq-moss">Ningún jugador coincide con «{query}».</p>
                ) : (
                    rows.map((row) => {
                        const owner = row.owner_id !== null ? managersById.get(row.owner_id) : undefined;

                        return (
                            <button key={row.id} type="button" onClick={() => onPick(row.id)} className="grid min-h-12 w-full cursor-pointer grid-cols-[32px_minmax(0,1fr)_auto_40px] items-center gap-2.5 border-b border-hq-border px-4 py-2 text-left hover:bg-hq-panel focus-visible:bg-hq-panel">
                                <EntityImage src={row.image} alt="" fallback={User} shape="square" className="size-8 rounded-none object-cover object-top" />
                                <span className="min-w-0">
                                    <b className="block truncate text-sm font-extrabold text-hq-paper">{row.name}</b>
                                    <small className="block truncate font-mono text-[11px] text-hq-moss-dim">{row.team_short} · {owner ? owner.name : 'libre'}</small>
                                </span>
                                <HqPositionTag position={row.position} />
                                <span className="text-right font-mono text-sm font-bold text-hq-lime tabular-nums">{row.points}</span>
                            </button>
                        );
                    })
                )}
            </div>
        </dialog>
    );
}
```

- [ ] **Step 9: la página** `pages/players/compare.tsx`

```tsx
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Check, Link2, Plus } from 'lucide-react';
import type { ReactElement } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CompareContext } from '@/components/compare/compare-context';
import type { CompareContextValue } from '@/components/compare/compare-context';
import { HqChartTooltip } from '@/components/compare/chart-tooltip';
import { derivePlayer } from '@/components/compare/derive';
import { ComparePickerDialog } from '@/components/compare/picker-dialog';
import { useComparison } from '@/components/compare/use-comparison';
import { CompareViewSwitch } from '@/components/compare/view-switch';
import AppLayout from '@/layouts/app-layout';
import { COMPARE_MAX } from '@/lib/compare-selection';
import { useNow } from '@/lib/use-now';
import { index as playersIndex } from '@/routes/players';
import type { CompareManager, CompareView, ComparedPlayer, LeagueCloudRow } from '@/types/models';

interface PlayersCompareProps {
    currentWeek: number;
    view: CompareView;
    ids: number[];
    players: ComparedPlayer[];
    league: LeagueCloudRow[];
    managers: CompareManager[];
    [key: string]: unknown;
}

interface PickerTarget {
    replaceIndex: number | null;
    opener: HTMLElement | null;
}

type CopyState = 'idle' | 'done' | 'failed';

export default function PlayersCompare({ currentWeek, view, ids, players, league, managers }: PlayersCompareProps) {
    const now = useNow(60_000);
    const comparison = useComparison({ ids, view, players });
    const [picker, setPicker] = useState<PickerTarget | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const [copyState, setCopyState] = useState<CopyState>('idle');
    const picked = useRef(false);
    const derived = useMemo(() => players.map((player) => derivePlayer(player, currentWeek, now)), [players, currentWeek, now]);
    const managersById = useMemo(() => new Map(managers.map((manager) => [manager.id, manager])), [managers]);

    const openPicker = (replaceIndex: number | null) => {
        picked.current = false;
        setPicker({ replaceIndex, opener: document.activeElement instanceof HTMLElement ? document.activeElement : null });
    };

    const context: CompareContextValue = {
        players,
        derived,
        league,
        managersById,
        currentWeek,
        now,
        add: comparison.add,
        remove: comparison.remove,
        openPicker,
        announce: setAnnouncement,
    };

    // "/" opens the picker here (the shell's player search would leave the page).
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target;

            if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey || (target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName)))) {
                return;
            }

            event.preventDefault();

            if (picker === null && ids.length < COMPARE_MAX) {
                openPicker(null);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    });

    const copyLink = () => {
        const done = (state: CopyState) => {
            setCopyState(state);
            setAnnouncement(state === 'done' ? 'Enlace copiado' : 'No se pudo copiar');
            window.setTimeout(() => setCopyState('idle'), 2200);
        };

        try {
            navigator.clipboard.writeText(window.location.href).then(() => done('done'), () => done('failed'));
        } catch {
            done('failed');
        }
    };

    const copyLabel = copyState === 'done' ? 'Enlace copiado' : copyState === 'failed' ? 'No se pudo copiar' : 'Copiar enlace';

    return (
        <CompareContext.Provider value={context}>
            <HqChartTooltip>
                <div className="flex-1">
                    <Head title="Comparador" />
                    <p className="sr-only" aria-live="polite">{announcement}</p>

                    <div className="flex flex-wrap items-end gap-x-6 gap-y-3.5 border-b border-hq-border-strong px-3.5 pt-4 pb-3 sm:px-5 sm:pt-[22px] sm:pb-4">
                        <div className="min-w-0">
                            <Link href={playersIndex().url} className="mb-3 inline-flex cursor-pointer items-center gap-1.5 font-mono text-[11px] font-bold tracking-[0.1em] text-hq-lime uppercase hover:text-hq-paper">
                                <ArrowLeft aria-hidden="true" className="size-3" />
                                Jugadores
                            </Link>
                            <h1 className="font-display text-[26px] leading-[0.95] text-hq-paper uppercase sm:text-[34px]">Comparador</h1>
                        </div>
                        <div className="ml-auto flex w-full items-end gap-2.5 sm:w-auto">
                            <CompareViewSwitch view={comparison.view} onChange={comparison.setView} />
                            <button type="button" onClick={copyLink} aria-label={copyLabel} className="inline-flex h-10 shrink-0 cursor-pointer items-center justify-center gap-[7px] border border-hq-border-strong px-3 font-mono text-[11px] font-bold tracking-[0.06em] whitespace-nowrap text-hq-moss uppercase transition-colors hover:border-hq-lime hover:text-hq-lime max-sm:w-11 max-sm:px-0 aria-[label='Enlace copiado']:border-hq-lime aria-[label='Enlace copiado']:text-hq-lime">
                                {copyState === 'done' ? <Check aria-hidden="true" className="size-3.5" /> : <Link2 aria-hidden="true" className="size-3.5" />}
                                <span className="max-sm:sr-only">{copyLabel}</span>
                            </button>
                        </div>
                    </div>

                    <div id="cmp-panel" role="tabpanel" aria-labelledby={`cmp-tab-${comparison.view}`}>
                        {players.length < 2 ? (
                            <div className="flex flex-col items-start gap-3.5 px-4 pt-9 pb-12 font-mono text-[13px] leading-normal text-hq-moss">
                                <p className="m-0">{players.length === 0 ? 'No hay jugadores en el comparador. Elige 2 o 3 en la lista.' : `Solo está ${players[0].name}. Añade otro jugador para comparar.`}</p>
                                <div className="flex flex-wrap gap-2">
                                    {players.length === 1 && (
                                        <button type="button" onClick={() => openPicker(null)} className="inline-flex h-11 cursor-pointer items-center gap-2 bg-hq-lime px-3.5 font-mono text-[11.5px] font-bold tracking-[0.06em] text-hq-ink uppercase sm:h-9">
                                            <Plus aria-hidden="true" className="size-3.5" />
                                            Añadir jugador
                                        </button>
                                    )}
                                    <Link href={playersIndex().url} className="inline-flex h-11 cursor-pointer items-center border border-hq-border-strong px-3.5 font-mono text-[11.5px] font-bold tracking-[0.06em] text-hq-moss uppercase hover:border-hq-lime hover:text-hq-lime sm:h-9">
                                        Elegir en la lista
                                    </Link>
                                </div>
                            </div>
                        ) : (
                            <>
                                {/* Task 11: <CompareVerdict /> when godMode. Tasks 8–10: the views. */}
                                {comparison.view === 'a' && null}
                                {comparison.view === 'b' && null}
                                {comparison.view === 'c' && null}
                            </>
                        )}
                    </div>

                    {picker && (
                        <ComparePickerDialog
                            title={picker.replaceIndex === null ? 'Añadir jugador' : `Cambiar a ${players[picker.replaceIndex].name}`}
                            league={league}
                            managersById={managersById}
                            excludeIds={ids}
                            base={players[picker.replaceIndex ?? 0] ?? null}
                            onPick={(id) => {
                                picked.current = true;
                                const slot = picker.replaceIndex ?? ids.length;
                                const focus = `[data-replace="${slot}"]`;

                                if (picker.replaceIndex === null) {
                                    comparison.add(id, focus);
                                } else {
                                    comparison.replace(picker.replaceIndex, id, focus);
                                }

                                setPicker(null);
                            }}
                            onClose={() => {
                                if (!picked.current && picker.opener?.isConnected) {
                                    picker.opener.focus({ preventScroll: true });
                                }

                                setPicker(null);
                            }}
                        />
                    )}
                </div>
            </HqChartTooltip>
        </CompareContext.Provider>
    );
}

PlayersCompare.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
```

Nota: el `onClose` del `<dialog>` también salta tras un `onPick` que desmonta el modal; la guarda `picked` evita devolver el foco al botón que lo abrió en ese caso (el foco va al botón "Cambiar" del nuevo jugador cuando llegan las props). Las tres líneas `comparison.view === … && null` son el punto de montaje que rellenan las Tasks 8, 9 y 10; en esta tarea el panel queda vacío con 2+ jugadores.

- [ ] **Step 10: comprobaciones**

Run: `npm run types:check && npm run lint:check && npx prettier --check resources/js/components/compare resources/js/lib/use-media-query.ts resources/js/pages/players/compare.tsx resources/css/app.css && npm run build`
Expected: verde.

- [ ] **Step 11: navegador** (http://comando-lechuga.test/jugadores/comparar, escritorio, 800 px y 390 px):
  - sin ids: estado vacío con "Elegir en la lista"; con 1 id: "Añadir jugador" abre el modal, `/` también, Esc y clic en el fondo cierran y el foco vuelve al botón;
  - con 2 ids: el selector de vista cambia la URL (`vista=`) sin recargar la página ni mover el scroll; flechas, Inicio y Fin; nombres visibles desde 1100 px, tooltip e indicador de vista activa por debajo;
  - "Copiar enlace" copia y anuncia; en móvil es un botón de icono;
  - **Review Focus 1 y 4:** `?ids=<a>,<a>,abc,99999999,<b>&vista=z` queda en `?ids=<a>,<b>&vista=a` (o la vista recordada), y la bandeja, al volver a la lista, muestra esos dos;
  - **Review Focus 5:** cambia de vista varias veces seguidas: la última gana, sin parpadeos de datos.

- [ ] **Step 12: commit**

```bash
git add resources/js/lib/use-media-query.ts resources/js/components/compare resources/js/pages/players/compare.tsx resources/css/app.css
git commit -m "feat: add the comparator shell, view switch, picker and derived data

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Vista A · Cara a cara (con el gráfico de evolución)

**Files:**
- Crear: `resources/js/components/compare/value-chart.tsx`
- Crear: `resources/js/components/compare/view-a.tsx`
- Modificar: `resources/js/pages/players/compare.tsx` (monta `<CompareViewA />`)

**Interfaces:**
- Consumes: `useCompare`, `useSlotHighlight`, `useTipTarget`, `useChartTooltip`, `mergeProps`, `TipRow`, `winner`, `percentSeries`, `formatPercentChange`, `CompareProperty`, `ComparePropertyLine`, `COMPARE_SLOT_COLORS`; componentes HQ (`HqLed`, `HqRecentScores`, `HqStartMeter`, `HqMarketValueDifference`, `HqPositionTag`, `HqStatusBadge`, `EntityImage`); **de team-strength:** `HqDifficultyBars`, `rivalDifficultyLevel`, `RIVAL_DIFFICULTY_LABELS`, `formatDifficulty` y las clases por nivel de `rival-difficulty.ts`.
- Produces: `CompareValueChart({ series, width, height, padRight?, label, onHighlight?, showAxis? })` con `series: { slot: number; name: string; history: [string, number][] }[]` (lo reutiliza la vista C) y `CompareViewA()`.

- [ ] **Step 0: antes de implementar**, lee cómo quedó la dificultad en `main` (ya con `feature/team-strength` mergeada): `resources/js/lib/rival-difficulty.ts`, `resources/js/components/hq-difficulty-bars.tsx` y cómo lo usan `hq-next-fixtures.tsx` y `teams/fixture-calendar.tsx`. **Reutilízalo; no crees otro componente ni otra escala.** Ajusta a su firma real las llamadas `HqDifficultyBars` de este plan (aquí se escribe `<HqDifficultyBars fixture={slot} />`). Si `rival-difficulty.ts` no exporta clases de texto por nivel, añádelas allí (`RIVAL_DIFFICULTY_TEXT_CLASSES`: difícil `text-hq-live`, media `text-hq-amber`, fácil `text-hq-lime`).

- [ ] **Step 1: `value-chart.tsx`** (porta `kit.js` `valueChart` L452-503 y `final.html` `wireChart` L494-559)

```tsx
import { useRef, useState } from 'react';
import type { PointerEvent } from 'react';
import { TipRow, useChartTooltip } from '@/components/compare/chart-tooltip';
import { useCompare } from '@/components/compare/compare-context';
import { formatPercentChange, percentSeries } from '@/components/compare/derive';
import { COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { formatMillions } from '@/lib/format';

export interface ValueChartSeries {
    slot: number;
    name: string;
    history: [string, number][];
}

interface CompareValueChartProps {
    series: ValueChartSeries[];
    width: number;
    height: number;
    padRight?: number;
    label: string;
    /** View A: the series nearest the pointer highlights its player in the whole view. */
    onHighlight?: (slot: number | null) => void;
    showAxis?: boolean;
}

const PAD_LEFT = 4;
const PAD_TOP = 10;
const PAD_BOTTOM = 20;

function dayMonth(date: string): string {
    const [, month, day] = date.split('-');

    return `${day}/${month}`;
}

/**
 * Value evolution as % over each series' first snapshot, on one scale,
 * scrubbable: crosshair and a dot per series, the tooltip with each value
 * and change; one tab stop, ←/→ a day, AvPág/RePág 7 days, Inicio/Fin, and
 * each day announced.
 */
export function CompareValueChart({ series, width, height, padRight = 8, label, onHighlight, showAxis = true }: CompareValueChartProps) {
    const { announce } = useCompare();
    const { show, hide } = useChartTooltip();
    const wrapRef = useRef<HTMLDivElement>(null);
    const svgRef = useRef<SVGSVGElement>(null);
    const [cursor, setCursor] = useState<number | null>(null);

    // Series may have different lengths: align on the common tail (the same last days for everyone).
    const length = Math.min(...series.map((item) => item.history.length));

    if (series.length === 0 || length < 2) {
        return <p className="font-mono text-xs text-hq-moss-dim">Sin histórico suficiente</p>;
    }

    const aligned = series.map((item) => ({ ...item, history: item.history.slice(-length) }));
    const values = aligned.map((item) => percentSeries(item.history));
    const flat = values.flat();
    const min = Math.min(0, ...flat);
    const span = Math.max(...flat) - min || 1;
    const x = (index: number) => PAD_LEFT + (index * (width - PAD_LEFT - padRight)) / (length - 1);
    const y = (value: number) => PAD_TOP + (1 - (value - min) / span) * (height - PAD_TOP - PAD_BOTTOM);
    const dates = aligned[0].history.map(([date]) => date);

    const tipFor = (index: number, hot: number | null) => {
        const ago = length - 1 - index;
        const head = `${dayMonth(dates[index])} · ${ago === 0 ? 'hoy' : `hace ${ago} ${ago === 1 ? 'día' : 'días'}`}`;
        const order = aligned.map((_, position) => position).sort((a, b) => values[b][index] - values[a][index]);

        return {
            content: (
                <>
                    <b className="font-sans text-[12.5px] leading-[1.15] font-black uppercase">{head}</b>
                    <span className="mt-[3px] text-hq-moss-dim">valor · cambio desde el {dayMonth(dates[0])}</span>
                    <span className="mt-2 flex flex-col gap-1 border-t border-hq-border pt-[7px]">
                        {order.map((position) => (
                            <TipRow
                                key={aligned[position].slot}
                                slotColor={COMPARE_SLOT_COLORS[aligned[position].slot]}
                                name={aligned[position].name}
                                value={formatMillions(aligned[position].history[index][1])}
                                extra={formatPercentChange(values[position][index])}
                                me={position === hot || aligned.length === 1}
                            />
                        ))}
                    </span>
                </>
            ),
            text: `${head}: ${order.map((position) => `${aligned[position].name} ${formatMillions(aligned[position].history[index][1])}, ${formatPercentChange(values[position][index])}`).join('; ')}`,
        };
    };

    const select = (index: number, hot: number | null, speak: boolean) => {
        const wrap = wrapRef.current;
        const svg = svgRef.current;

        if (!wrap || !svg) {
            return;
        }

        setCursor(index);
        onHighlight?.(hot === null ? null : aligned[hot].slot);
        const { content, text } = tipFor(index, hot);
        const topY = Math.min(...values.map((value) => y(value[index])));

        show(content, () => {
            if (!svg.isConnected) {
                return null;
            }

            const rect = svg.getBoundingClientRect();
            const left = rect.left + (x(index) / width) * rect.width;
            const top = rect.top + (topY / height) * rect.height;

            return { left, right: left, top: top - 4, bottom: rect.bottom };
        }, wrap);

        if (speak) {
            announce(text);
        }
    };

    const release = () => {
        setCursor(null);
        onHighlight?.(null);

        if (wrapRef.current) {
            hide(wrapRef.current);
        }
    };

    const fromPointer = (event: PointerEvent<HTMLDivElement>) => {
        const rect = svgRef.current?.getBoundingClientRect();

        if (!rect || rect.width === 0) {
            return;
        }

        const index = Math.max(0, Math.min(length - 1, Math.round((((event.clientX - rect.left) / rect.width) * width - PAD_LEFT) / (width - PAD_LEFT - padRight) * (length - 1))));
        const pointerY = ((event.clientY - rect.top) / rect.height) * height;
        let hot: number | null = null;
        let distance = Infinity;

        values.forEach((value, position) => {
            const gap = Math.abs(y(value[index]) - pointerY);

            if (gap < distance) {
                distance = gap;
                hot = position;
            }
        });

        select(index, (distance * rect.height) / height > 14 ? null : hot, false);
    };

    return (
        <div
            ref={wrapRef}
            data-cmp-chart=""
            role="group"
            tabIndex={0}
            aria-label={`${label}. Flechas izquierda y derecha para recorrer los días.`}
            className="relative cursor-crosshair touch-pan-y focus-visible:outline-offset-4"
            onPointerMove={fromPointer}
            onPointerDown={fromPointer}
            onPointerLeave={(event) => {
                if (event.pointerType !== 'touch' && document.activeElement !== wrapRef.current) {
                    release();
                }
            }}
            onFocus={(event) => {
                if (event.currentTarget.matches(':focus-visible')) {
                    select(cursor ?? length - 1, null, true);
                }
            }}
            onBlur={release}
            onKeyDown={(event) => {
                const current = cursor ?? length - 1;
                const moves: Record<string, number> = { ArrowLeft: current - 1, ArrowRight: current + 1, Home: 0, End: length - 1, PageUp: current - 7, PageDown: current + 7 };

                if (event.key in moves) {
                    event.preventDefault();
                    select(Math.max(0, Math.min(length - 1, moves[event.key])), null, true);
                }
            }}
        >
            <svg ref={svgRef} viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" className="block h-auto w-full" aria-hidden="true">
                {[0, 0.5, 1].map((fraction) => {
                    const lineY = PAD_TOP + fraction * (height - PAD_TOP - PAD_BOTTOM);

                    return <line key={fraction} x1={PAD_LEFT} x2={width - padRight} y1={lineY} y2={lineY} stroke="var(--color-hq-border)" vectorEffect="non-scaling-stroke" />;
                })}
                <line x1={PAD_LEFT} x2={width - padRight} y1={y(0)} y2={y(0)} stroke="var(--color-hq-border-strong)" strokeDasharray="3 3" vectorEffect="non-scaling-stroke" />
                {values.map((value, position) => (
                    <g key={aligned[position].slot} data-slot={aligned[position].slot}>
                        <path
                            d={value.map((point, index) => `${index ? 'L' : 'M'}${x(index).toFixed(1)} ${y(point).toFixed(1)}`).join(' ')}
                            fill="none"
                            stroke={COMPARE_SLOT_COLORS[aligned[position].slot]}
                            strokeWidth={2}
                            vectorEffect="non-scaling-stroke"
                        />
                    </g>
                ))}
                {showAxis && (
                    <>
                        <text x={PAD_LEFT} y={height - 5} className="fill-hq-moss-dim font-mono text-[10px]">{dayMonth(dates[0])}</text>
                        <text x={width - padRight} y={height - 5} textAnchor="end" className="fill-hq-moss-dim font-mono text-[10px]">hoy</text>
                    </>
                )}
            </svg>
            {cursor !== null && (
                <>
                    <span className="pointer-events-none absolute w-px bg-hq-moss-dim" style={{ left: `${(x(cursor) / width) * 100}%`, top: `${(PAD_TOP / height) * 100}%`, height: `${((height - PAD_TOP - PAD_BOTTOM) / height) * 100}%` }} />
                    {values.map((value, position) => (
                        <i
                            key={aligned[position].slot}
                            data-slot={aligned[position].slot}
                            className="pointer-events-none absolute -mt-[4.5px] -ml-[4.5px] size-[9px] rounded-full shadow-[0_0_0_2px_var(--color-hq-ink)]"
                            style={{ left: `${(x(cursor) / width) * 100}%`, top: `${(y(value[cursor]) / height) * 100}%`, background: COMPARE_SLOT_COLORS[aligned[position].slot] }}
                        />
                    ))}
                </>
            )}
        </div>
    );
}
```

(Si el linter exige no llamar hooks tras el `return` temprano, mueve el `return` de "Sin histórico suficiente" a después de declarar todos los hooks, como está: los hooks ya van antes.)

- [ ] **Step 2: `view-a.tsx`** (porta `final.html` L561-654; estilos de `a.html`/`kit.css` `.cmp`, `.corner`, `.row`, `.cell`, `.win`, `.chan`, `.score`)

```tsx
import { Link } from '@inertiajs/react';
import { House, Plane, Plus, Repeat2, Shield, User, X } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { useSlotHighlight } from '@/components/compare/chart-tooltip';
import { useCompare } from '@/components/compare/compare-context';
import type { DerivedPlayer } from '@/components/compare/derive';
import { winner } from '@/components/compare/derive';
import { CompareProperty, ComparePropertyLine } from '@/components/compare/property';
import { CompareValueChart } from '@/components/compare/value-chart';
import { HqDifficultyBars } from '@/components/hq-difficulty-bars';
import { HqLed } from '@/components/hq-led';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqStartMeter } from '@/components/hq-start-probability';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { COMPARE_MAX, COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { formatAverage, formatCurrency, formatDecimal, formatMillions } from '@/lib/format';
import { daznPointsBadgeClass, matchPointsBadgeClass } from '@/lib/points';
import { formatDifficulty, rivalDifficultyLevel, RIVAL_DIFFICULTY_LABELS, RIVAL_DIFFICULTY_TEXT_CLASSES } from '@/lib/rival-difficulty';
import { useMediaQuery } from '@/lib/use-media-query';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { ComparedPlayer } from '@/types/models';

interface RowSpec {
    label: string;
    hint?: string;
    cells: ReactNode[];
    /** Numbers the row winner is picked from; no `values` = no winner ("sin ganador"). */
    values?: (number | null)[];
    lowerIsBetter?: boolean;
    cellClassName?: string;
    /** Propiedad: one full-width line per player on phones. */
    stackOnPhone?: boolean;
}

interface SectionSpec {
    title: string;
    rows: (RowSpec | 'chart')[];
}

function shortDay(iso: string): string {
    const date = new Date(iso);

    return `${new Intl.DateTimeFormat('es-ES', { weekday: 'short' }).format(date).replace('.', '')} ${date.getDate()}`;
}

function longDay(iso: string): string {
    return new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(iso));
}

/**
 * Próximos 3 (mock pasada 2, con la dificultad 0–10): resumen, indicador en
 * la misma escala para todas las columnas (0 fácil → 10 difícil) y las tres
 * jornadas en el mismo orden para todos. Cada jornada usa HqDifficultyBars.
 */
function NextThree({ player, derived, slot }: { player: ComparedPlayer; derived: DerivedPlayer; slot: number }) {
    if (derived.upcoming.length === 0) {
        return <span className="font-mono text-xs text-hq-moss-dim">Sin partidos programados</span>;
    }

    const average = derived.nextAverageDifficulty;
    const level = average === null ? null : rivalDifficultyLevel(average);
    const rivalAverage = derived.nextAverageRivalPosition;

    return (
        <div className="@container flex flex-col gap-2.5">
            <div className="flex flex-col gap-1.5">
                <div className="flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5 font-mono text-[11px] leading-[1.3] text-hq-moss-dim">
                    {level && <b className={cn('text-xs font-bold tracking-[0.07em] uppercase', RIVAL_DIFFICULTY_TEXT_CLASSES[level])}>Dificultad {RIVAL_DIFFICULTY_LABELS[level]}</b>}
                    <span className="text-hq-moss tabular-nums">
                        {average !== null && `media ${formatDifficulty(average)} · `}
                        {rivalAverage !== null && `rival medio ${formatAverage(rivalAverage)}.º · `}
                        {derived.nextHomeCount} en casa
                    </span>
                </div>
                {average !== null && (
                    <>
                        <div
                            role="img"
                            aria-label={`Calendario de ${player.name}: dificultad ${formatDifficulty(average)} de 10${rivalAverage !== null ? `, rival medio ${formatAverage(rivalAverage)}.º` : ''}, ${derived.nextHomeCount} de ${derived.upcoming.length} en casa`}
                            className="relative my-0.5 grid h-2 grid-cols-[35fr_30fr_35fr] gap-0.5"
                            style={{ '--slot': COMPARE_SLOT_COLORS[slot] } as CSSProperties}
                        >
                            <i className="block bg-[color-mix(in_srgb,var(--color-hq-lime)_22%,var(--color-hq-well))]" />
                            <i className="block bg-[color-mix(in_srgb,var(--color-hq-amber)_22%,var(--color-hq-well))]" />
                            <i className="block bg-[color-mix(in_srgb,var(--color-hq-live)_30%,var(--color-hq-well))]" />
                            <span className="absolute -top-1 -ml-0.5 h-4 w-1 bg-(--slot) shadow-[0_0_0_2px_var(--color-hq-ink)]" style={{ left: `${Math.max(0, Math.min(100, average * 10))}%` }} />
                        </div>
                        <div aria-hidden="true" className="flex justify-between font-mono text-[10px] leading-none tracking-[0.06em] text-hq-led-off uppercase @max-[170px]:hidden">
                            <span>fácil</span>
                            <span>difícil</span>
                        </div>
                    </>
                )}
            </div>
            <ul className="m-0 list-none border-t border-hq-border p-0">
                {player.next_fixtures.map((slotFixture, index) => {
                    if (!slotFixture) {
                        return <li key={index} className="min-h-[46px] border-b border-dashed border-hq-border py-[7px] font-mono text-xs text-hq-moss-dim last:border-b-0">Sin partido</li>;
                    }

                    return (
                        <li key={slotFixture.week_number + '-' + index} className="grid min-h-[46px] grid-cols-[22px_minmax(0,1fr)_auto] items-center gap-x-2.5 gap-y-[3px] border-b border-dashed border-hq-border py-[7px] last:border-b-0">
                            <span className="sr-only">
                                Jornada {slotFixture.week_number}, {longDay(slotFixture.date)}, {slotFixture.is_home ? 'en casa contra ' : 'fuera contra '}
                                {slotFixture.opponent.name}, {slotFixture.rival_position}.º de la tabla
                                {slotFixture.difficulty !== null ? `, dificultad ${formatDifficulty(slotFixture.difficulty)} de 10` : ''}
                            </span>
                            <EntityImage src={slotFixture.opponent.logo} alt="" fallback={Shield} shape="square" className="size-[22px] rounded-none bg-transparent object-contain" />
                            <span aria-hidden="true" className="flex min-w-0 flex-col gap-1">
                                <b className="truncate text-[13px] leading-[1.1] font-extrabold text-hq-paper">
                                    <span className="@min-[300px]:hidden">{slotFixture.opponent.short_name}</span>
                                    <span className="hidden @min-[300px]:inline">{slotFixture.opponent.name}</span>
                                </b>
                                <span className="flex items-center gap-1.5 font-mono text-[11px] leading-none whitespace-nowrap text-hq-moss-dim tabular-nums">
                                    <span className="font-bold text-hq-moss">J{slotFixture.week_number}</span>
                                    <span>{shortDay(slotFixture.date)}</span>
                                    <span className="inline-flex items-center gap-1 text-hq-moss">
                                        {slotFixture.is_home ? <House className="size-3" /> : <Plane className="size-3" />}
                                        <span className="hidden @min-[300px]:inline">{slotFixture.is_home ? 'Casa' : 'Fuera'}</span>
                                    </span>
                                </span>
                            </span>
                            <span className="flex flex-col items-end gap-1">
                                <HqDifficultyBars fixture={slotFixture} />
                                <span aria-hidden="true" className="font-mono text-[11px] text-hq-moss tabular-nums">{slotFixture.rival_position}.º</span>
                            </span>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function sections(players: ComparedPlayer[], derived: DerivedPlayer[], currentWeek: number): SectionSpec[] {
    return [
        {
            title: 'Mercado',
            rows: [
                { label: 'Valor', hint: 'sin ganador', cells: players.map((player) => (<span className="font-mono text-[15px] font-bold text-hq-paper tabular-nums"><span className="max-sm:hidden">{formatCurrency(player.value)}</span><span className="sm:hidden">{formatMillions(player.value)}</span></span>)) },
                { label: 'Hoy', cells: players.map((player) => <HqMarketValueDifference difference={player.difference} trend={player.trend} className="text-sm" />), values: players.map((player) => player.difference) },
                { label: '30 días', hint: 'multiplicador', cells: players.map((player) => (player.value_trend_30d ? (<><span className="font-mono text-[15px] font-bold text-hq-paper tabular-nums">×{formatDecimal(player.value_trend_30d.multiple)}</span><span className="font-mono text-[11px] text-hq-moss-dim">desde {formatMillions(player.value_trend_30d.value)}</span></>) : <span className="text-hq-moss-dim">—</span>)), values: players.map((player) => player.value_trend_30d?.multiple ?? null) },
                'chart',
            ],
        },
        {
            title: 'Rendimiento',
            rows: [
                { label: 'Puntos', cells: players.map((player, index) => (<><HqLed tone="lime" className="text-2xl">{player.points}</HqLed><span className="font-mono text-[11px] text-hq-moss-dim">{derived[index].starts} titularidades</span></>)), values: players.map((player) => player.points) },
                { label: 'Media', hint: 'por partido', cells: players.map((player) => <span className={cn('self-start px-2 py-1 font-mono text-sm font-bold tabular-nums', matchPointsBadgeClass(player.average_points))}>{formatAverage(player.average_points)}</span>), values: players.map((player) => player.average_points) },
                { label: 'Media DAZN', hint: 'oficial', cells: derived.map((item) => (item.daznAverage === null ? <span className="text-hq-moss-dim">—</span> : <span className={cn('self-start px-2 py-1 font-mono text-sm font-bold tabular-nums', daznPointsBadgeClass(item.daznAverage))}>{formatAverage(item.daznAverage)}</span>)), values: derived.map((item) => (item.daznAverage === null ? null : Math.round(item.daznAverage * 10) / 10)) },
                { label: 'Pts / M€', cells: players.map((player) => (player.points_per_million ? (<><span className="font-mono text-[15px] font-bold text-hq-paper tabular-nums">{formatDecimal(player.points_per_million.value)}</span>{player.points_per_million.rank !== null && <span className="font-mono text-[11px] text-hq-moss-dim">{player.points_per_million.rank}.º de {player.points_per_million.ranked}</span>}</>) : <span className="text-hq-moss-dim">—</span>)), values: players.map((player) => player.points_per_million?.value ?? null) },
                { label: 'Minutos', hint: 'temporada', cells: derived.map((item) => (<><span className="font-mono text-[15px] font-bold text-hq-paper tabular-nums">{item.minutes}'</span><span className="font-mono text-[11px] text-hq-moss-dim">{item.minutesShare === null ? '—' : `${item.minutesShare} % posibles`}</span></>)), values: derived.map((item) => item.minutes) },
            ],
        },
        {
            title: 'Forma',
            rows: [
                { label: 'Últimas jornadas', hint: 'puntos por jornada', cells: derived.map((item) => { const last = (count: number) => item.weeks.slice(-count); return (<><HqRecentScores className="max-sm:hidden" scores={last(5).map((cell) => cell.score?.points ?? null)} finished={last(5).map(() => true)} opponents={last(5).map((cell) => cell.score?.opponent ?? null)} focusable /><HqRecentScores className="sm:hidden" size="sm" scores={last(3).map((cell) => cell.score?.points ?? null)} finished={last(3).map(() => true)} opponents={last(3).map((cell) => cell.score?.opponent ?? null)} focusable /></>); }), values: derived.map((item) => (item.weeks.length === 0 ? null : item.weeks.slice(-5).reduce((sum, cell) => sum + (cell.score?.points ?? 0), 0))) },
                { label: 'Últimas 3', hint: 'puntos · minutos', cells: derived.map((item) => (<><span className="font-mono text-[15px] font-bold text-hq-paper tabular-nums">{item.last3Points} pts</span><span className="font-mono text-[11px] text-hq-moss-dim">{item.last3Minutes}' de {item.last3.length * 90}'</span></>)), values: derived.map((item) => (item.last3.length === 0 ? null : item.last3Points)) },
            ],
        },
        {
            title: 'Calendario',
            rows: [
                { label: `Titularidad J${currentWeek}`, hint: 'FútbolFantasy', cells: players.map((player, index) => (<><HqStartMeter probability={derived[index].startProbability} status={player.status} size="sm" fetchedAt={player.next_start?.fetched_at ?? null} />{player.next_start && <span className="font-mono text-[11px] text-hq-moss-dim">{player.next_start.is_home ? 'vs ' : '@ '}{player.next_start.opponent.short_name} · {shortDay(player.next_start.date)}</span>}</>)), values: derived.map((item) => (item.startTone === 'out' ? -1 : item.startProbability)) },
                { label: 'Próximos 3', hint: 'gana la dificultad media más baja', cellClassName: 'justify-start', cells: players.map((player, index) => <NextThree player={player} derived={derived[index]} slot={index} />), values: derived.map((item) => (item.nextAverageDifficulty === null ? null : Math.round(item.nextAverageDifficulty * 10) / 10)), lowerIsBetter: true },
            ],
        },
        {
            title: 'Propiedad',
            rows: [
                { label: 'Dueño y cláusula', hint: 'sin ganador', stackOnPhone: true, cells: players.map((player, index) => (<><span className="max-sm:hidden"><CompareProperty player={player} derived={derived[index]} /></span><span className="sm:hidden"><ComparePropertyLine player={player} derived={derived[index]} /></span></>)) },
            ],
        },
    ];
}
```

Y el componente (continúa en el mismo archivo):

```tsx
export function CompareViewA() {
    const { players, derived, currentWeek, remove, openPicker } = useCompare();
    const { rootProps, bind, setHighlighted } = useSlotHighlight();
    const phone = useMediaQuery('(max-width: 639px)');
    const columns = players.length < COMPARE_MAX ? players.length + 1 : COMPARE_MAX;
    const tally = players.map(() => 0);
    const specs = sections(players, derived, currentWeek);
    const grid = 'grid grid-cols-[repeat(var(--cols),minmax(0,1fr))] sm:grid-cols-[minmax(128px,180px)_repeat(var(--cols),minmax(0,1fr))]';

    const renderedSections = specs.map((section) => {
        const sectionTally = players.map(() => 0);
        const rows = section.rows.map((row, rowIndex) => {
            if (row === 'chart') {
                return (
                    <div key="chart" className={cn(grid, 'border-b border-hq-border')}>
                        <div className="col-span-full px-3.5 pt-3 sm:col-span-1 sm:px-4 sm:py-3.5">
                            <b className="block text-xs font-extrabold text-hq-paper uppercase">Evolución</b>
                            <small className="font-mono text-[11px] text-hq-moss-dim">30 días, % sobre el valor inicial</small>
                        </div>
                        <div className="col-span-full flex flex-col gap-2 px-3.5 py-3 sm:col-span-[var(--cols)] sm:px-4">
                            <div className="flex flex-wrap gap-x-4 gap-y-1 font-mono text-[11px]">
                                {players.map((player, index) => (
                                    <span key={player.id} data-slot={index} {...bind(index)} tabIndex={0} className="inline-flex items-center gap-1.5 py-0.5 text-hq-moss">
                                        <i className="inline-block h-0.5 w-3.5" style={{ background: COMPARE_SLOT_COLORS[index] }} />
                                        {player.name}
                                        {player.value_trend_30d && (
                                            <b className={player.value_trend_30d.multiple >= 1 ? 'text-hq-lime' : 'text-hq-neg'}>
                                                {player.value_trend_30d.multiple >= 1 ? '+' : ''}{Math.round((player.value_trend_30d.multiple - 1) * 100)} %
                                            </b>
                                        )}
                                    </span>
                                ))}
                            </div>
                            <CompareValueChart
                                series={players.map((player, index) => ({ slot: index, name: player.name, history: player.market_history }))}
                                width={phone ? 360 : 900}
                                height={phone ? 120 : 130}
                                label="Evolución del valor en los últimos 30 días"
                                onHighlight={setHighlighted}
                            />
                        </div>
                    </div>
                );
            }

            const best = row.values ? winner(row.values, row.lowerIsBetter) : null;

            if (best !== null) {
                tally[best]++;
                sectionTally[best]++;
            }

            return (
                <div key={rowIndex} className={cn(grid, 'border-b border-hq-border', row.stackOnPhone && 'max-sm:grid-cols-1')}>
                    <div className="col-span-full flex flex-wrap items-baseline gap-x-2 gap-y-[3px] px-3.5 pt-3 sm:col-span-1 sm:flex-col sm:px-4 sm:py-3.5">
                        <b className="text-xs font-extrabold text-hq-paper uppercase">{row.label}</b>
                        {row.hint && <small className="font-mono text-[11px] text-hq-moss-dim">{row.hint}</small>}
                    </div>
                    {row.cells.map((cell, index) => (
                        <div key={players[index].id} data-slot={index} className={cn('flex min-w-0 flex-col justify-center gap-1 px-3.5 py-3 sm:px-4', index === best && 'shadow-[inset_0_-2px_0_var(--color-hq-lime)]', row.cellClassName)}>
                            {cell}
                        </div>
                    ))}
                    {players.length < COMPARE_MAX && <div className={cn(row.stackOnPhone && 'max-sm:hidden')} />}
                </div>
            );
        });

        const winnable = section.rows.some((row) => row !== 'chart' && row.values);
        const sectionBest = Math.max(...sectionTally);

        return (
            <section key={section.title}>
                <div className="flex items-center justify-between gap-3 border-b border-hq-border-strong bg-hq-panel px-3.5 py-2.5 sm:px-4">
                    <h2 className="hq-label">{section.title}</h2>
                    {winnable && players.length > 1 && (
                        <span className="flex flex-wrap items-center gap-1.5 font-mono text-[11px] text-hq-moss">
                            {players.map((player, index) => (
                                <span key={player.id}>
                                    {index > 0 && <i className="mr-1.5 text-hq-led-off not-italic">·</i>}
                                    {player.name.split(' ').slice(-1)[0]}{' '}
                                    <b className={sectionTally[index] === sectionBest && sectionBest > 0 ? 'text-hq-lime' : 'text-hq-paper'}>{sectionTally[index]}</b>
                                </span>
                            ))}
                        </span>
                    )}
                </div>
                {rows}
            </section>
        );
    });

    const top = Math.max(...tally);

    return (
        <div {...rootProps} style={{ '--cols': columns } as CSSProperties}>
            <div className={cn(grid, 'sticky top-(--hq-header-h) z-20 border-b border-hq-border-strong bg-hq-ink/96 backdrop-blur-[6px]')}>
                <div className="hidden flex-col justify-end gap-1 px-4 py-3 sm:flex">
                    <span className="hq-label">{players.length} de {COMPARE_MAX} jugadores</span>
                    <span className="hq-label text-hq-led-off">filas ganadas ↓</span>
                </div>
                {players.map((player, index) => (
                    <div key={player.id} data-slot={index} {...bind(index)} className="relative flex min-w-0 flex-col gap-2 border-t-2 px-3 py-2.5 sm:px-4 sm:py-3" style={{ borderTopColor: COMPARE_SLOT_COLORS[index] }}>
                        <div className="absolute top-1.5 right-1.5 flex gap-1">
                            <button type="button" data-replace={index} onClick={() => openPicker(index)} aria-label={`Cambiar a ${player.name}`} className="flex size-9 cursor-pointer items-center justify-center text-hq-moss-dim hover:text-hq-paper sm:size-7"><Repeat2 aria-hidden="true" className="size-3.5" /></button>
                            <button type="button" onClick={() => remove(index)} aria-label={`Quitar a ${player.name}`} className="flex size-9 cursor-pointer items-center justify-center text-hq-moss-dim hover:text-hq-live sm:size-7"><X aria-hidden="true" className="size-3.5" /></button>
                        </div>
                        <div className="flex min-w-0 items-center gap-2.5 pr-16">
                            <EntityImage src={player.image} alt={player.name} fallback={User} shape="square" className="size-8 shrink-0 rounded-none border border-hq-border-strong object-cover object-top sm:size-11" />
                            <div className="min-w-0">
                                <h2 className="text-[13px] leading-[1.1] font-black break-normal text-hq-paper uppercase sm:text-base">
                                    <Link href={playersShow(player.id).url} className="cursor-pointer hover:underline">{player.name}</Link>
                                </h2>
                                <div className="mt-1 flex flex-wrap items-center gap-1.5 font-mono text-[11px] text-hq-moss-dim">
                                    <HqPositionTag position={player.position} />
                                    <EntityImage src={player.team.logo} alt="" fallback={Shield} shape="square" className="size-3.5 rounded-none bg-transparent" />
                                    <span>{player.team.short_name}</span>
                                    <HqStatusBadge status={player.status} />
                                </div>
                            </div>
                        </div>
                        {players.length > 1 && (
                            <div className={cn('font-mono text-[11px] tabular-nums', tally[index] === top ? 'text-hq-lime' : 'text-hq-moss')}>
                                <HqLed tone={tally[index] === top ? 'lime' : 'moss'} className="text-lg">{tally[index]}</HqLed> {tally[index] === 1 ? 'fila' : 'filas'}
                            </div>
                        )}
                    </div>
                ))}
                {players.length < COMPARE_MAX && (
                    <div className="flex p-2">
                        <button type="button" data-add="" onClick={() => openPicker(null)} className="flex flex-1 cursor-pointer flex-col items-center justify-center gap-1 border border-dashed border-hq-border-strong p-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase hover:border-hq-lime hover:text-hq-lime">
                            <Plus aria-hidden="true" className="size-4" />
                            Añadir jugador
                            <small className="font-normal tracking-normal text-hq-moss-dim normal-case max-sm:hidden">busca o pulsa /</small>
                        </button>
                    </div>
                )}
            </div>
            {renderedSections}
            <p className="px-3.5 py-4 font-mono text-[11px] leading-normal text-hq-moss-dim sm:px-4">
                Gana cada fila el mejor valor, sin empate. Valor y cláusula no tienen ganador: depende de si compras o vendes. Calendario: dificultad 0–10 según la fuerza del rival (valor de plantilla + rendimiento) y si se juega en casa; gana la media más baja de los 3 próximos. Probabilidades: FútbolFantasy · DAZN: puntuación oficial.
            </p>
        </div>
    );
}
```

Detalles a verificar contra el mock y el código real:
- `HqLed` puede no tener tono `moss`; usa los tonos que existan (`lime` para el que lidera y el neutro que tenga el componente).
- La cabecera fija en móvil compacta la foto y el nombre (mock `.cmp.stuck`, `final.html` L71-76); si hace falta, un `IntersectionObserver` sobre un centinela encima de la cabecera pone `data-stuck` y reduce foto y tipografía.
- Los badges HQ de A ya traen su propio `HqTooltip`; si alguna celda nueva necesita tooltip, usa `useTipTarget` (Task 7), no `title`.

- [ ] **Step 3: monta la vista** en `compare.tsx`: `{comparison.view === 'a' && <CompareViewA />}`.

- [ ] **Step 4: comprobaciones**

Run: `npm run types:check && npm run lint:check && npx prettier --check resources/js/components/compare resources/js/pages/players/compare.tsx && npm run build`
Expected: verde.

- [ ] **Step 5: navegador** (1440, 800 y 390 px, con 2 y 3 jugadores, uno de ellos con cláusula, otro en el mercado y otro libre):
  - ganador por fila subrayado en lima, ninguno con empate; recuento por sección y total en la cabecera ("1 fila" en singular);
  - Próximos 3: resumen, indicador con la misma escala en todas las columnas (marcador del color del hueco), las tres jornadas alineadas entre columnas, y **las mismas barras y el mismo tooltip de dificultad que el calendario de Equipos**; gana la media más baja;
  - gráfico: arrastrar con ratón y dedo, teclado (←/→, AvPág/RePág, Inicio/Fin) con anuncio; el jugador más cercano se resalta y los demás bajan al 30 %;
  - "Cambiar" y "Añadir jugador" abren el modal con 6 sugerencias del mismo puesto; tras elegir, el foco queda en el "Cambiar" del nuevo jugador;
  - móvil: sin scroll horizontal, Propiedad apilada una línea por jugador, importes compactos;
  - **Review Focus 3:** compara un jugador sin histórico y sin próximos partidos: "Sin histórico suficiente" y "Sin partidos programados", sin errores en consola.

- [ ] **Step 6: commit**

```bash
git add resources/js/components/compare resources/js/pages/players/compare.tsx resources/js/lib/rival-difficulty.ts
git commit -m "feat: add the head-to-head comparator view

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Vista B · Pistas

**Files:**
- Crear: `resources/js/components/compare/view-b.tsx`
- Modificar: `resources/js/pages/players/compare.tsx`

**Interfaces:**
- Consumes: `useCompare`, `trackMetrics`, `trackValues`, `rankIn`, `beatsPercent`, `medianOf`, `trackPosition`, `jitter`, `winner`, `useSlotHighlight`, `useChartTooltip`, `TipRow`, `mergeProps`, `CompareProperty`/`ComparePropertyLine`, `HqRecentScores`, `HqNextFixtures` (que tras team-strength pinta `HqDifficultyBars`), `daznPointsBadgeClass`.
- Produces: `CompareViewB()`.

- [ ] **Step 0: antes de implementar**, lee cómo quedó en `main` `HqNextFixtures` con `HqDifficultyBars` (team-strength) y úsalo tal cual en la tarjeta "Próximos"; **no crees otro componente ni otra escala**.

- [ ] **Step 1: `view-b.tsx`** (porta `final.html` L692-844 y los estilos de `b.html`: `.track`, `.t-body`, `.dust`, `.median`, `.axis`, `.lane`, `.mk`, `.val`, `.stem`, `.hoverline`, `.hovercard`, `.ctxgrid`, `.pc`)

```tsx
import { Plus, Shield, User, X } from 'lucide-react';
import type { CSSProperties, KeyboardEvent, MouseEvent } from 'react';
import { useRef, useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { TipRow, useChartTooltip, useSlotHighlight } from '@/components/compare/chart-tooltip';
import { useCompare } from '@/components/compare/compare-context';
import type { TrackMetric, TrackScope } from '@/components/compare/derive';
import { beatsPercent, jitter, medianOf, rankIn, trackMetrics, trackPosition, trackValues, winner } from '@/components/compare/derive';
import { CompareProperty, ComparePropertyLine } from '@/components/compare/property';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { COMPARE_MAX, COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { formatAverage } from '@/lib/format';
import { POSITION_ABBREVIATIONS } from '@/lib/player-labels';
import { daznPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { LeagueCloudRow } from '@/types/models';

const LANE_HEIGHT = 34;
const DUST_HEIGHT = 26;

interface HoverState {
    track: number;
    row: LeagueCloudRow;
    x: number;
    rank: number;
    count: number;
}

export function CompareViewB() {
    const { players, derived, league, currentWeek, add, remove, openPicker } = useCompare();
    const { show, hide } = useChartTooltip();
    const { rootProps, bind } = useSlotHighlight();
    const [scope, setScope] = useState<TrackScope>('all');
    const [focus, setFocus] = useState({ track: 0, player: 0 });
    const [hover, setHover] = useState<HoverState | null>(null);
    const tracksRef = useRef<HTMLDivElement>(null);
    const metrics = trackMetrics(currentWeek);
    const positions = players.map((player) => player.position);
    const samePosition = players.every((player) => player.position === players[0].position);
    const scopeLabel = scope === 'position' ? 'de su puesto' : 'de la liga';

    const domainOf = (metric: TrackMetric, values: number[]): [number, number] => {
        if (metric.fixed) {
            return metric.fixed;
        }

        const all = [...values, ...players.map((player, index) => metric.player(player, derived[index])).filter((value): value is number => value !== null)];

        return [Math.min(...all), Math.max(...all)];
    };

    const markerTip = (trackIndex: number, playerIndex: number) => {
        const metric = metrics[trackIndex];
        const values = trackValues(league, metric, scope, positions);
        const value = metric.player(players[playerIndex], derived[playerIndex]);

        if (value === null) {
            return null;
        }

        const rank = rankIn(values, value);
        const shown = derived[playerIndex].startTone === 'out' && metric.key === 'start' ? 'Baja' : metric.format(value);

        return {
            content: (
                <>
                    <b className="font-sans text-[12.5px] font-black uppercase">{players[playerIndex].name}</b>
                    <span className="mt-[3px] text-hq-moss-dim">{metric.label} · {metric.note}</span>
                    <span className="mt-[7px] font-mono text-xl font-bold text-hq-paper tabular-nums">{shown}</span>
                    <span className="mt-[3px] text-hq-moss-dim">{rank}.º de {values.length} {scopeLabel} · supera al {beatsPercent(values, value)} %</span>
                    {players.length > 1 && (
                        <span className="mt-2 flex flex-col gap-1 border-t border-hq-border pt-[7px]">
                            {players.map((player, index) => {
                                const other = metric.player(player, derived[index]);

                                return <TipRow key={player.id} slotColor={COMPARE_SLOT_COLORS[index]} name={player.name} value={other === null ? 'sin dato' : metric.format(other)} extra={other === null ? '' : `${rankIn(values, other)}.º`} me={index === playerIndex} />;
                            })}
                        </span>
                    )}
                </>
            ),
            text: `${players[playerIndex].name}, ${metric.label}: ${shown}, ${rank}.º de ${values.length} ${scopeLabel}`,
        };
    };

    const onTracksKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const moves: Record<string, { track: number; player: number }> = {
            ArrowLeft: { track: focus.track, player: Math.max(0, focus.player - 1) },
            ArrowRight: { track: focus.track, player: Math.min(players.length - 1, focus.player + 1) },
            ArrowUp: { track: Math.max(0, focus.track - 1), player: focus.player },
            ArrowDown: { track: Math.min(metrics.length - 1, focus.track + 1), player: focus.player },
            Home: { track: focus.track, player: 0 },
            End: { track: focus.track, player: players.length - 1 },
        };

        if (!(event.key in moves)) {
            return;
        }

        event.preventDefault();
        const next = moves[event.key];
        setFocus(next);
        requestAnimationFrame(() => tracksRef.current?.querySelector<HTMLElement>(`[data-bm="${next.track}"][data-bi="${next.player}"]`)?.focus());
    };

    /** Nearest league player to the pointer on this track (mock `nearest`). */
    const hoverAt = (event: MouseEvent<HTMLDivElement>, trackIndex: number, values: number[], domain: [number, number]) => {
        if ((event.target as Element).closest('[data-bm]')) {
            setHover(null);

            return;
        }

        const metric = metrics[trackIndex];
        const rect = event.currentTarget.getBoundingClientRect();
        const fraction = (event.clientX - rect.left) / rect.width;
        let best: { row: LeagueCloudRow; distance: number; x: number } | null = null;

        for (const row of league) {
            const value = metric.league(row);

            if (value === null || (scope === 'position' && !positions.includes(row.position))) {
                continue;
            }

            const x = trackPosition(value, domain, metric.scale);
            const distance = Math.abs(x / 100 - fraction);

            if (best === null || distance < best.distance || (distance === best.distance && row.points > best.row.points)) {
                best = { row, distance, x };
            }
        }

        if (best) {
            const value = metric.league(best.row) ?? 0;
            setHover({ track: trackIndex, row: best.row, x: best.x, rank: rankIn(values, value), count: values.length });
        }
    };

    return (
        <div {...rootProps}>
            <div className="flex flex-wrap items-center gap-3 border-b border-hq-border px-3.5 py-3 sm:px-4 sm:py-3.5">
                <span className="hq-label">Comparar contra</span>
                <div role="group" aria-label="Población de referencia" className="inline-flex border border-hq-border-strong">
                    {(['all', 'position'] as const).map((option) => (
                        <button key={option} type="button" aria-pressed={scope === option} onClick={() => setScope(option)} className={cn('h-11 cursor-pointer px-3 font-mono text-[11px] font-bold tracking-[0.06em] uppercase sm:h-8', scope === option ? 'bg-hq-lime text-hq-ink' : 'text-hq-moss hover:text-hq-paper')}>
                            {option === 'all' ? 'Toda la liga' : samePosition ? `Solo ${POSITION_ABBREVIATIONS[players[0].position]}` : 'Su puesto'}
                        </button>
                    ))}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 border-b border-hq-border px-3.5 py-2.5 sm:px-4">
                {players.map((player, index) => (
                    <span key={player.id} data-slot={index} {...bind(index)} className="inline-flex items-center gap-2 border border-l-2 border-hq-border-strong py-1 pl-1 text-xs font-extrabold text-hq-paper uppercase" style={{ borderLeftColor: COMPARE_SLOT_COLORS[index] }}>
                        <EntityImage src={player.image} alt="" fallback={User} shape="square" className="size-6 rounded-none object-cover object-top" />
                        {player.name}
                        <HqPositionTag position={player.position} />
                        <button type="button" onClick={() => remove(index)} aria-label={`Quitar a ${player.name}`} className="flex size-9 cursor-pointer items-center justify-center text-hq-moss-dim hover:text-hq-live sm:size-7"><X aria-hidden="true" className="size-3.5" /></button>
                    </span>
                ))}
                {players.length < COMPARE_MAX ? (
                    <button type="button" data-add="" onClick={() => openPicker(null)} className="inline-flex h-11 cursor-pointer items-center gap-1.5 border border-dashed border-hq-border-strong px-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase hover:border-hq-lime hover:text-hq-lime sm:h-9">
                        <Plus aria-hidden="true" className="size-3.5" />
                        Añadir jugador
                    </button>
                ) : (
                    <span className="font-mono text-[11px] text-hq-moss-dim">Máximo 3 · quita uno para añadir otro</span>
                )}
            </div>

            <p className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3.5 py-3 font-mono text-[11px] text-hq-moss sm:px-4">
                <span>Cada marca es un jugador de LaLiga.</span>
                <span className="text-hq-lime">mejor →</span>
                <span>Pasa el dedo por una pista para ver quién es y tócalo para añadirlo.</span>
            </p>

            <section>
                <div className="flex items-center justify-between border-y border-hq-border-strong bg-hq-panel px-3.5 py-2.5 sm:px-4">
                    <h2 className="hq-label">Dónde está cada uno</h2>
                    <span className="font-mono text-[11px] text-hq-moss-dim">{trackValues(league, metrics[0], scope, positions).length} jugadores con puntos{scope === 'position' ? ' en su puesto' : ''}</span>
                </div>
                <div ref={tracksRef} role="group" aria-label="Pistas de la liga. Flechas arriba y abajo para cambiar de pista; izquierda y derecha para cambiar de jugador." onKeyDown={onTracksKeyDown}>
                    {metrics.map((metric, trackIndex) => {
                        const values = trackValues(league, metric, scope, positions);
                        const domain = domainOf(metric, values);
                        const median = medianOf(values);
                        const playerValues = players.map((player, index) => metric.player(player, derived[index]));
                        const best = metric.noBest ? null : winner(playerValues);
                        const hovering = hover?.track === trackIndex ? hover : null;

                        return (
                            <div key={metric.key} className="border-b border-hq-border px-3.5 py-3 sm:px-4">
                                <div className="mb-2 flex items-baseline gap-2.5">
                                    <span className="hq-label text-hq-paper">{metric.label}</span>
                                    <span className="font-mono text-[11px] text-hq-moss-dim">{metric.note}</span>
                                </div>
                                <div
                                    className="relative"
                                    style={{ minHeight: DUST_HEIGHT + 18 + players.length * LANE_HEIGHT }}
                                    onMouseMove={(event) => hoverAt(event, trackIndex, values, domain)}
                                    onMouseLeave={() => setHover(null)}
                                    onClick={(event) => {
                                        if (!(event.target as Element).closest('[data-bm]') && hovering && players.length < COMPARE_MAX && !players.some((player) => player.id === hovering.row.id)) {
                                            add(hovering.row.id);
                                        }
                                    }}
                                >
                                    <svg viewBox={`0 0 1000 ${DUST_HEIGHT}`} preserveAspectRatio="none" aria-hidden="true" className="block w-full fill-hq-led-off" style={{ height: DUST_HEIGHT }}>
                                        {league.map((row) => {
                                            const value = metric.league(row);

                                            if (value === null || (scope === 'position' && !positions.includes(row.position))) {
                                                return null;
                                            }

                                            return <rect key={row.id} x={(trackPosition(value, domain, metric.scale) * 9.96).toFixed(1)} y={(3 + jitter(row.id, trackIndex) * 17).toFixed(1)} width={3} height={3} />;
                                        })}
                                    </svg>
                                    {median !== null && (
                                        <span className="pointer-events-none absolute top-0 border-l border-dashed border-hq-moss-dim" style={{ left: `${trackPosition(median, domain, metric.scale)}%`, height: DUST_HEIGHT }}>
                                            <span className="absolute -top-4 -translate-x-1/2 font-mono text-[10px] whitespace-nowrap text-hq-moss-dim">mediana {metric.format(median)}</span>
                                        </span>
                                    )}
                                    <span className="pointer-events-none flex justify-between font-mono text-[10px] text-hq-led-off">
                                        <span>{metric.format(domain[0])}</span>
                                        <span>{metric.format(domain[1])}</span>
                                    </span>
                                    {hovering && (
                                        <>
                                            <span className="pointer-events-none absolute top-0 w-px bg-hq-paper/60" style={{ left: `${hovering.x}%`, height: DUST_HEIGHT }} />
                                            <span className="pointer-events-none absolute top-[calc(100%-4px)] z-10 flex items-center gap-2 border border-hq-border-bright bg-hq-ink px-2 py-1 font-mono text-[11px] whitespace-nowrap text-hq-moss" style={hovering.x > 60 ? { right: `${100 - hovering.x}%` } : { left: `${hovering.x}%` }}>
                                                <EntityImage src={hovering.row.image} alt="" fallback={User} shape="square" className="size-5 rounded-none object-cover object-top" />
                                                <span className="text-hq-paper">{hovering.row.name}</span>
                                                <small>{hovering.row.team_short} · <b className="text-hq-paper">{metric.format(metric.league(hovering.row) ?? 0)}</b> · {hovering.rank}.º de {hovering.count}</small>
                                                {!players.some((player) => player.id === hovering.row.id) && players.length < COMPARE_MAX && <em className="text-hq-lime not-italic">+ añadir</em>}
                                            </span>
                                        </>
                                    )}
                                    <div className="mt-2">
                                        {players.map((player, index) => {
                                            const value = playerValues[index];

                                            if (value === null) {
                                                return (
                                                    <div key={player.id} data-slot={index} className="relative font-mono text-[11px] text-hq-moss-dim" style={{ height: LANE_HEIGHT }}>
                                                        {player.name} · sin dato
                                                    </div>
                                                );
                                            }

                                            const x = trackPosition(value, domain, metric.scale);
                                            const tip = markerTip(trackIndex, index);
                                            const isFocus = focus.track === trackIndex && focus.player === index;

                                            return (
                                                <div key={player.id} data-slot={index} className="relative" style={{ height: LANE_HEIGHT, '--slot': COMPARE_SLOT_COLORS[index] } as CSSProperties}>
                                                    <span
                                                        role="img"
                                                        tabIndex={isFocus ? 0 : -1}
                                                        data-bm={trackIndex}
                                                        data-bi={index}
                                                        data-cmp-tip=""
                                                        aria-label={tip?.text}
                                                        {...bind(index)}
                                                        onPointerEnter={(event) => {
                                                            bind(index).onPointerEnter();
                                                            const element = event.currentTarget;
                                                            if (tip) {
                                                                show(tip.content, () => (element.isConnected ? element.getBoundingClientRect() : null), element);
                                                            }
                                                        }}
                                                        onPointerLeave={(event) => {
                                                            bind(index).onPointerLeave();
                                                            if (event.pointerType !== 'touch') {
                                                                hide(event.currentTarget);
                                                            }
                                                        }}
                                                        onFocus={(event) => {
                                                            bind(index).onFocus();
                                                            setFocus({ track: trackIndex, player: index });
                                                            const element = event.currentTarget;
                                                            if (tip) {
                                                                show(tip.content, () => (element.isConnected ? element.getBoundingClientRect() : null), element);
                                                            }
                                                        }}
                                                        onBlur={(event) => {
                                                            bind(index).onBlur();
                                                            hide(event.currentTarget);
                                                        }}
                                                        className="absolute top-1/2 size-7 -translate-x-1/2 -translate-y-1/2 overflow-hidden rounded-full border-2 border-(--slot) bg-hq-ink outline-offset-4 motion-safe:transition-[left] motion-safe:duration-[450ms]"
                                                        style={{ left: `${x}%` }}
                                                    >
                                                        <EntityImage src={player.image} alt="" fallback={User} shape="square" className="size-full rounded-none object-cover object-top" />
                                                    </span>
                                                    <span aria-hidden="true" className={cn('pointer-events-none absolute top-1/2 -translate-y-1/2 font-mono text-[11px] whitespace-nowrap', index === best ? 'text-hq-lime' : 'text-hq-paper')} style={x > 62 ? { right: `calc(${100 - x}% + 20px)` } : { left: `calc(${x}% + 20px)` }}>
                                                        <span className="mr-1.5 font-sans font-extrabold uppercase">{player.name}</span>
                                                        {derived[index].startTone === 'out' && metric.key === 'start' ? 'Baja' : metric.format(value)}
                                                        <small className="ml-1.5 text-hq-moss-dim">{rankIn(values, value)}.º de {values.length}</small>
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </section>

            <section>
                <div className="border-y border-hq-border-strong bg-hq-panel px-3.5 py-2.5 sm:px-4"><h2 className="hq-label">Lo que no cabe en una pista</h2></div>
                <div className="grid grid-cols-1 sm:grid-cols-[repeat(var(--cols),minmax(0,1fr))]" style={{ '--cols': players.length } as CSSProperties}>
                    {players.map((player, index) => (
                        <article key={player.id} data-slot={index} {...bind(index)} className="flex flex-col gap-2.5 border-b border-t-2 border-hq-border p-3.5 sm:border-r sm:p-4" style={{ borderTopColor: COMPARE_SLOT_COLORS[index] }}>
                            <div className="flex items-center gap-2.5">
                                <EntityImage src={player.image} alt={player.name} fallback={User} shape="square" className="size-10 rounded-none object-cover object-top" />
                                <div className="min-w-0">
                                    <h3 className="truncate text-sm font-black text-hq-paper uppercase">{player.name}</h3>
                                    <div className="flex items-center gap-1.5 font-mono text-[11px] text-hq-moss-dim">
                                        <HqPositionTag position={player.position} />
                                        <EntityImage src={player.team.logo} alt="" fallback={Shield} shape="square" className="size-3.5 rounded-none bg-transparent" />
                                        {player.team.short_name}
                                        <HqStatusBadge status={player.status} />
                                    </div>
                                </div>
                            </div>
                            <dl className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2">
                                <dt className="hq-label">Últimas 3</dt>
                                <dd><HqRecentScores size="sm" scores={derived[index].last3.map((cell) => cell.score?.points ?? null)} finished={derived[index].last3.map(() => true)} opponents={derived[index].last3.map((cell) => cell.score?.opponent ?? null)} focusable /></dd>
                                <dt className="hq-label">Próximos</dt>
                                <dd><HqNextFixtures fixtures={player.next_fixtures} size="sm" /></dd>
                                <dt className="hq-label">DAZN</dt>
                                <dd>{derived[index].daznAverage === null ? <span className="text-hq-moss-dim">—</span> : <span className="inline-flex items-center gap-2"><span className={cn('px-1.5 py-0.5 font-mono text-xs font-bold tabular-nums', daznPointsBadgeClass(derived[index].daznAverage ?? 0))}>{formatAverage(derived[index].daznAverage ?? 0)}</span><span className="font-mono text-[11px] text-hq-moss-dim">media oficial</span></span>}</dd>
                            </dl>
                            <div className="border-t border-dashed border-hq-border pt-2.5">
                                <span className="max-sm:hidden"><CompareProperty player={player} derived={derived[index]} /></span>
                                <span className="sm:hidden"><ComparePropertyLine player={player} derived={derived[index]} /></span>
                            </div>
                        </article>
                    ))}
                </div>
            </section>
            <p className="px-3.5 py-4 font-mono text-[11px] text-hq-moss-dim sm:px-4">Pistas en escala lineal salvo valor y pts/M€ (raíz) y subida 30 días (logarítmica). Titularidad: FútbolFantasy. DAZN: puntuación oficial.</p>
        </div>
    );
}
```

(Simplifica los manejadores repetidos del marcador con `mergeProps(bind(index), …)` si queda más legible; el comportamiento es el descrito. Las "stems" del mock —la línea vertical del punto de la nube al carril— son opcionales si en el navegador no aportan; si las añades, pártelas de `final.html` L732-735.)

- [ ] **Step 2: monta la vista**: `{comparison.view === 'b' && <CompareViewB />}`.

- [ ] **Step 3: comprobaciones** (`types:check`, `lint:check`, `prettier --check`, `build`): verde.

- [ ] **Step 4: navegador** (1440 y 390 px): seis pistas; "Toda la liga" / "Su puesto" (o "Solo DEL" si comparten puesto) cambia nubes, medianas y rangos; tarjeta al pasar por la nube con "+ añadir" que añade al hacer clic si hay hueco; los marcadores de los comparados dan valor, "N.º de N de la liga / de su puesto", "supera al X %" y los demás jugadores; teclado: una sola parada, ↑/↓ pista, ←/→ jugador, Inicio/Fin; los puntos de la liga no reciben foco; las tarjetas "Próximos" muestran **las mismas barras de dificultad que las listas**; **Review Focus 3:** un jugador con valor 0 sale "sin dato" en Pts/M€ y Valor sin romper la escala log.

- [ ] **Step 5: commit**

```bash
git add resources/js/components/compare/view-b.tsx resources/js/pages/players/compare.tsx
git commit -m "feat: add the league tracks comparator view

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Vista C · Carriles

**Files:**
- Crear: `resources/js/components/compare/view-c.tsx`
- Modificar: `resources/js/pages/players/compare.tsx`

**Interfaces:**
- Consumes: `useCompare`, `winner`, `CompareValueChart` (Task 8), `ComparePropertyLine`, `useSlotHighlight`, `useChartTooltip`, `TipRow`, `HqDaznBadge`, `HqMarketValueDifference`, `matchPointsBadgeClass`/`matchPointsColor`, `daznPointsBadgeClass`, `START_TONE_TEXT_CLASSES`, `STATUS_SHORT_LABELS`, `formatMatchDay`; **de team-strength:** `HqDifficultyBars`, `formatDifficulty`, `RIVAL_DIFFICULTY_LABELS`, `rivalDifficultyLevel`.
- Produces: `CompareViewC()`.

- [ ] **Step 0: antes de implementar**, lee cómo quedó en `main` `HqDifficultyBars` y su uso en `teams/fixture-calendar.tsx` (`MatchCell`: barras al pie de la celda + número + C/F). Las celdas futuras de C **reutilizan ese componente y ese tooltip**, sin otra escala ni otro componente.

- [ ] **Step 1: `view-c.tsx`** (porta `final.html` L846-998 y los estilos de `c.html`: `.tl`, `.hd`, `.pc`, `.fc`, `.sum`, `.now`, `.mins`, `.notch`, `.mh`, `.ml`, `.mm`, `.spark`)

Estructura:
- Selector de métrica `Puntos | DAZN | Minutos` (`role="group"`, `aria-pressed`, `cursor-pointer`).
- Leyenda: "mejor de la jornada" (muesca), "minutos jugados" (barra), "dificultad del rival" (un `HqDifficultyBars` de ejemplo **no**: usa un texto "dificultad del rival (0–10)" junto a 3 barras estáticas con las clases de nivel de `rival-difficulty.ts`, para no inventar un componente).
- Rejilla con `overflow-x-auto` y columnas `grid-template-columns: 180px repeat(CW−1, 44px) 28px repeat(3, 56px) 76px` (en móvil la primera columna 120 px y fija con `sticky left-0`): cabecera `J1…J(CW−1)`, `HOY`, `J(CW)…J(CW+2)` con el día del primer jugador (`formatMatchDay`), y el resumen (`Total` / `Media DAZN` / `Minutos`).
- Por jugador: cabecera del carril (foto, nombre, posición, equipo, estado, × `aria-label="Quitar a <nombre>"`), celdas pasadas, separador HOY, 3 celdas futuras y la celda de resumen.
- Al terminar: "Añadir un carril" (abre el modal) si hay hueco, y la sección "Mercado y propiedad".

Código:

```tsx
import { House, Plane, Plus, Shield, User, X } from 'lucide-react';
import type { CSSProperties, KeyboardEvent, ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { TipRow, useChartTooltip, useSlotHighlight } from '@/components/compare/chart-tooltip';
import { useCompare } from '@/components/compare/compare-context';
import { winner } from '@/components/compare/derive';
import { ComparePropertyLine } from '@/components/compare/property';
import { CompareValueChart } from '@/components/compare/value-chart';
import { HqDaznBadge } from '@/components/hq-dazn-badge';
import { HqDifficultyBars } from '@/components/hq-difficulty-bars';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { COMPARE_MAX, COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { formatAverage, formatDecimal, formatMatchDay, formatMillions } from '@/lib/format';
import { STATUS_LABELS, STATUS_SHORT_LABELS } from '@/lib/player-labels';
import { daznPointsBadgeClass, matchPointsBadgeClass } from '@/lib/points';
import { formatDifficulty, RIVAL_DIFFICULTY_LABELS, rivalDifficultyLevel } from '@/lib/rival-difficulty';
import { START_TONE_TEXT_CLASSES } from '@/lib/start-probability';
import { cn } from '@/lib/utils';
import type { ComparedPlayerScore } from '@/types/models';

type LaneMetric = 'points' | 'dazn' | 'minutes';

const METRIC_LABELS: Record<LaneMetric, string> = { points: 'Puntos', dazn: 'DAZN', minutes: 'Minutos' };

/** The lane's figure for one jornada: null = didn't play (minutes still show 0'). */
function cellValue(score: ComparedPlayerScore | null, metric: LaneMetric): number | null {
    if (!score) {
        return null;
    }

    if (metric === 'minutes') {
        return score.minutes;
    }

    if (score.minutes === 0) {
        return null;
    }

    return metric === 'dazn' ? score.dazn_points : score.points;
}

export function CompareViewC() {
    const { players, derived, currentWeek, remove, openPicker } = useCompare();
    const { show, hide } = useChartTooltip();
    const { rootProps, bind } = useSlotHighlight();
    const [metric, setMetric] = useState<LaneMetric>('points');
    const [focus, setFocus] = useState({ lane: 0, column: Math.max(0, currentWeek - 2) });
    const [litWeek, setLitWeek] = useState<number | 'sum' | null>(null);
    const scrollRef = useRef<HTMLDivElement>(null);
    const nowRef = useRef<HTMLDivElement>(null);
    const pastWeeks = Array.from({ length: Math.max(0, currentWeek - 1) }, (_, index) => index + 1);
    const lastColumn = pastWeeks.length + 3;

    // Open on "HOY": the past to the left, the next three to the right.
    useEffect(() => {
        const wrap = scrollRef.current;
        const now = nowRef.current;

        if (wrap && now && wrap.scrollWidth > wrap.clientWidth) {
            wrap.scrollLeft = Math.max(0, now.offsetLeft - wrap.clientWidth * 0.5);
        }
    }, [players]);

    const tops = pastWeeks.map((week) => winner(derived.map((item) => cellValue(item.weeks[week - 1]?.score ?? null, metric))));
    const sums = players.map((player, index) => (metric === 'minutes' ? derived[index].minutes : metric === 'dazn' ? (derived[index].daznAverage === null ? null : Math.round(derived[index].daznAverage * 10) / 10) : player.points));
    const sumBest = winner(sums);
    const sumText = (value: number | null) => (value === null ? '—' : metric === 'dazn' ? formatAverage(value) : metric === 'minutes' ? `${value}'` : String(value));
    const weekText = (score: ComparedPlayerScore | null) => {
        if (!score) {
            return 'NC';
        }

        const value = cellValue(score, metric);

        if (value === null) {
            return metric === 'minutes' ? "0'" : 'no jugó';
        }

        return metric === 'minutes' ? `${value}'` : metric === 'dazn' ? `${value} DAZN` : `${value} pts`;
    };

    /** Tooltip + accessible name of a cell (mock `cInfo`). `column` 0…CW−2 past, CW−1…CW+1 future, last = summary. */
    const cellInfo = (lane: number, column: number): { content: ReactNode; text: string } => {
        const player = players[lane];
        const rows = (valueOf: (index: number) => string, extraOf?: (index: number) => string) =>
            players.length > 1 ? (
                <span className="mt-2 flex flex-col gap-1 border-t border-hq-border pt-[7px]">
                    {players.map((other, index) => <TipRow key={other.id} slotColor={COMPARE_SLOT_COLORS[index]} name={other.name} value={valueOf(index)} extra={extraOf?.(index)} me={index === lane} />)}
                </span>
            ) : null;

        if (column === lastColumn) {
            const label = metric === 'minutes' ? 'Minutos en la temporada' : metric === 'dazn' ? 'Media DAZN' : 'Puntos en la temporada';

            return {
                content: (<><b className="font-sans text-[12.5px] font-black uppercase">{player.name}</b><span className="mt-[3px] text-hq-moss-dim">{label}</span><span className={cn('mt-[7px] text-xl font-bold tabular-nums', sumBest === lane ? 'text-hq-lime' : 'text-hq-paper')}>{sumText(sums[lane])}</span>{rows((index) => sumText(sums[index]))}</>),
                text: `${player.name}, ${label}: ${sumText(sums[lane])}`,
            };
        }

        if (column < pastWeeks.length) {
            const week = column + 1;
            const score = derived[lane].weeks[column].score;
            const top = tops[column] === lane;
            const more = score
                ? [metric !== 'points' ? `${score.points ?? 0} pts` : '', metric !== 'dazn' ? `DAZN ${score.dazn_points ?? '—'}` : '', `${metric !== 'minutes' ? `${score.minutes}' ` : ''}${score.starter ? 'titular' : 'suplente'}`].filter(Boolean).join(' · ')
                : '';

            return {
                content: (
                    <>
                        <b className="font-sans text-[12.5px] font-black uppercase">J{week} · {player.name}</b>
                        <span className="mt-[3px] text-hq-moss-dim">{score ? `${score.is_home ? 'vs ' : '@ '}${score.opponent.short_name} · ${score.is_home ? 'en casa' : 'fuera'}` : 'No convocado'}</span>
                        <span className={cn('mt-[7px] text-xl font-bold tabular-nums', top ? 'text-hq-lime' : 'text-hq-paper')}>{weekText(score)}</span>
                        {more && <span className="mt-[3px] text-hq-moss-dim">{more}</span>}
                        {top && <span className="mt-[5px] text-[10px] font-bold tracking-[0.06em] text-hq-lime uppercase">Mejor de la jornada</span>}
                        {rows((index) => weekText(derived[index].weeks[column].score))}
                    </>
                ),
                text: `Jornada ${week}, ${player.name}: ${score ? `${weekText(score)}, ${score.is_home ? 'en casa contra ' : 'fuera contra '}${score.opponent.name}, ${more}` : 'no convocado'}${top ? ', mejor de la jornada' : ''}`,
            };
        }

        const offset = column - pastWeeks.length;
        const fixture = player.next_fixtures[offset];
        const week = currentWeek + offset;

        if (!fixture) {
            return { content: (<><b className="font-sans text-[12.5px] font-black uppercase">J{week} · {player.name}</b><span className="mt-[3px] text-hq-moss-dim">Sin partido programado</span></>), text: `Jornada ${week}, ${player.name}: sin partido` };
        }

        const difficulty = fixture.difficulty;
        const difficultyText = difficulty === null ? '' : ` · dificultad ${formatDifficulty(difficulty)} / 10 (${RIVAL_DIFFICULTY_LABELS[rivalDifficultyLevel(difficulty)]})`;
        const start = offset === 0 ? (derived[lane].startTone === 'out' ? STATUS_LABELS[player.status] : derived[lane].startProbability === null ? '' : `Titularidad ${derived[lane].startProbability} % (FútbolFantasy)`) : '';

        return {
            content: (
                <>
                    <b className="font-sans text-[12.5px] font-black uppercase">J{fixture.week_number} · {player.name}</b>
                    <span className="mt-[3px] text-hq-moss-dim">{formatMatchDay(fixture.date)} · {fixture.is_home ? 'en casa' : 'fuera'}</span>
                    <span className="mt-[7px] text-base font-bold text-hq-paper">{fixture.is_home ? 'vs ' : '@ '}{fixture.opponent.name}</span>
                    <span className="mt-[3px] text-hq-moss-dim">rival {fixture.rival_position}.º de la tabla{difficultyText}</span>
                    {start && <span className="mt-[3px] text-hq-moss-dim">{start}</span>}
                    {rows((index) => { const other = players[index].next_fixtures[offset]; return other ? `${other.is_home ? 'vs ' : '@ '}${other.opponent.short_name}` : '—'; }, (index) => { const other = players[index].next_fixtures[offset]; return other?.difficulty != null ? formatDifficulty(other.difficulty) : ''; })}
                </>
            ),
            text: `Jornada ${fixture.week_number}, ${player.name}: ${fixture.is_home ? 'en casa contra ' : 'fuera contra '}${fixture.opponent.name}, ${fixture.rival_position}.º de la tabla${difficultyText}${start ? `, ${start}` : ''}`,
        };
    };

    /** Common props of every focusable cell: roving tabindex, side tooltip, jornada column light-up, lane highlight. */
    const cellProps = (lane: number, column: number, week: number | 'sum') => {
        const info = cellInfo(lane, column);
        const open = (element: HTMLElement) => {
            setLitWeek(week);
            show(info.content, () => {
                if (!element.isConnected) {
                    return null;
                }

                const rect = element.getBoundingClientRect();

                return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom, side: true };
            }, element);
        };

        return {
            'data-cell': '',
            'data-lane': lane,
            'data-col': column,
            'data-slot': lane,
            'data-cmp-tip': '',
            tabIndex: focus.lane === lane && focus.column === column ? 0 : -1,
            'aria-label': info.text,
            role: 'gridcell' as const,
            onPointerEnter: (event: React.PointerEvent<HTMLElement>) => open(event.currentTarget),
            onPointerLeave: (event: React.PointerEvent<HTMLElement>) => {
                if (event.pointerType !== 'touch') {
                    setLitWeek(null);
                    hide(event.currentTarget);
                }
            },
            onFocus: (event: React.FocusEvent<HTMLElement>) => {
                setFocus({ lane, column });
                open(event.currentTarget);
            },
            onBlur: (event: React.FocusEvent<HTMLElement>) => {
                setLitWeek(null);
                hide(event.currentTarget);
            },
        };
    };

    const onGridKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const moves: Record<string, { lane: number; column: number }> = {
            ArrowLeft: { lane: focus.lane, column: focus.column - 1 },
            ArrowRight: { lane: focus.lane, column: focus.column + 1 },
            ArrowUp: { lane: focus.lane - 1, column: focus.column },
            ArrowDown: { lane: focus.lane + 1, column: focus.column },
            Home: { lane: focus.lane, column: 0 },
            End: { lane: focus.lane, column: lastColumn },
        };

        if (!(event.key in moves)) {
            return;
        }

        event.preventDefault();
        const next = {
            lane: Math.max(0, Math.min(players.length - 1, moves[event.key].lane)),
            column: Math.max(0, Math.min(lastColumn, moves[event.key].column)),
        };
        setFocus(next);
        requestAnimationFrame(() => scrollRef.current?.querySelector<HTMLElement>(`[data-cell][data-lane="${next.lane}"][data-col="${next.column}"]`)?.focus());
    };

    const lit = (week: number | 'sum') => (litWeek === week ? 'bg-[color-mix(in_srgb,var(--color-hq-paper)_7%,transparent)]' : '');
    const columns = `var(--id-col) repeat(${pastWeeks.length}, 44px) 28px repeat(3, 58px) 78px`;

    return (
        <div {...rootProps}>
            <div className="flex flex-wrap items-center gap-3 border-b border-hq-border px-3.5 py-3 sm:px-4 sm:py-3.5">
                <span className="hq-label">Cifra en cada jornada</span>
                <div role="group" aria-label="Métrica" className="inline-flex border border-hq-border-strong">
                    {(Object.keys(METRIC_LABELS) as LaneMetric[]).map((option) => (
                        <button key={option} type="button" aria-pressed={metric === option} onClick={() => setMetric(option)} className={cn('h-11 cursor-pointer px-3 font-mono text-[11px] font-bold tracking-[0.06em] uppercase sm:h-8', metric === option ? 'bg-hq-lime text-hq-ink' : 'text-hq-moss hover:text-hq-paper')}>
                            {METRIC_LABELS[option]}
                        </button>
                    ))}
                </div>
            </div>

            <section>
                <div className="flex items-center justify-between border-b border-hq-border-strong bg-hq-panel px-3.5 py-2.5 sm:px-4">
                    <h2 className="hq-label">Temporada, jornada a jornada</h2>
                    <span className="font-mono text-[11px] text-hq-moss-dim">J1 → J{currentWeek + 2}</span>
                </div>
                <div className="flex flex-wrap gap-x-4 gap-y-1 px-3.5 py-2 font-mono text-[11px] text-hq-moss-dim sm:px-4">
                    <span className="inline-flex items-center gap-1.5"><i className="inline-block h-2 w-0.5 bg-hq-lime" />mejor de la jornada</span>
                    <span className="inline-flex items-center gap-1.5"><i className="inline-block h-[3px] w-4 bg-hq-moss" />minutos jugados</span>
                    <span>dificultad del rival: 0–10, barras como en el calendario</span>
                </div>
                <div ref={scrollRef} className="overflow-x-auto [--id-col:120px] sm:[--id-col:180px]">
                    <div role="grid" aria-label="Jornada a jornada. Flechas izquierda y derecha para cambiar de jornada; arriba y abajo para cambiar de jugador." onKeyDown={onGridKeyDown} className="grid min-w-max" style={{ gridTemplateColumns: columns }}>
                        <div role="row" className="contents">
                            <div className="sticky left-0 z-10 bg-hq-ink px-3 py-2 hq-label">{players.length} de {COMPARE_MAX}</div>
                            {pastWeeks.map((week) => <div key={week} className={cn('py-2 text-center font-mono text-[11px] text-hq-moss-dim', lit(week), litWeek === week && 'text-hq-paper shadow-[inset_0_-2px_0_var(--color-hq-lime)]')}>J{week}</div>)}
                            <div ref={nowRef} className="flex items-center justify-center font-mono text-[10px] font-bold text-hq-lime [writing-mode:vertical-rl]">HOY</div>
                            {[0, 1, 2].map((offset) => {
                                const sample = players[0]?.next_fixtures[offset];

                                return (
                                    <div key={offset} className={cn('py-2 text-center font-mono text-[11px] text-hq-khaki', lit(currentWeek + offset))}>
                                        J{currentWeek + offset}
                                        {sample && <small className="block text-[10px] text-hq-moss-dim">{formatMatchDay(sample.date).replace(',', '')}</small>}
                                    </div>
                                );
                            })}
                            <div className={cn('py-2 text-center hq-label', lit('sum'))}>{metric === 'minutes' ? 'Minutos' : metric === 'dazn' ? 'Media DAZN' : 'Total'}</div>
                        </div>

                        {players.map((player, lane) => {
                            const item = derived[lane];

                            return (
                                <div key={player.id} role="row" className="contents">
                                    <div data-slot={lane} {...bind(lane)} className="sticky left-0 z-10 flex min-w-0 items-center gap-2 border-t border-l-2 border-hq-border bg-hq-ink px-2 py-2" style={{ borderLeftColor: COMPARE_SLOT_COLORS[lane] }}>
                                        <EntityImage src={player.image} alt={player.name} fallback={User} shape="square" className="size-8 shrink-0 rounded-none object-cover object-top" />
                                        <span className="min-w-0 flex-1">
                                            <b className="block truncate text-xs font-black text-hq-paper uppercase">{player.name}</b>
                                            <span className="flex items-center gap-1 font-mono text-[10.5px] text-hq-moss-dim max-sm:hidden">
                                                <HqPositionTag position={player.position} />
                                                <EntityImage src={player.team.logo} alt="" fallback={Shield} shape="square" className="size-3 rounded-none bg-transparent" />
                                                {player.team.short_name}
                                                <HqStatusBadge status={player.status} />
                                            </span>
                                        </span>
                                        <button type="button" onClick={() => remove(lane)} aria-label={`Quitar a ${player.name}`} className="flex size-9 shrink-0 cursor-pointer items-center justify-center text-hq-moss-dim hover:text-hq-live sm:size-7"><X aria-hidden="true" className="size-3.5" /></button>
                                    </div>

                                    {item.weeks.map(({ week, score }, column) => {
                                        const value = cellValue(score, metric);
                                        const top = tops[column] === lane;

                                        return (
                                            <div key={week} {...cellProps(lane, column, week)} className={cn('relative flex cursor-default flex-col items-center justify-center gap-1 border-t border-hq-border py-1.5 focus-visible:outline-offset-[-3px]', lit(week))}>
                                                {top && <i aria-hidden="true" className="absolute top-0 left-1/2 h-1.5 w-0.5 -translate-x-1/2 bg-hq-lime" />}
                                                {!score ? (
                                                    <span aria-hidden="true" className="font-mono text-[10px] text-hq-led-off">NC</span>
                                                ) : (
                                                    <>
                                                        {metric === 'dazn' && score.dazn_points === null && score.dazn_estimate !== null ? (
                                                            <HqDaznBadge entry={score} size="xs" />
                                                        ) : value === null ? (
                                                            <span aria-hidden="true" className="font-mono text-[11px] text-hq-moss-dim">0'</span>
                                                        ) : metric === 'minutes' ? (
                                                            <span aria-hidden="true" className={cn('font-mono text-xs font-bold tabular-nums', score.starter ? 'text-hq-paper' : 'text-hq-moss')}>{value}'</span>
                                                        ) : (
                                                            <span aria-hidden="true" className={cn('px-1 font-mono text-xs font-bold tabular-nums', metric === 'dazn' ? daznPointsBadgeClass(value) : matchPointsBadgeClass(value))}>{value}</span>
                                                        )}
                                                        <EntityImage src={score.opponent.logo} alt="" fallback={Shield} shape="square" className="size-3.5 rounded-none bg-transparent" />
                                                        <span aria-hidden="true" className="h-[3px] w-7 bg-hq-border">
                                                            <i className={cn('block h-full', score.starter ? 'bg-hq-paper' : 'bg-hq-moss')} style={{ width: `${Math.min(100, (score.minutes / 90) * 100)}%` }} />
                                                        </span>
                                                    </>
                                                )}
                                            </div>
                                        );
                                    })}

                                    <div aria-hidden="true" className="border-t border-r border-l border-dashed border-hq-border-strong" />

                                    {[0, 1, 2].map((offset) => {
                                        const fixture = player.next_fixtures[offset];
                                        const column = pastWeeks.length + offset;

                                        return (
                                            <div key={offset} {...cellProps(lane, column, currentWeek + offset)} className={cn('flex cursor-default flex-col items-center justify-center gap-1 border-t border-hq-border py-1.5', lit(currentWeek + offset))}>
                                                {!fixture ? (
                                                    <span aria-hidden="true" className="font-mono text-xs text-hq-moss-dim">–</span>
                                                ) : (
                                                    <>
                                                        <span aria-hidden="true" className="relative">
                                                            <EntityImage src={fixture.opponent.logo} alt="" fallback={Shield} shape="square" className="size-5 rounded-none bg-transparent" />
                                                            <span className="absolute -right-1.5 -bottom-1 text-hq-moss">{fixture.is_home ? <House className="size-2.5" /> : <Plane className="size-2.5" />}</span>
                                                        </span>
                                                        <HqDifficultyBars fixture={fixture} />
                                                        {offset === 0 && (
                                                            <span aria-hidden="true" className={cn('font-mono text-[10.5px] font-bold tabular-nums', START_TONE_TEXT_CLASSES[item.startTone])}>
                                                                {item.startTone === 'out' ? STATUS_SHORT_LABELS[player.status] : item.startProbability === null ? '—' : `${item.startProbability}%`}
                                                            </span>
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                        );
                                    })}

                                    <div {...cellProps(lane, lastColumn, 'sum')} className={cn('flex cursor-default flex-col items-center justify-center gap-0.5 border-t border-hq-border py-1.5', lit('sum'))}>
                                        <span aria-hidden="true" className={cn('font-mono text-lg font-bold tabular-nums', sumBest === lane ? 'text-hq-lime' : 'text-hq-paper')}>{sumText(sums[lane])}</span>
                                        <small aria-hidden="true" className="font-mono text-[10px] text-hq-moss-dim">
                                            {metric === 'points' ? `media ${formatAverage(player.average_points)}` : metric === 'minutes' ? `${item.starts} de ${pastWeeks.length} titular` : 'oficial'}
                                        </small>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
                {players.length < COMPARE_MAX && (
                    <div className="border-t border-hq-border px-3.5 py-3 sm:px-4">
                        <button type="button" data-add="" onClick={() => openPicker(null)} className="inline-flex h-11 cursor-pointer items-center gap-2 border border-dashed border-hq-border-strong px-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase hover:border-hq-lime hover:text-hq-lime sm:h-9">
                            <Plus aria-hidden="true" className="size-3.5" />
                            Añadir un carril
                        </button>
                    </div>
                )}
            </section>

            <section>
                <div className="flex items-center justify-between border-y border-hq-border-strong bg-hq-panel px-3.5 py-2.5 sm:px-4">
                    <h2 className="hq-label">Mercado y propiedad</h2>
                    <span className="font-mono text-[11px] text-hq-moss-dim">últimos 30 días</span>
                </div>
                {players.map((player, lane) => {
                    const todayBest = winner(players.map((other) => other.difference));
                    const trendBest = winner(players.map((other) => other.value_trend_30d?.multiple ?? null));

                    return (
                        <div key={player.id} data-slot={lane} {...bind(lane)} className="grid grid-cols-1 gap-2.5 border-b border-l-2 border-hq-border px-3.5 py-3 sm:grid-cols-[180px_minmax(0,1fr)_minmax(0,1fr)] sm:items-center sm:px-4" style={{ borderLeftColor: COMPARE_SLOT_COLORS[lane] }}>
                            <span className="flex min-w-0 items-center gap-2 text-xs font-black text-hq-paper uppercase">
                                <EntityImage src={player.image} alt="" fallback={User} shape="square" className="size-7 rounded-none object-cover object-top" />
                                <span className="truncate">{player.name}</span>
                            </span>
                            <div className="grid grid-cols-[auto_auto_minmax(0,1fr)] items-center gap-3">
                                <div className={cn('flex flex-col gap-1', lane === todayBest && 'shadow-[inset_0_-2px_0_var(--color-hq-lime)]')}>
                                    <span className="font-mono text-sm font-bold text-hq-paper tabular-nums">{formatMillions(player.value)}</span>
                                    <HqMarketValueDifference difference={player.difference} trend={player.trend} className="text-xs" />
                                </div>
                                <div className={cn('flex flex-col gap-1', lane === trendBest && 'shadow-[inset_0_-2px_0_var(--color-hq-lime)]')}>
                                    <span className="font-mono text-sm font-bold text-hq-paper tabular-nums">{player.value_trend_30d ? `×${formatDecimal(player.value_trend_30d.multiple)}` : '—'}</span>
                                    <span className="font-mono text-[11px] text-hq-moss-dim">desde {player.value_trend_30d ? formatMillions(player.value_trend_30d.value) : '—'}</span>
                                </div>
                                <CompareValueChart series={[{ slot: lane, name: player.name, history: player.market_history }]} width={300} height={40} padRight={4} showAxis={false} label={`Evolución del valor de ${player.name} en los últimos 30 días`} />
                            </div>
                            <ComparePropertyLine player={player} derived={derived[lane]} />
                        </div>
                    );
                })}
            </section>
            <p className="px-3.5 py-4 font-mono text-[11px] text-hq-moss-dim sm:px-4">DAZN: puntuación oficial (en juego o sin publicar, la estimación). Titularidad J{currentWeek}: FútbolFantasy. Dificultad 0–10 según la fuerza del rival y si se juega en casa.</p>
        </div>
    );
}
```

(`React.PointerEvent`/`React.FocusEvent` requieren `import type { FocusEvent, PointerEvent } from 'react'`: cambia esas anotaciones a los tipos importados.)

- [ ] **Step 2: monta la vista**: `{comparison.view === 'c' && <CompareViewC />}`.

- [ ] **Step 3: comprobaciones** (`types:check`, `lint:check`, `prettier --check`, `build`): verde.

- [ ] **Step 4: navegador** (1440 y 390 px): se abre centrado en HOY; Puntos / DAZN / Minutos cambian celdas, muesca de "mejor de la jornada" y resumen; una jornada en juego o sin publicar muestra el `HqDaznBadge` con pulso en DAZN; al pasar por una celda se ilumina esa jornada en todos los carriles y el tooltip va al lado (encima en móvil); teclado con una sola parada, ←/→ jornada, ↑/↓ carril, Inicio/Fin, y cada celda con su `aria-label`; las celdas futuras llevan **las mismas barras y el mismo tooltip de dificultad que el calendario de Equipos** y la primera el % de titularidad; minigráficos de mercado interactivos; **Review Focus 2:** con `currentWeek = 1` (si el usuario aprueba simularlo), solo HOY + 3 próximas + resumen, sin errores.

- [ ] **Step 5: commit**

```bash
git add resources/js/components/compare/view-c.tsx resources/js/pages/players/compare.tsx
git commit -m "feat: add the week-by-week lanes comparator view

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: Veredicto en modo god

**Files:**
- Modificar: `resources/js/components/compare/derive.ts` (`verdict()` y tipos)
- Crear: `resources/js/components/compare/verdict.tsx`
- Modificar: `resources/js/pages/players/compare.tsx`
- Test: `tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php`

**Interfaces:**
- Consumes: la prop compartida `godMode` (`HandleInertiaRequests`, `HandleGodMode::isEnabled`), `DerivedPlayer`, `winner`, `describeMarketTrend`, **de team-strength** `rivalDifficultyLevel`, `formatDifficulty`, formateadores.
- Produces:

```ts
export type VerdictLens = 'buy' | 'sell' | 'start';
export interface VerdictReason { lead: string; rest: string }
export interface VerdictEvidenceRow { label: string; hint: string | null; texts: string[]; values: (number | null)[] | null; lowerIsBetter: boolean; mark: 'best' | 'worst' | null }
export interface Verdict { title: string; order: number[]; out: boolean[]; recommended: number | null; reasons: VerdictReason[]; rows: VerdictEvidenceRow[] }
export function normalizeAmong(values: (number | null)[], lowerIsBetter?: boolean): number[];
export function verdict(lens: VerdictLens, players: ComparedPlayer[], derived: DerivedPlayer[], currentWeek: number, now: number): Verdict;
```

- [ ] **Step 0: antes de implementar**, relee en `main` `rival-difficulty.ts` (team-strength): "calendario fácil" y "rival fácil" usan la **dificultad 0–10 con la media más baja como mejor** y el nivel "difícil" de `rivalDifficultyLevel`; nada de ±0,45.

- [ ] **Step 1: tests PHP que fallan** (la puerta del veredicto es la prop `godMode`):

```php
test('godMode is false on the comparator without the key or with a wrong one', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    comparisonSeason();

    $this->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('godMode', false));

    $this->get(route('players.compare').'?god_mode=wrong-key')
        ->assertRedirect(route('players.compare'))
        ->assertCookieMissing('god_mode');
});

test('the configured key turns godMode on for the comparator through the remembered cookie', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    comparisonSeason();

    $this->get(route('players.compare', ['ids' => '1']).'&god_mode=super-secret-key')
        ->assertRedirect(route('players.compare', ['ids' => '1']))
        ->assertCookie('god_mode', '1');

    $this->withCookie('god_mode', '1')
        ->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('godMode', true));
});
```

Run: `php artisan test --compact tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php --filter=godMode`
Expected: PASS ya (el mecanismo existe y está en el grupo `web`). Si fallara porque `HandleGodMode` no se aplica a esta ruta, añade la ruta al mismo grupo/middleware que `players.show` y repite. Estos tests fijan el contrato de la puerta; el `verdict()` es puro y se revisa en la review.

- [ ] **Step 2: `verdict()`** al final de `derive.ts` (porta `d.html` `norm` y `lensDef`, L222-271, con la dificultad nueva: los signos de calendario y rival se invierten respecto al mock porque aquí más bajo = más fácil)

```ts
export type VerdictLens = 'buy' | 'sell' | 'start';

export interface VerdictReason {
    /** Bold opening ("En el mercado a 5,2 M€"). */
    lead: string;
    rest: string;
}

export interface VerdictEvidenceRow {
    label: string;
    hint: string | null;
    texts: string[];
    values: (number | null)[] | null;
    lowerIsBetter: boolean;
    /** Fichar/Alinear underline the best; Vender marks the worst signal in red. */
    mark: 'best' | 'worst' | null;
}

export interface Verdict {
    title: string;
    /** Player indexes, best score first. */
    order: number[];
    /** Players ruled out for this lens (can't be bought now / won't play). */
    out: boolean[];
    /** Null when even the top score is ruled out ("Ninguno"). */
    recommended: number | null;
    reasons: VerdictReason[];
    rows: VerdictEvidenceRow[];
}

/** Min–max among the compared players (mock D `norm`): missing = 0, all equal = 0,5. */
export function normalizeAmong(values: (number | null)[], lowerIsBetter = false): number[] {
    const present = values.filter((value): value is number => value !== null);

    if (present.length === 0) {
        return values.map(() => 0);
    }

    const low = Math.min(...present);
    const high = Math.max(...present);

    return values.map((value) => {
        if (value === null) {
            return 0;
        }

        if (high === low) {
            return 0.5;
        }

        const normalized = (value - low) / (high - low);

        return lowerIsBetter ? 1 - normalized : normalized;
    });
}

function canBuy(item: DerivedPlayer): boolean {
    return item.acquire.kind === 'market' || item.acquire.kind === 'clause';
}

function acquireText(item: DerivedPlayer, player: ComparedPlayer, now: number): string {
    const amount = item.acquire.amount === null ? '—' : formatMillions(item.acquire.amount);

    switch (item.acquire.kind) {
        case 'market':
            return `Mercado · ${amount}`;
        case 'clause':
            return `Cláusula abierta · ${amount}${player.owner ? ` · de ${player.owner.name}` : ''}`;
        case 'locked':
            return `Bloqueada · ${amount}${item.acquire.until ? ` · se abre en ${formatLockLeft(item.acquire.until, now) ?? '—'}` : ''}`;
        case 'shielded':
            return `Blindado · ${amount}`;
        case 'owned':
            return 'Con dueño';
        default:
            return 'Libre · no está en el mercado';
    }
}

function isFalling(player: ComparedPlayer): boolean {
    return player.trend !== null && !describeMarketTrend(player.trend).rising;
}

export function verdict(lens: VerdictLens, players: ComparedPlayer[], derived: DerivedPlayer[], currentWeek: number, now: number): Verdict {
    let title: string;
    let scores: number[];
    let out: boolean[];
    let reasons: VerdictReason[];
    let rows: VerdictEvidenceRow[];

    if (lens === 'buy') {
        const ppm = normalizeAmong(players.map((player) => player.points_per_million?.value ?? null));
        const average = normalizeAmong(players.map((player) => player.average_points));
        const rise = normalizeAmong(players.map((player) => player.value_trend_30d?.multiple ?? null));
        const easyCalendar = normalizeAmong(derived.map((item) => item.nextAverageDifficulty), true);

        title = 'Para fichar';
        scores = players.map((_, index) => (canBuy(derived[index]) ? 1 : -2) + 0.9 * ppm[index] + average[index] + 0.6 * rise[index] + 0.4 * easyCalendar[index]);
        out = derived.map((item) => !canBuy(item));
        reasons = players.map((player, index) => {
            const item = derived[index];

            if (canBuy(item)) {
                return {
                    lead: `${item.acquire.kind === 'market' ? 'En el mercado' : 'Cláusula abierta'} a ${formatMillions(item.acquire.amount ?? 0)}`,
                    rest: `${player.points_per_million ? `${formatDecimal(player.points_per_million.value)} pts/M€ · ` : ''}media ${formatAverage(player.average_points)}`,
                };
            }

            if (item.acquire.kind === 'locked' && item.acquire.until) {
                return { lead: 'Cláusula bloqueada', rest: `${formatLockLeft(item.acquire.until, now) ?? ''} más` };
            }

            return item.acquire.kind === 'free'
                ? { lead: 'Libre', rest: 'pero hoy no está en el mercado' }
                : { lead: 'No se puede fichar ahora', rest: '' };
        });
        rows = [
            { label: 'Cómo conseguirlo', hint: 'y cuánto cuesta', texts: players.map((player, index) => acquireText(derived[index], player, now)), values: null, lowerIsBetter: false, mark: null },
            { label: 'Pts / M€', hint: null, texts: players.map((player) => (player.points_per_million ? formatDecimal(player.points_per_million.value) : '—')), values: players.map((player) => player.points_per_million?.value ?? null), lowerIsBetter: false, mark: 'best' },
            { label: 'Media', hint: 'por partido', texts: players.map((player) => formatAverage(player.average_points)), values: players.map((player) => player.average_points), lowerIsBetter: false, mark: 'best' },
            { label: 'Subida 30 días', hint: null, texts: players.map((player) => (player.value_trend_30d ? `×${formatDecimal(player.value_trend_30d.multiple)}` : '—')), values: players.map((player) => player.value_trend_30d?.multiple ?? null), lowerIsBetter: false, mark: 'best' },
            { label: 'Próximos 3', hint: 'dificultad media', texts: derived.map((item) => (item.nextAverageDifficulty === null ? '—' : formatDifficulty(item.nextAverageDifficulty))), values: derived.map((item) => item.nextAverageDifficulty), lowerIsBetter: true, mark: 'best' },
        ];
    } else if (lens === 'sell') {
        const dropping = normalizeAmong(players.map((player) => player.difference), true);
        const hardCalendar = normalizeAmong(derived.map((item) => item.nextAverageDifficulty));
        const lowStart = normalizeAmong(derived.map((item) => (item.startTone === 'out' ? 0 : item.startProbability)), true);
        const badForm = normalizeAmong(derived.map((item) => item.last3Points), true);

        title = 'Vender antes';
        scores = players.map((player, index) => 1.2 * dropping[index] + 0.7 * hardCalendar[index] + lowStart[index] + 0.8 * badForm[index] + (isFalling(player) ? 0.6 : 0));
        out = players.map(() => false);
        reasons = players.map((player, index) => {
            const item = derived[index];
            const bits: string[] = [];

            if (player.trend !== null) {
                bits.push(describeMarketTrend(player.trend).label.toLowerCase());
            }

            if (item.nextAverageDifficulty !== null && rivalDifficultyLevel(item.nextAverageDifficulty) === 'hard') {
                bits.push('calendario difícil');
            }

            if (item.startTone === 'low' || item.startTone === 'out') {
                bits.push(`titularidad ${item.startTone === 'out' ? 'nula' : `${item.startProbability} %`}`);
            }

            return player.difference < 0
                ? { lead: `pierde ${formatMillions(Math.abs(player.difference))} hoy`, rest: bits.join(' · ') }
                : { lead: bits.length > 0 ? '' : 'Sin señales de venta', rest: bits.join(' · ') };
        });
        rows = [
            { label: 'Valor hoy', hint: 'sin ganador', texts: players.map((player) => formatMillions(player.value)), values: null, lowerIsBetter: false, mark: null },
            { label: 'Tendencia', hint: null, texts: players.map((player) => (player.trend ? describeMarketTrend(player.trend).label : '—')), values: players.map((player) => player.difference), lowerIsBetter: true, mark: 'worst' },
            { label: 'Últimas 3', hint: 'puntos · minutos', texts: derived.map((item) => `${item.last3Points} pts · ${item.last3Minutes}'`), values: derived.map((item) => item.last3Points), lowerIsBetter: true, mark: 'worst' },
            { label: `Titularidad J${currentWeek}`, hint: null, texts: players.map((player, index) => (derived[index].startTone === 'out' ? 'Baja' : derived[index].startProbability === null ? '—' : `${derived[index].startProbability} %`)), values: derived.map((item) => (item.startTone === 'out' ? 0 : item.startProbability)), lowerIsBetter: true, mark: 'worst' },
            { label: 'Propiedad', hint: 'cláusula', texts: players.map((player, index) => acquireText(derived[index], player, now)), values: null, lowerIsBetter: false, mark: null },
        ];
    } else {
        const start = normalizeAmong(derived.map((item) => (item.startTone === 'out' ? null : item.startProbability)));
        const easyRival = normalizeAmong(players.map((player) => player.next_fixtures[0]?.difficulty ?? null), true);
        const form = normalizeAmong(derived.map((item) => item.last3Points));
        const dazn = normalizeAmong(derived.map((item) => item.daznAverage));

        title = `Para alinear en J${currentWeek}`;
        scores = players.map((_, index) => (derived[index].startTone === 'out' ? -5 : 0) + 1.4 * start[index] + 0.8 * easyRival[index] + form[index] + 0.6 * dazn[index]);
        out = derived.map((item) => item.startTone === 'out');
        reasons = players.map((player, index) => {
            const item = derived[index];
            const next = player.next_fixtures[0];

            if (item.startTone === 'out') {
                return { lead: player.status === 'injured' ? 'Lesionado' : 'Sancionado', rest: 'no juega' };
            }

            return {
                lead: item.startProbability === null ? 'sin %' : `${item.startProbability} % titular`,
                rest: `${next ? `${next.is_home ? 'vs ' : '@ '}${next.opponent.name} (${next.rival_position}.º${next.difficulty !== null ? `, dificultad ${formatDifficulty(next.difficulty)}` : ''}) · ` : ''}${item.last3Points} pts en 3 jornadas`,
            };
        });
        rows = [
            { label: `Titularidad J${currentWeek}`, hint: 'FútbolFantasy', texts: derived.map((item) => (item.startTone === 'out' ? 'Baja' : item.startProbability === null ? '—' : `${item.startProbability} %`)), values: derived.map((item) => (item.startTone === 'out' ? -1 : item.startProbability)), lowerIsBetter: false, mark: 'best' },
            { label: `Rival J${currentWeek}`, hint: 'dificultad', texts: players.map((player) => { const next = player.next_fixtures[0]; return next ? `${next.is_home ? '' : '@ '}${next.opponent.short_name} · ${next.difficulty !== null ? formatDifficulty(next.difficulty) : '—'}` : '—'; }), values: players.map((player) => player.next_fixtures[0]?.difficulty ?? null), lowerIsBetter: true, mark: 'best' },
            { label: 'Forma', hint: 'últimas 3', texts: derived.map((item) => `${item.last3Points} pts`), values: derived.map((item) => item.last3Points), lowerIsBetter: false, mark: 'best' },
            { label: 'Media DAZN', hint: 'oficial', texts: derived.map((item) => (item.daznAverage === null ? '—' : formatAverage(item.daznAverage))), values: derived.map((item) => (item.daznAverage === null ? null : Math.round(item.daznAverage * 10) / 10)), lowerIsBetter: false, mark: 'best' },
        ];
    }

    const order = players.map((_, index) => index).sort((a, b) => scores[b] - scores[a]);

    return { title, order, out, recommended: out[order[0]] ? null : order[0], reasons, rows };
}
```

Importa en `derive.ts` `formatDifficulty` y `rivalDifficultyLevel` de `@/lib/rival-difficulty` (team-strength).

- [ ] **Step 3: `verdict.tsx`** (porta `d.html` `renderLens`, L273-295, y su CSS `.verdict`, `.v1`, `.vr`, `.ev`, `.er`)

```tsx
import { User } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { useCompare } from '@/components/compare/compare-context';
import type { VerdictLens } from '@/components/compare/derive';
import { verdict, winner } from '@/components/compare/derive';
import { COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { cn } from '@/lib/utils';

const LENSES: { key: VerdictLens; label: (week: number) => string }[] = [
    { key: 'buy', label: () => 'Fichar' },
    { key: 'sell', label: () => 'Vender' },
    { key: 'start', label: (week) => `Alinear J${week}` },
];

/** God mode only: which of the compared players to buy, sell or field, with 4–5 rows of evidence. */
export function CompareVerdict() {
    const { players, derived, currentWeek, now } = useCompare();
    const [lens, setLens] = useState<VerdictLens>('buy');
    const result = verdict(lens, players, derived, currentWeek, now);
    const top = result.recommended;

    return (
        <section aria-labelledby="cmp-verdict-title" className="border-b border-hq-border-strong bg-hq-panel">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-hq-border px-3.5 py-2.5 sm:px-4">
                <h2 id="cmp-verdict-title" className="hq-label text-hq-lime">Veredicto</h2>
                <div role="tablist" aria-label="Decisión" className="inline-flex border border-hq-border-strong">
                    {LENSES.map((item) => (
                        <button key={item.key} role="tab" type="button" aria-selected={lens === item.key} onClick={() => setLens(item.key)} className={cn('h-11 cursor-pointer px-3 font-mono text-[11px] font-bold tracking-[0.06em] uppercase sm:h-8', lens === item.key ? 'bg-hq-lime text-hq-ink' : 'text-hq-moss hover:text-hq-paper')}>
                            {item.label(currentWeek)}
                        </button>
                    ))}
                </div>
            </div>

            <div className="grid gap-3 px-3.5 py-3.5 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] sm:px-4">
                <div className="flex items-start gap-3 border-l-2 pl-3" style={{ borderLeftColor: top === null ? 'var(--color-hq-border-strong)' : COMPARE_SLOT_COLORS[top] }}>
                    {top !== null && <EntityImage src={players[top].image} alt={players[top].name} fallback={User} shape="square" className="size-12 rounded-none object-cover object-top" />}
                    <div className="min-w-0">
                        <span className="hq-label">{result.title}</span>
                        <p className="m-0 mt-1 font-display text-2xl leading-none text-hq-paper uppercase">{top === null ? 'Ninguno' : players[top].name}</p>
                        {top !== null && (
                            <p className="m-0 mt-1.5 font-mono text-xs text-hq-moss">
                                {result.reasons[top].lead && <b className="text-hq-paper">{result.reasons[top].lead}</b>}
                                {result.reasons[top].lead && result.reasons[top].rest && ' · '}
                                {result.reasons[top].rest}
                            </p>
                        )}
                    </div>
                </div>
                <ol className="m-0 flex list-none flex-col gap-2 p-0">
                    {result.order.filter((index) => index !== top).map((index, position) => (
                        <li key={players[index].id} className={cn('flex items-start gap-2.5 font-mono text-xs', result.out[index] && 'opacity-60')} style={{ '--slot': COMPARE_SLOT_COLORS[index] } as CSSProperties}>
                            <span className="w-5 text-hq-moss-dim">{top === null ? '–' : `${position + 2}.º`}</span>
                            <span className="min-w-0">
                                <b className="border-l-2 border-(--slot) pl-1.5 text-hq-paper uppercase">{players[index].name}</b>
                                <span className="block text-hq-moss">
                                    {result.reasons[index].lead && <b className="text-hq-paper">{result.reasons[index].lead}</b>}
                                    {result.reasons[index].lead && result.reasons[index].rest && ' · '}
                                    {result.reasons[index].rest}
                                </span>
                            </span>
                        </li>
                    ))}
                </ol>
            </div>

            <div className="flex items-center justify-between border-y border-hq-border px-3.5 py-2 sm:px-4">
                <h3 className="hq-label">Por qué</h3>
                <span className="font-mono text-[11px] text-hq-moss-dim">{lens === 'sell' ? 'en rojo, la peor señal de cada fila' : 'subrayado, el mejor de cada fila'}</span>
            </div>
            <div role="table" className="overflow-x-auto">
                {result.rows.map((row) => {
                    const marked = row.values === null || row.mark === null ? null : row.mark === 'best' ? winner(row.values, row.lowerIsBetter) : winner(row.values, true);

                    return (
                        <div key={row.label} role="row" className="grid grid-cols-[minmax(110px,160px)_repeat(var(--cols),minmax(0,1fr))] border-b border-hq-border" style={{ '--cols': players.length } as CSSProperties}>
                            <div role="rowheader" className="px-3.5 py-2 sm:px-4">
                                <b className="block text-[11px] font-extrabold text-hq-paper uppercase">{row.label}</b>
                                {row.hint && <small className="font-mono text-[10.5px] text-hq-moss-dim">{row.hint}</small>}
                            </div>
                            {row.texts.map((text, index) => (
                                <div key={players[index].id} role="cell" className={cn('px-2.5 py-2 font-mono text-xs text-hq-paper tabular-nums', index === marked && (row.mark === 'worst' ? 'text-hq-live shadow-[inset_0_-2px_0_var(--color-hq-live)]' : 'shadow-[inset_0_-2px_0_var(--color-hq-lime)]'))}>
                                    {text}
                                </div>
                            ))}
                        </div>
                    );
                })}
            </div>
            <p className="px-3.5 py-2.5 font-mono text-[11px] text-hq-moss-dim sm:px-4">Lectura rápida con los datos de las fichas, no una recomendación. DAZN oficial · Titularidad FútbolFantasy · Dificultad 0–10.</p>
        </section>
    );
}
```

- [ ] **Step 4: monta el veredicto** en `compare.tsx`, encima de la vista activa y solo con la prop compartida:

```tsx
import { usePage } from '@inertiajs/react';
import { CompareVerdict } from '@/components/compare/verdict';
// …
    const { godMode } = usePage().props;
// … dentro del panel, antes de las vistas (con 2+ jugadores):
                                {godMode && <CompareVerdict />}
```

- [ ] **Step 5: comprobaciones**

Run: `php artisan test --compact tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php && npm run types:check && npm run lint:check && npx prettier --check resources/js/components/compare resources/js/pages/players/compare.tsx && npm run build`
Expected: verde.

- [ ] **Step 6: navegador**: sin cookie de god no aparece nada (y el HTML no contiene "Veredicto"); con `?god_mode=<GOD_MODE_KEY>` (el usuario te da la clave o la entra él) aparece encima de la vista activa, con Fichar / Vender / Alinear J{CW}; el recomendado cambia al cambiar de pestaña; "Ninguno" cuando el mejor está descartado; en Vender la peor señal va en rojo, en las otras dos el mejor subrayado; el calendario fácil es el de **media más baja**.

- [ ] **Step 7: commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/components/compare/derive.ts resources/js/components/compare/verdict.tsx resources/js/pages/players/compare.tsx tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php
git commit -m "feat: add the god-mode verdict to the comparator

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

## Verificación final (tras la Task 11)

- [ ] `php artisan test --compact` (suite completa): verde.
- [ ] `composer analyze` (phpstan + lint + types): verde.
- [ ] `npm run format:check` y `npm run build`: verde.
- [ ] Pasada de navegador completa a 1440 px y 390 px: listado → bandeja → comparador en las tres vistas → volver (la bandeja refleja la selección del comparador) → paginación del listado con selección → teclado en las tres vistas → `prefers-reduced-motion` (DevTools, emulación) sin animaciones del modal ni de los marcadores. Sin errores en consola ni scroll horizontal de página.
- [ ] Pedir al usuario que revise y, **solo con su aceptación explícita**, merge a `main` (regla del proyecto: nada de merges no aprobados).

## Autorrevisión (hecha)

- **Cobertura de la spec:**

  | Spec | Tarea |
  |---|---|
  | §2 ruta, validación, estado (`cmp-ids`, `cmp-vista`, URL) | 1, 5, 7 |
  | §3 casilla (`comparable`, `aria-pressed`, stopPropagation, 3 máx.) | 5 |
  | §3 dónde aparece (listado, mercado, plantilla, ficha del equipo, modal) | 5, 6 |
  | §3 bandeja (huecos, ×, Vaciar < 480 px, foco, avisos) | 5 |
  | §3 ficha del jugador (botón Comparar) | 6 |
  | §4 props y `ComparedPlayer` | 1, 3 |
  | §4 `LeagueCloudRow` y caché | 4 |
  | §4 DAZN solo oficial y estimación con `HqDaznBadge` en C | 3, 7, 10 |
  | §5 comunes (cabecera, selector de 3 iconos, tooltip, resaltado, colores, vacío) | 7 |
  | §5 A (secciones, ganador, recuentos, cabeceras fijas, Próximos 3, modal, gráfico) | 7, 8 |
  | §5 B (6 pistas, población, marcadores, tarjeta, teclado, tarjetas) | 9 |
  | §5 C (eje, métrica, celdas pasadas/futuras, iluminación, teclado, mercado) | 10 |
  | §5 implementación (`compare/…`, `derive.ts`, SVG propio, reduced motion) | 7–10 |
  | §5b veredicto god | 11 |
  | §6 tests PHP (0/2/3 ids, inválidos, >3, ruta, forma, consultas, LeagueCloud, cláusula, DAZN) | 1–4, 11 |
  | §6 frontend (types, lint, format) y navegador | 5–11, final |

- **Placeholders:** ninguno de los prohibidos. Dos puntos dependen a propósito de la rama previa y lo dicen: la firma exacta de `HqDifficultyBars` (aquí `fixture={slot}`) y los nombres de exportación de `rival-difficulty.ts`; cada tarea que pinta dificultad empieza con un Step 0 que obliga a leerlos en `main` y reutilizarlos.
- **Consistencia de tipos:** `ComparedPlayer`, `LeagueCloudRow`, `CompareManager`, `CompareView`, `DerivedPlayer`, `CompareEntry`, `COMPARE_SLOT_COLORS`, `useCompare()`, `CompareValueChart`, `ComparePropertyLine` y `verdict()` se usan con los mismos nombres en todas las tareas; `startProbabilityOf` (TS) y `LeagueCloud::startValue` (PHP) aplican la misma regla.
- **Review Focus:** cada punto tiene su test o comprobación en la tarea dueña (1 → Tasks 1, 5, 7; 2 → Tasks 1, 7, 10; 3 → Tasks 3, 4, 8, 9; 4 → Task 1 y 7; 5 → Tasks 7 y 8).
