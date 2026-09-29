# Plan de implementación: fuerza de equipos y dificultad de partido

> **Para agentes:** SUB-SKILL OBLIGATORIA: usa superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans para implementar este plan tarea a tarea. Los pasos usan casillas (`- [ ]`) para el seguimiento.

**Objetivo:** sustituir la dificultad por posición (`LeagueStandings::difficulty`) por un modelo único de fuerza de equipos (valor de plantilla + rendimiento con encogimiento, más casa/fuera y bajas del próximo partido). El modelo produce una **dificultad 0–10 (10 = muy difícil)** que usan en todas partes el calendario, las fichas, la API y la puja máxima.

**Arquitectura:**
- `TeamStrength` calcula la fuerza de los 20 equipos a una fecha. La matemática vive en un estático puro `fromInputs()`, que tiene un test de paridad con el estudio.
- `MatchDifficulty` convierte la fuerza del rival en la dificultad de un partido, según la variante general / attack / defense.
- Los consumidores cambian `LeagueStandings::difficulty` por `MatchDifficulty`.
- La puja máxima recibe `rivalEase = (5 − dificultad) / 5`, en la escala de hoy, y **no cambia sus parámetros**.

**Stack:** Laravel 13 / PHP 8.5, Pest, Inertia v3 + React + TypeScript + Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-09-29-team-strength-design.md`. Léela antes de cada tarea: manda sobre este plan. El estudio está en `C:/Users/Uri/code/comando-lechuga-research/team-difficulty/` (`informe.md`, `final2.mjs`, `_base.mjs`).

## Restricciones globales

- **Escala:** `dificultad = clamp(5 − 2,5·e, 0, 10)`, redondeada a 1 decimal. **10 = muy difícil, 0 = muy fácil.** `e` es la facilidad interna, donde un valor mayor significa más fácil: `e = −fuerza_rival + H·(casa ? +1 : −1)` más el ajuste por bajas.
- **Parámetros por defecto** (`TeamStrengthParameters`):

  | Parámetro | Valor |
  |---|---|
  | `topPlayers` | 15 |
  | `shrinkK` | 8 |
  | `homeBonus` | 0,4 |
  | `specificShare` | 0,3 |
  | `absenceWeight` | 0,3 |
  | `regularMinutesShare` | 0,7 |
  | `absentProbabilityBelow` | 30 |
  | `scaleSlope` | 2,5 |

- **z transversal:**
  - media y **desviación poblacional** entre los equipos que tienen valor;
  - si la desviación es 0, z = 0;
  - un equipo sin dato en una métrica (0 partidos) tiene z = 0 en esa métrica.
- **Mezcla:**
  - `w = n / (n + shrinkK)`;
  - `general = (1 − w)·zV + w·media(zDG, zTP, zPC)`;
  - `offensiveThreat = (1 − w)·zV + w·(0,7·rg + 0,3·ataque)`;
  - `defensiveSolidity = (1 − w)·zV + w·(0,7·rg + 0,3·defensa)`.
- **Variantes:**

  | Variante | Fuerza del rival que usa | Para quién |
  |---|---|---|
  | `General` | `general` | todo lo que es de equipo |
  | `Attack` | `defensiveSolidity` del rival | medios y delanteros |
  | `Defense` | `offensiveThreat` del rival | porteros y defensas |

- **Bajas:** solo en el **próximo** partido del rival, y solo cuando se calcula para "ahora". Afectan a `General` y `Attack`, **nunca** a `Defense`.
- **Puja máxima:** `rivalEase = (5 − dificultad) / 5`. **Sus parámetros no se tocan en esta rama.**
- **API:** `next_fixtures[].difficulty` pasa a 0–10 (10 = difícil) y se añade `difficulty_variant`. Es un cambio incompatible y va anotado en §7 "Cambios" de `api-docs.md`.
- **Frontend (opción B elegida por el usuario):**
  - las 5 barras, `round(dificultad / 2)` con mínimo 1 y máximo 5 (más barras = más difícil), con el número pequeño en mono al lado ("7,4");
  - niveles por color: fácil < 3,5 (lima), media < 6,5 (ámbar), difícil ≥ 6,5 (rojo);
  - tooltip: "Dificultad X,X / 10 · rival N.º Y · casa|fuera", más "· bajas del rival" si se ajustó;
  - todo lo clicable lleva `cursor-pointer`;
  - referencia visual: `public/_dificultad.html` (opción B).
- **Código y commits:**
  - PHP: `declare(strict_types=1)`, tipos de retorno explícitos, llaves siempre y PHPDoc con array shapes.
  - Pest para los tests.
  - Pasa `vendor/bin/pint --dirty --format agent` antes de cada commit.
  - Un commit por tarea en la rama `feature/team-strength`, terminado con `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Nunca merge a main.
- **Verificación de cada tarea:** `php artisan test --compact` con los archivos afectados. Al final, la suite completa y `composer analyze`, que incluye phpstan, lint y types.

## Review Focus

1. **Equipo recién ascendido o sin partidos (n = 0)**, o jugadores sin valor en `player_markets` en esa fecha: la fuerza es solo valor y nada revienta por dividir entre 0. Test en Task 1 y Task 2.
2. **Partido aplazado o sin fecha y rival que no es de la temporada:** `MatchDifficulty` devuelve `null` en lugar de romper, y los consumidores muestran "–". Test en Task 3 y Task 4.
3. **Fechas pasadas en el backtest de la puja:** no pueden entrar ni partidos ni valores posteriores a `at`, y no se aplican bajas. Test en Task 2 y Task 6.
4. **Pases clave nulos en algunos partidos:** esos partidos no cuentan para esa media, pero sí para las demás. Test en Task 2.
5. **Rendimiento de las páginas:** el calendario (20 equipos × 10 partidos) no puede hacer una consulta por celda. Test de número de consultas en Task 4.

---

### Task 1: Matemática pura de la fuerza (`TeamStrength::fromInputs`)

**Archivos:**
- Crear: `app/Enums/DifficultyVariant.php`
- Crear: `app/Services/TeamStrengthParameters.php`
- Crear: `app/Services/TeamStrengthInputs.php`
- Crear: `app/Services/TeamStrengthRating.php`
- Crear: `app/Services/TeamStrength.php`, solo con `fromInputs()` en esta tarea.
- Ya existe: `tests/Fixtures/team-strength-parity.json`. Son 20 equipos tras la J7 con los inputs agregados y la salida del estudio. No se modifica.
- Test: `tests/Unit/Services/TeamStrengthMathTest.php`, con `--unit`. Es pura y no necesita la app.

**Interfaces que produce:**

```php
enum DifficultyVariant: string { case General = 'general'; case Attack = 'attack'; case Defense = 'defense';
    public static function forPosition(?PlayerPosition $position): self; } // GK/DEF → Defense, MID/FWD → Attack, Coach/null → General

final readonly class TeamStrengthParameters { /* promoted public props with the defaults of Global Constraints */ }

/** Per-team aggregates at a date. Per-match means; null when the team has no finished match (or, for key passes, no match with data). */
final readonly class TeamStrengthInputs {
    public function __construct(
        public int $teamId, public int $matches, public float $logValue,
        public ?float $goalDifference, public ?float $shotsOnTargetDifference, public ?float $keyPassesFor,
        public ?float $goalsFor, public ?float $shotsOnTargetFor, public ?float $failedToScoreRate,
        public ?float $goalsAgainst, public ?float $shotsOnTargetAgainst, public ?float $keyPassesAgainst,
    ) {}
}

final readonly class TeamStrengthRating {
    public function __construct(
        public int $teamId, public int $matches, public float $valueZ, public float $performanceZ,
        public float $attackZ, public float $defenseZ,
        public float $general, public float $offensiveThreat, public float $defensiveSolidity,
    ) {}
    public function for(DifficultyVariant $variant): float; // General → general, Attack → defensiveSolidity, Defense → offensiveThreat
}

final class TeamStrength {
    /** @param list<TeamStrengthInputs> $inputs  @return array<int, TeamStrengthRating> keyed by team id */
    public static function fromInputs(array $inputs, TeamStrengthParameters $parameters): array;
}
```

- [ ] **Paso 1: tests que fallan.**

```php
<?php

use App\Enums\DifficultyVariant;
use App\Enums\PlayerPosition;
use App\Services\TeamStrength;
use App\Services\TeamStrengthInputs;
use App\Services\TeamStrengthParameters;

/**
 * @param  array<string, mixed>  $row  One row of tests/Fixtures/team-strength-parity.json
 */
function parityInputs(int $teamId, array $row): TeamStrengthInputs
{
    return new TeamStrengthInputs(
        teamId: $teamId,
        matches: $row['matches'],
        logValue: $row['log_value'],
        goalDifference: $row['goal_difference'] ?? null,
        shotsOnTargetDifference: $row['shots_on_target_difference'] ?? null,
        keyPassesFor: $row['key_passes_for'] ?? null,
        goalsFor: $row['goals_for'] ?? null,
        shotsOnTargetFor: $row['shots_on_target_for'] ?? null,
        failedToScoreRate: $row['failed_to_score_rate'] ?? null,
        goalsAgainst: $row['goals_against'] ?? null,
        shotsOnTargetAgainst: $row['shots_on_target_against'] ?? null,
        keyPassesAgainst: $row['key_passes_against'] ?? null,
    );
}

test('reproduces the study strengths after J7', function (): void {
    $rows = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/team-strength-parity.json'), true);
    $inputs = array_map(fn (int $i): TeamStrengthInputs => parityInputs($i + 1, $rows[$i]), array_keys($rows));

    $ratings = TeamStrength::fromInputs($inputs, new TeamStrengthParameters);

    foreach ($rows as $i => $row) {
        $rating = $ratings[$i + 1];
        expect($rating->general)->toEqualWithDelta($row['expected']['general'], 1e-4)
            ->and($rating->offensiveThreat)->toEqualWithDelta($row['expected']['offensive_threat'], 1e-4)
            ->and($rating->defensiveSolidity)->toEqualWithDelta($row['expected']['defensive_solidity'], 1e-4);
    }
});

test('a team with no matches is rated on squad value only', function (): void {
    $ratings = TeamStrength::fromInputs([
        new TeamStrengthInputs(1, 0, log(300e6), null, null, null, null, null, null, null, null, null),
        new TeamStrengthInputs(2, 0, log(100e6), null, null, null, null, null, null, null, null, null),
    ], new TeamStrengthParameters);

    expect($ratings[1]->general)->toEqualWithDelta(1.0, 1e-9)
        ->and($ratings[2]->general)->toEqualWithDelta(-1.0, 1e-9)
        ->and($ratings[1]->offensiveThreat)->toEqualWithDelta(1.0, 1e-9);
});

test('identical teams all rate 0 instead of dividing by zero', function (): void {
    $same = fn (int $id): TeamStrengthInputs => new TeamStrengthInputs($id, 3, 18.0, 0.5, 1.0, 4.0, 1.5, 4.0, 0.2, 1.0, 3.0, 3.0);

    $ratings = TeamStrength::fromInputs([$same(1), $same(2), $same(3)], new TeamStrengthParameters);

    expect(array_map(fn ($r): float => $r->general, $ratings))->toBe([1 => 0.0, 2 => 0.0, 3 => 0.0]);
});

test('the performance weight grows with matches played', function (): void {
    // Team 1 is the priciest but performs worst; with more matches it sinks toward its performance.
    $make = fn (int $matches): array => [
        new TeamStrengthInputs(1, $matches, 20.0, -1.0, -3.0, 2.0, 0.8, 2.0, 0.5, 1.8, 5.0, 6.0),
        new TeamStrengthInputs(2, $matches, 19.0, 0.0, 0.0, 4.0, 1.3, 4.0, 0.2, 1.3, 4.0, 4.0),
        new TeamStrengthInputs(3, $matches, 18.0, 1.0, 3.0, 6.0, 1.8, 6.0, 0.1, 0.8, 3.0, 2.0),
    ];

    $early = TeamStrength::fromInputs($make(2), new TeamStrengthParameters)[1]->general;
    $late = TeamStrength::fromInputs($make(24), new TeamStrengthParameters)[1]->general;

    expect($early)->toBeGreaterThan($late);
});

test('maps positions to difficulty variants', function (?PlayerPosition $position, DifficultyVariant $variant): void {
    expect(DifficultyVariant::forPosition($position))->toBe($variant);
})->with([
    [PlayerPosition::Goalkeeper, DifficultyVariant::Defense],
    [PlayerPosition::Defender, DifficultyVariant::Defense],
    [PlayerPosition::Midfield, DifficultyVariant::Attack],
    [PlayerPosition::Striker, DifficultyVariant::Attack],
    [PlayerPosition::Coach, DifficultyVariant::General],
    [null, DifficultyVariant::General],
]);
```

- [ ] **Paso 2:** ejecuta `php artisan test --compact tests/Unit/Services/TeamStrengthMathTest.php`. Debe FALLAR.
- [ ] **Paso 3: implementación.** Transcribe `strength()` de `final2.mjs` con los signos del fixture. Una métrica que se invierte en el estudio entra aquí con signo menos antes del z:
  - `failedToScoreRate` → `−failedToScoreRate`;
  - `goalsAgainst` → `−goalsAgainst`;
  - `shotsOnTargetAgainst` → `−shotsOnTargetAgainst`;
  - `keyPassesAgainst` → `−keyPassesAgainst`.

  Los z se calculan por métrica solo entre los equipos cuyo valor no es null, con media y desviación poblacional; si la desviación es 0, se usa 1. A un equipo con null en una métrica le corresponde z = 0.

  ```
  rg     = (zDG + zTP + zPC) / 3
  ataque = (zGF + zTPfavor + zPC + z(−fts)) / 4
  defensa = (z(−GA) + z(−TPcontra) + z(−PCcontra)) / 3
  w = matches / (matches + shrinkK)
  general           = (1−w)·zV + w·rg
  offensiveThreat   = (1−w)·zV + w·((1−specificShare)·rg + specificShare·ataque)
  defensiveSolidity = (1−w)·zV + w·((1−specificShare)·rg + specificShare·defensa)
  ```

  `valueZ`, `performanceZ`, `attackZ` y `defenseZ` se guardan en el rating para los tooltips y el backtest. `TeamStrengthRating::for()` hace el `match` de la variante.
- [ ] **Paso 4:** repite el Paso 2. Debe PASAR, incluida la paridad con los 20 equipos.
- [ ] **Paso 5:** pasa Pint y haz commit: `feat: add the team strength model maths`. Incluye `tests/Fixtures/team-strength-parity.json`.

---

### Task 2: Inputs desde la base de datos (`TeamStrength::ratingsAt`)

**Archivos:**
- Modificar: `app/Services/TeamStrength.php`, que pasa a ser una clase con estado para el memo de la instancia.
- Test: `tests/Feature/Services/TeamStrengthTest.php`

**Interfaces que consume:** Task 1.

**Interfaz que produce:**
- `TeamStrength::ratingsAt(Season $season, CarbonInterface $at, ?TeamStrengthParameters $parameters = null): array<int, TeamStrengthRating>`, con memo por `"{season}:{Y-m-d H:i}"` en la instancia.
- `TeamStrength::inputsAt(Season $season, CarbonInterface $at): list<TeamStrengthInputs>`, pública, para el backtest.

**Qué calcula, sin mirar nada posterior a `$at`:**
- **Equipos:** los de la temporada, `$season->teams`.
- **Valor:**
  - Se toma el día más reciente de `player_markets` con `date <= $at`. Es la misma regla que `MaxBidCalculator::referenceDate()`, que hay que mirar para copiar la consulta o extraer un helper compartido.
  - Por equipo, se suman los `topPlayers` valores más altos de sus jugadores, con pertenencia por `players.team_id`.
  - `logValue = ln(suma + 1)`. Sin valores, la suma es 0 y `logValue` 0.
- **Partidos:**
  - `Fixture` de la temporada con `state = Finished` y `date < $at`.
  - Por equipo y partido: goles a favor y en contra por el marcador; tiros a puerta a favor y en contra sumando el stat `shotsOnTarget` de `fixture_lineups.stats` por `team_id` (usa `SummarizesFixtureStats::statValue` o su lógica); pases clave con `local_key_passes` / `guest_key_passes`, que pueden ser nulos; y "sin marcar" como goles a favor = 0.
  - Medias por partido. Los pases clave solo cuentan en los partidos donde el dato existe, y si no hay ninguno, `null`.
- **Consultas:** hazlo en pocas consultas agregadas (valores, partidos, alineaciones), nunca una por equipo y partido.

- [ ] **Paso 1: tests que fallan.** Monta con factories:
  - una temporada activa con 3 equipos y jugadores con `player_markets` en dos fechas;
  - 2 partidos `Finished` con marcador, `local_key_passes` / `guest_key_passes` (uno de ellos null) y alineaciones con `stats` `shotsOnTarget`.

  Tests:
  - **(a)** `inputsAt()` devuelve las medias esperadas: goles, diferencia de tiros a puerta, y pases clave contando solo el partido con dato.
  - **(b)** Un partido posterior a `$at` y un valor de mercado posterior a `$at` **no** cuentan.
  - **(c)** Un equipo sin partidos tiene `matches = 0` y medias `null`, y `ratingsAt()` le da fuerza igual a `valueZ`.
  - **(d)** El memo: dos llamadas con la misma fecha hacen las mismas consultas una sola vez. Compruébalo con `DB::enableQueryLog()`.

  Recuerda que `Player::factory()->create()` crea solo un `PlayerSeason` para la temporada activa y que `position` es una clave de esa temporada.
- [ ] **Paso 2:** ejecuta los tests. Deben FALLAR.
- [ ] **Paso 3:** implementa `inputsAt()` y `ratingsAt()`, este último con `fromInputs()` y el memo.
- [ ] **Paso 4:** repite el Paso 2. Debe PASAR.
- [ ] **Paso 5:** pasa Pint y haz commit: `feat: compute team strength inputs from the database`.

---

### Task 3: Dificultad de un partido (`MatchDifficulty`)

**Archivos:**
- Crear: `app/Services/MatchDifficulty.php`
- Crear: `app/Services/MatchDifficultyResult.php`
- Test: `tests/Feature/Services/MatchDifficultyTest.php`

**Interfaces que consume:**
- `TeamStrength::ratingsAt()` y `DifficultyVariant`;
- `App\Services\StartProbabilities` (`forTeamsNextFixtures` y `nextFixtures`; léelo);
- `players.status`, con el enum `PlayerStatus`.

**Interfaces que produce:**

```php
final readonly class MatchDifficultyResult {
    public function __construct(
        public float $difficulty,          // 0–10, 1 decimal, 10 = hard
        public float $rivalEase,           // (5 − difficulty) / 5, for MaxBidCalculator only
        public DifficultyVariant $variant,
        public bool $absenceAdjusted,
        public ?int $rivalPosition,        // informative, from LeagueStandings::positions
        /** @var array{rival_strength: float, home: float, absences: float} */
        public array $components,          // contributions to e (the internal ease), for tooltips
    ) {}
    /** @return array{difficulty: float, difficulty_variant: string, difficulty_components: array{rival_strength: float, home: float, absences: float}} */
    public function toArray(): array;
}

final class MatchDifficulty {
    public function __construct(private TeamStrength $strength, private StartProbabilities $startProbabilities, private LeagueStandings $standings) {}
    /** Null when the fixture has no date or $teamId is not one of its two teams. */
    public function for(Fixture $fixture, int $teamId, DifficultyVariant $variant, ?CarbonInterface $at = null): ?MatchDifficultyResult;
    /**
     * Batch: one strength/absence computation for all. $items are [fixture, teamId, variant] triples.
     * @param  list<array{0: Fixture, 1: int, 2: DifficultyVariant}>  $items
     * @return list<MatchDifficultyResult|null>  same order
     */
    public function forMany(array $items, ?CarbonInterface $at = null): array;
}
```

**Reglas:**
- **Fecha:** `at` es por defecto `now()`. Se usa `ratingsAt($season, $at)`.
- **Facilidad interna:** `e = −rating(rival)->for($variant) + homeBonus·(X en casa ? +1 : −1)`.
- **Bajas:** solo si `$at` es "ahora" (el valor por defecto), `$variant` no es `Defense` y el partido es **el próximo** del rival (su primer partido `Scheduled` con fecha posterior a ahora).
  - `a` = valor de los titulares habituales del rival que no jugarán ÷ valor de todos sus habituales.
  - **Titular habitual:** en los partidos terminados de esta temporada, sus minutos (`fantasy_stats.mins_played[0]`) suman ≥ `regularMinutesShare` × (partidos del equipo × 90).
  - **No jugará:**
    - si hay XI de FútbolFantasy para ese partido, es un habitual que no es `predicted_starter` y cuya probabilidad es < `absentProbabilityBelow`, o cuyo `confirmed_starter === false`;
    - si no lo hay, un habitual con `status` `injured`, `suspended` u `out_of_league`.
  - `z(a)` se calcula entre los equipos que tienen próximo partido.
  - `e += absenceWeight·z(a)`, `absenceAdjusted = true` y `components['absences']` guarda esa aportación.
- **Resultado:**
  - `difficulty = round(clamp(5 − scaleSlope·e, 0, 10), 1)`;
  - `rivalEase = round((5 − difficulty) / 5, 3)`;
  - `rivalPosition`: `LeagueStandings::positions($season)` a la fecha, con memo.

- [ ] **Paso 1: tests que fallan.**
  - **(a)** Casa frente a fuera: con los mismos equipos, fuera = casa + 2,0 de dificultad (±1 cada lado, `2,5·0,4 = 1`), salvo recorte.
  - **(b)** Un rival claramente más fuerte da más dificultad.
  - **(c)** Clamp a 0 y a 10 en casos extremos. Construye 3 equipos con valores de mercado muy distintos y sin partidos.
  - **(d)** `rivalEase = (5 − dificultad)/5`.
  - **(e)** Bajas:
    - con un habitual caro del rival `injured`, la dificultad `General` y `Attack` baja respecto a sin baja, y `absenceAdjusted` es true;
    - `Defense` no cambia;
    - para un partido del rival que no es su próximo, no hay ajuste;
    - con `$at` en el pasado, no hay ajuste.
  - **(f)** Un partido sin fecha o un `teamId` ajeno devuelve null.
  - **(g)** `forMany` da lo mismo que `for` elemento a elemento y hace un número de consultas constante: compara el query log con 1 elemento y con 10.
- [ ] **Paso 2:** ejecuta los tests. Deben FALLAR.
- [ ] **Paso 3:** implementa. Reutiliza `StartProbabilities` para el XI; no dupliques su consulta.
- [ ] **Paso 4:** repite el Paso 2. Debe PASAR.
- [ ] **Paso 5:** pasa Pint y haz commit: `feat: add match difficulty from team strength`.

---

### Task 4: Consumidores web (calendario, próximos partidos y ficha del equipo)

**Archivos:**
- Modificar: `app/Services/FixtureCalendar.php`, que usa `MatchDifficulty::forMany` con `General`:
  - `difficulty` 0–10 por partido;
  - `average` = media de las dificultades de la fila;
  - **orden ascendente** (media más baja = calendario más fácil primero);
  - se conserva `rival_position`.
- Modificar: `app/Http/Controllers/Concerns/AttachesNextFixtures.php`, en `nextFixtureSlot()` y quien lo llame:
  - por jugador, `DifficultyVariant::forPosition($player->position)`;
  - el slot añade `difficulty` (0–10), `difficulty_variant`, `difficulty_components` y `date` (ISO 8601), y conserva `rival_position`;
  - todo en lote con `forMany`.
- Modificar: `app/Http/Controllers/TeamsController.php` (`show`, próximos 3 del propio equipo, ~L118-139): variante `General`.
- Modificar: `app/Models/Player.php`, el docblock de `next_fixtures` (L43).
- Tests:
  - `tests/Feature/Http/Controllers/TeamsCalendarTest.php`: el test de ~L130 esperaba −1, −0,333… y pasa a construir un mundo donde el orden sale del modelo nuevo, comprobando el orden ascendente y valores de 0 a 10. Mantén el test de número de consultas de ~L156, con un nuevo límite razonable y constante respecto al número de equipos.
  - `tests/Feature/Http/Controllers/TeamsControllerTest.php` (~L740).
  - `tests/Feature/Http/Controllers/SeasonManagersControllerTest.php` (~L742 y L791).
  - `tests/Feature/Http/Controllers/PlayersControllerTest.php`: comprueba `difficulty_variant` según la posición.

**Interfaces que consume:** `MatchDifficulty::forMany`, `MatchDifficultyResult::toArray()` y `DifficultyVariant::forPosition`.

- [ ] **Paso 1:** actualiza y añade los tests para que comprueben:
  - la escala 0–10;
  - la variante por posición;
  - el orden ascendente del calendario;
  - que un rival sin fecha o fuera de la temporada muestra el slot con `difficulty: null`.

  Deben FALLAR.
- [ ] **Paso 2:** implementa los cambios en los tres archivos.
- [ ] **Paso 3:** ejecuta `php artisan test --compact tests/Feature/Http tests/Feature/Services`. Todo en verde.
- [ ] **Paso 4:** pasa Pint y haz commit: `feat: use the team strength difficulty in the calendar and next fixtures`.

---

### Task 5: API y documentación

**Archivos:**
- Modificar: `app/Http/Controllers/Concerns/AttachesApiNextFixtures.php` (L30-90). Cada entrada lleva:
  - `difficulty` (0–10, 10 = difícil, o `null` si no aplica);
  - `difficulty_variant` según la posición del jugador;
  - se conserva `rival_position`.
- Modificar: `resources/docs/api-docs.md`:
  - §3.3 (~L299-301), las líneas ~329, 332, 626 (ejemplo), 668-669 y el glosario (~1079);
  - una entrada en §7 "Cambios", fechada el 2026-09-29 y marcada como **cambio incompatible**: "`next_fixtures[].difficulty` pasa de −1…+1 (+1 = fácil) a 0–10 (10 = difícil); nuevo `difficulty_variant`".
- Tests:
  - `tests/Feature/Http/Controllers/Api/PlayerSignalsTest.php` (~L126-143);
  - `ApiDocsDriftTest`, que tiene que documentar `difficulty_variant`;
  - `tests/Feature/Http/Controllers/Api/ApiWorld.php`, si hace falta que haya datos;
  - `MaxBidGuardTest` debe seguir pasando sin cambios.

- [ ] **Paso 1:** tests que fallan: la escala y la variante en `/api/players` y en `/api/players/{id}`.
- [ ] **Paso 2:** implementa el código y la documentación.
- [ ] **Paso 3:** ejecuta `php artisan test --compact tests/Feature/Http/Controllers/Api`. Todo en verde.
- [ ] **Paso 4:** pasa Pint y haz commit: `feat: expose the new 0–10 difficulty in the API`.

---

### Task 6: Puja máxima con la dificultad nueva, sin cambiar parámetros

**Archivos:**
- Modificar: `app/Services/MaxBidCalculator.php`. En `queryUpcomingRivals()` (~L525-568) cada rival pasa a `{team, position, days_until, difficulty}`, con:
  - `difficulty` = `MatchDifficulty::for($fixture, $player->team_id, forPosition(posición del jugador), $at)->rivalEase`;
  - las bajas solo en el primer rival y solo cuando `$at` es hoy, que es lo que `MatchDifficulty` ya hace al pasar `$at`;
  - el memo de `$upcomingRivalsByTeamAndDate` incluye la variante en la clave.
  - Documenta que el campo `difficulty` de este array es la **facilidad −1…+1** que espera `sportFactors()`, sin tocar `sportFactors()`.
- Modificar: `app/Console/Commands/BacktestMaxBid.php`: la rejilla `--grid` añade `rivalsWeight` ∈ {0,15, 0,3, 0,45} junto a la de `proximityHalfLifeDays`, y la tabla muestra la columna.
- Tests:
  - `tests/Feature/Services/MaxBidCalculatorTest.php`: L241-268 (peso por proximidad), L340-384 (fija `rivalsEffect ≈ −0,1367`, que hay que recalcular con el mundo del test y el modelo nuevo; documenta en el test de dónde sale el valor) y L443/L459.
  - `tests/Unit/Services/MaxBidFormulaTest.php` no cambia, porque usa entradas directas.
  - `tests/Feature/Console/Commands/BacktestMaxBidTest.php`: la columna nueva.

**Añadido por el usuario (2026-09-29): la probabilidad de titularidad.**
- `MaxBidInputs` gana `?float $nextStartProbability` (0–1). Es la probabilidad de FútbolFantasy para el **próximo** partido del jugador en `$at`: la fila de `fixture_lineup_probabilities` de ese partido con `fetched_at <= $at` más reciente, usando `probability / 100`, o `1.0` si `confirmed_starter === true` y `0.0` si es `false`. Vale `null` si no hay fila, lo que ocurre en el backtest antes de la J8.
- En `sportFactors()`, la participación que hoy es `p` (reciente) pasa a ser `p' = (1 − λ)·p + λ·prob` cuando `nextStartProbability` no es null, y `p` si lo es.
- `λ = MaxBidParameters::startProbabilityWeight`, **nuevo parámetro con valor 0,5**. Es el único parámetro que se añade; los existentes no se tocan.
- `BacktestMaxBid --grid` barre también `startProbabilityWeight` ∈ {0, 0,25, 0,5, 0,75}.
- **Tests:**
  - `MaxBidFormulaTest`: sin probabilidad, el resultado es idéntico al de hoy; con probabilidad 0 o 1, la participación se mueve en el sentido correcto.
  - `MaxBidCalculatorTest`: `gatherInputs` lee la fila correcta (la más reciente con `fetched_at <= $at`) y la confirmada manda sobre el %.
- **Comparación final (Paso 5):** muestra el antes y el después por separado para la dificultad nueva y para la probabilidad, con Koski y Rüdiger entre los jugadores.

**No toques los parámetros existentes de `MaxBidParameters`.** El único que se añade es `startProbabilityWeight`.

- [ ] **Paso 1:** actualiza los tests. Deben FALLAR si las expectativas cambian.
- [ ] **Paso 2:** implementa.
- [ ] **Paso 3:** ejecuta `php artisan test --compact tests/Feature/Services/MaxBidCalculatorTest.php tests/Unit/Services tests/Feature/Console/Commands/BacktestMaxBidTest.php tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php`. Todo en verde.
- [ ] **Paso 4:** pasa Pint y haz commit: `feat: feed the max bid with the team strength difficulty`.
- [ ] **Paso 5, para el controlador y no el implementador:** con la base local, ejecuta `php artisan season:backtest-max-bid` y `--grid`, y prepara para el usuario una comparación antes/después de la puja de 5–8 jugadores (incluido Koski) con `git stash` de esta tarea o con la rama `main`. **No cambies parámetros sin su OK.**

---

### Task 7: Comando `season:backtest-team-strength {--grid}`

**Archivos:**
- Crear: `app/Console/Commands/BacktestTeamStrength.php`, con los atributos `#[Signature]` y `#[Description]` como los `season:*`.
- Test: `tests/Feature/Console/Commands/BacktestTeamStrengthTest.php`

**Comportamiento:**
- **Walk-forward:** recorre cada partido `Finished` de la temporada activa en el que los dos equipos tienen ≥ 2 partidos previos, calculando las fuerzas con `TeamStrength::inputsAt($season, $fixture->date)` más `fromInputs()`. Así no mira el futuro y es rápido en rejilla, porque los inputs se calculan una vez por fecha.
- **Por lado del partido**, calcula la facilidad `e` de cada variante (sin bajas) y los objetivos:
  - puntos (3/1/0), diferencia de goles, goles a favor y portería a cero;
  - media de puntos Fantasy de los titulares medios y delanteros, de porteros y defensas, y de todos (`fixture_lineups.fantasy_points`, con la posición de `PlayerSeason`).
- **Imprime una tabla** con la ρ de Spearman de cada variante y la de la posición en la tabla (`LeagueStandings::positions` a la fecha) con cada objetivo, más `n`.
- **Con `--grid`**, repite con `shrinkK` ∈ {4, 8, 16}, `homeBonus` ∈ {0,2, 0,4, 0,6} y `specificShare` ∈ {0,2, 0,3, 0,5}, y muestra la mejor combinación por objetivo. La de `defense` se lee sobre los puntos Fantasy de porteros y defensas.
- **No escribe nada** en la base de datos ni en la configuración.

- [ ] **Paso 1: test que falla.** Un mundo pequeño (4 equipos, 6 partidos terminados con alineaciones, puntos y valores) en el que el comando termina bien, imprime la cabecera y una fila por variante más la de la posición, y **no cambia** el número de filas de ninguna tabla. Comprueba las tablas principales antes y después.
- [ ] **Paso 2:** ejecuta el test. Debe FALLAR.
- [ ] **Paso 3:** implementa. La ρ de Spearman es un helper privado que asigna rangos promedio a los empates.
- [ ] **Paso 4:** repite el Paso 2. Debe PASAR.
- [ ] **Paso 5:** pasa Pint y haz commit: `feat: add season:backtest-team-strength`.

---

### Task 8: Frontend (opción B) y limpieza

**Archivos:**
- Modificar: `resources/js/lib/rival-difficulty.ts`, con la dificultad 0–10:
  - `rivalDifficultyLevel(d)`: < 3,5 fácil, < 6,5 media, el resto difícil;
  - `rivalDifficultyBars(d)`: `Math.min(5, Math.max(1, Math.round(d / 2)))`;
  - etiquetas "fácil", "media" y "difícil";
  - colores: difícil `hq-live`, media `hq-amber` y fácil `hq-lime`, con los tintes actuales;
  - un formateador `formatDifficulty(d)` que da "7,4" con coma decimal.
- Crear: `resources/js/components/hq-difficulty-bars.tsx`, con las 5 barras más el número pequeño en mono. El tooltip usa `HqTooltip`: "Dificultad 7,4 / 10 · rival 14.º · fuera", más "· bajas del rival" si `difficulty_components.absences !== 0` (o si el slot trae `absence_adjusted`). Referencia visual: la opción B de `public/_dificultad.html`, que hay que leer sin tocar.
- Modificar para usarlo:
  - `resources/js/components/hq-next-fixtures.tsx` (L76-139);
  - `resources/js/pages/players/next-rivals-list.tsx` (L45-109);
  - `resources/js/pages/teams/fixture-calendar.tsx`: `MatchCell` (L42-68) con las barras al pie de la celda y el número, más C/F de casa/fuera; `AverageBar` (L112-138) con una escala de 0 a 10 en la que la media más baja es más fácil; los umbrales de `averageLevel` pasan a los de nivel; el texto al pie dice "Dificultad según la fuerza del rival (valor de plantilla + rendimiento) y si se juega en casa. 10 = más difícil. Media más baja, calendario más fácil.";
  - `resources/js/components/hq-max-bid-card.tsx`: `RivalRow` sigue mostrando "±d × peso" con `difficulty` (la facilidad −1…+1 que sigue viniendo del backend en `maxBid`), así que solo hace falta revisarlo y aclarar el texto si procede.
- Modificar: `resources/js/types/models.ts`: `NextFixtureSlot` (L36-44) y `FixtureCalendarMatch` / `FixtureCalendarRow` (L456-478), con los campos nuevos y los comentarios de escala.
- Limpieza:
  - borra `LeagueStandings::difficulty()` y su test (`tests/Feature/Services/LeagueStandingsTest.php` ~L42) si ya no queda ningún uso; compruébalo con `grep -rn "difficulty(" app`;
  - todo lo clicable lleva `cursor-pointer`.

- [ ] **Paso 1:** tipos y helpers. `npm run types:check` guiará los usos rotos.
- [ ] **Paso 2:** componentes e integraciones.
- [ ] **Paso 3:** ejecuta `npm run types:check`, `npm run lint:check`, `npx prettier --check` en los archivos cambiados y `npm run build`, y luego `php artisan test --compact tests/Feature/Http`. Si tienes navegador, comprueba en http://comando-lechuga.test el calendario de Equipos (`?vista=calendario`), una ficha de jugador y el listado. No arranques `npm run dev`.
- [ ] **Paso 4:** haz commit: `feat: show the 0–10 difficulty with bars and number`.

---

## Autorrevisión (hecha)

- **Cobertura de la spec:**

  | Spec | Tarea |
  |---|---|
  | §2.1 | Task 1 y Task 2 |
  | §2.2 | Task 3 |
  | §2.3 | Task 1 |
  | §3 | Task 1, Task 2 y Task 3 |
  | §4.1 y §4.2 | Task 4 |
  | §4.3 | Task 5 |
  | §4.4 | Task 6 |
  | §4.5 | Task 8 |
  | §5 | Task 7 |
  | §6 | En cada tarea |

- **Consistencia de tipos:** `DifficultyVariant`, `TeamStrengthInputs`, `TeamStrengthRating::for()`, `MatchDifficultyResult::toArray()` y `rivalEase` se usan con los mismos nombres en todas las tareas.
- **Review Focus:** cada punto tiene su test en la tarea dueña:

  | Punto | Tareas |
  |---|---|
  | 1 | Task 1 y Task 2 |
  | 2 | Task 3 y Task 4 |
  | 3 | Task 2 y Task 6 |
  | 4 | Task 2 |
  | 5 | Task 3 y Task 4 |
