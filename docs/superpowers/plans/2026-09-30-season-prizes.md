# Plan de implementación: premios de fin de temporada

> **Para agentes:** SUB-SKILL OBLIGATORIA: usa superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans para implementar este plan tarea a tarea. Los pasos usan casillas (`- [ ]`) para el seguimiento.

> **Cuándo y dónde:** implementar en **su propia rama desde `main`** (`feature/season-prizes`), y solo cuando no haya otro trabajo de código en marcha en el repo. Sin worktree: rama normal. Un commit por tarea. Nada se mergea sin aprobación explícita del usuario.

**Objetivo:** una página `/premios`, a la que se entra desde un enlace ancho bajo la clasificación de Inicio. Enseña la clasificación actual de los 10 premios del bote de sanciones, con los 7 mánagers ordenados en cada uno.

**Arquitectura:**
- El enum `SeasonPrize` define los premios.
- Cada premio tiene su calculadora (`App\Services\Prizes\*`), que devuelve una `PrizeRow` por mánager.
- `PrizeRanking` asigna los puestos, con empates.
- `SquadHistory` reconstruye quién tenía a cada jugador en cada momento. La usan el Banquillo y el Fichaje del Pueblo.
- `SeasonPrizeStandings` junta los 10 premios y los guarda en caché 10 minutos. Un listener de `CommandFinished` la invalida tras las sincronizaciones.
- `PrizesController@index` pasa el resultado a la página Inertia `prizes/index`, en React.

**Stack:** Laravel 13 / PHP 8.5, Pest, Inertia v3 + React + TypeScript + Tailwind v4, Wayfinder.

**Spec:** `docs/superpowers/specs/2026-09-30-season-prizes-design.md`. Léela antes de cada tarea: manda sobre este plan. Mock aprobado: `public/_premios-d.html` (D1 · Lista; no se commitea). Investigación: `C:/Users/Uri/code/comando-lechuga-research/premios/PLAN.md`.

## Restricciones globales

- **Decisiones del usuario** (spec, primera sección):
  - empates = puesto compartido y dinero a partes iguales;
  - sin tope de premios por mánager;
  - Banquillo reconstruido con el historial completo;
  - Criminal = solo `signing` + `buyout` del mánager, sobreprecio `max(0, importe − valor del día o último anterior)`;
  - Pueblo: el dueño inicial cuenta, una etapa de 0 jornadas cuenta y el tiempo va en jornadas, en `SeasonClock::firstKickoff`;
  - solo cuentan las jornadas terminadas (`SeasonClock::finishedWeekNumbers`);
  - Hueco libre = «Sin categoría todavía» con 5 €;
  - el enlace de Inicio es sencillo, sin avance.
- **Sin migraciones.** Todo sale de tablas que ya existen.
- **Si el mejor valor es 0, no hay líder.** Los empates comparten puesto (1, 1, 3) y se ordenan entre sí por `season_managers.position`.
- **PHP:**
  - `declare(strict_types=1)`, tipos de retorno explícitos, llaves siempre;
  - PHPDoc con array shapes;
  - enums en TitleCase;
  - clases nuevas `final` cuando no se extienden.
- **Pint** tras tocar PHP: `vendor/bin/pint --dirty --format agent`. **PHPStan:** `composer phpstan`.
- **Tests:**
  - Pest con factorías;
  - crear con `php artisan make:test --pest {Nombre}` (y `--unit` para los unitarios);
  - ejecutar el mínimo: `php artisan test --compact tests/...`.
- **Frontend:**
  - tokens `hq-*`;
  - **todo lo que se pulsa lleva `cursor-pointer`**;
  - funciona a 390 px sin scroll horizontal (salvo el selector «Soy»);
  - se reutilizan `HqPageHeader`, `HqSection`, `HqLed`, `EntityImage` + `crestTintStyle` y `HqPlayerStatsModal`;
  - los diálogos siguen el patrón de `HqScoringLegendDialog`: foco retenido, Esc, fondo y «×».
- **El detalle** se abre **centrado** en todas las anchuras.
- **Regla nueva del proyecto:** cualquier puntuación de un jugador en una jornada que se enseñe abre `HqPlayerStatsModal`. En esta página aplica al «el que más dejó» del Banquillo.
- **Sin notas al pie ni textos de distancia** en las filas.
- **`localStorage`** solo para el selector «Soy» (`premios-me`, con `none` para nadie). Toda lectura y escritura va en `try/catch`.
- **Commits:**
  - uno por tarea, en inglés, con el prefijo `feat:`/`test:`;
  - terminan con `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`;
  - no se mergea sin aprobación.

## Review Focus

1. **Temporada sin jornadas terminadas** (J1 en juego). Todas las calculadoras devuelven 0 o null sin fallar, ningún premio tiene líder y la página enseña «Nadie todavía». Test en la tarea 9.
2. **Un mánager sin alineación en una jornada terminada** (entró tarde a la liga). No rompe Domingo, Pupas, Noche ni Banquillo, y no gana «último» por no tener alineación. Test en la tarea 2.
3. **Una operación del Criminal sin valor de mercado** anterior (jugador recién dado de alta). No cuenta y no rompe. Test en la tarea 4.
4. **Un jugador que se vende y se vuelve a comprar** (etapas repetidas del mismo dueño). En el Pueblo cuenta como un dueño con «×2», y sus jornadas se suman. Test en la tarea 7.
5. **La jornada en juego** (J8 con alineaciones ya guardadas). No cuenta en ningún premio, tampoco en la racha del Matrimonio. Test en la tarea 5.

---

## Mapa de archivos

| Archivo | Responsabilidad |
|---|---|
| `app/Enums/SeasonPrize.php` | Los 10 premios: nombre, €, regla, si está decidido. El orden de los casos es el orden de la página. |
| `app/Services/Prizes/PrizeCalculator.php` | Interfaz: `rows(Season): list<PrizeRow>`. |
| `app/Services/Prizes/PrizeRow.php` | Fila de un mánager en un premio: valor + contexto. |
| `app/Services/Prizes/PrizeRanking.php` | Orden, puestos con empate, líderes, reparto. |
| `app/Services/Prizes/Concerns/ListsSeasonManagers.php` | Ids de los mánagers de la temporada + «el más repetido». |
| `app/Services/Prizes/NocheMagica.php` | Mejor jornada. |
| `app/Services/Prizes/WeeklyExtremes.php` | Qué jornadas terminadas acabó cada mánager primero / último. |
| `app/Services/Prizes/ReyDelDomingo.php`, `ElPupas.php` | Recuento de primeros / últimos. |
| `app/Services/Prizes/ElAtracador.php`, `LaVictima.php` | Cláusulas pagadas / sufridas. |
| `app/Services/Prizes/ElCriminal.php` | Sobreprecio en compras y cláusulas. |
| `app/Services/Prizes/Matrimonio.php` | Racha más larga mánager-jugador. |
| `app/Services/Prizes/SquadHistory.php` | Etapas de propiedad por jugador; dueño y plantilla en un instante. |
| `app/Services/Prizes/FichajeDelPueblo.php` | Jugadores con más dueños y jornadas de cada dueño. |
| `app/Services/Prizes/BanquilloDeOro.php` | Puntos sin alinear por jornada. |
| `app/Services/SeasonPrizeStandings.php` | Junta los 10 premios, con caché e invalidación. |
| `app/Listeners/ForgetSeasonPrizeStandings.php` | Invalida la caché tras las sincronizaciones. |
| `app/Http/Controllers/PrizesController.php` | Página `/premios`. |
| `routes/web.php` | Ruta `prizes.index`. |
| `resources/js/types/prizes.ts` | Tipos de la página. |
| `resources/js/lib/prize-viewer.ts` | Selector «Soy» (localStorage). |
| `resources/js/lib/prize-format.ts` | Valor y contexto de cada premio. |
| `resources/js/components/prizes/*.tsx` | Escudo/foto, etiquetas de jornada, bloque del primero, lista, fila, detalle y pasaporte. |
| `resources/js/pages/prizes/index.tsx` | La página. |
| `resources/js/pages/home/prizes-link.tsx` + `standings-table.tsx` | El enlace de Inicio. |

---

### Task 1: Enum `SeasonPrize`, `PrizeRow` y `PrizeRanking`

**Files:**
- Create: `app/Enums/SeasonPrize.php`
- Create: `app/Services/Prizes/PrizeRow.php`
- Create: `app/Services/Prizes/PrizeCalculator.php`
- Create: `app/Services/Prizes/PrizeRanking.php`
- Create: `app/Services/Prizes/Concerns/ListsSeasonManagers.php`
- Test: `tests/Unit/Enums/SeasonPrizeTest.php`, `tests/Unit/Services/PrizeRankingTest.php`

**Interfaces:**
- Produces:
  - `SeasonPrize::cases()` (orden de la página), `->label(): string`, `->amount(): int`, `->rule(): string`, `->isDecided(): bool`.
  - `new PrizeRow(int $seasonManagerId, int|float|null $value, array $context = [])`.
  - `interface PrizeCalculator { public function rows(Season $season): array; }` (`list<PrizeRow>`).
  - `PrizeRanking::rank(list<PrizeRow>, array<int,int> $leaguePositions): list<array{row: PrizeRow, place: int|null}>`.
  - `PrizeRanking::leaders(list<array{row: PrizeRow, place: int|null}>): list<int>`.
  - `PrizeRanking::shares(SeasonPrize, list<int>): array<int, float>`.
  - Trait `ListsSeasonManagers`: `managerIds(Season): list<int>` y `mostFrequent(list<int|null>): array{season_manager_id: int, count: int}|null`.

- [ ] **Step 1: Write the failing tests**

`php artisan make:test --pest --unit Enums/SeasonPrizeTest` and replace its content:

```php
<?php

declare(strict_types=1);

use App\Enums\SeasonPrize;

test('the ten prizes add up to the 70 euro pot in page order', function (): void {
    expect(array_map(fn (SeasonPrize $prize): string => $prize->value, SeasonPrize::cases()))->toBe([
        'noche_magica', 'el_atracador', 'rey_del_domingo', 'banquillo_de_oro',
        'el_criminal', 'la_victima', 'el_pupas', 'matrimonio', 'fichaje_del_pueblo', 'hueco_libre',
    ])
        ->and(array_sum(array_map(fn (SeasonPrize $prize): int => $prize->amount(), SeasonPrize::cases())))->toBe(70)
        ->and(SeasonPrize::NocheMagica->amount())->toBe(10)
        ->and(SeasonPrize::ElCriminal->amount())->toBe(5);
});

test('only the free slot is undecided', function (): void {
    expect(SeasonPrize::HuecoLibre->isDecided())->toBeFalse()
        ->and(SeasonPrize::Matrimonio->isDecided())->toBeTrue()
        ->and(SeasonPrize::FichajeDelPueblo->label())->toBe('El Fichaje del Pueblo');
});
```

`php artisan make:test --pest --unit Services/PrizeRankingTest` and replace its content:

```php
<?php

declare(strict_types=1);

use App\Enums\SeasonPrize;
use App\Services\Prizes\PrizeRanking;
use App\Services\Prizes\PrizeRow;

test('ties share the better place and follow the league order', function (): void {
    $ranked = PrizeRanking::rank([
        new PrizeRow(1, 2),
        new PrizeRow(2, 5),
        new PrizeRow(3, 5),
        new PrizeRow(4, null),
        new PrizeRow(5, 0),
    ], [1 => 1, 2 => 4, 3 => 2, 4 => 3, 5 => 5]);

    expect(array_map(fn (array $entry): array => [$entry['row']->seasonManagerId, $entry['place']], $ranked))
        ->toBe([[3, 1], [2, 1], [1, 3], [5, 4], [4, null]])
        ->and(PrizeRanking::leaders($ranked))->toBe([3, 2]);
});

test('nobody leads while the best value is zero', function (): void {
    $ranked = PrizeRanking::rank([new PrizeRow(1, 0), new PrizeRow(2, 0)], [1 => 1, 2 => 2]);

    expect(PrizeRanking::leaders($ranked))->toBe([]);
});

test('a prize is split evenly between tied leaders', function (): void {
    expect(PrizeRanking::shares(SeasonPrize::ElPupas, [7, 9]))->toBe([7 => 2.5, 9 => 2.5])
        ->and(PrizeRanking::shares(SeasonPrize::ElPupas, []))->toBe([]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Unit/Enums/SeasonPrizeTest.php tests/Unit/Services/PrizeRankingTest.php`
Expected: FAIL, «Class "App\Enums\SeasonPrize" not found».

- [ ] **Step 3: Write the implementation**

`app/Enums/SeasonPrize.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The season-end prizes paid from the sanctions pot (70 €). The case order
 * is the page order: the four 10 € prizes first.
 */
enum SeasonPrize: string
{
    case NocheMagica = 'noche_magica';
    case ElAtracador = 'el_atracador';
    case ReyDelDomingo = 'rey_del_domingo';
    case BanquilloDeOro = 'banquillo_de_oro';
    case ElCriminal = 'el_criminal';
    case LaVictima = 'la_victima';
    case ElPupas = 'el_pupas';
    case Matrimonio = 'matrimonio';
    case FichajeDelPueblo = 'fichaje_del_pueblo';
    case HuecoLibre = 'hueco_libre';

    public function label(): string
    {
        return match ($this) {
            self::NocheMagica => 'Noche Mágica',
            self::ElAtracador => 'El Atracador',
            self::ReyDelDomingo => 'Rey del Domingo',
            self::BanquilloDeOro => 'El Banquillo de Oro',
            self::ElCriminal => 'El Criminal',
            self::LaVictima => 'La Víctima',
            self::ElPupas => 'El Pupas',
            self::Matrimonio => 'Matrimonio',
            self::FichajeDelPueblo => 'El Fichaje del Pueblo',
            self::HuecoLibre => 'Hueco libre',
        };
    }

    /** Euros. */
    public function amount(): int
    {
        return match ($this) {
            self::NocheMagica, self::ElAtracador, self::ReyDelDomingo, self::BanquilloDeOro => 10,
            default => 5,
        };
    }

    public function rule(): string
    {
        return match ($this) {
            self::NocheMagica => 'La mejor puntuación en una sola jornada de todo el año.',
            self::ElAtracador => 'El que más cláusulas paga.',
            self::ReyDelDomingo => 'El que más veces queda primero de la jornada.',
            self::BanquilloDeOro => 'El que más puntos deja sin alinear.',
            self::ElCriminal => 'El que más paga por encima del valor de mercado, en compras y cláusulas.',
            self::LaVictima => 'Al que más cláusulas le pagan.',
            self::ElPupas => 'El que más veces queda último de la jornada.',
            self::Matrimonio => 'La pareja mánager-jugador con más jornadas seguidas alineado.',
            self::FichajeDelPueblo => 'El jugador que pasa por más manos; se lo lleva quien más jornadas lo tuvo.',
            self::HuecoLibre => 'Lo proponéis vosotros.',
        };
    }

    /** False only for the free slot, whose category is still to be proposed. */
    public function isDecided(): bool
    {
        return $this !== self::HuecoLibre;
    }
}
```

`app/Services/Prizes/PrizeRow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

/**
 * One manager's standing in one prize. `value` is null when the prize does
 * not apply to him (e.g. he never owned the Fichaje del Pueblo player);
 * `context` carries the prize-specific detail (a jornada, a player id…).
 */
final readonly class PrizeRow
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $seasonManagerId,
        public int|float|null $value,
        public array $context = [],
    ) {}
}
```

`app/Services/Prizes/PrizeCalculator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\Season;

interface PrizeCalculator
{
    /**
     * One row per manager of the season, in any order.
     *
     * @return list<PrizeRow>
     */
    public function rows(Season $season): array;
}
```

`app/Services/Prizes/PrizeRanking.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Enums\SeasonPrize;

final class PrizeRanking
{
    /**
     * Highest value first; nulls last without a place. Ties share the better
     * place (1, 1, 3) and are ordered among themselves by league position.
     *
     * @param  list<PrizeRow>  $rows
     * @param  array<int, int>  $leaguePositions  season manager id => league position
     * @return list<array{row: PrizeRow, place: int|null}>
     */
    public static function rank(array $rows, array $leaguePositions): array
    {
        usort($rows, function (PrizeRow $a, PrizeRow $b) use ($leaguePositions): int {
            $aMissing = $a->value === null;
            $bMissing = $b->value === null;

            if ($aMissing !== $bMissing) {
                return $aMissing ? 1 : -1;
            }

            $byValue = $b->value <=> $a->value;

            if ($byValue !== 0) {
                return $byValue;
            }

            return ($leaguePositions[$a->seasonManagerId] ?? PHP_INT_MAX) <=> ($leaguePositions[$b->seasonManagerId] ?? PHP_INT_MAX);
        });

        return array_map(fn (PrizeRow $row): array => [
            'row' => $row,
            'place' => $row->value === null
                ? null
                : 1 + count(array_filter($rows, fn (PrizeRow $other): bool => $other->value !== null && $other->value > $row->value)),
        ], $rows);
    }

    /**
     * Managers in first place, only when that value is above zero.
     *
     * @param  list<array{row: PrizeRow, place: int|null}>  $ranked
     * @return list<int>
     */
    public static function leaders(array $ranked): array
    {
        return array_values(array_map(
            fn (array $entry): int => $entry['row']->seasonManagerId,
            array_filter($ranked, fn (array $entry): bool => $entry['place'] === 1 && $entry['row']->value > 0),
        ));
    }

    /**
     * The prize split evenly between its leaders (decisión 1).
     *
     * @param  list<int>  $leaders
     * @return array<int, float> season manager id => euros
     */
    public static function shares(SeasonPrize $prize, array $leaders): array
    {
        if ($leaders === []) {
            return [];
        }

        return array_fill_keys($leaders, $prize->amount() / count($leaders));
    }
}
```

`app/Services/Prizes/Concerns/ListsSeasonManagers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes\Concerns;

use App\Models\Season;
use App\Models\SeasonManager;

trait ListsSeasonManagers
{
    /**
     * @return list<int>
     */
    private function managerIds(Season $season): array
    {
        /** @var list<int> */
        return SeasonManager::query()
            ->where('season_id', $season->id)
            ->orderBy('position')
            ->pluck('id')
            ->all();
    }

    /**
     * The most repeated manager id (first seen wins a tie); null when none.
     *
     * @param  list<int|null>  $ids
     * @return array{season_manager_id: int, count: int}|null
     */
    private function mostFrequent(array $ids): ?array
    {
        $counts = array_count_values(array_filter($ids, fn (?int $id): bool => $id !== null));

        if ($counts === []) {
            return null;
        }

        arsort($counts);
        $id = array_key_first($counts);

        return ['season_manager_id' => (int) $id, 'count' => $counts[$id]];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Unit/Enums/SeasonPrizeTest.php tests/Unit/Services/PrizeRankingTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
git add app/Enums/SeasonPrize.php app/Services/Prizes tests/Unit/Enums/SeasonPrizeTest.php tests/Unit/Services/PrizeRankingTest.php
git commit -m "feat: add the season prize enum and ranking"
```

---

### Task 2: Noche Mágica, Rey del Domingo y El Pupas

**Files:**
- Create: `app/Services/Prizes/NocheMagica.php`, `app/Services/Prizes/WeeklyExtremes.php`, `app/Services/Prizes/ReyDelDomingo.php`, `app/Services/Prizes/ElPupas.php`
- Test: `tests/Feature/Services/Prizes/LineupPrizesTest.php`

**Interfaces:**
- Consumes: `PrizeCalculator`, `PrizeRow`, `ListsSeasonManagers` (tarea 1); `SeasonClock::finishedWeekNumbers(Season): list<int>`.
- Produces:
  - `NocheMagica::rows()` → valor = puntos, `context['week_number']`.
  - `ReyDelDomingo::rows()` y `ElPupas::rows()` → valor = recuento, `context['weeks'] = list<int>` (orden cronológico).
  - `WeeklyExtremes::weeks(Season, bool $top): array<int, list<int>>`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Services/Prizes/LineupPrizesTest`, contenido:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\ElPupas;
use App\Services\Prizes\NocheMagica;
use App\Services\Prizes\PrizeRow;
use App\Services\Prizes\ReyDelDomingo;

/**
 * A season on jornada 3 (in play): jornadas 1 and 2 are finished.
 *
 * @return array{Season, SeasonManager, SeasonManager, SeasonManager}
 */
function lineupPrizeSeason(): array
{
    $season = Season::factory()->create(['current_week' => 3, 'total_weeks' => 38]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'state' => FixtureState::Live]);
    $a = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 1]);
    $b = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 2]);
    $late = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 3]);

    foreach ([[$a, 1, 60], [$b, 1, 40], [$a, 2, 50], [$b, 2, 50], [$late, 2, 30], [$a, 3, 99], [$b, 3, 1]] as [$manager, $week, $points]) {
        ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => $week, 'points' => $points]);
    }

    return [$season, $a, $b, $late];
}

/**
 * @param  list<PrizeRow>  $rows
 * @return array<int, PrizeRow>
 */
function byManager(array $rows): array
{
    return collect($rows)->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId)->all();
}

test('noche magica keeps the best finished jornada and ignores the one in play', function (): void {
    [$season, $a, $b, $late] = lineupPrizeSeason();

    $rows = byManager(app(NocheMagica::class)->rows($season));

    expect($rows[$a->id]->value)->toBe(60)
        ->and($rows[$a->id]->context)->toBe(['week_number' => 1])
        ->and($rows[$b->id]->value)->toBe(50)
        ->and($rows[$late->id]->value)->toBe(30);
});

test('a jornada tied on top counts for every tied manager', function (): void {
    [$season, $a, $b] = lineupPrizeSeason();

    $rows = byManager(app(ReyDelDomingo::class)->rows($season));

    expect($rows[$a->id]->value)->toBe(2)
        ->and($rows[$a->id]->context)->toBe(['weeks' => [1, 2]])
        ->and($rows[$b->id]->value)->toBe(1)
        ->and($rows[$b->id]->context)->toBe(['weeks' => [2]]);
});

test('a manager without a lineup in a jornada is never last in it', function (): void {
    [$season, $a, $b, $late] = lineupPrizeSeason();

    $rows = byManager(app(ElPupas::class)->rows($season));

    expect($rows[$b->id]->value)->toBe(1)
        ->and($rows[$b->id]->context)->toBe(['weeks' => [1]])
        ->and($rows[$late->id]->value)->toBe(1)
        ->and($rows[$late->id]->context)->toBe(['weeks' => [2]])
        ->and($rows[$a->id]->value)->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/Prizes/LineupPrizesTest.php`
Expected: FAIL, «Class "App\Services\Prizes\NocheMagica" not found».

- [ ] **Step 3: Write the implementation**

`app/Services/Prizes/NocheMagica.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\ManagerLineup;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;
use App\Services\SeasonClock;

/** The best single finished jornada of each manager; the earlier one on a tie. */
final class NocheMagica implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly SeasonClock $clock) {}

    public function rows(Season $season): array
    {
        $weeks = $this->clock->finishedWeekNumbers($season);
        /** @var array<int, array{points: int, week_number: int}> $best */
        $best = [];

        if ($weeks !== []) {
            ManagerLineup::query()
                ->whereIn('week_number', $weeks)
                ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
                ->orderBy('week_number')
                ->get(['season_manager_id', 'week_number', 'points'])
                ->each(function (ManagerLineup $lineup) use (&$best): void {
                    $current = $best[$lineup->season_manager_id] ?? null;

                    if ($current === null || $lineup->points > $current['points']) {
                        $best[$lineup->season_manager_id] = ['points' => $lineup->points, 'week_number' => $lineup->week_number];
                    }
                });
        }

        return array_map(fn (int $id): PrizeRow => isset($best[$id])
            ? new PrizeRow($id, $best[$id]['points'], ['week_number' => $best[$id]['week_number']])
            : new PrizeRow($id, null), $this->managerIds($season));
    }
}
```

`app/Services/Prizes/WeeklyExtremes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\ManagerLineup;
use App\Models\Season;
use App\Services\SeasonClock;

/**
 * Which finished jornadas each manager ended first (or last) in. Ties all
 * count; only jornadas with at least two lineups; a manager without a
 * lineup that jornada is left out of it.
 */
final class WeeklyExtremes
{
    public function __construct(private readonly SeasonClock $clock) {}

    /**
     * @return array<int, list<int>> season manager id => week numbers, ascending
     */
    public function weeks(Season $season, bool $top): array
    {
        $weeks = $this->clock->finishedWeekNumbers($season);
        $result = [];

        if ($weeks === []) {
            return [];
        }

        ManagerLineup::query()
            ->whereIn('week_number', $weeks)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->orderBy('week_number')
            ->get(['season_manager_id', 'week_number', 'points'])
            ->groupBy('week_number')
            ->each(function ($lineups, $weekNumber) use ($top, &$result): void {
                if ($lineups->count() < 2) {
                    return;
                }

                $target = $top ? $lineups->max('points') : $lineups->min('points');

                $lineups
                    ->filter(fn (ManagerLineup $lineup): bool => $lineup->points === $target)
                    ->each(function (ManagerLineup $lineup) use ($weekNumber, &$result): void {
                        $result[$lineup->season_manager_id][] = (int) $weekNumber;
                    });
            });

        return $result;
    }
}
```

`app/Services/Prizes/ReyDelDomingo.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;

/** How many finished jornadas each manager ended first in. */
final class ReyDelDomingo implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly WeeklyExtremes $extremes) {}

    public function rows(Season $season): array
    {
        $weeks = $this->extremes->weeks($season, top: true);

        return array_map(fn (int $id): PrizeRow => new PrizeRow($id, count($weeks[$id] ?? []), ['weeks' => $weeks[$id] ?? []]), $this->managerIds($season));
    }
}
```

`app/Services/Prizes/ElPupas.php`: igual que `ReyDelDomingo`, con el docblock «How many finished jornadas each manager ended last in.» y `$this->extremes->weeks($season, top: false)`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;

/** How many finished jornadas each manager ended last in. */
final class ElPupas implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly WeeklyExtremes $extremes) {}

    public function rows(Season $season): array
    {
        $weeks = $this->extremes->weeks($season, top: false);

        return array_map(fn (int $id): PrizeRow => new PrizeRow($id, count($weeks[$id] ?? []), ['weeks' => $weeks[$id] ?? []]), $this->managerIds($season));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/Prizes/LineupPrizesTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
git add app/Services/Prizes tests/Feature/Services/Prizes/LineupPrizesTest.php
git commit -m "feat: compute noche magica, rey del domingo and el pupas"
```

---

### Task 3: El Atracador y La Víctima

**Files:**
- Create: `app/Services/Prizes/ElAtracador.php`, `app/Services/Prizes/LaVictima.php`
- Test: `tests/Feature/Services/Prizes/BuyoutPrizesTest.php`

**Interfaces:**
- Consumes: `ListsSeasonManagers::managerIds()`, `mostFrequent()`.
- Produces:
  - `ElAtracador::rows()`: valor = cláusulas pagadas, `context['favourite'] = array{season_manager_id, count}|null`.
  - `LaVictima::rows()`: valor = cláusulas sufridas, `context['nemesis']`, con la misma forma.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Services/Prizes/BuyoutPrizesTest`:

```php
<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\ElAtracador;
use App\Services\Prizes\LaVictima;
use App\Services\Prizes\PrizeRow;

function buyout(Season $season, SeasonManager $payer, SeasonManager $victim): void
{
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Buyout,
        'source_season_manager_id' => $payer->id,
        'target_season_manager_id' => $victim->id,
    ]);
}

test('counts clauses paid and received with the favourite victim and the nemesis', function (): void {
    $season = Season::factory()->create();
    [$duke, $dubi, $cid] = SeasonManager::factory()->count(3)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();

    buyout($season, $duke, $dubi);
    buyout($season, $duke, $dubi);
    buyout($season, $duke, $cid);
    buyout($season, $cid, $duke);
    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Signing, 'source_season_manager_id' => $duke->id]);

    $paid = collect(app(ElAtracador::class)->rows($season))->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId);
    $received = collect(app(LaVictima::class)->rows($season))->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId);

    expect($paid[$duke->id]->value)->toBe(3)
        ->and($paid[$duke->id]->context)->toBe(['favourite' => ['season_manager_id' => $dubi->id, 'count' => 2]])
        ->and($paid[$dubi->id]->value)->toBe(0)
        ->and($paid[$dubi->id]->context)->toBe(['favourite' => null])
        ->and($received[$dubi->id]->value)->toBe(2)
        ->and($received[$dubi->id]->context)->toBe(['nemesis' => ['season_manager_id' => $duke->id, 'count' => 2]])
        ->and($received[$duke->id]->value)->toBe(1);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/Prizes/BuyoutPrizesTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Write the implementation**

`app/Services/Prizes/ElAtracador.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;

/** Clauses each manager paid (buyout source), with the manager he robbed most. */
final class ElAtracador implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function rows(Season $season): array
    {
        $buyouts = Activity::query()
            ->where('season_id', $season->id)
            ->where('type', SeasonActivityType::Buyout)
            ->orderBy('id')
            ->get(['source_season_manager_id', 'target_season_manager_id']);

        return array_map(function (int $id) use ($buyouts): PrizeRow {
            $paid = $buyouts->where('source_season_manager_id', $id);

            /** @var list<int|null> $victims */
            $victims = $paid->pluck('target_season_manager_id')->values()->all();

            return new PrizeRow($id, $paid->count(), ['favourite' => $this->mostFrequent($victims)]);
        }, $this->managerIds($season));
    }
}
```

`app/Services/Prizes/LaVictima.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;

/** Clauses paid against each manager (buyout target), with who paid most. */
final class LaVictima implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function rows(Season $season): array
    {
        $buyouts = Activity::query()
            ->where('season_id', $season->id)
            ->where('type', SeasonActivityType::Buyout)
            ->orderBy('id')
            ->get(['source_season_manager_id', 'target_season_manager_id']);

        return array_map(function (int $id) use ($buyouts): PrizeRow {
            $received = $buyouts->where('target_season_manager_id', $id);

            /** @var list<int|null> $payers */
            $payers = $received->pluck('source_season_manager_id')->values()->all();

            return new PrizeRow($id, $received->count(), ['nemesis' => $this->mostFrequent($payers)]);
        }, $this->managerIds($season));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/Prizes/BuyoutPrizesTest.php`
Expected: PASS.

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
git add app/Services/Prizes tests/Feature/Services/Prizes/BuyoutPrizesTest.php
git commit -m "feat: compute el atracador and la victima"
```

---

### Task 4: El Criminal

**Files:**
- Create: `app/Services/Prizes/ElCriminal.php`
- Test: `tests/Feature/Services/Prizes/ElCriminalTest.php`

**Interfaces:**
- Produces: `ElCriminal::rows()`. El valor son los euros pagados de más (int). `context['worst'] = array{player_id: int, overpaid: int}|null`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Services/Prizes/ElCriminalTest`:

```php
<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\ElCriminal;
use App\Services\Prizes\PrizeRow;

test('sums what purchases and clauses paid above the market value of that day or the last one before', function (): void {
    $season = Season::factory()->create();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $camello = Player::factory()->create();
    $newcomer = Player::factory()->create();

    PlayerMarket::factory()->create(['player_id' => $camello->id, 'date' => '2026-08-10', 'value' => 30_000_000]);
    PlayerMarket::factory()->create(['player_id' => $camello->id, 'date' => '2026-08-20', 'value' => 50_000_000]);

    $deal = fn (SeasonActivityType $type, Player $player, int $amount, string $at) => Activity::factory()->create([
        'season_id' => $season->id, 'type' => $type, 'source_season_manager_id' => $manager->id,
        'target_season_manager_id' => $type === SeasonActivityType::Buyout ? SeasonManager::factory()->create(['season_id' => $season->id])->id : null,
        'player_id' => $player->id, 'amount' => $amount, 'occurred_at' => $at,
    ]);

    $deal(SeasonActivityType::Signing, $camello, 53_100_000, '2026-08-15 21:00:00');
    $deal(SeasonActivityType::Buyout, $camello, 49_000_000, '2026-08-21 10:00:00');
    $deal(SeasonActivityType::Signing, $newcomer, 90_000_000, '2026-08-21 10:00:00');
    $deal(SeasonActivityType::Sale, $camello, 99_000_000, '2026-08-22 10:00:00');

    $row = collect(app(ElCriminal::class)->rows($season))->first(fn (PrizeRow $row): bool => $row->seasonManagerId === $manager->id);

    expect($row->value)->toBe(23_100_000)
        ->and($row->context)->toBe(['worst' => ['player_id' => $camello->id, 'overpaid' => 23_100_000]]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/Prizes/ElCriminalTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Write the implementation**

`app/Services/Prizes/ElCriminal.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;

/**
 * What each manager paid above market value: purchases (`signing`, won
 * bids included) and clauses (`buyout`) only. The value is the player's
 * market value that day, or the last one before; an operation with no
 * earlier value does not count.
 */
final class ElCriminal implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function rows(Season $season): array
    {
        $operations = Activity::query()
            ->where('season_id', $season->id)
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Buyout])
            ->whereNotNull('player_id')
            ->whereNotNull('amount')
            ->orderBy('occurred_at')
            ->get(['source_season_manager_id', 'player_id', 'amount', 'occurred_at']);

        $valuesByPlayer = PlayerMarket::query()
            ->whereIn('player_id', $operations->pluck('player_id')->unique())
            ->orderBy('date')
            ->get(['player_id', 'date', 'value'])
            ->groupBy('player_id');

        /** @var array<int, array{total: int, worst: array{player_id: int, overpaid: int}|null}> $totals */
        $totals = [];

        foreach ($operations as $operation) {
            $day = $operation->occurred_at->toDateString();
            $value = $valuesByPlayer->get($operation->player_id)
                ?->filter(fn (PlayerMarket $market): bool => $market->date->toDateString() <= $day)
                ->last()?->value;

            if ($value === null) {
                continue;
            }

            $overpaid = max(0, (int) $operation->amount - (int) $value);
            $managerId = $operation->source_season_manager_id;
            $current = $totals[$managerId] ?? ['total' => 0, 'worst' => null];
            $current['total'] += $overpaid;

            if ($overpaid > 0 && ($current['worst'] === null || $overpaid > $current['worst']['overpaid'])) {
                $current['worst'] = ['player_id' => (int) $operation->player_id, 'overpaid' => $overpaid];
            }

            $totals[$managerId] = $current;
        }

        return array_map(fn (int $id): PrizeRow => new PrizeRow($id, $totals[$id]['total'] ?? 0, ['worst' => $totals[$id]['worst'] ?? null]), $this->managerIds($season));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/Prizes/ElCriminalTest.php`
Expected: PASS. (Camello: la compra del 15-08 contra el valor del 10-08 da 23,1 M; la cláusula del 21-08 contra 50 M da 0; el jugador sin valor anterior no cuenta; la venta no cuenta.)

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
git add app/Services/Prizes/ElCriminal.php tests/Feature/Services/Prizes/ElCriminalTest.php
git commit -m "feat: compute el criminal"
```

---

### Task 5: Matrimonio

**Files:**
- Create: `app/Services/Prizes/Matrimonio.php`
- Test: `tests/Feature/Services/Prizes/MatrimonioTest.php`

**Interfaces:**
- Produces: `Matrimonio::rows()`: valor = racha (int), o `null` sin alineaciones. `context = array{player_id: int, from_week: int, to_week: int, alive: bool}|[]`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Services/Prizes/MatrimonioTest`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\Matrimonio;
use App\Services\Prizes\PrizeRow;

test('keeps the longest run of consecutive finished jornadas, the latest on a tie, and ignores the one in play', function (): void {
    $season = Season::factory()->create(['current_week' => 7, 'total_weeks' => 38]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 7, 'state' => FixtureState::Live]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $remiro = Player::factory()->create();
    $sivera = Player::factory()->create();

    $weeksByPlayer = [$remiro->id => [1, 2, 4, 5, 6, 7], $sivera->id => [1, 2, 3]];

    foreach (range(1, 7) as $week) {
        $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => $week]);

        foreach ($weeksByPlayer as $playerId => $weeks) {
            if (in_array($week, $weeks, true)) {
                ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $playerId]);
            }
        }
    }

    $row = collect(app(Matrimonio::class)->rows($season))->first(fn (PrizeRow $row): bool => $row->seasonManagerId === $manager->id);

    expect($row->value)->toBe(3)
        ->and($row->context)->toBe(['player_id' => $remiro->id, 'from_week' => 4, 'to_week' => 6, 'alive' => true]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/Prizes/MatrimonioTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Write the implementation**

`app/Services/Prizes/Matrimonio.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\ManagerLineup;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;
use App\Services\SeasonClock;

/**
 * Each manager's longest run of consecutive finished jornadas with the same
 * player in his lineup; the latest run on a tie. `alive` when it reaches
 * the last finished jornada.
 */
final class Matrimonio implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly SeasonClock $clock) {}

    public function rows(Season $season): array
    {
        $weeks = $this->clock->finishedWeekNumbers($season);
        /** @var array<int, array<int, list<int>>> $weeksByManagerPlayer */
        $weeksByManagerPlayer = [];

        if ($weeks !== []) {
            ManagerLineup::query()
                ->with('players:id,manager_lineup_id,player_id')
                ->whereIn('week_number', $weeks)
                ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
                ->orderBy('week_number')
                ->get(['id', 'season_manager_id', 'week_number'])
                ->each(function (ManagerLineup $lineup) use (&$weeksByManagerPlayer): void {
                    foreach ($lineup->players as $entry) {
                        $weeksByManagerPlayer[$lineup->season_manager_id][$entry->player_id][] = $lineup->week_number;
                    }
                });
        }

        $lastWeek = $weeks === [] ? 0 : max($weeks);

        return array_map(function (int $id) use ($weeksByManagerPlayer, $lastWeek): PrizeRow {
            $best = null;

            foreach ($weeksByManagerPlayer[$id] ?? [] as $playerId => $playerWeeks) {
                $start = null;
                $previous = null;

                foreach ($playerWeeks as $week) {
                    $start = $previous !== null && $week === $previous + 1 ? $start : $week;
                    $previous = $week;
                    $length = $week - $start + 1;

                    if ($best === null || $length > $best['length'] || ($length === $best['length'] && $week > $best['to_week'])) {
                        $best = ['length' => $length, 'player_id' => (int) $playerId, 'from_week' => $start, 'to_week' => $week];
                    }
                }
            }

            if ($best === null) {
                return new PrizeRow($id, null);
            }

            return new PrizeRow($id, $best['length'], [
                'player_id' => $best['player_id'],
                'from_week' => $best['from_week'],
                'to_week' => $best['to_week'],
                'alive' => $best['to_week'] === $lastWeek,
            ]);
        }, $this->managerIds($season));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/Prizes/MatrimonioTest.php`
Expected: PASS. (La J7 en juego no cuenta: Remiro va J4–J6, 3 jornadas, «alive», y empata con Sivera J1–J3; gana la racha más reciente.)

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
git add app/Services/Prizes/Matrimonio.php tests/Feature/Services/Prizes/MatrimonioTest.php
git commit -m "feat: compute matrimonio"
```

---

### Task 6: `SquadHistory` (quién tenía a cada jugador y cuándo)

**Files:**
- Create: `app/Services/Prizes/SquadHistory.php`
- Test: `tests/Feature/Services/Prizes/SquadHistoryTest.php`

**Interfaces:**
- Produces:
  - `SquadHistory::forSeason(Season): self`.
  - `->spells(int $playerId): list<array{season_manager_id: int, from: CarbonImmutable|null, to: CarbonImmutable|null}>`. `to` es exclusivo; `null` = abierto.
  - `->playerIds(): list<int>`.
  - `->ownerAt(int $playerId, CarbonInterface $at): ?int`.
  - `->squadAt(int $seasonManagerId, CarbonInterface $at): list<int>`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Services/Prizes/SquadHistoryTest`:

```php
<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\SquadHistory;
use Carbon\CarbonImmutable;

/**
 * DUBI starts with Vlachodimos (first move is his sale), CID buys him from
 * the market, Cruza pays his clause, then sells him. Remiro never moves:
 * he stays with Ariobretxa all season.
 *
 * @return array{Season, array<string, SeasonManager>, Player, Player}
 */
function squadHistorySeason(): array
{
    $season = Season::factory()->create();
    $managers = collect(['dubi', 'cid', 'cruza', 'ario'])
        ->mapWithKeys(fn (string $key): array => [$key => SeasonManager::factory()->create(['season_id' => $season->id])])
        ->all();
    $vlachodimos = Player::factory()->create();
    $remiro = Player::factory()->create();

    $move = fn (SeasonActivityType $type, string $source, ?string $target, string $at) => Activity::factory()->create([
        'season_id' => $season->id, 'type' => $type, 'player_id' => $vlachodimos->id,
        'source_season_manager_id' => $managers[$source]->id,
        'target_season_manager_id' => $target === null ? null : $managers[$target]->id,
        'occurred_at' => $at,
    ]);

    $move(SeasonActivityType::Sale, 'dubi', null, '2026-08-13 10:00:00');
    $move(SeasonActivityType::Signing, 'cid', null, '2026-08-14 20:00:00');
    $move(SeasonActivityType::Buyout, 'cruza', 'cid', '2026-08-27 09:00:00');
    $move(SeasonActivityType::Sale, 'cruza', null, '2026-08-27 18:00:00');

    ManagerPlayer::factory()->create(['season_manager_id' => $managers['ario']->id, 'player_id' => $remiro->id]);

    return [$season, $managers, $vlachodimos, $remiro];
}

test('replays the ownership spells of a player, the initial owner included', function (): void {
    [$season, $managers, $vlachodimos] = squadHistorySeason();

    $spells = SquadHistory::forSeason($season)->spells($vlachodimos->id);

    expect(array_column($spells, 'season_manager_id'))->toBe([$managers['dubi']->id, $managers['cid']->id, $managers['cruza']->id])
        ->and($spells[0]['from'])->toBeNull()
        ->and($spells[2]['to']?->toDateTimeString())->toBe('2026-08-27 18:00:00');
});

test('knows the squad at a known jornada lock', function (): void {
    [$season, $managers, $vlachodimos, $remiro] = squadHistorySeason();
    $history = SquadHistory::forSeason($season);
    $jornada2Lock = CarbonImmutable::parse('2026-08-22 19:00:00');

    expect($history->ownerAt($vlachodimos->id, $jornada2Lock))->toBe($managers['cid']->id)
        ->and($history->squadAt($managers['cid']->id, $jornada2Lock))->toBe([$vlachodimos->id])
        ->and($history->squadAt($managers['ario']->id, $jornada2Lock))->toBe([$remiro->id])
        ->and($history->ownerAt($vlachodimos->id, CarbonImmutable::parse('2026-08-13 11:00:00')))->toBeNull();
});

test('the replayed present matches the current squads', function (): void {
    [$season, $managers, $vlachodimos, $remiro] = squadHistorySeason();
    $history = SquadHistory::forSeason($season);

    foreach ($managers as $manager) {
        expect($history->squadAt($manager->id, now()))
            ->toEqualCanonicalizing(ManagerPlayer::query()->where('season_manager_id', $manager->id)->pluck('player_id')->all());
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/Prizes/SquadHistoryTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Write the implementation**

`app/Services/Prizes/SquadHistory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Who owned each player, and when, rebuilt from the season's activity:
 * a `signing` opens the source's spell, a `sale` closes it (the player goes
 * to the market), a `buyout` closes the target's spell and opens the
 * source's. When a player's first move gives him up (sale or buyout), the
 * giver held him from the start (the initial allocation). A player of
 * `manager_players` with no activity at all belongs to that manager all
 * season. Spells are [from, to): `to` exclusive, null = still open.
 *
 * @phpstan-type Spell array{season_manager_id: int, from: CarbonImmutable|null, to: CarbonImmutable|null}
 */
final class SquadHistory
{
    /**
     * @param  array<int, list<Spell>>  $spellsByPlayer
     */
    private function __construct(private readonly array $spellsByPlayer) {}

    public static function forSeason(Season $season): self
    {
        $spellsByPlayer = [];

        Activity::query()
            ->where('season_id', $season->id)
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Sale, SeasonActivityType::Buyout])
            ->whereNotNull('player_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['type', 'player_id', 'source_season_manager_id', 'target_season_manager_id', 'occurred_at'])
            ->groupBy('player_id')
            ->each(function (Collection $moves, int|string $playerId) use (&$spellsByPlayer): void {
                $spellsByPlayer[(int) $playerId] = self::replay($moves);
            });

        ManagerPlayer::query()
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get(['season_manager_id', 'player_id'])
            ->each(function (ManagerPlayer $owned) use (&$spellsByPlayer): void {
                $spellsByPlayer[$owned->player_id] ??= [['season_manager_id' => $owned->season_manager_id, 'from' => null, 'to' => null]];
            });

        return new self($spellsByPlayer);
    }

    /**
     * @param  Collection<int, Activity>  $moves
     * @return list<Spell>
     */
    private static function replay(Collection $moves): array
    {
        $spells = [];
        $open = null;

        foreach ($moves as $move) {
            $giver = match ($move->type) {
                SeasonActivityType::Sale => $move->source_season_manager_id,
                SeasonActivityType::Buyout => $move->target_season_manager_id,
                default => null,
            };
            $taker = $move->type === SeasonActivityType::Sale ? null : $move->source_season_manager_id;

            if ($spells === [] && $giver !== null) {
                $spells[] = ['season_manager_id' => $giver, 'from' => null, 'to' => null];
                $open = 0;
            }

            if ($open !== null && ($giver !== null || $taker !== null)) {
                $spells[$open]['to'] = $move->occurred_at;
                $open = null;
            }

            if ($taker !== null) {
                $spells[] = ['season_manager_id' => $taker, 'from' => $move->occurred_at, 'to' => null];
                $open = array_key_last($spells);
            }
        }

        return $spells;
    }

    /**
     * @return list<Spell>
     */
    public function spells(int $playerId): array
    {
        return $this->spellsByPlayer[$playerId] ?? [];
    }

    /**
     * @return list<int>
     */
    public function playerIds(): array
    {
        return array_keys($this->spellsByPlayer);
    }

    public function ownerAt(int $playerId, CarbonInterface $at): ?int
    {
        foreach ($this->spells($playerId) as $spell) {
            if (($spell['from'] === null || $spell['from'] <= $at) && ($spell['to'] === null || $at < $spell['to'])) {
                return $spell['season_manager_id'];
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function squadAt(int $seasonManagerId, CarbonInterface $at): array
    {
        return array_values(array_filter(
            $this->playerIds(),
            fn (int $playerId): bool => $this->ownerAt($playerId, $at) === $seasonManagerId,
        ));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/Prizes/SquadHistoryTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Validate against the local database**

Escribe el script de solo lectura `C:/Users/Uri/AppData/Local/Temp/squad-check.php`. **No va en el repo.**

```php
<?php
use App\Models\ManagerPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\SquadHistory;

$season = Season::current();
$history = SquadHistory::forSeason($season);
$mismatches = 0;
foreach (SeasonManager::query()->where('season_id', $season->id)->get() as $manager) {
    $replayed = $history->squadAt($manager->id, now());
    $current = ManagerPlayer::query()->where('season_manager_id', $manager->id)->pluck('player_id')->all();
    $missing = array_diff($current, $replayed);
    $extra = array_diff($replayed, $current);
    if ($missing !== [] || $extra !== []) {
        $mismatches++;
        echo $manager->name.': falta '.json_encode(array_values($missing)).' sobra '.json_encode(array_values($extra)).PHP_EOL;
    }
}
echo "Mánagers con diferencias: {$mismatches}".PHP_EOL;
```

Run (PowerShell): `php artisan tinker --execute "require 'C:/Users/Uri/AppData/Local/Temp/squad-check.php';"`
Expected: `Mánagers con diferencias: 0`. Si hay diferencias, **para** y enséñaselas al usuario antes de seguir: la decisión 3 da por hecho que el historial está completo.

- [ ] **Step 6: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
git add app/Services/Prizes/SquadHistory.php tests/Feature/Services/Prizes/SquadHistoryTest.php
git commit -m "feat: rebuild squad ownership history from activity"
```

---

### Task 7: El Fichaje del Pueblo

**Files:**
- Create: `app/Services/Prizes/FichajeDelPueblo.php`
- Test: `tests/Feature/Services/Prizes/FichajeDelPuebloTest.php`

**Interfaces:**
- Consumes: `SquadHistory` (tarea 6); `SeasonClock::finishedWeekNumbers()` y `firstKickoff(Season, int): ?CarbonImmutable`.
- Produces:
  - `FichajeDelPueblo::candidates(Season): list<PuebloCandidate>`, con `PuebloCandidate = array{player_id: int, chain: list<int>, owners: list<int>, transfers: int, on_market: bool, weeks_held: array<int, int>, winners: list<int>}`:
    - `chain`: los dueños en orden, con repeticiones;
    - `owners`: los dueños distintos, en orden de llegada;
    - `weeks_held`: mánager => jornadas;
    - `winners`: los que más jornadas lo tuvieron (vacío si el máximo es 0).
    - Solo hay candidatos con **al menos 2 dueños distintos**.
  - `FichajeDelPueblo::rows()`: valor = jornadas con el candidato (el máximo si hay empate de jugador), `null` si no lo tuvo nunca. `context['player_id']` es el candidato con el que se cuenta.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Services/Prizes/FichajeDelPuebloTest`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\FichajeDelPueblo;
use App\Services\Prizes\PrizeRow;

test('picks the player with most distinct owners and counts the jornadas each one held him', function (): void {
    $season = Season::factory()->create(['current_week' => 5, 'total_weeks' => 38]);
    foreach ([1 => '2026-08-15 19:00:00', 2 => '2026-08-22 19:00:00', 3 => '2026-08-29 19:00:00', 4 => '2026-09-05 19:00:00'] as $week => $kickoff) {
        Fixture::factory()->create(['season_id' => $season->id, 'week_number' => $week, 'date' => $kickoff, 'state' => FixtureState::Finished]);
    }
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 5, 'date' => '2026-09-12 19:00:00', 'state' => FixtureState::Live]);

    [$dubi, $cid, $cruza, $gau] = SeasonManager::factory()->count(4)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    $vlachodimos = Player::factory()->create();
    $boring = Player::factory()->create();

    $move = fn (Player $player, SeasonActivityType $type, SeasonManager $source, ?SeasonManager $target, string $at) => Activity::factory()->create([
        'season_id' => $season->id, 'type' => $type, 'player_id' => $player->id,
        'source_season_manager_id' => $source->id, 'target_season_manager_id' => $target?->id, 'occurred_at' => $at,
    ]);

    $move($vlachodimos, SeasonActivityType::Sale, $dubi, null, '2026-08-10 10:00:00');
    $move($vlachodimos, SeasonActivityType::Signing, $cid, null, '2026-08-11 20:00:00');
    $move($vlachodimos, SeasonActivityType::Buyout, $cruza, $cid, '2026-08-27 09:00:00');
    $move($vlachodimos, SeasonActivityType::Sale, $cruza, null, '2026-08-27 18:00:00');
    $move($vlachodimos, SeasonActivityType::Signing, $cid, null, '2026-09-01 20:00:00');
    $move($boring, SeasonActivityType::Signing, $gau, null, '2026-08-11 20:00:00');

    $pueblo = app(FichajeDelPueblo::class);
    $candidates = $pueblo->candidates($season);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['player_id'])->toBe($vlachodimos->id)
        ->and($candidates[0]['chain'])->toBe([$dubi->id, $cid->id, $cruza->id, $cid->id])
        ->and($candidates[0]['owners'])->toBe([$dubi->id, $cid->id, $cruza->id])
        ->and($candidates[0]['transfers'])->toBe(3)
        ->and($candidates[0]['on_market'])->toBeFalse()
        ->and($candidates[0]['weeks_held'])->toBe([$dubi->id => 0, $cid->id => 3, $cruza->id => 0])
        ->and($candidates[0]['winners'])->toBe([$cid->id]);

    $rows = collect($pueblo->rows($season))->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId);

    expect($rows[$cid->id]->value)->toBe(3)
        ->and($rows[$cid->id]->context)->toBe(['player_id' => $vlachodimos->id])
        ->and($rows[$cruza->id]->value)->toBe(0)
        ->and($rows[$gau->id]->value)->toBeNull();
});
```

(CID lo tiene en el cierre de J1 y J2, pierde la J3 porque Cruza lo tiene del 27 al 27 y luego está libre, y vuelve para la J4: 3 jornadas. La J5 está en juego. Hay 3 traspasos: la cláusula, la venta y la recompra. El reparto inicial no cuenta.)

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/Prizes/FichajeDelPuebloTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Write the implementation**

`app/Services/Prizes/FichajeDelPueblo.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;
use App\Services\SeasonClock;

/**
 * The player who went through most hands (distinct owners, the initial one
 * included; a same-day spell counts). Among his owners, the prize goes to
 * whoever held him the most finished jornadas, a jornada being held by the
 * owner at its lineup lock (first kickoff). Several players can tie.
 *
 * @phpstan-type PuebloCandidate array{player_id: int, chain: list<int>, owners: list<int>, transfers: int, on_market: bool, weeks_held: array<int, int>, winners: list<int>}
 */
final class FichajeDelPueblo implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly SeasonClock $clock) {}

    /**
     * @return list<PuebloCandidate>
     */
    public function candidates(Season $season): array
    {
        $history = SquadHistory::forSeason($season);
        $ownersByPlayer = [];

        foreach ($history->playerIds() as $playerId) {
            $ownersByPlayer[$playerId] = array_values(array_unique(array_column($history->spells($playerId), 'season_manager_id')));
        }

        $mostOwners = $ownersByPlayer === [] ? 0 : max(array_map('count', $ownersByPlayer));

        if ($mostOwners < 2) {
            return [];
        }

        $locks = [];

        foreach ($this->clock->finishedWeekNumbers($season) as $week) {
            $lock = $this->clock->firstKickoff($season, $week);

            if ($lock !== null) {
                $locks[] = $lock;
            }
        }

        $candidates = [];

        foreach ($ownersByPlayer as $playerId => $owners) {
            if (count($owners) !== $mostOwners) {
                continue;
            }

            $spells = $history->spells($playerId);
            $weeksHeld = array_fill_keys($owners, 0);

            foreach ($locks as $lock) {
                $owner = $history->ownerAt($playerId, $lock);

                if ($owner !== null) {
                    $weeksHeld[$owner]++;
                }
            }

            $max = max($weeksHeld);
            $onMarket = end($spells)['to'] !== null;

            $candidates[] = [
                'player_id' => $playerId,
                'chain' => array_column($spells, 'season_manager_id'),
                'owners' => $owners,
                'transfers' => count($spells) - 1 + ($onMarket ? 1 : 0),
                'on_market' => $onMarket,
                'weeks_held' => $weeksHeld,
                'winners' => $max === 0 ? [] : array_keys(array_filter($weeksHeld, fn (int $held): bool => $held === $max)),
            ];
        }

        return $candidates;
    }

    public function rows(Season $season): array
    {
        $candidates = $this->candidates($season);

        return array_map(function (int $id) use ($candidates): PrizeRow {
            $best = null;

            foreach ($candidates as $candidate) {
                if (isset($candidate['weeks_held'][$id]) && ($best === null || $candidate['weeks_held'][$id] > $best['value'])) {
                    $best = ['value' => $candidate['weeks_held'][$id], 'player_id' => $candidate['player_id']];
                }
            }

            return $best === null
                ? new PrizeRow($id, null)
                : new PrizeRow($id, $best['value'], ['player_id' => $best['player_id']]);
        }, $this->managerIds($season));
    }
}
```

Nota: `transfers` = etapas − 1 (cambios de manos, incluida la vuelta desde el mercado) + 1 si hoy está en el mercado.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/Prizes/FichajeDelPuebloTest.php`
Expected: PASS.

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
git add app/Services/Prizes/FichajeDelPueblo.php tests/Feature/Services/Prizes/FichajeDelPuebloTest.php
git commit -m "feat: compute el fichaje del pueblo"
```

---

### Task 8: El Banquillo de Oro

**Files:**
- Create: `app/Services/Prizes/BanquilloDeOro.php`
- Test: `tests/Feature/Services/Prizes/BanquilloDeOroTest.php`

**Interfaces:**
- Consumes: `SquadHistory::forSeason()->squadAt()`; `SeasonClock::finishedWeekNumbers()` y `firstKickoff()`.
- Produces: `BanquilloDeOro::rows()`: valor = puntos sin alinear (int). `context['top_miss'] = array{fixture_lineup_id: int, player_id: int, week_number: int, points: int}|null`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Services/Prizes/BanquilloDeOroTest`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\BanquilloDeOro;
use App\Services\Prizes\PrizeRow;

test('adds the points of squad players left out of each finished lineup', function (): void {
    $season = Season::factory()->create(['current_week' => 2, 'total_weeks' => 38]);
    $finished = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => '2026-08-15 19:00:00', 'state' => FixtureState::Finished]);
    $live = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => '2026-08-22 19:00:00', 'state' => FixtureState::Live]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    [$starter, $benched, $alsoBenched] = Player::factory()->count(3)->create()->all();

    foreach ([$starter, $benched, $alsoBenched] as $player) {
        ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $player->id]);
    }

    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $starter->id]);

    FixtureLineup::factory()->create(['fixture_id' => $finished->id, 'player_id' => $starter->id, 'fantasy_points' => 20]);
    $topMiss = FixtureLineup::factory()->create(['fixture_id' => $finished->id, 'player_id' => $benched->id, 'fantasy_points' => 14]);
    FixtureLineup::factory()->create(['fixture_id' => $finished->id, 'player_id' => $alsoBenched->id, 'fantasy_points' => null]);
    FixtureLineup::factory()->create(['fixture_id' => $live->id, 'player_id' => $benched->id, 'fantasy_points' => 30]);

    $row = collect(app(BanquilloDeOro::class)->rows($season))->first(fn (PrizeRow $row): bool => $row->seasonManagerId === $manager->id);

    expect($row->value)->toBe(14)
        ->and($row->context)->toBe(['top_miss' => [
            'fixture_lineup_id' => $topMiss->id, 'player_id' => $benched->id, 'week_number' => 1, 'points' => 14,
        ]]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/Prizes/BanquilloDeOroTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Write the implementation**

`app/Services/Prizes/BanquilloDeOro.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;
use App\Services\SeasonClock;

/**
 * Per finished jornada: the manager's squad at the lineup lock minus the
 * players he lined up; their fantasy points that jornada (null = 0) add
 * up. A jornada without the manager's lineup is skipped. `top_miss` is the
 * single biggest score left out.
 */
final class BanquilloDeOro implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly SeasonClock $clock) {}

    public function rows(Season $season): array
    {
        $history = SquadHistory::forSeason($season);
        $managerIds = $this->managerIds($season);
        $totals = array_fill_keys($managerIds, 0);
        $topMisses = array_fill_keys($managerIds, null);

        foreach ($this->clock->finishedWeekNumbers($season) as $week) {
            $lock = $this->clock->firstKickoff($season, $week);

            if ($lock === null) {
                continue;
            }

            $lineups = ManagerLineup::query()
                ->with('players:id,manager_lineup_id,player_id')
                ->where('week_number', $week)
                ->whereIn('season_manager_id', $managerIds)
                ->get(['id', 'season_manager_id'])
                ->keyBy('season_manager_id');

            $scores = FixtureLineup::query()
                ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id)->where('week_number', $week))
                ->whereNotNull('player_id')
                ->get(['id', 'player_id', 'fantasy_points'])
                ->groupBy('player_id');

            foreach ($managerIds as $managerId) {
                $lineup = $lineups->get($managerId);

                if ($lineup === null) {
                    continue;
                }

                $linedUp = $lineup->players->pluck('player_id')->all();

                foreach (array_diff($history->squadAt($managerId, $lock), $linedUp) as $playerId) {
                    foreach ($scores->get($playerId, collect()) as $score) {
                        $points = (int) ($score->fantasy_points ?? 0);
                        $totals[$managerId] += $points;

                        if ($points > 0 && ($topMisses[$managerId] === null || $points > $topMisses[$managerId]['points'])) {
                            $topMisses[$managerId] = ['fixture_lineup_id' => $score->id, 'player_id' => (int) $playerId, 'week_number' => $week, 'points' => $points];
                        }
                    }
                }
            }
        }

        return array_map(fn (int $id): PrizeRow => new PrizeRow($id, $totals[$id], ['top_miss' => $topMisses[$id]]), $managerIds);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/Prizes/BanquilloDeOroTest.php`
Expected: PASS. (En la J1: el suplente con 14 cuenta y el de puntos nulos suma 0. La J2 está en juego y no cuenta.)

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
git add app/Services/Prizes/BanquilloDeOro.php tests/Feature/Services/Prizes/BanquilloDeOroTest.php
git commit -m "feat: compute el banquillo de oro"
```

---

### Task 9: `SeasonPrizeStandings` + caché + invalidación

**Files:**
- Create: `app/Services/SeasonPrizeStandings.php`
- Create: `app/Listeners/ForgetSeasonPrizeStandings.php`
- Test: `tests/Feature/Services/SeasonPrizeStandingsTest.php`

**Interfaces:**
- Consumes: todas las calculadoras, `PrizeRanking`, `FichajeDelPueblo::candidates()`.
- Produces:
  - `SeasonPrizeStandings::forSeason(Season): array{prizes: list<PrizeStanding>, players: array<int, array{id: int, nickname: string, image: string}>}`.
  - `PrizeStanding = array{key: string, name: string, amount: int, rule: string, decided: bool, leaders: list<int>, shares: array<int, float>, rows: list<array{season_manager_id: int, place: int|null, value: int|float|null, context: array<string, mixed>}>, candidates: list<PuebloCandidate>}`.
  - `SeasonPrizeStandings::cacheKey(Season): string` (`season-prizes:{id}`) y `SeasonPrizeStandings::forget(Season): void`.
  - `ForgetSeasonPrizeStandings::COMMANDS` (lista de órdenes).

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Services/SeasonPrizeStandingsTest`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\SeasonPrizeStandings;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function currentPrizeSeason(int $currentWeek): Season
{
    return Season::factory()->create([
        'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
        'current_week' => $currentWeek, 'total_weeks' => 38,
    ]);
}

test('builds the ten prizes in page order with every manager ranked', function (): void {
    $season = currentPrizeSeason(2);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Scheduled]);
    [$gau, $cid] = SeasonManager::factory()->count(2)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    ManagerLineup::factory()->create(['season_manager_id' => $gau->id, 'week_number' => 1, 'points' => 71]);
    ManagerLineup::factory()->create(['season_manager_id' => $cid->id, 'week_number' => 1, 'points' => 71]);

    $prizes = app(SeasonPrizeStandings::class)->forSeason($season)['prizes'];
    $noche = $prizes[0];

    expect(array_column($prizes, 'key'))->toHaveCount(10)
        ->and($noche['key'])->toBe('noche_magica')
        ->and($noche['leaders'])->toBe([$gau->id, $cid->id])
        ->and($noche['shares'])->toBe([$gau->id => 5.0, $cid->id => 5.0])
        ->and(array_column($noche['rows'], 'place'))->toBe([1, 1])
        ->and($prizes[9]['decided'])->toBeFalse()
        ->and($prizes[9]['rows'])->toBe([]);
});

test('before any finished jornada nobody leads anything', function (): void {
    $season = currentPrizeSeason(1);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'state' => FixtureState::Live]);
    SeasonManager::factory()->count(3)->create(['season_id' => $season->id]);

    $prizes = app(SeasonPrizeStandings::class)->forSeason($season)['prizes'];

    expect(array_merge(...array_column($prizes, 'leaders')))->toBe([]);
});

test('a finished sync command forgets the cached standings', function (): void {
    $season = currentPrizeSeason(1);
    Cache::put(SeasonPrizeStandings::cacheKey($season), ['stale'], 600);

    event(new CommandFinished('season:sync-standing', new ArrayInput([]), new NullOutput, 0));
    expect(Cache::has(SeasonPrizeStandings::cacheKey($season)))->toBeTrue();

    event(new CommandFinished('season:sync-activity', new ArrayInput([]), new NullOutput, 1));
    expect(Cache::has(SeasonPrizeStandings::cacheKey($season)))->toBeTrue();

    event(new CommandFinished('season:sync-activity', new ArrayInput([]), new NullOutput, 0));
    expect(Cache::has(SeasonPrizeStandings::cacheKey($season)))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/SeasonPrizeStandingsTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Write the implementation**

`app/Services/SeasonPrizeStandings.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeasonPrize;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\BanquilloDeOro;
use App\Services\Prizes\ElAtracador;
use App\Services\Prizes\ElCriminal;
use App\Services\Prizes\ElPupas;
use App\Services\Prizes\FichajeDelPueblo;
use App\Services\Prizes\LaVictima;
use App\Services\Prizes\Matrimonio;
use App\Services\Prizes\NocheMagica;
use App\Services\Prizes\PrizeCalculator;
use App\Services\Prizes\PrizeRanking;
use App\Services\Prizes\PrizeRow;
use App\Services\Prizes\ReyDelDomingo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * The current standing of every season-end prize, cached for 10 minutes
 * and forgotten after the syncs that change it (ForgetSeasonPrizeStandings).
 *
 * @phpstan-import-type PuebloCandidate from FichajeDelPueblo
 *
 * @phpstan-type PrizeStanding array{key: string, name: string, amount: int, rule: string, decided: bool, leaders: list<int>, shares: array<int, float>, rows: list<array{season_manager_id: int, place: int|null, value: int|float|null, context: array<string, mixed>}>, candidates: list<PuebloCandidate>}
 * @phpstan-type PrizePlayer array{id: int, nickname: string, image: string}
 */
final class SeasonPrizeStandings
{
    public const int CACHE_MINUTES = 10;

    /**
     * @return array{prizes: list<PrizeStanding>, players: array<int, PrizePlayer>}
     */
    public function forSeason(Season $season): array
    {
        /** @var array{prizes: list<PrizeStanding>, players: array<int, PrizePlayer>} */
        return Cache::remember(self::cacheKey($season), now()->addMinutes(self::CACHE_MINUTES), fn (): array => $this->build($season));
    }

    public static function cacheKey(Season $season): string
    {
        return "season-prizes:{$season->id}";
    }

    public static function forget(Season $season): void
    {
        Cache::forget(self::cacheKey($season));
    }

    /**
     * @return array{prizes: list<PrizeStanding>, players: array<int, PrizePlayer>}
     */
    private function build(Season $season): array
    {
        /** @var array<int, int> $positions */
        $positions = SeasonManager::query()->where('season_id', $season->id)->pluck('position', 'id')->all();
        $prizes = [];

        foreach (SeasonPrize::cases() as $prize) {
            $calculator = $this->calculator($prize);
            $candidates = [];

            if ($calculator === null) {
                $ranked = [];
                $leaders = [];
            } elseif ($calculator instanceof FichajeDelPueblo) {
                $candidates = $calculator->candidates($season);
                $leaders = array_values(array_unique(array_merge(...array_map(fn (array $candidate): array => $candidate['winners'], $candidates ?: [['winners' => []]]))));
                $ranked = $this->rankAfterLeaders($calculator->rows($season), $leaders, $positions);
            } else {
                $ranked = PrizeRanking::rank($calculator->rows($season), $positions);
                $leaders = PrizeRanking::leaders($ranked);
            }

            $prizes[] = [
                'key' => $prize->value,
                'name' => $prize->label(),
                'amount' => $prize->amount(),
                'rule' => $prize->rule(),
                'decided' => $prize->isDecided(),
                'leaders' => $leaders,
                'shares' => PrizeRanking::shares($prize, $leaders),
                'rows' => array_map(fn (array $entry): array => [
                    'season_manager_id' => $entry['row']->seasonManagerId,
                    'place' => $entry['place'],
                    'value' => $entry['row']->value,
                    'context' => $entry['row']->context,
                ], $ranked),
                'candidates' => $candidates,
            ];
        }

        return ['prizes' => $prizes, 'players' => $this->players($prizes)];
    }

    /**
     * The Fichaje del Pueblo's leaders come from each tied player's winner,
     * not from the highest value: they go first with place 1 and the rest
     * are ranked after them.
     *
     * @param  list<PrizeRow>  $rows
     * @param  list<int>  $leaders
     * @param  array<int, int>  $positions
     * @return list<array{row: PrizeRow, place: int|null}>
     */
    private function rankAfterLeaders(array $rows, array $leaders, array $positions): array
    {
        $leading = array_values(array_filter($rows, fn (PrizeRow $row): bool => in_array($row->seasonManagerId, $leaders, true)));
        $others = array_values(array_filter($rows, fn (PrizeRow $row): bool => !in_array($row->seasonManagerId, $leaders, true)));

        return [
            ...array_map(fn (array $entry): array => ['row' => $entry['row'], 'place' => 1], PrizeRanking::rank($leading, $positions)),
            ...array_map(fn (array $entry): array => [
                'row' => $entry['row'],
                'place' => $entry['place'] === null ? null : $entry['place'] + count($leading),
            ], PrizeRanking::rank($others, $positions)),
        ];
    }

    private function calculator(SeasonPrize $prize): ?PrizeCalculator
    {
        $class = match ($prize) {
            SeasonPrize::NocheMagica => NocheMagica::class,
            SeasonPrize::ElAtracador => ElAtracador::class,
            SeasonPrize::ReyDelDomingo => ReyDelDomingo::class,
            SeasonPrize::BanquilloDeOro => BanquilloDeOro::class,
            SeasonPrize::ElCriminal => ElCriminal::class,
            SeasonPrize::LaVictima => LaVictima::class,
            SeasonPrize::ElPupas => ElPupas::class,
            SeasonPrize::Matrimonio => Matrimonio::class,
            SeasonPrize::FichajeDelPueblo => FichajeDelPueblo::class,
            SeasonPrize::HuecoLibre => null,
        };

        return $class === null ? null : app($class);
    }

    /**
     * Every player a prize refers to (Matrimonio, Criminal, Banquillo,
     * Fichaje del Pueblo), by id, in the shape the page draws.
     *
     * @param  list<PrizeStanding>  $prizes
     * @return array<int, PrizePlayer>
     */
    private function players(array $prizes): array
    {
        $ids = [];

        foreach ($prizes as $prize) {
            foreach ($prize['rows'] as $row) {
                $context = $row['context'];
                $ids[] = $context['player_id'] ?? null;
                $ids[] = $context['worst']['player_id'] ?? null;
                $ids[] = $context['top_miss']['player_id'] ?? null;
            }

            foreach ($prize['candidates'] as $candidate) {
                $ids[] = $candidate['player_id'];
            }
        }

        /** @var array<int, PrizePlayer> */
        return Player::query()
            ->whereIn('id', array_unique(array_filter($ids)))
            ->get()
            ->mapWithKeys(fn (Player $player): array => [$player->id => Arr::only($player->toArray(), ['id', 'nickname', 'image'])])
            ->all();
    }
}
```

`app/Listeners/ForgetSeasonPrizeStandings.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Season;
use App\Services\SeasonPrizeStandings;
use Illuminate\Console\Events\CommandFinished;

/**
 * Forgets the cached prize standings of the current season(s) after a
 * successful sync that can change them.
 */
final class ForgetSeasonPrizeStandings
{
    /** @var list<string> */
    public const array COMMANDS = [
        'season:sync-activity',
        'season:sync-manager-lineups',
        'season:sync-manager-players',
        'season:sync-current-match-data',
        'season:sync-live-match-data',
        'season:sync-match-data-backfill',
        'season:sync-fixtures',
        'season:sync-week',
        'season:sync-player-markets',
    ];

    public function handle(CommandFinished $event): void
    {
        if ($event->exitCode !== 0 || !in_array($event->command, self::COMMANDS, true)) {
            return;
        }

        Season::query()
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->get()
            ->each(fn (Season $season) => SeasonPrizeStandings::forget($season));
    }
}
```

Laravel descubre solo los listeners de `app/Listeners` (tipo del argumento de `handle`). Si el tercer test no pasa porque no se registra, comprueba con `php artisan event:list --event="Illuminate\Console\Events\CommandFinished"`. Si hace falta, regístralo en `AppServiceProvider::boot()` con `Event::listen(CommandFinished::class, ForgetSeasonPrizeStandings::class)`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Services/SeasonPrizeStandingsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
git add app/Services/SeasonPrizeStandings.php app/Listeners/ForgetSeasonPrizeStandings.php tests/Feature/Services/SeasonPrizeStandingsTest.php
git commit -m "feat: assemble cached season prize standings"
```

---

### Task 10: `PrizesController` y ruta `/premios`

**Files:**
- Create: `app/Http/Controllers/PrizesController.php`
- Modify: `routes/web.php` (import + `Route::get('/premios', [PrizesController::class, 'index'])->name('prizes.index');` después de `/actividad`)
- Create: `resources/js/pages/prizes/index.tsx` (un marcador mínimo para que Inertia encuentre el componente; se sustituye en la tarea 12)
- Test: `tests/Feature/Http/Controllers/PrizesControllerTest.php`

**Interfaces:**
- Consumes: `SeasonPrizeStandings::forSeason()`, `SeasonClock::finishedWeekNumbers()`, `DaznEstimatePresenter::present(FixtureLineup, Fixture)`.
- Produces, props de Inertia de `prizes/index`:
  - `season`, `lastFinishedWeek: int`;
  - `managers: list<{id, name, logo, primary_color, position}>`;
  - `prizes: list<PrizeStanding>`;
  - `players: object<id, {id, nickname, image}>`;
  - `benchMisses: object<fixture_lineup_id, {player, team, points, stats, fixture, week_number} & DaznFields>`.

- [ ] **Step 1: Write the failing test**

`php artisan make:test --pest Http/Controllers/PrizesControllerTest`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the prizes page with every prize and the bench miss modal data', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
        'current_week' => 2, 'total_weeks' => 38,
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => now()->subWeek(), 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Scheduled]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 1]);
    $benched = Player::factory()->create();
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $benched->id]);
    ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 40]);
    $miss = FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $benched->id, 'fantasy_points' => 12, 'fantasy_stats' => ['goals' => 1]]);

    $this->get(route('prizes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('prizes/index')
            ->where('lastFinishedWeek', 1)
            ->has('managers', 1)
            ->has('prizes', 10)
            ->where('prizes.0.key', 'noche_magica')
            ->where('prizes.3.rows.0.context.top_miss.fixture_lineup_id', $miss->id)
            ->where("benchMisses.{$miss->id}.points", 12)
            ->where("benchMisses.{$miss->id}.week_number", 1)
            ->where("benchMisses.{$miss->id}.player.id", $benched->id)
            ->has("benchMisses.{$miss->id}.fixture")
            ->has("players.{$benched->id}"));
});

test('the prizes route is /premios', function (): void {
    expect(route('prizes.index', absolute: false))->toBe('/premios');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Http/Controllers/PrizesControllerTest.php`
Expected: FAIL, «Route [prizes.index] not defined».

- [ ] **Step 3: Write the implementation**

`app/Http/Controllers/PrizesController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SeasonPrize;
use App\Models\FixtureLineup;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\DaznEstimatePresenter;
use App\Services\SeasonClock;
use App\Services\SeasonPrizeStandings;
use Inertia\Inertia;
use Inertia\Response;

class PrizesController extends Controller
{
    public function index(SeasonPrizeStandings $standings, SeasonClock $clock): Response
    {
        $season = Season::current();
        $computed = $standings->forSeason($season);
        $finishedWeeks = $clock->finishedWeekNumbers($season);

        return Inertia::render('prizes/index', [
            'season' => $season,
            'lastFinishedWeek' => $finishedWeeks === [] ? 0 : max($finishedWeeks),
            'managers' => SeasonManager::query()
                ->where('season_id', $season->id)
                ->orderBy('position')
                ->get(['id', 'name', 'logo', 'primary_color', 'position']),
            'prizes' => $computed['prizes'],
            // Cast to object: numeric keys would otherwise serialize as a sparse array.
            'players' => (object) $computed['players'],
            'benchMisses' => (object) $this->benchMisses($computed['prizes']),
        ]);
    }

    /**
     * The HqPlayerStatsModal entry of each manager's biggest bench miss,
     * keyed by fixture lineup id. The team is the match team
     * (`fixture_lineups.team_id`), never the player's current club.
     *
     * @param  list<array{key: string, rows: list<array{context: array<string, mixed>}>}>  $prizes
     * @return array<int, array<string, mixed>>
     */
    private function benchMisses(array $prizes): array
    {
        $bench = collect($prizes)->firstWhere('key', SeasonPrize::BanquilloDeOro->value);
        $ids = collect($bench['rows'] ?? [])->pluck('context.top_miss.fixture_lineup_id')->filter()->all();

        return FixtureLineup::query()
            ->with(['player', 'team', 'fixture.localTeam', 'fixture.guestTeam'])
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (FixtureLineup $lineup): array => [$lineup->id => [
                'player' => $lineup->player,
                'team' => $lineup->team,
                'points' => (int) $lineup->fantasy_points,
                'stats' => $lineup->fantasy_stats ?? (object) [],
                'fixture' => $lineup->fixture,
                'week_number' => $lineup->fixture->week_number,
                ...DaznEstimatePresenter::present($lineup, $lineup->fixture),
            ]])
            ->all();
    }
}
```

`resources/js/pages/prizes/index.tsx` (marcador temporal):

```tsx
export default function PrizesIndex() {
    return null;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Http/Controllers/PrizesControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
composer phpstan
git add app/Http/Controllers/PrizesController.php routes/web.php resources/js/pages/prizes/index.tsx tests/Feature/Http/Controllers/PrizesControllerTest.php
git commit -m "feat: add the prizes page route"
```

---

### Task 11: Frontend base: tipos, selector «Soy», formato y piezas pequeñas

**Files:**
- Create: `resources/js/types/prizes.ts`
- Create: `resources/js/lib/prize-viewer.ts`
- Create: `resources/js/lib/prize-format.ts`
- Create: `resources/js/components/prizes/prize-crest.tsx`
- Create: `resources/js/components/prizes/jornada-badges.tsx`

**Interfaces:**
- Consumes: props de la tarea 10.
- Produces:
  - Tipos: `PrizeManager`, `PrizePlayer`, `PrizeRowData`, `PrizeContext`, `PuebloCandidate`, `PrizeStanding`, `BenchMiss`, `PrizesPageProps`.
  - `usePrizeViewer(): number | null` y `togglePrizeViewer(id: number): void`.
  - `shortManagerName(name: string): string`.
  - `leaderValue(prize, row): {big: string; unit: string}`.
  - `restValue(prize, row): {text: string; muted: boolean}`.
  - `<ManagerCrest manager size />`, `<PlayerPhoto player size />`.
  - `<JornadaBadges weeks max tone />` y `<JornadaSpan from to />`.

- [ ] **Step 1: Write the types**

`resources/js/types/prizes.ts`:

```ts
import type {
    DaznFields,
    Fixture,
    JornadaStats,
    Player,
    Season,
    SeasonManager,
    Team,
} from '@/types/models';

export type PrizeManager = Pick<
    SeasonManager,
    'id' | 'name' | 'logo' | 'primary_color' | 'position'
>;

export interface PrizePlayer {
    id: number;
    nickname: string;
    image: string;
}

/** Every key a prize may put in a row's context (see the prize calculators). */
export interface PrizeContext {
    week_number?: number;
    weeks?: number[];
    favourite?: { season_manager_id: number; count: number } | null;
    nemesis?: { season_manager_id: number; count: number } | null;
    worst?: { player_id: number; overpaid: number } | null;
    player_id?: number;
    from_week?: number;
    to_week?: number;
    alive?: boolean;
    top_miss?: {
        fixture_lineup_id: number;
        player_id: number;
        week_number: number;
        points: number;
    } | null;
}

export interface PrizeRowData {
    season_manager_id: number;
    place: number | null;
    value: number | null;
    context: PrizeContext;
}

export interface PuebloCandidate {
    player_id: number;
    chain: number[];
    owners: number[];
    transfers: number;
    on_market: boolean;
    weeks_held: Record<string, number>;
    winners: number[];
}

export type SeasonPrizeKey =
    | 'noche_magica'
    | 'el_atracador'
    | 'rey_del_domingo'
    | 'banquillo_de_oro'
    | 'el_criminal'
    | 'la_victima'
    | 'el_pupas'
    | 'matrimonio'
    | 'fichaje_del_pueblo'
    | 'hueco_libre';

export interface PrizeStanding {
    key: SeasonPrizeKey;
    name: string;
    amount: number;
    rule: string;
    decided: boolean;
    leaders: number[];
    shares: Record<string, number>;
    rows: PrizeRowData[];
    candidates: PuebloCandidate[];
}

export interface BenchMiss extends DaznFields {
    player: Player;
    team: Team;
    points: number;
    stats: JornadaStats;
    fixture: Fixture;
    week_number: number;
}

export interface PrizesPageProps {
    season: Season;
    lastFinishedWeek: number;
    managers: PrizeManager[];
    prizes: PrizeStanding[];
    players: Record<string, PrizePlayer>;
    benchMisses: Record<string, BenchMiss>;
}
```

- [ ] **Step 2: Write the viewer store and the formatting helpers**

`resources/js/lib/prize-viewer.ts`:

```ts
import { useSyncExternalStore } from 'react';

/** The «Soy» picker: a per-viewer convenience only, never shared. */
const STORAGE_KEY = 'premios-me';
const NOBODY = 'none';
const CHANGE_EVENT = 'premios-me-change';

function read(): number | null {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);

        if (raw === null || raw === NOBODY) {
            return null;
        }

        const id = Number(raw);

        return Number.isInteger(id) && id > 0 ? id : null;
    } catch {
        return null;
    }
}

let current: number | null = typeof window === 'undefined' ? null : read();

function subscribe(onChange: () => void): () => void {
    window.addEventListener(CHANGE_EVENT, onChange);

    return () => window.removeEventListener(CHANGE_EVENT, onChange);
}

/** The highlighted manager id, or null for the neutral state. */
export function usePrizeViewer(): number | null {
    return useSyncExternalStore(
        subscribe,
        () => current,
        () => null,
    );
}

/** Selects a manager; selecting the highlighted one again clears it. */
export function togglePrizeViewer(id: number): void {
    current = current === id ? null : id;

    try {
        window.localStorage.setItem(
            STORAGE_KEY,
            current === null ? NOBODY : String(current),
        );
    } catch {
        // Storage can be unavailable (private mode): the choice still holds for this visit.
    }

    window.dispatchEvent(new Event(CHANGE_EVENT));
}
```

`resources/js/lib/prize-format.ts`:

```ts
import { formatDecimal } from '@/lib/format';
import type { PrizeRowData, PrizeStanding } from '@/types/prizes';

/** "Cruza FC" → "Cruza", "CID F.C" → "CID": the club suffix adds nothing in tight lists. */
export function shortManagerName(name: string): string {
    return name.replace(/\s+F\.?\s?C\.?$/i, '').trim() || name;
}

function times(count: number, one: string, many: string): string {
    return count === 1 ? one : many;
}

/** Euros → "181,7" (millions, one decimal). */
export function millions(euros: number): string {
    return formatDecimal(Math.round(euros / 100_000) / 10);
}

/** The leader's big LED value and its small unit. */
export function leaderValue(
    prize: PrizeStanding,
    row: PrizeRowData,
): { big: string; unit: string } {
    const value = row.value ?? 0;

    switch (prize.key) {
        case 'noche_magica':
            return { big: String(value), unit: 'pts' };
        case 'el_atracador':
        case 'la_victima':
            return {
                big: String(value),
                unit: times(value, 'cláusula', 'cláusulas'),
            };
        case 'rey_del_domingo':
        case 'el_pupas':
            return { big: String(value), unit: times(value, 'vez', 'veces') };
        case 'banquillo_de_oro':
            return { big: String(value), unit: 'pts sin alinear' };
        case 'el_criminal':
            return { big: millions(value), unit: 'M€ de más' };
        case 'matrimonio':
            return { big: String(value), unit: 'jornadas seguidas' };
        default:
            return { big: String(value), unit: '' };
    }
}

/** The short value in the list of the other six ("—" when a count is 0, "no lo tuvo" without a value). */
export function restValue(
    prize: PrizeStanding,
    row: PrizeRowData,
): { text: string; muted: boolean } {
    if (row.value === null) {
        return {
            text: prize.key === 'fichaje_del_pueblo' ? 'no lo tuvo' : '—',
            muted: true,
        };
    }

    switch (prize.key) {
        case 'noche_magica':
            return {
                text: `${row.value} · J${row.context.week_number}`,
                muted: false,
            };
        case 'rey_del_domingo':
        case 'el_pupas':
            return row.value === 0
                ? { text: '—', muted: true }
                : { text: String(row.value), muted: false };
        case 'el_criminal':
            return { text: `${millions(row.value)} M€`, muted: false };
        case 'fichaje_del_pueblo':
            return {
                text: `${row.value} ${times(row.value, 'jor.', 'jor.')}`,
                muted: false,
            };
        default:
            return { text: String(row.value), muted: false };
    }
}
```

(`formatDecimal` ya existe en `resources/js/lib/format.ts`. Comprueba que da coma decimal en español; si no, usa `value.toLocaleString('es-ES', { maximumFractionDigits: 1, minimumFractionDigits: 1 })`.)

- [ ] **Step 3: Write the crest/photo and badge components**

`resources/js/components/prizes/prize-crest.tsx`:

```tsx
import { Shield, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { crestTintStyle } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import type { PrizeManager, PrizePlayer } from '@/types/prizes';

/** A manager crest on its tinted square, as in the home standings. */
export function ManagerCrest({
    manager,
    className = 'size-[22px]',
}: {
    manager: PrizeManager;
    className?: string;
}) {
    return (
        <EntityImage
            src={manager.logo}
            alt={manager.name}
            fallback={Shield}
            shape="square"
            style={crestTintStyle(manager.primary_color)}
            className={cn(
                'shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt p-[2px] text-hq-khaki',
                className,
            )}
        />
    );
}

/** A player's square photo (face at the top). */
export function PlayerPhoto({
    player,
    className = 'size-9',
}: {
    player: PrizePlayer;
    className?: string;
}) {
    if (!player.image) {
        return (
            <span
                className={cn(
                    'inline-grid shrink-0 place-items-center border border-hq-border-strong bg-hq-panel-alt text-hq-moss',
                    className,
                )}
            >
                <User className="size-1/2" aria-hidden="true" />
            </span>
        );
    }

    return (
        <img
            src={player.image}
            alt={player.nickname}
            className={cn(
                'shrink-0 border border-hq-border-strong bg-hq-panel-alt object-cover object-top',
                className,
            )}
        />
    );
}
```

`resources/js/components/prizes/jornada-badges.tsx`:

```tsx
import { cn } from '@/lib/utils';

const BADGE =
    'inline-block border px-1 py-[3px] font-mono text-[10.5px] leading-none font-semibold whitespace-nowrap';

/**
 * Jornada tags in chronological order. Past `max` tags, the most recent
 * `max - 1` are shown after a "+N" tag holding the older ones (listed in
 * its title); the detail shows them all.
 */
export function JornadaBadges({
    weeks,
    max,
    tone = 'lime',
    className,
}: {
    weeks: number[];
    max: number;
    tone?: 'lime' | 'neg';
    className?: string;
}) {
    if (weeks.length === 0) {
        return null;
    }

    const shown = weeks.length > max ? weeks.slice(weeks.length - (max - 1)) : weeks;
    const older = weeks.slice(0, weeks.length - shown.length);

    return (
        <span className={cn('inline-flex flex-wrap items-center gap-[3px]', className)}>
            {older.length > 0 && (
                <i
                    title={older.map((week) => `J${week}`).join(' · ')}
                    className={cn(BADGE, 'border-hq-border-bright bg-hq-panel-alt text-hq-paper not-italic')}
                >
                    +{older.length}
                </i>
            )}
            {shown.map((week) => (
                <i
                    key={week}
                    className={cn(
                        BADGE,
                        'not-italic',
                        tone === 'neg'
                            ? 'border-hq-neg/40 text-hq-neg'
                            : 'border-hq-lime/40 text-hq-lime',
                    )}
                >
                    J{week}
                </i>
            ))}
        </span>
    );
}

/** A streak as its first and last jornada: "J9 – J31". */
export function JornadaSpan({ from, to }: { from: number; to: number }) {
    return (
        <span className="inline-flex items-center gap-1">
            <i className={cn(BADGE, 'border-hq-lime/40 text-hq-lime not-italic')}>J{from}</i>
            <span className="font-mono text-[10px] text-hq-moss-dim">–</span>
            <i className={cn(BADGE, 'border-hq-lime/40 text-hq-lime not-italic')}>J{to}</i>
        </span>
    );
}
```

- [ ] **Step 4: Type-check and lint**

Run: `npm run types:check` y después `npm run lint:check`
Expected: sin errores. (Estos archivos todavía no se usan: los consume la tarea 12.)

- [ ] **Step 5: Commit**

```bash
npx prettier --write resources/js/types/prizes.ts resources/js/lib/prize-viewer.ts resources/js/lib/prize-format.ts resources/js/components/prizes
git add resources/js/types/prizes.ts resources/js/lib/prize-viewer.ts resources/js/lib/prize-format.ts resources/js/components/prizes
git commit -m "feat: add prize page types, viewer picker store and small pieces"
```

---

### Task 12: La página `/premios` (D1 · Lista)

**Files:**
- Create: `resources/js/components/prizes/prize-leader.tsx`
- Create: `resources/js/components/prizes/prize-rest-list.tsx`
- Create: `resources/js/components/prizes/prize-row.tsx`
- Modify: `resources/js/pages/prizes/index.tsx` (sustituye el marcador)
- Modify: `resources/js/components/hq-page-header.tsx` (prop opcional `lede`)

**Interfaces:**
- Consumes: todo lo de la tarea 11 y `@/routes` (`home`).
- Produces:
  - `<PrizeRow prize managers players viewer onOpen />`, que abre el detalle con `onOpen(prize.key)`;
  - la página, que guarda `openKey: SeasonPrizeKey | null` para la tarea 13.

- [ ] **Step 1: Write the leader block**

`resources/js/components/prizes/prize-leader.tsx`:

```tsx
import { HqLed } from '@/components/hq-led';
import { JornadaBadges, JornadaSpan } from '@/components/prizes/jornada-badges';
import { ManagerCrest, PlayerPhoto } from '@/components/prizes/prize-crest';
import { leaderValue, millions, shortManagerName } from '@/lib/prize-format';
import { cn } from '@/lib/utils';
import type { PrizeManager, PrizePlayer, PrizeRowData, PrizeStanding } from '@/types/prizes';

interface LeaderProps {
    prize: PrizeStanding;
    managers: Map<number, PrizeManager>;
    players: Record<string, PrizePlayer>;
    viewer: number | null;
}

const SLOT =
    'flex min-w-0 flex-col justify-center gap-[7px] bg-hq-lime/[0.06] px-3.5 py-2.5 md:border-x md:border-hq-border';

function You() {
    return (
        <span className="ml-1 bg-hq-paper px-[3px] py-[2px] align-middle font-mono text-[10px] leading-none font-bold tracking-[0.08em] text-hq-ink">
            TÚ
        </span>
    );
}

function BigValue({ prize, row }: { prize: PrizeStanding; row: PrizeRowData }) {
    const { big, unit } = leaderValue(prize, row);

    return (
        <span className="inline-flex items-baseline gap-[5px] whitespace-nowrap">
            <HqLed tone="lime" className="text-[28px]">
                {big}
            </HqLed>
            {unit && <small className="font-mono text-[11px] text-hq-moss">{unit}</small>}
        </span>
    );
}

/** The leader's own context, one prize at a time (spec "Qué enseña cada premio"). */
function LeaderContext({ prize, row, managers, players, max }: LeaderProps & { row: PrizeRowData; max: number }) {
    const context = row.context;
    const mono = 'font-mono text-[11px] text-hq-moss-dim';

    switch (prize.key) {
        case 'noche_magica':
            return <JornadaBadges weeks={[context.week_number ?? 0]} max={1} />;
        case 'rey_del_domingo':
            return <JornadaBadges weeks={context.weeks ?? []} max={max} />;
        case 'el_pupas':
            return <JornadaBadges weeks={context.weeks ?? []} max={max} tone="neg" />;
        case 'el_atracador':
        case 'la_victima': {
            const other = prize.key === 'el_atracador' ? context.favourite : context.nemesis;
            const manager = other ? managers.get(other.season_manager_id) : undefined;

            return manager && other ? (
                <span className={mono}>
                    {prize.key === 'el_atracador' ? 'Su víctima favorita: ' : 'Su verdugo: '}
                    <b className="font-semibold text-hq-paper">
                        {shortManagerName(manager.name)} ×{other.count}
                    </b>
                </span>
            ) : null;
        }
        case 'el_criminal': {
            const player = context.worst ? players[context.worst.player_id] : undefined;

            return player && context.worst ? (
                <span className={mono}>
                    El peor:{' '}
                    <b className="font-semibold text-hq-paper">
                        {player.nickname}, +{millions(context.worst.overpaid)} M€
                    </b>
                </span>
            ) : null;
        }
        case 'matrimonio':
            return (
                <span className="inline-flex items-center gap-2">
                    <JornadaSpan from={context.from_week ?? 0} to={context.to_week ?? 0} />
                    {context.alive && (
                        <span className="inline-flex items-center gap-[5px] font-mono text-[10.5px] font-semibold tracking-[0.06em] text-hq-lime uppercase">
                            <i className="block size-[6px] bg-hq-lime" aria-hidden="true" />
                            Sigue
                        </span>
                    )}
                </span>
            );
        default:
            return null;
    }
}

function rowOf(prize: PrizeStanding, id: number): PrizeRowData {
    return prize.rows.find((row) => row.season_manager_id === id) as PrizeRowData;
}

/** «Nadie todavía», one leader, or tied leaders side by side sharing the slot (no «Empate» seal). */
export function PrizeLeader({ prize, managers, players, viewer }: LeaderProps) {
    if (!prize.decided) {
        return <div className={cn(SLOT, 'bg-transparent font-mono text-xs text-hq-moss-dim')}>Sin categoría todavía</div>;
    }

    if (prize.key === 'fichaje_del_pueblo') {
        return <PuebloLeader prize={prize} managers={managers} players={players} viewer={viewer} />;
    }

    if (prize.leaders.length === 0) {
        return <div className={cn(SLOT, 'font-mono text-xs text-hq-moss-dim')}>Nadie todavía</div>;
    }

    if (prize.leaders.length > 1) {
        const first = rowOf(prize, prize.leaders[0]);
        const withContext = prize.leaders.length <= 3;

        return (
            <div className={SLOT}>
                <BigValue prize={prize} row={first} />
                <div className="grid" style={{ gridTemplateColumns: `repeat(${prize.leaders.length}, minmax(0, 1fr))` }}>
                    {prize.leaders.map((id) => {
                        const manager = managers.get(id);
                        const row = rowOf(prize, id);
                        const player = row.context.player_id ? players[row.context.player_id] : undefined;

                        return manager ? (
                            <div key={id} className="flex min-w-0 flex-col items-center gap-1 px-1 text-center [&+&]:shadow-[inset_1px_0_0_var(--color-hq-border-strong)]">
                                <span className="flex">
                                    <ManagerCrest manager={manager} className="size-7" />
                                    {prize.key === 'matrimonio' && player && <PlayerPhoto player={player} className="-ml-2 size-7" />}
                                </span>
                                <span
                                    title={manager.name}
                                    className={cn(
                                        'max-w-full truncate font-sans text-xs font-extrabold',
                                        id === viewer && 'underline underline-offset-[3px]',
                                    )}
                                >
                                    {shortManagerName(manager.name)}
                                </span>
                                {withContext && prize.key === 'matrimonio' && player && (
                                    <span className="max-w-full truncate font-mono text-[10.5px] text-hq-moss">{player.nickname}</span>
                                )}
                                {withContext && (
                                    <LeaderContext prize={prize} row={row} managers={managers} players={players} viewer={viewer} max={5} />
                                )}
                            </div>
                        ) : null;
                    })}
                </div>
            </div>
        );
    }

    const id = prize.leaders[0];
    const manager = managers.get(id);
    const row = rowOf(prize, id);
    const player = row.context.player_id ? players[row.context.player_id] : undefined;

    if (!manager) {
        return null;
    }

    return (
        <div className={cn(SLOT, 'max-sm:grid max-sm:grid-cols-[minmax(0,1fr)_auto] max-sm:items-center max-sm:gap-x-2')}>
            <div className="flex min-w-0 items-center gap-[9px]">
                <span className="flex shrink-0">
                    <ManagerCrest manager={manager} className="size-9" />
                    {prize.key === 'matrimonio' && player && <PlayerPhoto player={player} className="-ml-2 size-9" />}
                </span>
                <span className="min-w-0">
                    <span className="block truncate font-sans text-[13.5px] font-extrabold">
                        {manager.name}
                        {id === viewer && <You />}
                    </span>
                    {prize.key === 'matrimonio' && player && (
                        <span className="block truncate font-mono text-[11px] text-hq-moss">{player.nickname}</span>
                    )}
                </span>
            </div>
            <BigValue prize={prize} row={row} />
            <div className="min-w-0 max-sm:col-span-2">
                <LeaderContext prize={prize} row={row} managers={managers} players={players} viewer={viewer} max={12} />
            </div>
        </div>
    );
}

/** Fichaje del Pueblo: only the player and his winning manager, owners count as the value; tied players side by side. */
function PuebloLeader({ prize, managers, players, viewer }: LeaderProps) {
    const candidates = prize.candidates.filter((candidate) => candidate.winners.length > 0);

    if (candidates.length === 0) {
        return <div className={cn(SLOT, 'font-mono text-xs text-hq-moss-dim')}>Nadie todavía</div>;
    }

    const owners = (
        <span className="inline-flex items-baseline gap-[5px] whitespace-nowrap">
            <HqLed tone="lime" className="text-[28px]">
                {candidates[0].owners.length}
            </HqLed>
            <small className="font-mono text-[11px] text-hq-moss">dueños</small>
        </span>
    );

    const winner = (id: number, withLabel: boolean) => {
        const manager = managers.get(id);

        return manager ? (
            <span className="inline-flex max-w-full min-w-0 items-center gap-[5px] font-mono text-[11px] text-hq-moss">
                {withLabel && 'Se lo lleva'}
                <ManagerCrest manager={manager} className="size-[14px]" />
                <b className="truncate font-bold text-hq-lime">{shortManagerName(manager.name)}</b>
                {id === viewer && <You />}
            </span>
        ) : null;
    };

    if (candidates.length > 1) {
        return (
            <div className={SLOT}>
                {owners}
                <div className="grid" style={{ gridTemplateColumns: `repeat(${candidates.length}, minmax(0, 1fr))` }}>
                    {candidates.map((candidate) => {
                        const player = players[candidate.player_id];

                        return (
                            <div key={candidate.player_id} className="flex min-w-0 flex-col items-center gap-1 px-1 text-center [&+&]:shadow-[inset_1px_0_0_var(--color-hq-border-strong)]">
                                {player && <PlayerPhoto player={player} className="size-7" />}
                                <span className="max-w-full truncate font-sans text-xs font-extrabold" title={player?.nickname}>
                                    {player?.nickname}
                                </span>
                                {winner(candidate.winners[0], false)}
                            </div>
                        );
                    })}
                </div>
            </div>
        );
    }

    const candidate = candidates[0];
    const player = players[candidate.player_id];

    return (
        <div className={cn(SLOT, 'max-sm:grid max-sm:grid-cols-[minmax(0,1fr)_auto] max-sm:items-center max-sm:gap-x-2')}>
            <div className="flex min-w-0 items-center gap-[9px]">
                {player && <PlayerPhoto player={player} className="size-9" />}
                <span className="min-w-0">
                    <span className="block truncate font-sans text-[13.5px] font-extrabold">{player?.nickname}</span>
                    {winner(candidate.winners[0], true)}
                </span>
            </div>
            {owners}
        </div>
    );
}
```

- [ ] **Step 2: Write the rest list and the row**

`resources/js/components/prizes/prize-rest-list.tsx`:

```tsx
import { HqLed } from '@/components/hq-led';
import { ManagerCrest } from '@/components/prizes/prize-crest';
import { restValue, shortManagerName } from '@/lib/prize-format';
import { cn } from '@/lib/utils';
import type { PrizeManager, PrizePlayer, PrizeStanding } from '@/types/prizes';

/** The other six (everyone but the leaders), two columns of three; a single column on mid widths. */
export function PrizeRestList({
    prize,
    managers,
    players,
    viewer,
}: {
    prize: PrizeStanding;
    managers: Map<number, PrizeManager>;
    players: Record<string, PrizePlayer>;
    viewer: number | null;
}) {
    if (!prize.decided) {
        return (
            <p className="flex items-center px-3.5 py-2 font-mono text-xs text-hq-moss-dim">
                Cuando se apruebe, sale aquí con los 7 ordenados.
            </p>
        );
    }

    const tiedPlayers = prize.key === 'fichaje_del_pueblo' && prize.candidates.length > 1;
    const rows = prize.rows.filter((row) => !prize.leaders.includes(row.season_manager_id));

    return (
        <ol className="grid min-w-0 grid-flow-col grid-cols-2 grid-rows-3 content-center gap-x-4 gap-y-0.5 px-3.5 py-2 max-md:grid-flow-row max-md:grid-cols-1 max-md:grid-rows-none max-sm:grid-flow-col max-sm:grid-cols-2 max-sm:grid-rows-3 max-sm:gap-x-3">
            {rows.map((row) => {
                const manager = managers.get(row.season_manager_id);
                const value = restValue(prize, row);
                const player = row.context.player_id ? players[row.context.player_id] : undefined;
                const sub = prize.key === 'matrimonio' || tiedPlayers ? player?.nickname : undefined;
                const isViewer = row.season_manager_id === viewer;

                return manager ? (
                    <li
                        key={row.season_manager_id}
                        className={cn(
                            'grid min-h-[26px] min-w-0 grid-cols-[14px_20px_minmax(0,1fr)_auto] items-center gap-[7px]',
                            isViewer && '-ml-[5px] pl-[3px] shadow-[inset_2px_0_0_var(--color-hq-paper)]',
                        )}
                    >
                        <HqLed tone="off" className="text-right text-xs">
                            {row.place ?? '–'}
                        </HqLed>
                        <ManagerCrest manager={manager} className={cn('size-5', row.value === null && 'opacity-45')} />
                        <span
                            title={manager.name}
                            className={cn(
                                'min-w-0 truncate font-mono text-xs text-hq-moss',
                                isViewer && 'font-bold text-hq-paper',
                                row.value === null && 'text-hq-led-off',
                            )}
                        >
                            {shortManagerName(manager.name)}
                            {sub && <small className="block truncate text-[10.5px] text-hq-moss-dim">{sub}</small>}
                        </span>
                        <span
                            className={cn(
                                'font-mono text-xs font-semibold whitespace-nowrap text-hq-paper tabular-nums',
                                value.muted && 'text-hq-led-off',
                            )}
                        >
                            {value.text}
                        </span>
                    </li>
                ) : null;
            })}
        </ol>
    );
}
```

`resources/js/components/prizes/prize-row.tsx`:

```tsx
import type { KeyboardEvent } from 'react';
import { PrizeLeader } from '@/components/prizes/prize-leader';
import { PrizeRestList } from '@/components/prizes/prize-rest-list';
import { cn } from '@/lib/utils';
import type { PrizeManager, PrizePlayer, PrizeStanding, SeasonPrizeKey } from '@/types/prizes';

/** One prize: name, € and rule | the leader | the other six. The whole row opens the detail. */
export function PrizeRow({
    prize,
    managers,
    players,
    viewer,
    onOpen,
}: {
    prize: PrizeStanding;
    managers: Map<number, PrizeManager>;
    players: Record<string, PrizePlayer>;
    viewer: number | null;
    onOpen: (key: SeasonPrizeKey) => void;
}) {
    const open = () => onOpen(prize.key);
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            open();
        }
    };

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={open}
            onKeyDown={onKeyDown}
            aria-label={`${prize.name}, ${prize.amount} euros. Ver clasificación`}
            className="group grid cursor-pointer grid-cols-[minmax(0,1fr)_280px_minmax(0,1.3fr)] border-b border-hq-border transition-colors hover:bg-hq-panel max-md:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] max-sm:grid-cols-1"
        >
            <div className="min-w-0 px-4 py-3 max-md:col-span-2 max-md:px-3.5 max-md:pb-2 max-sm:col-span-1">
                <div className="flex flex-wrap items-baseline gap-2">
                    <h4
                        className={cn(
                            'font-display leading-none text-hq-paper uppercase group-hover:text-hq-lime',
                            prize.amount === 10 ? 'text-[19px] max-sm:text-base' : 'text-[17px] max-sm:text-base',
                        )}
                    >
                        {prize.name}
                    </h4>
                    <span
                        className={cn(
                            'font-mono text-[11.5px] leading-none font-bold tabular-nums',
                            prize.amount === 10 ? 'text-hq-gold' : 'text-hq-khaki',
                        )}
                    >
                        {prize.amount} €
                    </span>
                </div>
                <p className="mt-1.5 text-xs leading-snug text-hq-moss">{prize.rule}</p>
            </div>
            <div className="min-w-0 max-md:border-t max-md:border-hq-border [&>*]:h-full">
                <PrizeLeader prize={prize} managers={managers} players={players} viewer={viewer} />
            </div>
            <div className="min-w-0 max-md:border-t max-md:border-hq-border">
                <PrizeRestList prize={prize} managers={managers} players={players} viewer={viewer} />
            </div>
        </div>
    );
}
```

- [ ] **Step 3: Write the page**

`resources/js/pages/prizes/index.tsx`:

```tsx
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useMemo, useState } from 'react';
import { HqPageHeader } from '@/components/hq-page-header';
import { ManagerCrest } from '@/components/prizes/prize-crest';
import { PrizeDetailDialog } from '@/components/prizes/prize-detail-dialog';
import { PrizeRow } from '@/components/prizes/prize-row';
import { shortManagerName } from '@/lib/prize-format';
import { togglePrizeViewer, usePrizeViewer } from '@/lib/prize-viewer';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import type { PrizeManager, PrizesPageProps, SeasonPrizeKey } from '@/types/prizes';

function Tier({ amount, title, summary }: { amount: number; title: string; summary: string }) {
    return (
        <div className="flex items-baseline justify-between gap-2.5 border-b border-hq-border bg-hq-well px-4 py-[9px] max-sm:px-3.5">
            <h3 className="font-mono text-[11px] leading-none font-bold tracking-[0.09em] text-hq-paper uppercase">
                <span className={cn('mr-2', amount === 10 ? 'text-hq-gold' : 'text-hq-khaki')}>{amount} €</span>
                {title}
            </h3>
            <span className="font-mono text-[11px] tracking-[0.06em] text-hq-moss-dim uppercase">{summary}</span>
        </div>
    );
}

export default function PrizesIndex({ lastFinishedWeek, managers, prizes, players, benchMisses }: PrizesPageProps) {
    const viewer = usePrizeViewer();
    const [openKey, setOpenKey] = useState<SeasonPrizeKey | null>(null);
    const managersById = useMemo(() => new Map<number, PrizeManager>(managers.map((manager) => [manager.id, manager])), [managers]);
    const openPrize = prizes.find((prize) => prize.key === openKey) ?? null;
    const rowProps = { managers: managersById, players, viewer, onOpen: setOpenKey };

    return (
        <>
            <Head title="Premios" />
            <Link
                href={home()}
                className="mx-4 mt-3 inline-flex min-h-8 cursor-pointer items-center gap-1.5 font-mono text-[11px] font-semibold tracking-[0.08em] text-hq-moss uppercase hover:text-hq-lime max-sm:mx-3.5"
            >
                <ArrowLeft className="size-3.5" aria-hidden="true" />
                Clasificación
            </Link>
            <HqPageHeader
                code="BOTE DE SANCIONES"
                title="Premios"
                lede={`El campeón no se lleva nada: esto va de otra cosa. Clasificación tras la J${lastFinishedWeek}; se reparten al acabar la temporada.`}
                meta={[
                    { label: 'Bote', value: <span className="text-hq-gold">70 €</span> },
                    { label: 'Premios', value: '10' },
                    { label: 'Jornada', value: `${lastFinishedWeek}/38` },
                ]}
            />
            <div role="group" aria-label="Resaltar mánager" className="hq-no-scrollbar flex items-center gap-2 overflow-x-auto border-b border-hq-border px-4 py-2 max-sm:px-3.5">
                <span className="shrink-0 font-mono text-[11px] tracking-[0.07em] text-hq-moss-dim uppercase">Soy</span>
                {managers.map((manager) => {
                    const pressed = manager.id === viewer;

                    return (
                        <button
                            key={manager.id}
                            type="button"
                            aria-pressed={pressed}
                            title={pressed ? 'Pulsa otra vez para quitar' : undefined}
                            onClick={() => togglePrizeViewer(manager.id)}
                            className={cn(
                                'inline-flex min-h-8 shrink-0 cursor-pointer items-center gap-1.5 border py-0 pr-2.5 pl-1 font-mono text-[11.5px] font-semibold whitespace-nowrap',
                                pressed
                                    ? 'border-hq-paper bg-hq-panel-alt text-hq-paper'
                                    : 'border-hq-border text-hq-moss hover:border-hq-border-bright hover:text-hq-paper',
                            )}
                        >
                            <ManagerCrest manager={manager} className="size-6" />
                            {shortManagerName(manager.name)}
                        </button>
                    );
                })}
            </div>
            <Tier amount={10} title="Premios grandes" summary="4 · 40 €" />
            {prizes.filter((prize) => prize.amount === 10).map((prize) => (
                <PrizeRow key={prize.key} prize={prize} {...rowProps} />
            ))}
            <Tier amount={5} title="Premios pequeños" summary="6 · 30 €" />
            {prizes.filter((prize) => prize.amount === 5).map((prize) => (
                <PrizeRow key={prize.key} prize={prize} {...rowProps} />
            ))}
            <PrizeDetailDialog
                prize={openPrize}
                managers={managersById}
                players={players}
                benchMisses={benchMisses}
                viewer={viewer}
                onClose={() => setOpenKey(null)}
            />
        </>
    );
}
```

Y en `resources/js/components/hq-page-header.tsx`, una prop opcional para la línea bajo el título. Las demás páginas no cambian:

```tsx
// in HqPageHeaderProps
    /** One line of text under the title (e.g. the prizes page's summary). */
    lede?: ReactNode;

// in the component, right after the <h1>…</h1>
                {lede && (
                    <p className="mt-2 max-w-[56ch] text-[13px] leading-snug text-hq-moss">
                        {lede}
                    </p>
                )}
```

(añade `lede` a la desestructuración de props).

- [ ] **Step 4: Type-check, lint (the dialog comes in task 13)**

`PrizeDetailDialog` todavía no existe. Para que `types:check` pase, crea en este paso un stub en `resources/js/components/prizes/prize-detail-dialog.tsx` que la tarea 13 sustituye:

```tsx
import type { BenchMiss, PrizeManager, PrizePlayer, PrizeStanding } from '@/types/prizes';

export function PrizeDetailDialog(props: {
    prize: PrizeStanding | null;
    managers: Map<number, PrizeManager>;
    players: Record<string, PrizePlayer>;
    benchMisses: Record<string, BenchMiss>;
    viewer: number | null;
    onClose: () => void;
}) {
    void props;

    return null;
}
```

Run: `npm run types:check`, `npm run lint:check` y `npm run build`
Expected: sin errores.

- [ ] **Step 5: Look at it (no dev server)**

`npm run build` ya generó los assets. Abre `http://comando-lechuga.test/premios` en una pestaña propia y compárala con `public/_premios-d.html` (datos «Hoy») a escritorio y a 390 px. Comprueba:
- las filas, el primero, las etiquetas con «+N», los empates lado a lado sin sello y el Fichaje del Pueblo solo con jugador + ganador;
- «Soy»: marcar y desmarcar, también con teclado;
- `cursor-pointer` en filas y botones;
- que no hay scroll horizontal a 390 px.

Cierra la pestaña al acabar. **No arranques `npm run dev`.**

- [ ] **Step 6: Commit**

```bash
npx prettier --write resources/js/components/prizes resources/js/pages/prizes
git add resources/js/components/prizes resources/js/pages/prizes resources/js/components/hq-page-header.tsx
git commit -m "feat: render the prizes page"
```

---

### Task 13: Detalle del premio (centrado), pasaporte y modal del Banquillo

**Files:**
- Modify: `resources/js/components/prizes/prize-detail-dialog.tsx` (sustituye el stub)
- Create: `resources/js/components/prizes/prize-passport.tsx`

**Interfaces:**
- Consumes: `HqPlayerStatsModal`, `HqPlayerStatsEntry` (`@/components/hq-player-stats-modal`); tipos de la tarea 11; `restValue`, `millions`, `shortManagerName`.
- Produces: `<PrizeDetailDialog prize managers players benchMisses viewer onClose />`.

- [ ] **Step 1: Write the passport**

`resources/js/components/prizes/prize-passport.tsx`:

```tsx
import { ManagerCrest, PlayerPhoto } from '@/components/prizes/prize-crest';
import { cn } from '@/lib/utils';
import type { PrizeManager, PrizePlayer, PuebloCandidate } from '@/types/prizes';

const TILT = [-4, 3, -2, 4, -3, 2, -1];

function jornadas(count: number): string {
    return `${count} ${count === 1 ? 'jornada' : 'jornadas'}`;
}

/** One stamp per distinct owner, in order, ×N for repeat spells, jornadas held under it; the winner in lime. */
export function PrizePassport({
    candidate,
    player,
    managers,
}: {
    candidate: PuebloCandidate;
    player: PrizePlayer | undefined;
    managers: Map<number, PrizeManager>;
}) {
    const winner = managers.get(candidate.winners[0]);

    return (
        <div className="min-w-0 bg-hq-panel px-4 py-3">
            <div className="flex items-center gap-[9px]">
                {player && <PlayerPhoto player={player} className="size-[30px]" />}
                <span>
                    <b className="font-sans text-[13.5px] font-extrabold">{player?.nickname}</b>
                    <small className="mt-[3px] block font-mono text-[11px] text-hq-moss">
                        {candidate.owners.length} dueños · {candidate.transfers} traspasos
                        {candidate.on_market && ' · hoy en el mercado'}
                    </small>
                </span>
            </div>
            <div className="mt-3 flex flex-wrap gap-x-2 gap-y-2.5">
                {candidate.owners.map((id, index) => {
                    const manager = managers.get(id);
                    const spells = candidate.chain.filter((owner) => owner === id).length;
                    const isWinner = candidate.winners.includes(id);
                    const held = candidate.weeks_held[id] ?? 0;

                    return manager ? (
                        <span key={id} className="flex flex-col items-center gap-[5px]" title={manager.name}>
                            <span
                                className={cn(
                                    'relative grid place-items-center p-1',
                                    isWinner ? 'border border-hq-lime bg-hq-lime/12' : 'border border-dashed border-hq-border-bright',
                                )}
                                style={{ transform: `rotate(${TILT[index % TILT.length]}deg)` }}
                            >
                                <ManagerCrest manager={manager} className="size-[22px] border-0 bg-transparent" />
                                {spells > 1 && (
                                    <sup className="absolute -top-[7px] -right-[7px] bg-hq-paper px-[2px] py-px font-mono text-[9px] leading-none font-bold text-hq-ink">
                                        ×{spells}
                                    </sup>
                                )}
                            </span>
                            <small className={cn('font-mono text-[10px] whitespace-nowrap', isWinner ? 'text-hq-lime' : 'text-hq-moss-dim')}>
                                {held} jor.
                            </small>
                        </span>
                    ) : null;
                })}
            </div>
            {winner && (
                <p className="mt-3 font-mono text-[11.5px] leading-relaxed text-hq-moss">
                    Se lo lleva{' '}
                    <ManagerCrest manager={winner} className="mx-1 inline-block size-4 align-[-3px]" />
                    <b className="font-bold text-hq-lime">{winner.name}</b>, el que más tiempo lo tuvo (
                    {jornadas(candidate.weeks_held[winner.id] ?? 0)}).
                </p>
            )}
        </div>
    );
}
```

- [ ] **Step 2: Write the dialog**

`resources/js/components/prizes/prize-detail-dialog.tsx`: sigue el patrón de foco de `HqScoringLegendDialog` (lee ese archivo: `getFocusableElements`, Tab atrapado, Esc, devolver el foco al elemento que lo abrió). La diferencia es que este diálogo va **centrado siempre**, también en móvil.

```tsx
import { X } from 'lucide-react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { useEffect, useId, useRef, useState } from 'react';
import { HqLed } from '@/components/hq-led';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import type { HqPlayerStatsEntry } from '@/components/hq-player-stats-modal';
import { ManagerCrest } from '@/components/prizes/prize-crest';
import { PrizePassport } from '@/components/prizes/prize-passport';
import { millions, restValue, shortManagerName } from '@/lib/prize-format';
import { cn } from '@/lib/utils';
import type { BenchMiss, PrizeManager, PrizePlayer, PrizeRowData, PrizeStanding } from '@/types/prizes';

function getFocusableElements(container: HTMLElement): HTMLElement[] {
    return Array.from(
        container.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'),
    ).filter((element) => element.offsetParent !== null);
}

function jList(weeks: number[] | undefined): string {
    return (weeks ?? []).map((week) => `J${week}`).join(' · ');
}

/** Everyone's own context line in the detail (the rows only show the leader's). */
function detailLine(prize: PrizeStanding, row: PrizeRowData, managers: Map<number, PrizeManager>, players: Record<string, PrizePlayer>): string {
    const context = row.context;
    const name = (id: number | undefined) => (id ? shortManagerName(managers.get(id)?.name ?? '') : '');

    switch (prize.key) {
        case 'noche_magica':
            return context.week_number ? `en la J${context.week_number}` : '';
        case 'rey_del_domingo':
        case 'el_pupas':
            return jList(context.weeks);
        case 'el_atracador':
            return context.favourite ? `Más a ${name(context.favourite.season_manager_id)} (×${context.favourite.count})` : '';
        case 'la_victima':
            return context.nemesis ? `Su verdugo: ${name(context.nemesis.season_manager_id)} (×${context.nemesis.count})` : '';
        case 'el_criminal':
            return context.worst ? `El peor: ${players[context.worst.player_id]?.nickname ?? ''}, +${millions(context.worst.overpaid)} M€` : '';
        case 'matrimonio':
            return context.player_id
                ? `${players[context.player_id]?.nickname ?? ''} · J${context.from_week}–J${context.to_week} · ${context.alive ? 'sigue' : 'racha rota'}`
                : '';
        case 'fichaje_del_pueblo':
            return context.player_id ? (players[context.player_id]?.nickname ?? '') : 'No lo tuvo';
        default:
            return '';
    }
}

export function PrizeDetailDialog({
    prize,
    managers,
    players,
    benchMisses,
    viewer,
    onClose,
}: {
    prize: PrizeStanding | null;
    managers: Map<number, PrizeManager>;
    players: Record<string, PrizePlayer>;
    benchMisses: Record<string, BenchMiss>;
    viewer: number | null;
    onClose: () => void;
}) {
    const titleId = useId();
    const dialogRef = useRef<HTMLDivElement>(null);
    const [statsEntry, setStatsEntry] = useState<HqPlayerStatsEntry | null>(null);
    const isOpen = prize !== null;

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const dialog = dialogRef.current;

        if (dialog) {
            getFocusableElements(dialog)[0]?.focus();
        }

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = previousOverflow;
            opener?.focus();
        };
    }, [isOpen]);

    if (!prize) {
        return null;
    }

    const onKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
        if (event.key === 'Escape' && statsEntry === null) {
            event.stopPropagation();
            onClose();

            return;
        }

        if (event.key !== 'Tab' || !dialogRef.current) {
            return;
        }

        const focusable = getFocusableElements(dialogRef.current);
        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    };

    const notes: string[] = [];

    if (prize.key !== 'fichaje_del_pueblo' && prize.leaders.length > 1) {
        notes.push('Empate arriba: hoy se repartiría a partes iguales.');
    }

    if (prize.key === 'fichaje_del_pueblo') {
        prize.candidates.forEach((candidate) => {
            const order = candidate.chain.map((id) => shortManagerName(managers.get(id)?.name ?? '')).join(' › ');
            notes.push(`Orden de ${players[candidate.player_id]?.nickname ?? ''}: ${order}${candidate.on_market ? ' › mercado' : ''}.`);
        });
    }

    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/65 p-3" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                onKeyDown={onKeyDown}
                className="max-h-[calc(100vh-40px)] w-[min(520px,calc(100vw-24px))] overflow-y-auto border border-hq-border-bright bg-hq-panel text-hq-paper"
            >
                <div className="flex items-start gap-3 border-b border-hq-border-strong px-4 py-3.5">
                    <div className="min-w-0">
                        <span className={cn('font-mono text-[11.5px] font-bold', prize.amount === 10 ? 'text-hq-gold' : 'text-hq-khaki')}>
                            {prize.amount} €
                        </span>
                        <h3 id={titleId} className="mt-1.5 font-display text-[22px] leading-none uppercase">
                            {prize.name}
                        </h3>
                        <p className="mt-1.5 text-[12.5px] leading-snug text-hq-moss">{prize.rule}</p>
                    </div>
                    <button
                        type="button"
                        aria-label="Cerrar"
                        onClick={onClose}
                        className="ml-auto grid size-10 shrink-0 cursor-pointer place-items-center border border-hq-border-strong text-hq-moss hover:border-hq-paper hover:text-hq-paper"
                    >
                        <X className="size-[18px]" aria-hidden="true" />
                    </button>
                </div>

                {prize.key === 'fichaje_del_pueblo' && prize.candidates.length > 0 && (
                    <div className={cn('grid gap-px border-b border-hq-border bg-hq-border', prize.candidates.length > 1 && 'sm:grid-cols-2')}>
                        {prize.candidates.map((candidate) => (
                            <PrizePassport key={candidate.player_id} candidate={candidate} player={players[candidate.player_id]} managers={managers} />
                        ))}
                    </div>
                )}

                {!prize.decided ? (
                    <p className="px-4 py-4 font-mono text-xs text-hq-moss">
                        Premio de 5 € sin categoría. Cuando la propongáis y se apruebe, se calcula como los demás.
                    </p>
                ) : (
                    <ol className="py-1.5">
                        {prize.rows.map((row) => {
                            const manager = managers.get(row.season_manager_id);
                            const isLeader = prize.leaders.includes(row.season_manager_id);
                            const miss = row.context.top_miss ? benchMisses[row.context.top_miss.fixture_lineup_id] : undefined;
                            const line = detailLine(prize, row, managers, players);

                            return manager ? (
                                <li
                                    key={row.season_manager_id}
                                    className={cn(
                                        'grid min-h-10 grid-cols-[18px_22px_minmax(0,1fr)_auto] items-center gap-[9px] px-4 py-[5px] [&+&]:border-t [&+&]:border-hq-border',
                                        isLeader && 'bg-hq-lime/[0.07]',
                                        row.season_manager_id === viewer && 'shadow-[inset_2px_0_0_var(--color-hq-paper)]',
                                    )}
                                >
                                    <HqLed tone={isLeader ? 'lime' : 'off'} className="text-right text-[15px]">
                                        {row.place ?? '–'}
                                    </HqLed>
                                    <ManagerCrest manager={manager} className="size-[22px]" />
                                    <span className="min-w-0 font-mono text-[12.5px] font-semibold">
                                        {manager.name}
                                        {line && <small className="mt-[3px] block text-[11px] font-normal text-hq-moss">{line}</small>}
                                        {miss && row.context.top_miss && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setStatsEntry({
                                                        player: miss.player,
                                                        team: miss.team,
                                                        points: miss.points,
                                                        stats: miss.stats,
                                                        dazn: miss,
                                                        fixture: miss.fixture,
                                                    })
                                                }
                                                className="mt-[3px] block cursor-pointer text-left text-[11px] font-normal text-hq-moss underline decoration-hq-border-bright underline-offset-2 hover:text-hq-lime"
                                            >
                                                El que más dejó: {miss.player.nickname} · J{miss.week_number} · {miss.points} pts
                                            </button>
                                        )}
                                    </span>
                                    <span className={cn('font-mono text-[13px] font-semibold whitespace-nowrap tabular-nums', isLeader && 'text-hq-lime')}>
                                        {restValue(prize, row).text}
                                    </span>
                                </li>
                            ) : null;
                        })}
                    </ol>
                )}

                {notes.length > 0 && (
                    <p className="border-t border-hq-border px-4 pt-2.5 pb-3.5 font-mono text-[11.5px] leading-relaxed text-hq-moss">
                        {notes.map((note) => (
                            <span key={note} className="block">
                                {note}
                            </span>
                        ))}
                    </p>
                )}
            </div>
            <HqPlayerStatsModal entry={statsEntry} onClose={() => setStatsEntry(null)} />
        </div>
    );
}
```

- [ ] **Step 3: Type-check, lint, build**

Run: `npm run types:check`, `npm run lint:check` y `npm run build`
Expected: sin errores. Si `HqPlayerStatsEntry['dazn']` no acepta `BenchMiss` entero, pasa solo los campos de `DaznFields` (`dazn_points`, `dazn_estimate`, `dazn_estimate_version`, `dazn_estimate_reasons`, `dazn_estimate_source`).

- [ ] **Step 4: Look at it**

En una pestaña propia, abre `/premios`, a escritorio y a 390 px, y comprueba:
- el detalle sale centrado;
- se cierra con Esc, con el fondo y con la «×», y devuelve el foco a la fila;
- el Fichaje del Pueblo enseña el pasaporte (dos, uno al lado del otro, si hay empate de jugador);
- en el Banquillo, «El que más dejó» abre `HqPlayerStatsModal` con el equipo del partido, y Esc cierra primero el modal y después el detalle.

Cierra la pestaña.

- [ ] **Step 5: Commit**

```bash
npx prettier --write resources/js/components/prizes
git add resources/js/components/prizes
git commit -m "feat: add the prize detail dialog with the passport"
```

---

### Task 14: El enlace de Inicio

**Files:**
- Create: `resources/js/pages/home/prizes-link.tsx`
- Modify: `resources/js/pages/home/standings-table.tsx` (renderiza `<PrizesLink />` justo antes de `</HqSection>`, línea ~146)
- Test: `tests/Feature/Http/Controllers/HomeControllerTest.php` (sin cambios de props: el enlace es estático; no hace falta test PHP nuevo)

**Interfaces:**
- Consumes: `@/routes/prizes` (`index`), que Wayfinder genera tras la tarea 10 al hacer `npm run build`.

- [ ] **Step 1: Write the link**

`resources/js/pages/home/prizes-link.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { ArrowRight, Trophy } from 'lucide-react';
import { index as prizesIndex } from '@/routes/prizes';

/** The only way into /premios: a wide plain link under the home standings (no menu entry, no teaser). */
export function PrizesLink() {
    return (
        <Link
            href={prizesIndex()}
            className="group grid w-full cursor-pointer grid-cols-[auto_1fr_auto] items-center gap-3 border-t border-hq-border-strong bg-hq-panel py-2.5 pr-4 pl-[18px] transition-colors hover:bg-hq-panel-alt max-sm:gap-2.5 max-sm:px-3.5"
        >
            <span className="grid size-[34px] place-items-center border border-hq-gold/55 text-hq-gold">
                <Trophy className="size-[18px]" aria-hidden="true" />
            </span>
            <span className="min-w-0">
                <span className="block font-display text-sm leading-none text-hq-paper uppercase">Premios de fin de temporada</span>
                <span className="mt-1 block font-mono text-[11.5px] text-hq-moss">
                    <b className="font-bold text-hq-gold">70 €</b> en 10 premios · el campeón no cobra
                </span>
            </span>
            <span className="inline-flex min-h-8 items-center gap-2 border border-hq-border-bright px-3 font-mono text-[11px] font-bold tracking-[0.08em] text-hq-paper uppercase transition-colors group-hover:border-hq-lime group-hover:bg-hq-lime group-hover:text-hq-ink max-sm:w-8 max-sm:justify-center max-sm:border-0 max-sm:px-0 max-sm:group-hover:bg-transparent max-sm:group-hover:text-hq-lime">
                <span className="max-sm:hidden">Ver premios</span>
                <ArrowRight className="size-3.5" aria-hidden="true" />
            </span>
        </Link>
    );
}
```

- [ ] **Step 2: Mount it under the standings**

En `resources/js/pages/home/standings-table.tsx`, importa `import { PrizesLink } from '@/pages/home/prizes-link';` y añade `<PrizesLink />` como último hijo del `HqSection`, justo antes de `</HqSection>` (tras las vistas de escritorio y móvil de la tabla). Si en el proyecto las importaciones entre archivos de una página son relativas (mira `resources/js/pages/home.tsx`), usa `./prizes-link`.

- [ ] **Step 3: Type-check, lint, build, look**

Run: `npm run types:check`, `npm run lint:check` y `npm run build`
Expected: sin errores. En una pestaña propia, abre `/` a escritorio y a 390 px:
- la franja sale bajo la tabla;
- a 390 px el botón es solo la flecha;
- el hover y `cursor-pointer` funcionan;
- lleva a `/premios`.

Cierra la pestaña.

- [ ] **Step 4: Run the home tests**

Run: `php artisan test --compact tests/Feature/Http/Controllers/HomeControllerTest.php`
Expected: PASS (sin cambios de backend).

- [ ] **Step 5: Commit**

```bash
npx prettier --write resources/js/pages/home
git add resources/js/pages/home/prizes-link.tsx resources/js/pages/home/standings-table.tsx
git commit -m "feat: link the prizes page from the home standings"
```

---

### Task 15: Verificación final

**Files:** ninguno nuevo.

- [ ] **Step 1: Backend**

Run:
- `vendor/bin/pint --dirty --format agent`
- `composer phpstan`
- `php artisan test --compact tests/Unit/Enums/SeasonPrizeTest.php tests/Unit/Services/PrizeRankingTest.php tests/Feature/Services/Prizes tests/Feature/Services/SeasonPrizeStandingsTest.php tests/Feature/Http/Controllers/PrizesControllerTest.php tests/Feature/Http/Controllers/HomeControllerTest.php`

Expected: todo en verde.

- [ ] **Step 2: Frontend**

Run: `npm run types:check`, `npm run lint:check`, `npm run format:check` y `npm run build`
Expected: sin errores.

- [ ] **Step 3: Real data check**

Con la base de datos local, abre `/premios`, en una pestaña propia, y contrasta los líderes con `premios/PLAN.md` §4 («Líder local (J7)»):
- Noche Mágica: Gauchitos 71 (J5);
- Atracador: DukeBlack9 22;
- Rey del Domingo: CID 3;
- Criminal: DukeBlack9;
- Víctima: DukeBlack9 18;
- Pupas: empate DukeBlack9 / planuky;
- Matrimonio: empate Ariobretxa (Remiro) / planuky (Jorge Salinas);
- Pueblo: empate de jugador Vlachodimos / Agirrezabala.

Las diferencias que salen de las decisiones (jornadas en vez de días en el Pueblo, jornada en juego excluida en el Matrimonio) son esperadas: anótalas para el usuario. Revisa también que no hay nada roto a 390 px. Cierra la pestaña.

- [ ] **Step 4: Ask the user to run the full suite**

Pide al usuario que ejecute `php artisan test --compact` completo. **No mergear** sin su aprobación.
