# DAZN estimado (v1): diseño

**Fecha:** 2026-09-29 · **Rama:** `feature/dazn-estimate`

La investigación está en `C:/Users/Uri/code/comando-lechuga-research/dazn-predictor/`: `README.md`, `resultado/informe.md` y `blind/baremo_v2_5.md`.

## 1. Objetivo

LaLiga Fantasy publica la nota DAZN (`marca_points`, de 0 a 4) de cada jugador al terminar el partido. Hasta ese momento la app no muestra nada. Queremos:

- mostrar **durante el partido** una estimación de esa nota, claramente marcada como provisional;
- **congelarla** cuando llegue la oficial, para comparar "LaLiga Fantasy: 4 · Comando Lechuga estimó: 3 (−1)".

**Criterio de éxito:** con las stats finales, la estimación acierta la nota exacta en torno al 67 % de las veces y queda a ±1 en el 99 %. Es la precisión medida en prueba ciega sobre J1–J7.

**No se toca:** los puntos Fantasy nunca cambian por la estimación. El KPI "Media DAZN" solo cuenta notas oficiales.

## 2. Baremo v1

En la app este baremo se llama **v1**. Corresponde a la versión **v2.5** de la investigación: la v2 más la portería a cero de porteros y defensas. No usa las `/plays` de worldcup26.

### 2.1 Entradas y convenciones

- **Posición:** `PlayerSeason.position` de la temporada del partido (`goalkeeper`, `defender`, `midfield`, `striker`).
  - Entrenador, jugador sin `PlayerSeason` o fila sin resolver (`player_id` nulo): sin estimación.
- **Stats de Fantasy:** `fixture_lineups.fantasy_stats[clave][0]`, el valor del par `[valor, puntos]`. Si una clave falta, vale 0.
- **Stats de worldcup26:** `fixture_lineups.stats`, lista de `{name, value}`. Si un nombre falta o no es numérico, vale 0.
- **Minutos:**
  - Con `fantasy_stats` presente: `mins_played[0]`.
  - Sin `fantasy_stats` (respaldo): minutos derivados según la sección 2.4.
- **Resto de variables:**
  - `m = min(minutos / 90, 1)`. Con 0 minutos **no hay estimación** (`null`), para que los suplentes que no juegan no muestren un 0.
  - **Portería a cero:** el equipo del jugador lleva 0 goles encajados en el marcador del partido (`local_score` y `guest_score`, con nulo contado como 0). En vivo significa "de momento".
  - **Victoria:** goles del equipo mayores que los del rival (se usa en los dos escenarios; ver tablas).
- **Cálculo:** `raw = base + Σ valor × peso`. Se redondea `raw` a 4 decimales y se aplican los umbrales: `< 0,65` → 0, `< 1,50` → 1, `< 2,25` → 2, `< 3,00` → 3, y el resto → 4. Un valor igual al umbral cuenta en el tramo superior.

### 2.2 Escenario principal: Fantasy más faltas de worldcup26

Se usa cuando la fila tiene `fantasy_stats`.

**Base**

| Portero | Defensa | Medio | Delantero |
|---|---|---|---|
| 0,25 + 1,20·m | 0,80 + 0,55·m | 0,80 + 0,50·m | 0,75 + 0,20·m |

**Pesos**

| Acción | Campo | PO | DF | MC | DL |
|---|---|---:|---:|---:|---:|
| Gol | `goals` | 0 | 1,5 | 1,2 | 1,0 |
| Asistencia | `goal_assist` | 1,5 | 1,0 | 1,0 | 1,0 |
| Pase clave sin gol | `offtarget_att_assist` | 0,6 | 0,5 | 0,4 | 0,4 |
| Tiro | `total_scoring_att` | 0 | 0,2 | 0,2 | 0,2 |
| Entrada al área | `pen_area_entries` | 0 | 0,2 | 0,2 | 0,2 |
| Regate | `won_contest` | 0 | 0,15 | 0,15 | 0,15 |
| Recuperación | `ball_recovery` | 0,05 | 0,1 | 0,1 | 0,15 |
| Despeje | `effective_clearance` | 0,05 | 0,08 | 0,05 | 0,08 |
| Parada | `saves` | 0,2 | 0 | 0 | 0 |
| Penalti parado | `penalty_save` | 0,9 | 0 | 0 | 0 |
| Victoria | resultado | 0,1 | 0 | 0 | 0 |
| **Portería a cero** | marcador | **0,25** | **0,25** | 0 | 0 |
| Falta cometida | wc `foulsCommitted` | −0,3 | −0,15 | −0,15 | −0,15 |
| Amarilla | `yellow_card` | 0 | −0,4 | −0,4 | −0,4 |
| Roja o segunda amarilla | `red_card` + `second_yellow_card` | 0 | −0,5 | −0,5 | −0,5 |
| Penalti fallado | `penalty_failed` | 0 | −0,7 | −0,7 | −0,7 |
| Gol encajado con él en el campo | `goals_conceded` | −0,5 | −0,45 | −0,3 | −0,2 |

### 2.3 Respaldo: solo worldcup26

Se usa cuando la fila aún no tiene `fantasy_stats`. Su precisión es del 59 % exacto y del 97 % a ±1.

**Base**

| Portero | Defensa | Medio | Delantero |
|---|---|---|---|
| 0,05 + 1,75·m | 0,80 + 1,25·m | 0,85 + 1,05·m | 0,85 + 0,70·m |

Además, +0,10 a los jugadores de campo si llevan 30 minutos o más.

**Pesos**

| Acción | Campo wc | PO | DF | MC | DL |
|---|---|---:|---:|---:|---:|
| Gol | `totalGoals` | 0 | 1,5 | 1,2 | 1,0 |
| Asistencia | `goalAssists` | 2,0 | 1,5 | 1,5 | 1,3 |
| Tiro a puerta | `shotsOnTarget` | 0 | 0,2 | 0,2 | 0,2 |
| Tiro fuera o bloqueado | `max(totalShots − shotsOnTarget, 0)` | 0 | 0,07 | 0,07 | 0,07 |
| Falta recibida | `foulsSuffered` | 0 | 0,05 | 0,05 | 0,05 |
| Parada | `saves` | 0,3 | 0 | 0 | 0 |
| Victoria | resultado | 0,2 | 0,2 | 0 | 0 |
| **Portería a cero** | marcador | **0,2** | **0,2** | 0 | 0 |
| Falta cometida | `foulsCommitted` | −0,25 | −0,15 | −0,15 | −0,15 |
| Fuera de juego | `offsides` | 0 | −0,15 | −0,15 | −0,15 |
| Amarilla | `yellowCards` | 0 | −0,35 | −0,35 | −0,35 |
| Roja | `redCards` | 0 | −0,6 | −0,6 | −0,6 |
| Gol encajado con él en el campo | `goalsConceded` | −0,55 | −0,45 | −0,3 | −0,2 |

### 2.4 Minutos derivados, solo para el respaldo

1. **Minuto actual del partido:**
   - `Finished` → 90;
   - `HalfTime` → 45;
   - `FirstHalf` o `SecondHalf` → el entero inicial de `display_clock` (`"67'"` → 67, `"45'+2'"` → 45);
   - cualquier otro estado → 0.
2. **Minutos del jugador:**
   - titular sin cambio: el minuto actual;
   - titular sustituido: `sub_minute`;
   - suplente que entró: minuto actual − `sub_minute`, con 0 como mínimo;
   - suplente que no ha entrado: 0.

### 2.5 Motivos para el tooltip

El motor devuelve, junto a la nota, hasta **4 motivos en castellano**. Se ordenan por el impacto absoluto de la aportación y los minutos van siempre primero, por ejemplo "90 minutos jugados", "1 gol", "3 tiros", "2 faltas", "Portería a cero" o "1 gol encajado".

- No llevan "(resta)" ni cifras de peso.
- Si no hay goles, asistencias ni tarjetas y ninguna otra acción aporta, el motivo es "Sin goles, asistencias ni tarjetas".

## 3. Datos

**Migraciones**

- `fixture_lineups.dazn_estimate`: `unsignedTinyInteger`, nullable. Es la estimación vigente, o la congelada si ya hay nota oficial.
- `fixture_lineups.dazn_estimate_version`: `string`, NOT NULL, default `''`, según la convención de AGENTS.md. Hoy siempre vale `'v1'`.
- `fixture_lineups.dazn_estimate_meta`: `json`, nullable. Guarda `{source, minutes, reasons}` de la misma estimación, para que las vistas no tengan que recalcular ni conocer la posición.
- `fixtures.dazn_published`: `boolean`, default `false`. Pasa a `true` cuando algún jugador del partido tiene `marca_points[1] > 0`.
  - Así la regla "todo el partido pasa a oficial" se evalúa en un único sitio, sin recorrer las alineaciones en cada vista.
  - Una vez en `true` no vuelve atrás.

Se añaden a `$fillable`, casts y docblocks de los modelos, y states `withDaznEstimate()` en `FixtureLineupFactory` y `daznPublished()` en `FixtureFactory`.

## 4. Cálculo y congelación en el sync

- **Servicio:** `App\Services\DaznEstimator`.
  - Firma: `estimate(FixtureLineup $lineup, Fixture $fixture, ?PlayerPosition $position): ?DaznEstimate`.
  - `DaznEstimate` es un `final readonly class` con `points` (int 0–4), `raw` (float), `source` (`'fantasy'` o `'worldcup26'`), `minutes` (int) y `reasons` (`list<string>`).
  - Tiene la constante `VERSION = 'v1'`.
  - Es una función pura sobre sus entradas y no consulta la base de datos. El que la llama pasa la posición.
- **Enganche:** `SyncsMatchData::syncMatchDataForFixtures()` llama a un paso nuevo `refreshDaznEstimates($fixture)` justo después de `fillFantasyScores()`, para cada partido sincronizado. El paso:
  1. Si `fixture.dazn_published` ya es `true`, no hace nada: la estimación está congelada.
  2. Si algún jugador tiene `marca_points[1] > 0`, marca `dazn_published = true` y no recalcula. La estimación guardada en la pasada anterior queda congelada.
  3. Si no, recalcula y guarda `dazn_estimate`, `dazn_estimate_version` y `dazn_estimate_meta` de todas las filas con `player_id`. Carga las `PlayerSeason` de esos jugadores para la temporada del partido en una sola consulta.
- **Frecuencia:** cada 20 s con `season:sync-live-match-data`, desde 90 minutos antes del inicio hasta 4 horas después. Si la nota oficial tarda más, lo cubren `season:sync-current-match-data` (de 4 a 48 horas) y el backfill diario, que comparten el mismo trait.
- **Fallos:** si falla la llamada a Fantasy para un jugador, su fila se queda sin `fantasy_stats` y se estima con el respaldo. Una fila sin posición se queda con `dazn_estimate = null`.

## 5. Presentación: una sola regla para web y API

`App\Services\DaznEstimatePresenter::present(FixtureLineup, Fixture)` es una función pura que solo lee columnas guardadas, sin recalcular. Devuelve:

```
dazn_points           int|null   oficial: marca_points[1] si fixture.dazn_published; si no, null
dazn_estimate         int|null   ver la regla de visibilidad
dazn_estimate_version string     'v1' o '' si no hay estimación
dazn_estimate_reasons list<string>  solo mientras la estimación es provisional (de dazn_estimate_meta)
dazn_estimate_source  'fantasy'|'worldcup26'|null  solo mientras es provisional
```

**Regla de visibilidad de `dazn_estimate`:**

- Si el partido tiene nota oficial publicada: la estimación congelada, que puede ser `null` en partidos antiguos sin relleno.
- Si no la tiene: la estimación guardada, solo si el partido ha terminado o el jugador lleva **15 minutos o más** (`dazn_estimate_meta.minutes`). Si no se cumple, `null`.

`dazn_points` deja de depender de `state === Finished` y pasa a depender de `dazn_published`. En la práctica es lo mismo, y así sigue siendo coherente cuando el partido termina sin nota todavía.

**Dónde se usa:**

1. `FixturesController::presentLineup()`: ficha del partido, con lista, banquillo, vista de campo y modal.
2. `AttachesLineupPlayerScores` (Mánagers y API de mánagers) y `TeamsController`: modal abierto desde Mánagers y Equipos.
3. `PlayersController`: fila por jornada de la ficha del jugador.
4. API: `Api\FixturesController` y `FixtureLineupResource`, `Api\PlayersController::attachScores()` y `PlayerDetailResource`, y las alineaciones de `Api\ManagerController`.

## 6. Interfaz

Sigue el mock validado `public/_dazn.html`, que no se commitea.

- **Estimación provisional:** el logo DAZN con el número, con el pulso `hq-est` (opacidad 1 → 0,45, 1,8 s). Con `prefers-reduced-motion` no hay animación y la opacidad queda en 0,6.
- **Tooltip de la estimación:** usa `HqTooltip`. Contenido:
  - título ámbar "DAZN PROVISIONAL · ESTIMACIÓN";
  - "Aún no es la nota oficial. La calculamos con lo que lleva de partido:";
  - la lista de motivos, con viñetas `·`;
  - pie: "Acierta la nota exacta ~7 de cada 10 veces y casi siempre queda a ±1. LaLiga Fantasy publica la oficial al acabar el partido."
  - Si la fuente es worldcup26, el pie dice en su lugar: "Estimación con datos parciales: menos fiable (~6 de cada 10)."
- **Oficial con estimación congelada:** el número oficial sin pulso. El tooltip "DAZN OFICIAL" lleva "LaLiga Fantasy: N" y "Comando Lechuga estimó: M (±d)", o "✓" si coinciden. Pie: "Nuestra estimación se congeló al publicarse la nota oficial."
- **Componente:** uno nuevo, `HqDaznBadge` (`resources/js/components/hq-dazn-badge.tsx`), que reciben:
  - `hq-lineup-player-token.tsx`: la variante `bench` sustituye su bloque DAZN actual y la variante `pitch` lo añade debajo del nombre, sobre una placa oscura;
  - `hq-player-stats-modal.tsx`: con `HqPlayerStatsEntry` ampliado;
  - `hq-player-match-timeline.tsx`: la celda DAZN, que conserva `daznPointsBadgeClass` para la oficial.
- **Tipos:** `resources/js/types/models.ts` añade los campos de la sección 5 a `FixtureLineupEntry`, `ManagerLineupPlayerEntry` y `PlayerFichaScore`.
- **Media DAZN** (`pages/players/show.tsx`): se mantiene y solo usa oficiales (`dazn_points` o `marca_points[1]` en partidos publicados).

## 7. API y documentación

- **Campos nuevos:**
  - `lineups[]` de `/api/fixtures/{id}`: `dazn_estimate` y `dazn_estimate_version`.
  - `scores[]` de `/api/players/{id}`: los mismos dos campos.
  - Jugadores de `lineup_history[]` en `/api/managers/{id}`: los mismos dos campos. `current_lineup` no cambia.
  - Los motivos no se exponen en la API.
- **`resources/docs/api-docs.md`:**
  - §2.4 "Nota DAZN": explicar la estimación, la versión, los 15 minutos, la congelación y que la oficial siempre manda.
  - Las tablas de los tres endpoints.
  - Una entrada en §7 "Cambios".
- **Tests de documentación:** `ApiWorld` incluye filas con `dazn_estimate`, para que `ApiDocsDriftTest` encuentre los campos documentados.

## 8. Relleno histórico

Comando `season:backfill-dazn-estimates`:

- Para cada partido `Finished` de la temporada activa:
  - calcula las estimaciones que falten (`dazn_estimate` nulo) con las stats finales;
  - pone `dazn_published = true` si hay alguna nota oficial mayor que 0.
- No toca estimaciones existentes, así que se puede repetir sin efectos.
- Se ejecuta a mano una vez después del deploy.

## 9. Tests (Pest)

1. **`DaznEstimator`**, test unitario/feature con casos dorados.
   - Varias actuaciones reales de `blind/actuaciones.json` con la nota esperada de `blind/predicciones_v2_5.json`, en los dos escenarios: portero a cero, defensa con amarilla, delantero con gol, suplente de menos de 30 minutos y segunda amarilla.
   - El cálculo de minutos derivados.
   - Los umbrales exactos: `raw = 1,50` → 2.
   - Que no hay estimación para entrenadores ni para filas sin posición.
2. **Sync** (`SyncLiveSeasonMatchDataTest`):
   - recalcula mientras no hay nota oficial;
   - marca `dazn_published` y congela cuando llega la primera nota mayor que 0, sin recalcular;
   - usa el respaldo de worldcup26 si Fantasy falla.
3. **Web:**
   - `FixturesControllerTest`: nota provisional antes y oficial más congelada después, y la regla de los 15 minutos.
   - `PlayersControllerTest`, `SeasonManagersController` y `TeamsController`: que llegan los campos.
4. **API:** `FixtureShowTest`, `PlayerShowTest`, el test del mánager y `ApiDocsDriftTest`.
5. **Comando de relleno:** que rellena, marca publicado y es idempotente.
6. **Frontend:** `npm run types` y `npm run lint`, o las comprobaciones que tenga el proyecto, sin errores.

## 10. Futuro: pasar a v2 con las `/plays` de worldcup26

El issue https://github.com/rezarahiminia/livescoreFootball/issues/110 pide ids de jugador y de equipo en `GET /get/soccer/esp.1/events/{id}/plays`. Hoy `participants`, `athletesInvolved`, `athleteSourceIds` y `team` vienen **siempre vacíos**, y relacionar a cada jugador por el texto de la jugada es demasiado frágil. Cuando lo resuelvan:

1. **Comprobar el payload.** Descargar las `plays` de un partido reciente y confirmar que `athletesInvolved[].id` (o `participants[].athlete.id`) es el mismo id de atleta que `rosters[].roster[].athlete.id`, que ya guardamos como `fixture_lineups.wc26_id` y `players.wc26_id`.
2. **Tabla `fixture_plays`.** Una fila por jugada, unas 1.500 por partido.
   - Columnas: `fixture_id`, `wc26_play_id` (único), `sequence`, `type` (Pass, Cross, Tackle, Interception, Clear, Aerial, Take On, Dispossessed, Save, Claim…, 44 tipos), `minute`, `period`, `text` NOT NULL default `''`, `player_id` nullable, `wc26_athlete_id`, `team_id` nullable, `x` y `y` nullable, y `payload` json con el resto.
   - Modelo `FixturePlay` con su factory.
3. **Sync.** Un paso más en `SyncsMatchData` para los partidos en vivo:
   - `GET …/events/{wc26_id}/plays?limit=300&page=N` hasta vaciar, unas 5–6 páginas;
   - la API permite 120 peticiones por minuto, así que en vivo hay que traer solo las páginas nuevas (a partir del último `sequence` guardado) y en el backfill paginar entero;
   - upsert por `wc26_play_id`.
4. **Conteos por jugador.** Calcular desde `fixture_plays` los mismos conteos que usó la investigación: `passes`, `accurate_passes`, `touches`, `key_passes`, `tackles`, `interceptions`, `take_ons`, `aerials`, `crosses` y `gk_claims`.
   - Reglas de clasificación: `dazn-predictor/wc26/parse.mjs` y `wc26/v3/feat.js`.
   - Pueden calcularse al vuelo o guardarse en `fixture_lineups.play_counts` json.
5. **Baremo app v2** = investigación v3.5:
   - pesos de `dazn-predictor/wc26/v3/calcular_v3.js` y `blind/baremo_v3.md`;
   - umbrales propios: (ii) 0,70 / 1,60 / 2,45 / 3,20 y (i) 0,65 / 1,50 / 2,25 / 3,05;
   - más el término v3.5: defensa en un 0-0, +0,25 en (ii) y +0,35 en (i).
   - Se implementa como otra estrategia de `DaznEstimator` con `VERSION = 'v2'`.
   - Si el partido tiene las `plays` incompletas (menos de unas 1.000), usa v1.
   - Precisión esperada: 71 % y 70 % exacto.
6. **Versión.** `dazn_estimate_version` distingue v1 y v2 por fila. Las estimaciones congeladas en v1 no se recalculan. Antes de activar v2, revalidarla contra las jornadas jugadas desde J7 comparando con `marca_points`.

## 11. Fuera de alcance

- Mostrar las `plays` en la interfaz.
- Recalibrar pesos automáticamente. Se hará a mano hacia la J15, con las estimaciones congeladas como datos.
- Notificaciones.
