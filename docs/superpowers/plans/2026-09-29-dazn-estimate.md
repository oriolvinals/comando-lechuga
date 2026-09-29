# Plan de implementación: DAZN estimado v1

> **Para agentes:** SUB-SKILL OBLIGATORIA: usa superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans para implementar este plan tarea a tarea. Los pasos usan casillas (`- [ ]`) para el seguimiento.

**Objetivo:** estimar en vivo la nota DAZN (0–4) de cada jugador con el baremo v1, congelarla cuando llegue la oficial y mostrarla en la web y en la API.

**Arquitectura:**
- Un servicio puro, `DaznEstimator`, calcula la nota a partir de una `FixtureLineup` y su `Fixture`.
- El trait de sync la guarda (`dazn_estimate`, `dazn_estimate_version`, `dazn_estimate_meta`) y la congela marcando `fixtures.dazn_published`.
- Un presentador puro, `DaznEstimatePresenter`, aplica la regla de visibilidad para la web y la API.
- Un componente React, `HqDaznBadge`, pinta la nota provisional o la oficial.

**Stack:** Laravel 13 / PHP 8.5, Pest, Inertia v3 + React + TypeScript, Tailwind.

**Spec:** `docs/superpowers/specs/2026-09-29-dazn-estimate-design.md`. Léela antes de cada tarea; manda sobre este plan si hay contradicción.

## Restricciones globales

- **Versión:** la de la app es `'v1'`. En la investigación se llama v2.5; no uses ese nombre en el código.
- **Columnas string que no son enum:** NOT NULL con default `''` (AGENTS.md).
- **PHP:** `declare(strict_types=1)`, tipos de retorno explícitos, llaves siempre y PHPDoc con array shapes. Tras tocar PHP, ejecuta `vendor/bin/pint --dirty --format agent`.
- **Tests:** Pest. Crea los archivos con `php artisan make:test --pest {Name}` (añade `--unit` si es unitario). Ejecuta los tests más acotados posibles con `php artisan test --compact <ruta o --filter>`.
- **Límites:**
  - Nunca cambies los puntos Fantasy.
  - El KPI "Media DAZN" solo cuenta notas oficiales.
  - Solo se muestra la estimación si el partido ha terminado o si el jugador lleva 15 minutos o más.
- **Textos de UI** en castellano, tal cual la spec, §6.
- **Frontend:** `npm run types:check`, `npm run lint:check` y `npm run format:check` limpios. No arranques `npm run dev`.
- **Commits:** uno por tarea, en la rama `feature/dazn-estimate`, terminados con `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Nunca merge a main.

## Review Focus

1. **Partido sin marcador (null) antes del inicio:** la portería a cero y la victoria deben tratar null como 0 sin petar. Test en Task 2.
2. **Suplente que no ha jugado (0 minutos) en un partido terminado:** nunca debe mostrar un DAZN 0 estimado. Tests en Task 2 (estimador → null) y Task 4.
3. **Nota oficial que llega con valor 0 en algunos jugadores y mayor que 0 en otros:** el partido entero pasa a oficial y ninguna estimación se recalcula después. Test en Task 3.
4. **Partido terminado sin nota oficial todavía:** se ven las estimaciones aunque el jugador lleve menos de 15 minutos, y `dazn_points` sigue en null. Test en Task 4.
5. **Fila de worldcup26 con stats sin claves o con valores no numéricos, y `fantasy_stats` con claves que faltan:** cuentan como 0. Test en Task 2.

---

### Task 1: Esquema (migraciones, modelos, factories)

**Archivos:**
- Crear: `database/migrations/2026_09_29_120000_add_dazn_estimate_to_fixture_lineups_table.php`
- Crear: `database/migrations/2026_09_29_120100_add_dazn_published_to_fixtures_table.php`
- Modificar: `app/Models/FixtureLineup.php` (docblock, `#[Fillable]`, `$attributes`, casts)
- Modificar: `app/Models/Fixture.php` (docblock, `#[Fillable]`, casts)
- Modificar: `database/factories/FixtureLineupFactory.php` y `database/factories/FixtureFactory.php`
- Test: `tests/Feature/Models/FixtureLineupTest.php` (existe) y `tests/Feature/Models/FixtureTest.php` (créalo si no existe)

**Interfaces que produce:**
- `FixtureLineup`:
  - `dazn_estimate` (`?int`);
  - `dazn_estimate_version` (`string`, default `''`);
  - `dazn_estimate_meta` (`?array{source: string, minutes: int, reasons: list<string>}`).
- `Fixture`: `dazn_published` (`bool`, default `false`).
- Factories:
  - `FixtureLineupFactory::withDaznEstimate(int $points = 2, int $minutes = 90, string $source = 'fantasy', array $reasons = ['90 minutos jugados'])`;
  - `FixtureFactory::daznPublished()`.

- [ ] **Paso 1: tests que fallan.** Añade a `tests/Feature/Models/FixtureLineupTest.php`:

```php
test('casts the DAZN estimate columns', function (): void {
    $lineup = FixtureLineup::factory()->withDaznEstimate(points: 3, minutes: 67, source: 'worldcup26', reasons: ['67 minutos jugados', '1 gol'])->create()->fresh();

    expect($lineup->dazn_estimate)->toBe(3)
        ->and($lineup->dazn_estimate_version)->toBe('v1')
        ->and($lineup->dazn_estimate_meta)->toBe(['source' => 'worldcup26', 'minutes' => 67, 'reasons' => ['67 minutos jugados', '1 gol']]);
});

test('defaults to no DAZN estimate', function (): void {
    $lineup = FixtureLineup::factory()->create()->fresh();

    expect($lineup->dazn_estimate)->toBeNull()
        ->and($lineup->dazn_estimate_version)->toBe('')
        ->and($lineup->dazn_estimate_meta)->toBeNull();
});
```

Y a `tests/Feature/Models/FixtureTest.php` (con `use App\Models\Fixture;`):

```php
test('dazn_published defaults to false and casts to bool', function (): void {
    expect(Fixture::factory()->create()->fresh()->dazn_published)->toBeFalse()
        ->and(Fixture::factory()->daznPublished()->create()->fresh()->dazn_published)->toBeTrue();
});
```

- [ ] **Paso 2:** ejecuta `php artisan test --compact tests/Feature/Models/FixtureLineupTest.php tests/Feature/Models/FixtureTest.php`. Deben FALLAR, porque la columna o el método de la factory no existen.

- [ ] **Paso 3: migraciones.** Créalas con `php artisan make:migration add_dazn_estimate_to_fixture_lineups_table --no-interaction` y renómbralas a los nombres de arriba si hace falta. Contenido:

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->unsignedTinyInteger('dazn_estimate')->nullable()->after('fantasy_stats');
            $table->string('dazn_estimate_version')->default('')->after('dazn_estimate');
            $table->json('dazn_estimate_meta')->nullable()->after('dazn_estimate_version');
        });
    }

    public function down(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->dropColumn(['dazn_estimate', 'dazn_estimate_version', 'dazn_estimate_meta']);
        });
    }
};
```

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixtures', function (Blueprint $table): void {
            $table->boolean('dazn_published')->default(false)->after('display_clock');
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table): void {
            $table->dropColumn('dazn_published');
        });
    }
};
```

Usa la misma cabecera que las migraciones existentes: `declare(strict_types=1);` y los `use` de Migration, Blueprint y Schema.

- [ ] **Paso 4: modelos.**
  - **`FixtureLineup`:**
    - añade al docblock `@property-read int|null $dazn_estimate`, `@property-read string $dazn_estimate_version` y `@property-read array{source: string, minutes: int, reasons: list<string>}|null $dazn_estimate_meta`;
    - añade las tres columnas a `#[Fillable([...])]`;
    - en `$attributes`, añade `'dazn_estimate_version' => ''`;
    - casts: `'dazn_estimate' => 'int'`, `'dazn_estimate_version' => 'string'`, `'dazn_estimate_meta' => 'array'`.
  - **`Fixture`:**
    - añade al docblock `@property-read bool $dazn_published`;
    - añádelo a `#[Fillable]`;
    - cast `'dazn_published' => 'bool'`;
    - en `$attributes` (créalo si no existe), `'dazn_published' => false`.

- [ ] **Paso 5: factories.** En `FixtureLineupFactory`:

```php
/**
 * @param  list<string>  $reasons
 */
public function withDaznEstimate(int $points = 2, int $minutes = 90, string $source = 'fantasy', array $reasons = ['90 minutos jugados']): static
{
    return $this->state(fn (): array => [
        'dazn_estimate' => $points,
        'dazn_estimate_version' => 'v1',
        'dazn_estimate_meta' => ['source' => $source, 'minutes' => $minutes, 'reasons' => $reasons],
    ]);
}
```

En `FixtureFactory`:

```php
public function daznPublished(): static
{
    return $this->state(fn (): array => ['dazn_published' => true]);
}
```

- [ ] **Paso 6:** repite el Paso 2. Ahora debe PASAR. Ejecuta también `php artisan test --compact tests/Feature/Models` para confirmar que nada más se rompe.
- [ ] **Paso 7:** ejecuta `vendor/bin/pint --dirty --format agent` y haz commit: `feat: add DAZN estimate columns to fixture lineups and fixtures`.

---

### Task 2: `DaznEstimator` (baremo v1)

**Archivos:**
- Crear: `app/Services/DaznEstimate.php`
- Crear: `app/Services/DaznEstimator.php`
- Ya existe: `tests/Fixtures/dazn/estimate-v1-golden.json`, con 165 actuaciones reales de J1–J7 y su nota esperada en cada escenario. Viene de la prueba ciega; no se modifica.
- Test: `tests/Feature/Services/DaznEstimatorTest.php`

**Interfaces que consume:** las columnas de la Task 1 y `App\Enums\PlayerPosition`.

**Interfaces que produce:**
- `final readonly class DaznEstimate { public int $points; public float $raw; public string $source; public int $minutes; /** @var list<string> */ public array $reasons; }`, con `source` igual a `'fantasy'` o `'worldcup26'`, y el método `toMeta(): array{source: string, minutes: int, reasons: list<string>}`.
- `final class DaznEstimator`:
  - `public const string VERSION = 'v1';`
  - `public function estimate(FixtureLineup $lineup, Fixture $fixture, ?PlayerPosition $position): ?DaznEstimate`.
  - Devuelve `null` si la posición es nula o de entrenador, o si el jugador lleva 0 minutos.

- [ ] **Paso 1: tests que fallan.** Crea `tests/Feature/Services/DaznEstimatorTest.php`:

```php
<?php

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Services\DaznEstimator;

/**
 * An unsaved lineup + fixture pair for the estimator, which is pure and never queries.
 *
 * @param  array<string, mixed>  $lineup
 * @param  array<string, mixed>  $fixture
 * @return array{0: FixtureLineup, 1: Fixture}
 */
function daznCase(array $lineup = [], array $fixture = []): array
{
    $fixtureModel = new Fixture([
        'team_local_id' => 1,
        'team_guest_id' => 2,
        'local_score' => 0,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
        'display_clock' => null,
        ...$fixture,
    ]);

    $lineupModel = new FixtureLineup([
        'team_id' => 1,
        'starter' => true,
        'subbed_in' => false,
        'subbed_out' => false,
        'sub_minute' => null,
        'stats' => [],
        'fantasy_stats' => null,
        ...$lineup,
    ]);

    return [$lineupModel, $fixtureModel];
}

test('reproduces the blind-tested v1 ratings on real J1–J7 performances', function (array $row): void {
    $position = PlayerPosition::from($row['position']);
    $fixture = ['local_score' => $row['team_goals'], 'guest_score' => $row['rival_goals']];
    $lineup = [
        'starter' => $row['starter'],
        'subbed_in' => $row['subbed_in'],
        'subbed_out' => $row['subbed_out'],
        'sub_minute' => $row['sub_minute'],
        'stats' => $row['stats'],
    ];
    $estimator = new DaznEstimator;

    [$withFantasy, $fixtureModel] = daznCase([...$lineup, 'fantasy_stats' => $row['fantasy_stats']], $fixture);
    [$worldcup26Only] = daznCase($lineup, $fixture);

    expect($estimator->estimate($withFantasy, $fixtureModel, $position)?->points)->toBe($row['expected_fantasy'])
        ->and($estimator->estimate($worldcup26Only, $fixtureModel, $position)?->points)->toBe($row['expected_worldcup26']);
})->with(fn (): array => array_map(
    fn (array $row): array => [$row],
    json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/dazn/estimate-v1-golden.json'), true),
));

test('uses Fantasy stats when present and worldcup26 as the fallback', function (): void {
    [$lineup, $fixture] = daznCase(['fantasy_stats' => ['mins_played' => [90, 2]]]);
    [$fallback] = daznCase();

    expect((new DaznEstimator)->estimate($lineup, $fixture, PlayerPosition::Midfield)?->source)->toBe('fantasy')
        ->and((new DaznEstimator)->estimate($fallback, $fixture, PlayerPosition::Midfield)?->source)->toBe('worldcup26');
});

test('returns no estimate for a coach, an unknown position, or zero minutes', function (): void {
    [$lineup, $fixture] = daznCase(['fantasy_stats' => ['mins_played' => [90, 2]]]);
    [$unusedSub] = daznCase(['starter' => false, 'fantasy_stats' => ['mins_played' => [0, 0]]]);
    [$unusedSubFallback] = daznCase(['starter' => false]);

    $estimator = new DaznEstimator;

    expect($estimator->estimate($lineup, $fixture, PlayerPosition::Coach))->toBeNull()
        ->and($estimator->estimate($lineup, $fixture, null))->toBeNull()
        ->and($estimator->estimate($unusedSub, $fixture, PlayerPosition::Defender))->toBeNull()
        ->and($estimator->estimate($unusedSubFallback, $fixture, PlayerPosition::Defender))->toBeNull();
});

test('derives worldcup26 minutes from the lineup and the match clock', function (array $lineup, array $fixture, int $minutes): void {
    [$lineupModel, $fixtureModel] = daznCase($lineup, $fixture);

    expect((new DaznEstimator)->estimate($lineupModel, $fixtureModel, PlayerPosition::Midfield)?->minutes)->toBe($minutes);
})->with([
    'starter, finished' => [[], ['state' => FixtureState::Finished], 90],
    'starter, half time' => [[], ['state' => FixtureState::HalfTime], 45],
    'starter, second half with stoppage' => [[], ['state' => FixtureState::SecondHalf, 'display_clock' => "67'"], 67],
    'starter, first-half stoppage' => [[], ['state' => FixtureState::FirstHalf, 'display_clock' => "45'+2'"], 45],
    'starter subbed out' => [['subbed_out' => true, 'sub_minute' => 58], ['state' => FixtureState::Finished], 58],
    'sub came on' => [['starter' => false, 'subbed_in' => true, 'sub_minute' => 70], ['state' => FixtureState::SecondHalf, 'display_clock' => "81'"], 11],
]);

test('treats a null score as 0-0 and missing or non-numeric stats as 0', function (): void {
    [$lineup, $fixture] = daznCase(
        ['stats' => [['name' => 'totalGoals', 'value' => 'n/a'], ['value' => 3], ['name' => 'foulsCommitted']]],
        ['local_score' => null, 'guest_score' => null, 'state' => FixtureState::Finished],
    );

    // Defender, 90', worldcup26 only: base 0,80 + 1,25 + 0,10 (≥30') + clean sheet 0,20 = 2,35 → 3.
    expect((new DaznEstimator)->estimate($lineup, $fixture, PlayerPosition::Defender)?->points)->toBe(3);
});

test('a raw value equal to a threshold counts in the upper band', function (): void {
    // Midfielder, 90', Fantasy: base 0,80 + 0,50 + 1 shot × 0,20 = 1,50 exactly → 2.
    [$lineup, $fixture] = daznCase(['fantasy_stats' => ['mins_played' => [90, 2], 'total_scoring_att' => [1, 0]]]);

    $estimate = (new DaznEstimator)->estimate($lineup, $fixture, PlayerPosition::Midfield);

    expect($estimate?->raw)->toBe(1.5)
        ->and($estimate?->points)->toBe(2);
});

test('explains the estimate with minutes first and up to three actions by impact', function (): void {
    [$lineup, $fixture] = daznCase(
        ['fantasy_stats' => ['mins_played' => [90, 2], 'goals' => [1, 5], 'total_scoring_att' => [3, 0], 'yellow_card' => [1, -1], 'ball_recovery' => [2, 0]], 'stats' => [['name' => 'foulsCommitted', 'value' => 2]]],
        ['local_score' => 1, 'guest_score' => 0],
    );

    expect((new DaznEstimator)->estimate($lineup, $fixture, PlayerPosition::Striker)?->reasons)
        ->toBe(['90 minutos jugados', '1 gol', '3 tiros', '1 amarilla']);
});

test('says there were no goals, assists nor cards when no action counts', function (): void {
    [$lineup, $fixture] = daznCase(['fantasy_stats' => ['mins_played' => [20, 1]]], ['local_score' => 0, 'guest_score' => 1]);

    expect((new DaznEstimator)->estimate($lineup, $fixture, PlayerPosition::Striker)?->reasons)
        ->toBe(['20 minutos jugados', 'Sin goles, asistencias ni tarjetas']);
});
```

Comprobación del caso de los motivos: la aportación al raw del delantero es gol +1,0, 3 tiros +0,6, amarilla −0,4, 2 faltas −0,3 y 2 recuperaciones +0,3. Ordenadas por valor absoluto quedan gol, tiros y amarilla.

- [ ] **Paso 2:** ejecuta `php artisan test --compact tests/Feature/Services/DaznEstimatorTest.php`. Debe FALLAR porque la clase no existe.

- [ ] **Paso 3: implementación.** Crea `app/Services/DaznEstimate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One player's estimated DAZN rating for one match, with what it was built from.
 */
final readonly class DaznEstimate
{
    /**
     * @param  'fantasy'|'worldcup26'  $source
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $points,
        public float $raw,
        public string $source,
        public int $minutes,
        public array $reasons,
    ) {}

    /**
     * @return array{source: string, minutes: int, reasons: list<string>}
     */
    public function toMeta(): array
    {
        return ['source' => $this->source, 'minutes' => $this->minutes, 'reasons' => $this->reasons];
    }
}
```

Crea `app/Services/DaznEstimator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\FixtureLineup;

/**
 * Estimates a player's DAZN rating (LaLiga Fantasy `marca_points`, 0–4) for one
 * match from the stats synced so far — baremo v1 (research "v2.5"). Fantasy's
 * per-match stats plus worldcup26 fouls when Fantasy has sent the player's stats;
 * worldcup26 alone otherwise. Pure: never queries, the caller passes the position.
 */
final class DaznEstimator
{
    public const string VERSION = 'v1';

    /** @var list<float> */
    private const array THRESHOLDS = [0.65, 1.5, 2.25, 3.0];

    /** @var list<array{0: float, 1: float}> Base + slope × m, by position index (GK, DF, MF, FW). */
    private const array FANTASY_BASE = [[0.25, 1.20], [0.80, 0.55], [0.80, 0.50], [0.75, 0.20]];

    /** @var list<array{0: float, 1: float}> */
    private const array WORLDCUP26_BASE = [[0.05, 1.75], [0.80, 1.25], [0.85, 1.05], [0.85, 0.70]];

    public function estimate(FixtureLineup $lineup, Fixture $fixture, ?PlayerPosition $position): ?DaznEstimate
    {
        $positionIndex = match ($position) {
            PlayerPosition::Goalkeeper => 0,
            PlayerPosition::Defender => 1,
            PlayerPosition::Midfield => 2,
            PlayerPosition::Striker => 3,
            default => null,
        };

        if ($positionIndex === null) {
            return null;
        }

        $fantasyStats = $lineup->fantasy_stats;
        $source = is_array($fantasyStats) ? 'fantasy' : 'worldcup26';
        $minutes = is_array($fantasyStats)
            ? (int) $this->fantasyValue($fantasyStats, 'mins_played')
            : $this->derivedMinutes($lineup, $fixture);

        if ($minutes <= 0) {
            return null;
        }

        $isLocal = $lineup->team_id === $fixture->team_local_id;
        $teamGoals = (int) ($isLocal ? $fixture->local_score : $fixture->guest_score);
        $rivalGoals = (int) ($isLocal ? $fixture->guest_score : $fixture->local_score);
        $won = $teamGoals > $rivalGoals;
        $cleanSheet = $rivalGoals === 0;
        $worldcup26 = $this->worldcup26Values($lineup->stats);

        [$base, $slope] = ($source === 'fantasy' ? self::FANTASY_BASE : self::WORLDCUP26_BASE)[$positionIndex];
        $raw = $base + $slope * min($minutes / 90, 1.0);

        if ($source === 'worldcup26' && $positionIndex > 0 && $minutes >= 30) {
            $raw += 0.10;
        }

        $terms = is_array($fantasyStats)
            ? $this->fantasyTerms($fantasyStats, $worldcup26, $won, $cleanSheet)
            : $this->worldcup26Terms($worldcup26, $won, $cleanSheet);

        $contributions = [];

        foreach ($terms as $term) {
            $contribution = $term['value'] * $term['weights'][$positionIndex];

            if ($contribution === 0.0) {
                continue;
            }

            $raw += $contribution;
            $contributions[] = ['reason' => $this->reasonLabel($term['label'], $term['value']), 'impact' => abs($contribution)];
        }

        $raw = round($raw, 4);

        return new DaznEstimate(
            points: $this->points($raw),
            raw: $raw,
            source: $source,
            minutes: $minutes,
            reasons: $this->reasons($minutes, $contributions),
        );
    }

    /**
     * @param  array<string, mixed>  $fantasyStats
     * @param  array<string, float>  $worldcup26
     * @return list<array{label: array{0: string, 1: string}|string, value: float, weights: list<float>}>
     */
    private function fantasyTerms(array $fantasyStats, array $worldcup26, bool $won, bool $cleanSheet): array
    {
        $value = fn (string $key): float => $this->fantasyValue($fantasyStats, $key);

        return [
            ['label' => ['gol', 'goles'], 'value' => $value('goals'), 'weights' => [0, 1.5, 1.2, 1.0]],
            ['label' => ['asistencia', 'asistencias'], 'value' => $value('goal_assist'), 'weights' => [1.5, 1.0, 1.0, 1.0]],
            ['label' => ['pase clave', 'pases clave'], 'value' => $value('offtarget_att_assist'), 'weights' => [0.6, 0.5, 0.4, 0.4]],
            ['label' => ['tiro', 'tiros'], 'value' => $value('total_scoring_att'), 'weights' => [0, 0.2, 0.2, 0.2]],
            ['label' => ['entrada al área', 'entradas al área'], 'value' => $value('pen_area_entries'), 'weights' => [0, 0.2, 0.2, 0.2]],
            ['label' => ['regate', 'regates'], 'value' => $value('won_contest'), 'weights' => [0, 0.15, 0.15, 0.15]],
            ['label' => ['recuperación', 'recuperaciones'], 'value' => $value('ball_recovery'), 'weights' => [0.05, 0.1, 0.1, 0.15]],
            ['label' => ['despeje', 'despejes'], 'value' => $value('effective_clearance'), 'weights' => [0.05, 0.08, 0.05, 0.08]],
            ['label' => ['parada', 'paradas'], 'value' => $value('saves'), 'weights' => [0.2, 0, 0, 0]],
            ['label' => ['penalti parado', 'penaltis parados'], 'value' => $value('penalty_save'), 'weights' => [0.9, 0, 0, 0]],
            ['label' => 'Victoria', 'value' => $won ? 1.0 : 0.0, 'weights' => [0.1, 0, 0, 0]],
            ['label' => 'Portería a cero', 'value' => $cleanSheet ? 1.0 : 0.0, 'weights' => [0.25, 0.25, 0, 0]],
            ['label' => ['falta', 'faltas'], 'value' => $worldcup26['foulsCommitted'] ?? 0.0, 'weights' => [-0.3, -0.15, -0.15, -0.15]],
            ['label' => ['amarilla', 'amarillas'], 'value' => $value('yellow_card'), 'weights' => [0, -0.4, -0.4, -0.4]],
            ['label' => ['roja', 'rojas'], 'value' => $value('red_card') + $value('second_yellow_card'), 'weights' => [0, -0.5, -0.5, -0.5]],
            ['label' => ['penalti fallado', 'penaltis fallados'], 'value' => $value('penalty_failed'), 'weights' => [0, -0.7, -0.7, -0.7]],
            ['label' => ['gol encajado', 'goles encajados'], 'value' => $value('goals_conceded'), 'weights' => [-0.5, -0.45, -0.3, -0.2]],
        ];
    }

    /**
     * @param  array<string, float>  $worldcup26
     * @return list<array{label: array{0: string, 1: string}|string, value: float, weights: list<float>}>
     */
    private function worldcup26Terms(array $worldcup26, bool $won, bool $cleanSheet): array
    {
        $value = fn (string $key): float => $worldcup26[$key] ?? 0.0;

        return [
            ['label' => ['gol', 'goles'], 'value' => $value('totalGoals'), 'weights' => [0, 1.5, 1.2, 1.0]],
            ['label' => ['asistencia', 'asistencias'], 'value' => $value('goalAssists'), 'weights' => [2.0, 1.5, 1.5, 1.3]],
            ['label' => ['tiro a puerta', 'tiros a puerta'], 'value' => $value('shotsOnTarget'), 'weights' => [0, 0.2, 0.2, 0.2]],
            ['label' => ['tiro fuera', 'tiros fuera'], 'value' => max($value('totalShots') - $value('shotsOnTarget'), 0.0), 'weights' => [0, 0.07, 0.07, 0.07]],
            ['label' => ['falta recibida', 'faltas recibidas'], 'value' => $value('foulsSuffered'), 'weights' => [0, 0.05, 0.05, 0.05]],
            ['label' => ['parada', 'paradas'], 'value' => $value('saves'), 'weights' => [0.3, 0, 0, 0]],
            ['label' => 'Victoria', 'value' => $won ? 1.0 : 0.0, 'weights' => [0.2, 0.2, 0, 0]],
            ['label' => 'Portería a cero', 'value' => $cleanSheet ? 1.0 : 0.0, 'weights' => [0.2, 0.2, 0, 0]],
            ['label' => ['falta', 'faltas'], 'value' => $value('foulsCommitted'), 'weights' => [-0.25, -0.15, -0.15, -0.15]],
            ['label' => ['fuera de juego', 'fueras de juego'], 'value' => $value('offsides'), 'weights' => [0, -0.15, -0.15, -0.15]],
            ['label' => ['amarilla', 'amarillas'], 'value' => $value('yellowCards'), 'weights' => [0, -0.35, -0.35, -0.35]],
            ['label' => ['roja', 'rojas'], 'value' => $value('redCards'), 'weights' => [0, -0.6, -0.6, -0.6]],
            ['label' => ['gol encajado', 'goles encajados'], 'value' => $value('goalsConceded'), 'weights' => [-0.55, -0.45, -0.3, -0.2]],
        ];
    }

    /**
     * The raw value of a Fantasy `[value, points]` stat pair; 0 when missing or non-numeric.
     *
     * @param  array<string, mixed>  $fantasyStats
     */
    private function fantasyValue(array $fantasyStats, string $key): float
    {
        $pair = $fantasyStats[$key] ?? null;

        return is_array($pair) && isset($pair[0]) && is_numeric($pair[0]) ? (float) $pair[0] : 0.0;
    }

    /**
     * worldcup26 roster stats (`[{name, value}]`) keyed by name; non-numeric values are dropped.
     *
     * @param  array<int, mixed>  $stats
     * @return array<string, float>
     */
    private function worldcup26Values(array $stats): array
    {
        $values = [];

        foreach ($stats as $stat) {
            if (is_array($stat) && isset($stat['name']) && is_numeric($stat['value'] ?? null)) {
                $values[(string) $stat['name']] = (float) $stat['value'];
            }
        }

        return $values;
    }

    /**
     * Minutes played so far from the lineup and the match clock, for the worldcup26-only fallback.
     */
    private function derivedMinutes(FixtureLineup $lineup, Fixture $fixture): int
    {
        $currentMinute = match ($fixture->state) {
            FixtureState::Finished => 90,
            FixtureState::HalfTime => 45,
            FixtureState::FirstHalf, FixtureState::SecondHalf => preg_match('/^(\d+)/', (string) $fixture->display_clock, $matches) === 1 ? (int) $matches[1] : 0,
            default => 0,
        };

        return match (true) {
            $lineup->starter && $lineup->subbed_out => (int) $lineup->sub_minute,
            $lineup->starter => $currentMinute,
            $lineup->subbed_in => max($currentMinute - (int) $lineup->sub_minute, 0),
            default => 0,
        };
    }

    private function points(float $raw): int
    {
        $points = 0;

        foreach (self::THRESHOLDS as $threshold) {
            if ($raw < $threshold) {
                break;
            }

            $points++;
        }

        return $points;
    }

    /**
     * @param  array{0: string, 1: string}|string  $label  A fixed text, or [singular, plural] prefixed by the count.
     */
    private function reasonLabel(array|string $label, float $value): string
    {
        if (is_string($label)) {
            return $label;
        }

        $count = (int) round($value);

        return $count === 1 ? "1 {$label[0]}" : "{$count} {$label[1]}";
    }

    /**
     * Minutes first, then the three actions that moved the rating most.
     *
     * @param  list<array{reason: string, impact: float}>  $contributions
     * @return list<string>
     */
    private function reasons(int $minutes, array $contributions): array
    {
        $minutesReason = $minutes === 1 ? '1 minuto jugado' : "{$minutes} minutos jugados";

        if ($contributions === []) {
            return [$minutesReason, 'Sin goles, asistencias ni tarjetas'];
        }

        usort($contributions, fn (array $a, array $b): int => $b['impact'] <=> $a['impact']);

        return [$minutesReason, ...array_map(fn (array $c): string => $c['reason'], array_slice($contributions, 0, 3))];
    }
}
```

Nota: `usort` es estable en PHP 8+, así que a igual impacto se mantiene el orden de la tabla.

- [ ] **Paso 4:** repite el Paso 2. Debe PASAR, con las 165 filas doradas incluidas. Si falla alguna fila dorada, revisa pesos y bases contra la spec §2; **no cambies el JSON**.
- [ ] **Paso 5:** ejecuta `vendor/bin/pint --dirty --format agent` y haz commit: `feat: add the DAZN rating estimator (baremo v1)`. Incluye `tests/Fixtures/dazn/estimate-v1-golden.json` en el commit.

---

### Task 3: Recalcular y congelar en el sync

**Archivos:**
- Modificar: `app/Console/Commands/Concerns/SyncsMatchData.php`. Llama al paso nuevo justo después de `$this->fillFantasyScores(...)` en `syncMatchDataForFixtures()` y añade el método privado `refreshDaznEstimates()`.
- Test: `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`. Reutiliza `liveMatchEventPayload()` y el patrón del test "fills fantasy_points and fantasy_stats…" (~L951).

**Interfaces que consume:** `DaznEstimator::estimate()`, `DaznEstimator::VERSION`, `DaznEstimate::toMeta()`, `Fixture::$dazn_published` y `PlayerSeason` (`player_id`, `season_id`, `position`).

- [ ] **Paso 1: tests que fallan.** Añade tres tests al archivo, copiando el montaje del test de ~L951: temporada, equipos con `wc26_id` 83/86, partido `week_number` 3 que empezó hace 30 minutos, jugador con `fantasy_id` 2759 y sus mocks de `GetEventRequest` y `GetPlayerRequest`. Además:
  - crea `PlayerSeason::factory()->create(['player_id' => $player->id, 'season_id' => $season->id, 'position' => PlayerPosition::Goalkeeper])`;
  - mockea en el payload el marcador que necesite cada caso (`liveMatchEventPayload` acepta overrides; el marcador va en `header.competitions.0.competitors[*].score`, así que mira el helper).

```php
test('estimates the DAZN rating of every resolved player while no official rating exists', function (): void {
    // montaje de ~L951, con Fantasy devolviendo ['mins_played' => [90, 2], 'saves' => [3, 1]] para la jornada 3 y el marcador 1-0 para el local
    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $lineup = FixtureLineup::query()->where('player_id', $player->id)->sole();
    // Portero, 90', 3 paradas, victoria, a cero: 0,25 + 1,20 + 0,60 + 0,10 + 0,25 = 2,40 → 3
    expect($lineup->dazn_estimate)->toBe(3)
        ->and($lineup->dazn_estimate_version)->toBe('v1')
        ->and($lineup->dazn_estimate_meta['source'])->toBe('fantasy')
        ->and($lineup->dazn_estimate_meta['minutes'])->toBe(90)
        ->and($fixture->fresh()->dazn_published)->toBeFalse();
});

test('freezes the whole fixture once any official DAZN rating arrives', function (): void {
    // mismo montaje; la fila ya tiene una estimación previa y Fantasy devuelve ahora 'marca_points' => [-1, 3]
    FixtureLineup::factory()->withDaznEstimate(points: 1, minutes: 80)->create([
        'fixture_id' => $fixture->id, 'player_id' => $player->id, 'team_id' => $home->id, 'wc26_id' => 5001,
    ]);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $lineup = FixtureLineup::query()->where('player_id', $player->id)->sole();
    expect($fixture->fresh()->dazn_published)->toBeTrue()
        ->and($lineup->dazn_estimate)->toBe(1)
        ->and($lineup->dazn_estimate_meta['minutes'])->toBe(80);
});

test('uses the worldcup26 fallback when Fantasy fails for a player', function (): void {
    // mismo montaje, pero GetPlayerRequest devuelve MockResponse::make([], 500), y el roster del payload trae 'stats' => [['name' => 'saves', 'value' => 2]]
    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $lineup = FixtureLineup::query()->where('player_id', $player->id)->sole();
    expect($lineup->fantasy_stats)->toBeNull()
        ->and($lineup->dazn_estimate_meta['source'])->toBe('worldcup26')
        ->and($lineup->dazn_estimate)->not->toBeNull();
});
```

Escribe el montaje completo en cada test, siguiendo el estilo del archivo; no dejes los comentarios de montaje como placeholder. En el tercer test el partido va en vivo (`STATUS_SECOND_HALF` o el que use `liveMatchEventPayload`) con reloj, así que los minutos derivados son mayores que 0. Comprueba en el helper qué `displayClock` pone y ajústalo para que haya minutos.

- [ ] **Paso 2:** ejecuta `php artisan test --compact tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php --filter=DAZN`. Debe FALLAR.

- [ ] **Paso 3: implementación.** En `syncMatchDataForFixtures()`, después de `$this->fillFantasyScores($fixture, $fantasyConnector, $fantasyPlayerCache);`:

```php
            $this->refreshDaznEstimates($fixture);
```

Y el método nuevo (añade `use App\Models\PlayerSeason;` y `use App\Services\DaznEstimator;`):

```php
    /**
     * Re-estimates every resolved player's DAZN rating until LaLiga Fantasy
     * publishes the official ones. The first official rating (> 0) on any
     * player flips `dazn_published` and freezes the whole fixture: the last
     * estimates stay as they were, for the "Comando Lechuga estimó" comparison.
     */
    private function refreshDaznEstimates(Fixture $fixture): void
    {
        if ($fixture->dazn_published) {
            return;
        }

        $lineups = FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->whereNotNull('player_id')
            ->get();

        $hasOfficialRating = $lineups->contains(
            fn (FixtureLineup $lineup): bool => (int) ($lineup->fantasy_stats['marca_points'][1] ?? 0) > 0,
        );

        if ($hasOfficialRating) {
            $fixture->update(['dazn_published' => true]);

            return;
        }

        $positionsByPlayer = PlayerSeason::query()
            ->where('season_id', $fixture->season_id)
            ->whereIn('player_id', $lineups->pluck('player_id'))
            ->get()
            ->mapWithKeys(fn (PlayerSeason $playerSeason): array => [$playerSeason->player_id => $playerSeason->position]);

        $estimator = app(DaznEstimator::class);

        foreach ($lineups as $lineup) {
            $estimate = $estimator->estimate($lineup, $fixture, $positionsByPlayer->get($lineup->player_id));

            $lineup->update([
                'dazn_estimate' => $estimate?->points,
                'dazn_estimate_version' => $estimate === null ? '' : DaznEstimator::VERSION,
                'dazn_estimate_meta' => $estimate?->toMeta(),
            ]);
        }
    }
```

- [ ] **Paso 4:** repite el Paso 2. Debe PASAR. Después ejecuta el archivo completo y los otros dos tests de sync (`SyncCurrentSeasonMatchDataTest.php` y `SyncSeasonMatchDataBackfillTest.php`) para confirmar que nada más se rompe.
- [ ] **Paso 5:** ejecuta `vendor/bin/pint --dirty --format agent` y haz commit: `feat: estimate and freeze DAZN ratings during the live sync`.

---

### Task 4: `DaznEstimatePresenter` (regla de visibilidad)

**Archivos:**
- Crear: `app/Services/DaznEstimatePresenter.php`
- Test: `tests/Unit/Services/DaznEstimatePresenterTest.php`, unitario, con modelos sin guardar.

**Interfaces que produce:**

```php
DaznEstimatePresenter::present(FixtureLineup $lineup, Fixture $fixture): array
// array{dazn_points: int|null, dazn_estimate: int|null, dazn_estimate_version: string, dazn_estimate_reasons: list<string>, dazn_estimate_source: string|null}
DaznEstimatePresenter::MIN_VISIBLE_MINUTES = 15
```

- [ ] **Paso 1: tests que fallan.**

```php
<?php

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Services\DaznEstimatePresenter;

/**
 * @param  array<string, mixed>  $lineup
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function presentDazn(array $lineup = [], array $fixture = []): array
{
    return DaznEstimatePresenter::present(
        new FixtureLineup(['fantasy_stats' => null, 'dazn_estimate' => null, 'dazn_estimate_version' => '', 'dazn_estimate_meta' => null, ...$lineup]),
        new Fixture(['state' => FixtureState::SecondHalf, 'dazn_published' => false, ...$fixture]),
    );
}

$estimated = fn (int $minutes): array => ['dazn_estimate' => 2, 'dazn_estimate_version' => 'v1', 'dazn_estimate_meta' => ['source' => 'fantasy', 'minutes' => $minutes, 'reasons' => ["{$minutes} minutos jugados"]]];

test('shows a provisional estimate once the player has 15 minutes', function () use ($estimated): void {
    expect(presentDazn($estimated(15)))->toBe([
        'dazn_points' => null,
        'dazn_estimate' => 2,
        'dazn_estimate_version' => 'v1',
        'dazn_estimate_reasons' => ['15 minutos jugados'],
        'dazn_estimate_source' => 'fantasy',
    ]);
});

test('hides a live estimate under 15 minutes', function () use ($estimated): void {
    expect(presentDazn($estimated(14))['dazn_estimate'])->toBeNull()
        ->and(presentDazn($estimated(14))['dazn_estimate_reasons'])->toBe([]);
});

test('shows any estimate once the match is finished and still unpublished', function () use ($estimated): void {
    $presented = presentDazn($estimated(4), ['state' => FixtureState::Finished]);

    expect($presented['dazn_estimate'])->toBe(2)
        ->and($presented['dazn_points'])->toBeNull();
});

test('shows the official rating and the frozen estimate once published, without reasons', function () use ($estimated): void {
    $presented = presentDazn([...$estimated(90), 'fantasy_stats' => ['marca_points' => [-1, 3]]], ['state' => FixtureState::Finished, 'dazn_published' => true]);

    expect($presented)->toBe([
        'dazn_points' => 3,
        'dazn_estimate' => 2,
        'dazn_estimate_version' => 'v1',
        'dazn_estimate_reasons' => [],
        'dazn_estimate_source' => null,
    ]);
});

test('an unused substitute has neither an official rating nor an estimate', function (): void {
    expect(presentDazn([], ['state' => FixtureState::Finished, 'dazn_published' => true]))->toBe([
        'dazn_points' => null,
        'dazn_estimate' => null,
        'dazn_estimate_version' => '',
        'dazn_estimate_reasons' => [],
        'dazn_estimate_source' => null,
    ]);
});
```

- [ ] **Paso 2:** ejecuta `php artisan test --compact tests/Unit/Services/DaznEstimatePresenterTest.php`. Debe FALLAR.

- [ ] **Paso 3: implementación.**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;

/**
 * The one visibility rule for DAZN ratings, shared by the web and the API.
 * Official ratings only once the fixture is published; before that, the stored
 * estimate once the player has 15 minutes, or at any time after full time.
 */
final class DaznEstimatePresenter
{
    public const int MIN_VISIBLE_MINUTES = 15;

    /**
     * @return array{dazn_points: int|null, dazn_estimate: int|null, dazn_estimate_version: string, dazn_estimate_reasons: list<string>, dazn_estimate_source: string|null}
     */
    public static function present(FixtureLineup $lineup, Fixture $fixture): array
    {
        $published = $fixture->dazn_published;
        $official = $lineup->fantasy_stats['marca_points'][1] ?? null;
        $meta = $lineup->dazn_estimate_meta ?? [];
        $estimate = $lineup->dazn_estimate;

        $isProvisional = !$published && $estimate !== null;

        if ($isProvisional && $fixture->state !== FixtureState::Finished && (int) ($meta['minutes'] ?? 0) < self::MIN_VISIBLE_MINUTES) {
            $estimate = null;
            $isProvisional = false;
        }

        return [
            'dazn_points' => $published && is_numeric($official) ? (int) $official : null,
            'dazn_estimate' => $estimate,
            'dazn_estimate_version' => $estimate === null ? '' : $lineup->dazn_estimate_version,
            'dazn_estimate_reasons' => $isProvisional ? array_values($meta['reasons'] ?? []) : [],
            'dazn_estimate_source' => $isProvisional ? ($meta['source'] ?? null) : null,
        ];
    }
}
```

- [ ] **Paso 4:** repite el Paso 2. Debe PASAR.
- [ ] **Paso 5:** ejecuta `vendor/bin/pint --dirty --format agent` y haz commit: `feat: add the DAZN estimate visibility presenter`.

---

### Task 5: Conectar la web (partido, mánagers, equipos, ficha de jugador)

**Archivos:**
- Modificar: `app/Http/Controllers/FixturesController.php`, en `presentLineup()`, L145-176. Quita el cálculo de `$daznPoints` y la clave `'dazn_points'`, y añade `...DaznEstimatePresenter::present($lineup, $fixture)`.
- Modificar: `app/Http/Controllers/Concerns/AttachesLineupPlayerScores.php`:
  - añade `->with('fixture')` a la consulta de `FixtureLineup`;
  - en el `each`, fija como propiedades virtuales `$entry->dazn_points`, `dazn_estimate`, `dazn_estimate_version`, `dazn_estimate_reasons` y `dazn_estimate_source`, a partir de `DaznEstimatePresenter::present($fixtureLineup, $fixtureLineup->fixture)`;
  - si no hay `$fixtureLineup`, fíjalas a `null`, `null`, `''`, `[]` y `null`;
  - documenta las nuevas propiedades en el docblock del trait y del modelo `ManagerLineupPlayer`, siguiendo el estilo de `stats`.
- Modificar: `app/Http/Controllers/TeamsController.php`, en la construcción de `$entry` (~L280-293): añade `...DaznEstimatePresenter::present($lineup, $fixture)`.
- Modificar: `app/Http/Controllers/PlayersController.php`, en `$scores->map(...)` (~L170-187): añade `...DaznEstimatePresenter::present($lineup, $lineup->fixture)`.
- Tests:
  - `tests/Feature/Http/Controllers/FixturesControllerTest.php`: actualiza los tests de DAZN de L164, L264, L289 y L312. Donde esperen `dazn_points` con el partido terminado, crea el partido con `->daznPublished()`.
  - `tests/Feature/Http/Controllers/PlayersControllerTest.php`, `tests/Feature/Http/Controllers/SeasonManagersControllerTest.php` y `tests/Feature/Http/Controllers/TeamsControllerTest.php`.

- [ ] **Paso 1: tests que fallan.** En `FixturesControllerTest`, con la aserción Inertia que ya usa el archivo para `dazn_points`:

```php
test('shows a provisional DAZN estimate for a live player with 15+ minutes', function (): void {
    // partido en SecondHalf, sin publicar, con una FixtureLineup ->withDaznEstimate(points: 2, minutes: 60, reasons: ['60 minutos jugados', '1 gol'])
    // assertInertia: el lineup tiene dazn_points null, dazn_estimate 2, dazn_estimate_version 'v1', dazn_estimate_reasons ['60 minutos jugados', '1 gol'], dazn_estimate_source 'fantasy'
});

test('hides the DAZN estimate of a live player under 15 minutes', function (): void {
    // igual, con minutes: 10 → dazn_estimate null
});

test('shows the official DAZN rating and the frozen estimate once published', function (): void {
    // partido Finished ->daznPublished(), fantasy_stats ['marca_points' => [-1, 3]], ->withDaznEstimate(points: 2)
    // → dazn_points 3, dazn_estimate 2, dazn_estimate_reasons []
});
```

Escribe cada test completo con el montaje y el estilo de aserción del archivo; los comentarios de arriba describen el montaje y lo esperado.

Añade un test por cada una de las otras tres páginas. Deben comprobar que la entrada de la ficha del jugador (`scores.0`), la de la alineación del mánager y la del equipo llevan `dazn_estimate` y `dazn_points`. Usa un partido publicado con estimación congelada. Copia el montaje del test de esa página que ya comprueba `stats`.

- [ ] **Paso 2:** ejecuta `php artisan test --compact tests/Feature/Http/Controllers/FixturesControllerTest.php tests/Feature/Http/Controllers/PlayersControllerTest.php tests/Feature/Http/Controllers/SeasonManagersControllerTest.php tests/Feature/Http/Controllers/TeamsControllerTest.php`. Los tests nuevos deben FALLAR.
- [ ] **Paso 3:** implementa los cambios de los cuatro archivos de arriba, añadiendo `use App\Services\DaznEstimatePresenter;` en cada uno.
- [ ] **Paso 4:** repite el Paso 2. Todo en verde.
- [ ] **Paso 5:** ejecuta `vendor/bin/pint --dirty --format agent` y haz commit: `feat: expose DAZN estimates to the fixture, manager, team and player pages`.

---

### Task 6: API y documentación

**Archivos:**
- Modificar: `app/Http/Controllers/Api/FixturesController.php`, en `show()`:
  - en el `each`, fija `$lineup->api_dazn = DaznEstimatePresenter::present($lineup, $fixture)`;
  - documenta `api_dazn` en el docblock de `FixtureLineup`, como `resolved_stats`.
- Modificar: `app/Http/Resources/FixtureLineupResource.php`. Añade después de `'stats'`:
  - `'dazn_estimate' => $this->api_dazn['dazn_estimate'] ?? null`;
  - `'dazn_estimate_version' => $this->api_dazn['dazn_estimate_version'] ?? ''`.
- Modificar: `app/Http/Controllers/Api/PlayersController.php`, en `attachScores()`:
  - añade `$dazn = DaznEstimatePresenter::present($lineup, $fixture);`;
  - añade las claves `'dazn_estimate' => $dazn['dazn_estimate']` y `'dazn_estimate_version' => $dazn['dazn_estimate_version']` después de `'marca_points'`.
  - **No cambies `marca_points`.**
- Modificar: `app/Http/Resources/PlayerDetailResource.php`, solo si enumera las claves de `scores`. Si solo pasa `api_scores`, no hace falta.
- Modificar: `app/Http/Controllers/Api/ManagerController.php`, en `presentFinishedLineup()`: por jugador, añade `'dazn_estimate' => $entry->dazn_estimate` y `'dazn_estimate_version' => $entry->dazn_estimate_version`. Los rellena `AttachesLineupPlayerScores` desde la Task 5.
- Modificar: `resources/docs/api-docs.md`:
  - §2.4 "Nota DAZN" (~L170): añade un párrafo;
  - la tabla de `scores[]` de `/api/players/{id}` (~L767), la de `lineups[]` de `/api/fixtures/{id}` (~L940-955) y la de `lineup_history[].players[]` de `/api/managers/{id}`: añade las dos filas;
  - §7 "Cambios": añade una entrada con fecha 2026-09-29.
- Modificar: `tests/Feature/Http/Controllers/Api/ApiWorld.php`. Al menos una alineación de partido y una de jugador deben llevar `->withDaznEstimate(...)`, y el partido debe estar `->daznPublished()`, para que `ApiDocsDriftTest` encuentre los campos.
- Tests: `tests/Feature/Http/Controllers/Api/FixtureShowTest.php`, `PlayerShowTest.php`, el test de mánager (`ManagerShowTest.php` o el que exista) y `ApiDocsDriftTest.php`, que ya valida lo documentado.

Texto para §2.4:

```markdown
**Estimación propia (`dazn_estimate`).** Mientras LaLiga Fantasy no publica las notas DAZN de un partido, Comando Lechuga estima la de cada jugador con sus stats en vivo (baremo `dazn_estimate_version` = `"v1"`: acierta la nota exacta ~7 de cada 10 veces y casi siempre queda a ±1). Solo se da si el jugador lleva 15 minutos o más, o si el partido ya ha terminado. En cuanto aparece la primera nota oficial del partido, las estimaciones se congelan y dejan de cambiar: sirven para comparar con la oficial. **La nota oficial (`marca_points`) siempre manda**; `dazn_estimate` nunca suma puntos.
```

- [ ] **Paso 1: tests que fallan.**
  - `FixtureShowTest`: con una alineación `->withDaznEstimate(points: 2, minutes: 70)` en un partido en vivo, `lineups.0.dazn_estimate` vale 2 y `dazn_estimate_version` vale `'v1'`; con `minutes: 5`, `dazn_estimate` es `null`.
  - `PlayerShowTest`: con un partido publicado y la estimación congelada, `scores.0.dazn_estimate` vale 2 y `scores.0.marca_points` sigue siendo el oficial.
  - Test de mánager: `lineup_history.0.players.0.dazn_estimate` presente.
- [ ] **Paso 2:** ejecuta `php artisan test --compact tests/Feature/Http/Controllers/Api`. Los nuevos deben FALLAR.
- [ ] **Paso 3:** implementa los cambios de código, documentación y `ApiWorld`.
- [ ] **Paso 4:** repite el Paso 2. Toda la carpeta en verde, incluido `ApiDocsDriftTest`.
- [ ] **Paso 5:** ejecuta `vendor/bin/pint --dirty --format agent` y haz commit: `feat: expose DAZN estimates in the API and document them`.

---

### Task 7: Comando `season:backfill-dazn-estimates`

**Archivos:**
- Crear: `app/Console/Commands/BackfillSeasonDaznEstimates.php`, con `php artisan make:command BackfillSeasonDaznEstimates --no-interaction`. Sigue el estilo de los comandos `season:*` existentes, por ejemplo `SyncSeasonMatchDataBackfill.php`, en cómo resuelven la temporada activa.
- Test: `tests/Feature/Console/Commands/BackfillSeasonDaznEstimatesTest.php`

**Comportamiento:**
- Firma: `season:backfill-dazn-estimates`. Descripción: "Estimate DAZN ratings for finished fixtures that have none yet".
- Para cada partido `Finished` de la temporada activa:
  - carga sus filas con `player_id` y las posiciones (`PlayerSeason` de la temporada, en una consulta por partido);
  - rellena solo las filas con `dazn_estimate === null`, con `DaznEstimator`;
  - si alguna fila tiene `marca_points[1] > 0`, fija `dazn_published = true`.
- Al final imprime `"Estimated {n} lineups across {m} fixtures."`.
- **No se programa**: se ejecuta a mano una vez.

- [ ] **Paso 1: tests que fallan.**

```php
test('estimates finished fixtures and marks the published ones', function (): void {
    // temporada activa; partido Finished 1-0 con una FixtureLineup (fantasy_stats ['mins_played' => [90, 2], 'saves' => [3, 1], 'marca_points' => [-1, 3]]) y su PlayerSeason de portero
    $this->artisan('season:backfill-dazn-estimates')->assertSuccessful();

    expect($lineup->fresh()->dazn_estimate)->toBe(3)
        ->and($fixture->fresh()->dazn_published)->toBeTrue();
});

test('never overwrites an existing estimate and can run twice', function (): void {
    // igual, pero la fila ya tiene ->withDaznEstimate(points: 1)
    $this->artisan('season:backfill-dazn-estimates')->assertSuccessful();
    $this->artisan('season:backfill-dazn-estimates')->assertSuccessful();

    expect($lineup->fresh()->dazn_estimate)->toBe(1);
});

test('skips fixtures that are not finished', function (): void {
    // partido SecondHalf con una fila sin estimación
    $this->artisan('season:backfill-dazn-estimates')->assertSuccessful();

    expect($lineup->fresh()->dazn_estimate)->toBeNull();
});
```

Escribe los montajes completos con factories: `Season::factory()` activa (fechas que incluyan `now()`), `Team`, `Fixture` con `season_id`, `team_local_id` y el marcador, `Player`, `PlayerSeason` y `FixtureLineup` con `team_id` del local. Portero, 90 minutos, 3 paradas, victoria 1-0 y a cero dan 0,25 + 1,20 + 0,60 + 0,10 + 0,25 = 2,40, es decir, 3.

- [ ] **Paso 2:** ejecuta los tests. Deben FALLAR.
- [ ] **Paso 3:** implementa el comando. Para no duplicar el cálculo por fila con el trait de sync, extrae la carga de posiciones y el guardado de una estimación a un servicio pequeño, `App\Services\DaznEstimateWriter`, con `write(Fixture $fixture, Collection $lineups, bool $onlyMissing): int`. Úsalo desde `refreshDaznEstimates()` (Task 3) y desde el comando. Si lo haces, ajusta `refreshDaznEstimates()` para delegar en él y vuelve a ejecutar los tests de la Task 3.
- [ ] **Paso 4:** ejecuta los tests de esta tarea y de nuevo `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`. Todo en verde.
- [ ] **Paso 5:** ejecuta `vendor/bin/pint --dirty --format agent` y haz commit: `feat: add season:backfill-dazn-estimates`.

---

### Task 8: Frontend (`HqDaznBadge` e integraciones)

**Archivos:**
- Crear: `resources/js/components/hq-dazn-badge.tsx`
- Modificar: `resources/css/app.css`. Junto a `--animate-hq-pulse` (~L74), añade `--animate-hq-est: hq-est 1.8s ease-in-out infinite;` y su `@keyframes hq-est { 0%, 100% { opacity: 1 } 50% { opacity: 0.45 } }`. Con `prefers-reduced-motion`, la clase no anima y deja opacidad 0,6; usa `motion-reduce:animate-none motion-reduce:opacity-60` en el componente.
- Modificar: `resources/js/types/models.ts`. Crea el tipo compartido y añádelo a `FixtureLineupEntry` (quitando su `dazn_points` suelto de L121, que pasa a venir del tipo), `ManagerLineupPlayerEntry` y `PlayerFichaScore`:

```ts
/** DAZN rating fields shared by every per-match player entry (see DaznEstimatePresenter). */
export interface DaznFields {
    /** Official LaLiga Fantasy rating, only once the fixture's ratings are published. */
    dazn_points: number | null;
    /** Our estimate: provisional while unpublished (15+ min or full time), frozen after. */
    dazn_estimate: number | null;
    dazn_estimate_version: string;
    /** Why the provisional estimate is what it is; empty once official. */
    dazn_estimate_reasons: string[];
    dazn_estimate_source: 'fantasy' | 'worldcup26' | null;
}
```

- Modificar: `resources/js/components/hq-lineup-player-token.tsx`:
  - variante `bench` (~L396-408): sustituye el bloque `HqTooltip` "Puntos DAZN" por `<HqDaznBadge entry={entry} size="sm" />`, con la condición `hasPlayed`;
  - variante `pitch` (~L224+): añade `<HqDaznBadge entry={entry} size="xs" plate />` debajo del nombre.
- Modificar: `resources/js/components/hq-player-stats-modal.tsx`:
  - sustituye `daznPoints?: number` por `dazn?: DaznFields`;
  - `lineupPlayerStatsEntry()` rellena `dazn` desde `selected`;
  - el pintado (~L219-228) usa `<HqDaznBadge entry={dazn} size="md" />`;
  - actualiza los tres usos de páginas (`teams/show.tsx` ~L433, `season-managers/index.tsx` ~L242 y `season-managers/show.tsx` ~L262) y el de `fixtures/show.tsx` (~L673-695), pasando `dazn` desde la entrada.
- Modificar: `resources/js/components/hq-player-match-timeline.tsx` (~L304 y L330-345): la celda usa `<HqDaznBadge entry={score} size="row" />`. La oficial conserva `daznPointsBadgeClass` dentro del componente para el tamaño `row`.
- Modificar: `resources/js/pages/players/show.tsx` (~L228-240): `daznScores` filtra `score.dazn_points !== null` y promedia `score.dazn_points`, así que solo cuenta oficiales.
- Modificar: `resources/js/components/hq-tooltip.tsx`, solo si hace falta pasar contenido rico en `label`. Ya admite `ReactNode`, `wrap` y `tone="amber"`.

**Componente** (`hq-dazn-badge.tsx`):

```tsx
import { HqTooltip } from '@/components/hq-tooltip';
import { daznPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { DaznFields } from '@/types/models';

type Size = 'xs' | 'sm' | 'md' | 'row';

const LOGO_SIZE: Record<Size, string> = { xs: 'h-3 w-3', sm: 'h-3.5 w-3.5', md: 'h-4 w-4', row: 'h-3.5 w-3.5' };

/**
 * A player's DAZN rating for one match: the official one (with our frozen estimate on hover),
 * or our provisional estimate — pulsing, amber tooltip with the reasons — until it is published.
 * Renders nothing when there is neither.
 */
export function HqDaznBadge({ entry, size = 'sm', plate = false }: { entry: DaznFields; size?: Size; plate?: boolean }) {
    const official = entry.dazn_points;
    const estimate = entry.dazn_estimate;

    if (official === null && estimate === null) {
        return null;
    }

    const isProvisional = official === null;
    const value = isProvisional ? estimate : official;

    const content =
        size === 'row' && !isProvisional ? (
            <span className={cn('inline-flex h-[22px] min-w-[30px] items-center justify-center px-[5px] font-mono text-xs leading-none font-bold tabular-nums', daznPointsBadgeClass(value as number))}>
                {value}
            </span>
        ) : (
            <span
                className={cn(
                    'inline-flex items-center gap-1 font-mono text-[11px] leading-none font-semibold text-hq-moss',
                    plate && 'rounded-sm bg-hq-ink/80 px-1 py-0.5',
                    isProvisional && 'animate-hq-est motion-reduce:animate-none motion-reduce:opacity-60',
                )}
            >
                <img src="/images/dazn-logo.png" alt="DAZN" className={LOGO_SIZE[size]} />
                {value}
            </span>
        );

    return (
        <HqTooltip wrap focusable tone={isProvisional ? 'amber' : 'lime'} label={isProvisional ? <ProvisionalTooltip entry={entry} /> : <OfficialTooltip official={official as number} estimate={estimate} />}>
            {content}
        </HqTooltip>
    );
}

function ProvisionalTooltip({ entry }: { entry: DaznFields }) {
    return (
        <span className="flex flex-col gap-1.5 text-left">
            <span className="font-mono text-[10px] font-bold tracking-wider text-hq-amber">DAZN PROVISIONAL · ESTIMACIÓN</span>
            <span>Aún no es la nota oficial. La calculamos con lo que lleva de partido:</span>
            <ul className="flex flex-col gap-0.5">
                {entry.dazn_estimate_reasons.map((reason) => (
                    <li key={reason}>
                        <span className="mr-1 text-hq-amber">·</span>
                        {reason}
                    </li>
                ))}
            </ul>
            <small className="text-hq-moss">
                {entry.dazn_estimate_source === 'worldcup26'
                    ? 'Estimación con datos parciales: menos fiable (~6 de cada 10).'
                    : 'Acierta la nota exacta ~7 de cada 10 veces y casi siempre queda a ±1. LaLiga Fantasy publica la oficial al acabar el partido.'}
            </small>
        </span>
    );
}

function OfficialTooltip({ official, estimate }: { official: number; estimate: number | null }) {
    const difference = estimate === null ? null : estimate - official;

    return (
        <span className="flex flex-col gap-1.5 text-left">
            <span className="font-mono text-[10px] font-bold tracking-wider text-hq-lime">DAZN OFICIAL</span>
            <span>LaLiga Fantasy: {official}</span>
            {estimate !== null && (
                <>
                    <span>
                        Comando Lechuga estimó: {estimate} {difference === 0 ? '✓' : `(${difference! > 0 ? '+' : '−'}${Math.abs(difference!)})`}
                    </span>
                    <small className="text-hq-moss">Nuestra estimación se congeló al publicarse la nota oficial.</small>
                </>
            )}
        </span>
    );
}
```

Ajusta los tokens de color (`bg-hq-ink`, `text-hq-amber`, `text-hq-lime`, `text-hq-moss`) a los que existan en `resources/css/app.css`. Comprueba cada uno con grep y usa el más cercano del sistema HQ. Evita los operadores `!` si el lint del proyecto los prohíbe, reescribiendo con una variable local.

**Pasos:**
- [ ] **Paso 1:** crea el tipo y el componente, añade la animación y ejecuta `npm run types:check`. Fallará por los usos antiguos de `dazn_points` y `daznPoints`, y eso guía el Paso 2.
- [ ] **Paso 2:** integra el componente en el token (bench y pitch), el modal y sus cuatro llamadores, la línea de tiempo y la Media DAZN, hasta que `npm run types:check` quede limpio.
- [ ] **Paso 3:** ejecuta `npm run lint`, `npm run format`, `npm run types:check` y `npm run build`. Todo limpio.
- [ ] **Paso 4:** vuelve a ejecutar los tests PHP de páginas de la Task 5. Los props no deben haber cambiado. Después ejecuta `php artisan test --compact tests/Feature/Http`.
- [ ] **Paso 5:** haz commit: `feat: show provisional and official DAZN ratings with HqDaznBadge`.

---

## Autorrevisión (hecha)

- **Cobertura de la spec:**

  | Spec | Tarea |
  |---|---|
  | §2 | Task 2 |
  | §3 | Task 1 |
  | §4 | Task 3 |
  | §5 | Task 4 y Task 5 |
  | §6 | Task 8 |
  | §7 | Task 6 |
  | §8 | Task 7 |
  | §9 | En cada tarea |
  | §10 | Solo documentación, ya en la spec |
  | §11 | Fuera de alcance |

- **Consistencia de tipos:**
  - `DaznEstimate::toMeta()` → `dazn_estimate_meta` (Task 1, 3 y 4).
  - `DaznEstimatePresenter::present()` devuelve las cinco claves que consumen las Task 5, 6 y 8 (`DaznFields`).
- **Review Focus:** cada punto tiene su test. El 1 y el 5 en la Task 2, el 2 en la Task 2 y en la Task 4, el 3 en la Task 3 y el 4 en la Task 4.
