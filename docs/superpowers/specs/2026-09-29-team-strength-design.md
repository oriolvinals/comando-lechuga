# Fuerza de equipos y dificultad de partido: diseño

**Fecha:** 2026-09-29 · **Rama:** `feature/team-strength`, que se crea cuando se haya mergeado `feature/dazn-estimate`.

Estudio: `C:/Users/Uri/code/comando-lechuga-research/team-difficulty/` (`informe.md`, `final2.mjs` y `final.txt`).

## 1. Objetivo

Sustituir la "dificultad" actual (`LeagueStandings::difficulty`, que solo mira la posición del rival en la tabla) por un **modelo único de fuerza de equipos**, usado **en todas partes** con la misma medida:

- el calendario de Equipos;
- los próximos partidos en la ficha del equipo, las listas de jugadores, la plantilla del mánager y la ficha del jugador;
- la API (`next_fixtures`);
- la puja máxima.

El comparador de jugadores, cuando exista, también lo usará.

**Criterio de éxito:** en el backtest walk-forward (J3–J7, 98 observaciones), la dificultad nueva tiene una ρ (en valor absoluto, con signo negativo) de Spearman con los puntos del partido de ≥ 0,30 (hoy 0,13), con la diferencia de goles de ≥ 0,33 (hoy 0,14) y con los puntos Fantasy de los titulares de ≥ 0,30 (hoy 0,12). Los valores de referencia del estudio son 0,33 / 0,37 / 0,34.

**Decisiones del usuario (2026-09-29):**
- escala lineal;
- escala de **dificultad 0–10, con 10 = muy difícil y 0 = muy fácil** (cambio del usuario del mismo día);
- en el calendario y la ficha del equipo, solo la dificultad general; por posición, en lo que es de un jugador;
- valor de mercado de Fantasy como base;
- sin calendario UEFA manual;
- bajas desde el XI probable de FútbolFantasy o, si no hay, desde el estado del jugador;
- la dificultad de los defensas se calibra con sus puntos Fantasy;
- un comando de recalibración.

## 2. Modelo

Todo se calcula a una **fecha `t`**, usando solo datos anteriores a `t`. Así el mismo código sirve en vivo y en el backtest.

### 2.1 Fuerza de cada equipo en `t`

Los valores se estandarizan entre los 20 equipos de la temporada en esa fecha (z transversal: media 0 y desviación 1). Si la desviación es 0, el z es 0.

1. **Valor de plantilla** `zV`: z de `ln(suma de los 15 valores más altos)` de sus jugadores.
   - Valores: el día más reciente de `player_markets` con `date ≤ t`, con la misma regla que `MaxBidCalculator::referenceDate`.
   - Pertenencia: `players.team_id`.
2. **Rendimiento**, con los partidos de LaLiga `Finished` con `date < t`:
   - `zDG`: diferencia de goles por partido;
   - `zTP`: tiros a puerta a favor menos en contra, por partido, sumando `shotsOnTarget` de `fixture_lineups.stats` por `team_id`;
   - `zPC`: pases clave a favor por partido (`fixtures.local_key_passes` / `guest_key_passes`); si faltan, esos partidos no cuentan para esta media.
   - `rendimientoGeneral = media(zDG, zTP, zPC)`.
   - `ataque = media(zGF, zTP_favor, zPC, z(−tasa de partidos sin marcar))`.
   - `defensa = media(z(−GA), z(−TP_contra), z(−PC_contra))`.
3. **Mezcla**: `w = n / (n + k)`, con `n` igual a los partidos jugados y **`k = 8`**.
   - `fuerzaGeneral = (1 − w)·zV + w·rendimientoGeneral`.
   - `amenazaOfensiva = (1 − w)·zV + w·(0,7·rendimientoGeneral + 0,3·ataque)`.
   - `solidezDefensiva = (1 − w)·zV + w·(0,7·rendimientoGeneral + 0,3·defensa)`.
   - Con `n = 0` (pretemporada o J1) queda `fuerza = zV`.

### 2.2 Dificultad de un partido para el equipo X contra el rival R

`e = −fuerza_R + H·(X en casa ? +1 : −1)`, con **`H = 0,4`**.

| Variante | Fuerza del rival usada | Quién la usa |
|---|---|---|
| `general` | `fuerzaGeneral` | calendario, ficha y listas por equipo, API por defecto |
| `attack` | `solidezDefensiva` (¿le marcas?) | medios y delanteros |
| `defense` | `amenazaOfensiva` (¿te marca?) | porteros y defensas |

**Bajas**, solo en el **próximo** partido de R:

- `a` = parte del valor de los titulares habituales de R (≥ 70 % de los minutos disputados por su equipo en los partidos anteriores) que no jugará. Se considera que no juega si:
  - hay XI probable de FútbolFantasy (`StartProbabilities`) y el jugador no es titular previsto, o su probabilidad es < 30 %; si hay XI confirmado, se usa ese;
  - no hay XI probable y `players.status` es `injured`, `suspended` u `out_of_league`.
- Ajuste: `e += 0,3·z(a)` en `general` y `attack`, **nunca en `defense`**. `z(a)` se calcula entre los equipos que tienen próximo partido.

**Escala:** `dificultad = clamp(5 − 2,5·e, 0, 10)`, redondeada a 1 decimal. **10 es lo más difícil y 0 lo más fácil.** (`e` es la facilidad interna: más alta = más fácil.)

**Escala interna para la puja máxima:** `rivalEase = (5 − dificultad) / 5`, en −1…+1 con +1 = fácil, que es lo que espera hoy `MaxBidCalculator::sportFactors()`. Fuera de la puja, todo usa la dificultad 0–10.

### 2.3 Parámetros

Van en `App\Services\TeamStrengthParameters`, un `final readonly class` con valores por defecto:

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

## 3. Arquitectura

- **`App\Services\TeamStrength`**: `ratingsAt(Season $season, CarbonInterface $at): array<int, TeamStrengthRating>`, con clave el id de equipo.
  - Hace pocas consultas agregadas (valores, partidos y stats de alineación) y memoriza por `season:fecha` en la instancia, como `MaxBidCalculator`.
  - La matemática va en un método estático puro `fromInputs(TeamStrengthInputs, TeamStrengthParameters)`, para testearla y usarla en el backtest.
  - `TeamStrengthRating` (readonly) lleva: `valueZ`, `performanceZ`, `attackZ`, `defenseZ`, `matches`, `general`, `offensiveThreat` y `defensiveSolidity`.
- **`App\Services\MatchDifficulty`**: `for(Fixture $fixture, int $teamId, DifficultyVariant $variant, ?CarbonInterface $at = null): MatchDifficultyResult` y la versión en lote `forMany(...)`.
  - `MatchDifficultyResult` (readonly) lleva `difficulty` (float 0–10, 1 decimal, 10 = difícil), `rivalEase` (−1…+1, solo para la puja), `variant`, `absenceAdjusted` (bool), `rivalPosition` (?int, informativo) y `components` (`{rival_strength, home, absences}`) para el tooltip.
  - Por defecto, `at` es `now()` para los partidos futuros.
  - El ajuste por bajas solo se aplica si el partido es el próximo de ese rival y `at` es "ahora", porque las bajas no tienen histórico.
- **`App\Enums\DifficultyVariant`**: `General`, `Attack` y `Defense`, con `forPosition(PlayerPosition)`. Porteros y defensas → `Defense`, medios y delanteros → `Attack`, y el entrenador o una posición nula → `General`.
- **Sin tablas nuevas**: se calcula al vuelo con memo por petición. Si una página tarda más de 150 ms extra por este cálculo, se añadirá caché (`Cache::remember` por `season:fecha:hora`); queda fuera de este alcance salvo que los tests de rendimiento lo exijan.
- `LeagueStandings::difficulty()` queda **deprecado** y se borra cuando no quede ningún consumidor, dentro de esta misma rama.

## 4. Consumidores

1. **`FixtureCalendar`** (calendario de Equipos):
   - cada partido: `difficulty` (0–10) de la variante `general`, más `rival_position`, que se sigue mostrando;
   - la media de la fila se hace sobre `difficulty`;
   - el orden, de más fácil (media más baja) a más difícil;
   - el texto al pie cambia a "Dificultad según la fuerza del rival (valor de plantilla + rendimiento) y si se juega en casa. 10 = más difícil."
2. **`AttachesNextFixtures`** (ficha del equipo, listas de jugadores, plantilla del mánager y ficha del jugador):
   - por jugador se usa `DifficultyVariant::forPosition($player->position)`;
   - los próximos partidos del propio equipo en su ficha usan `general`;
   - `NextFixtureSlot`: `difficulty` pasa a 0–10 (10 = difícil) y se añaden `difficulty_variant` y `difficulty_components`; se conserva `rival_position`.
3. **`AttachesApiNextFixtures`** (API): en cada entrada de `next_fixtures`, `difficulty` **cambia de significado**: pasa de −1…+1 (+1 = fácil) a 0–10 (10 = difícil). Se añade `difficulty_variant`. El cambio se anota en §7 "Cambios" de `api-docs.md` como cambio incompatible.
   - `api-docs.md` §3.3, las tablas y los ejemplos se actualizan, con una entrada en §7 "Cambios".
   - Un rival que no está en la tabla ya no da `null`, porque la fuerza no depende de la tabla. Solo es `null` si el rival no pertenece a la temporada.
4. **`MaxBidCalculator`**:
   - `queryUpcomingRivals()` usa `MatchDifficulty` a la fecha `$at` (sin bajas en fechas pasadas), con la variante según la posición del jugador y las bajas solo en el primer rival cuando `$at` es hoy;
   - el campo que consume `sportFactors()` es `rivalEase = (5 − dificultad) / 5` (+1 = fácil, como hoy);
   - `sportFactors()` no cambia;
   - `BacktestMaxBid` añade `rivalsWeight` a su rejilla, con 0,15, 0,3 y 0,45.
   - **Los parámetros de la puja no se cambian en esta rama.** Se ejecuta el backtest, se presentan los resultados al usuario junto con el ancla de Koski (~30 M esperado) y solo se retoca con su OK.
5. **Frontend**:
   - `resources/js/lib/rival-difficulty.ts` pasa a trabajar con la dificultad 0–10:
     - niveles: < 3,5 → fácil, < 6,5 → media, ≥ 6,5 → difícil (colores de hoy: difícil rojo, media ámbar, fácil lima);
     - barras: `round(dificultad / 2)`, con un mínimo de 1 y un máximo de 5 (más barras = más difícil);
     - etiquetas: "fácil", "media" y "difícil".
   - Los componentes `hq-next-fixtures.tsx`, `players/next-rivals-list.tsx`, `teams/fixture-calendar.tsx` y `hq-max-bid-card.tsx` (este solo muestra `difficulty × peso`) pasan a consumir la dificultad 0–10.
   - El tooltip dice "Dificultad X,X / 10 · rival N.º Y · casa/fuera", más "· bajas del rival" si se aplicó el ajuste.
   - **Presentación elegida por el usuario (2026-09-29): opción B del mock `public/_dificultad.html`.** Las 5 barras actuales (`round(dificultad / 2)`, más barras = más difícil, coloreadas por nivel) con el número de dificultad pequeño en mono al lado ("7,4"), en los tres sitios: celda del calendario (barras al pie, con el número), "Próximos 3" de la fila de jugador (barras y número bajo el escudo) y lista de próximos rivales de la ficha (gauge de 5 segmentos, número, posición del rival y etiqueta). Casa/fuera: C/F en la celda del calendario e icono casa/avión en los demás. Las bajas del rival, con un icono de persona tachada en el próximo partido. El tooltip, igual en todos: "Dificultad 7,4 / 10 · rival 14.º · fuera", y "Bajas del rival: …" cuando se aplique. El mock es la referencia visual para la implementación.

## 5. Recalibración

Comando `season:backtest-team-strength {--grid}`:

- **Walk-forward** sobre los partidos terminados en los que los dos equipos llevan ≥ 2 partidos previos. Imprime la ρ de Spearman de cada variante con estos objetivos: puntos, diferencia de goles, goles a favor, portería a cero, puntos Fantasy de medios y delanteros titulares, de porteros y defensas titulares, y de todos los titulares. Añade la ρ de la posición en la tabla como referencia.
- Con `--grid`, barre `shrinkK` ∈ {4, 8, 16}, `homeBonus` ∈ {0,2, 0,4, 0,6} y `specificShare` ∈ {0,2, 0,3, 0,5}, y muestra el mejor por objetivo.
- **No aplica nada**: los parámetros se cambian a mano con un commit.
- La calibración de `defense` se lee sobre los puntos Fantasy de porteros y defensas, no sobre la portería a cero.
- Recomendación de uso: cada ~5 jornadas.
- Revisiones pendientes que usan este comando:
  - hacia la J12–J15: una semivida de 8–10 partidos;
  - hacia la J15: si separar de verdad las variantes;
  - hacia la J19: casa/fuera por equipo.

## 6. Tests

1. **`TeamStrength::fromInputs`**: casos pequeños a mano.
   - z con 3 equipos, `w` con n = 0 / 8 / 24, orden de fuerzas y variantes 70/30;
   - desviación 0 → z = 0;
   - pases clave nulos que no cuentan.
2. **Paridad con el estudio:** un fixture JSON con los inputs de la fecha tras la J7 (exportado desde `final2.mjs`) y la tabla del estudio en campo neutral, pasada a dificultad (Barça 10,0, Madrid 9,5 … Málaga 1,8). El servicio reproduce esos valores con una tolerancia de ±0,1.
3. **`MatchDifficulty`:**
   - casa −1,0 y fuera +1,0 de dificultad;
   - bajas solo en el próximo partido y nunca en `defense`;
   - clamp a 0–10;
   - `rivalEase = (5 − dificultad) / 5`;
   - `DifficultyVariant::forPosition`.
4. **Consumidores:** actualizar los tests que hoy fijan la dificultad por posición (`TeamsCalendarTest`, `TeamsControllerTest`, `SeasonManagersControllerTest`, `Api/PlayerSignalsTest`, `MaxBidCalculatorTest` para `rivalsEffect`) con valores del modelo nuevo, construidos con factories. Mantener el test de número de queries del calendario, con un nuevo límite razonable.
5. **API:** `ApiDocsDriftTest` con `difficulty` (0–10) y `difficulty_variant` documentados, y `MaxBidGuardTest` intacto.
6. **Comando de backtest:** smoke test con un mundo pequeño que imprime la tabla y no escribe nada.

## 7. Fuera de alcance

- Semivida o forma reciente, splits de casa/fuera por equipo y cansancio UEFA (ver §5).
- Cambiar los parámetros de la puja máxima sin el OK del usuario.
- Persistir el histórico de fuerzas en una tabla.
