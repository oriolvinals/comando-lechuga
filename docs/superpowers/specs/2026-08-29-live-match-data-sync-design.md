# Sync en vivo de datos de partido desde worldcup26.ir

## Motivación

Fase 2 (`match_data_id` en `teams`/`fixtures`/`players`, ya en `main`) enlazó identidades con worldcup26.ir. Esta fase (3) usa ese enlace para sincronizar datos reales de partido — alineaciones confirmadas, sustituciones, goles/tarjetas y stats reales por jugador — reemplazando también a `SyncCurrentSeasonFixtures` como escritor de `state`/`local_score`/`guest_score`, esta vez con el reemplazo entrando en la misma rama que la retirada (ver [[match_data_linking_phase2_lessons]] / la sección "`SyncCurrentSeasonFixtures` no cambia" del spec de fase 2, donde se revirtió por hacerlo en ramas separadas).

El rediseño de la fitxa de partit para mostrar estos datos es la fase 4, fuera de alcance aquí — esta fase solo sincroniza y persiste.

## Arquitectura

Comando nuevo `season:sync-live-match-data`, programado `everyMinute()` (mismo patrón que `season:sync-live-player-scores`), que:

1. Selecciona los fixtures "en ventana en vivo": de la temporada actual, con `match_data_id` no nulo, `date <= now()` y `date >= now()->subHours(4)`.

   La selección es **por ventana de fecha, no por `Fixture.state`**: en el primer despliegue, `state` nunca ha sido escrito por esta fuente todavía (huevo y gallina), así que basarse en él dejaría el comando sin nada que sincronizar hasta que algo más lo actualizara primero. Las 4 horas replican `FiltersLiveFixtures::RECENTLY_FINISHED_WINDOW_HOURS`, por consistencia con el resto del código de sync en vivo — pero esta comanda no reutiliza ese trait porque su condición de selección es distinta (fecha, no `state`).

2. Para cada fixture en la ventana, llama a `Worldcup26Connector::getEvent($fixture->match_data_id)` — el mismo endpoint que `LinkMatchDataPlayers` ya usa, `GET /get/soccer/esp.1/events/{match_data_id}` — y con la respuesta:
   - Actualiza `state` (derivado de `competitions[0].status.type.name`), `local_score`/`guest_score` (de `competitors[].score`) y `local_formation`/`guest_formation` (de `rosters[].formation`) en el propio `Fixture`.
   - Hace upsert de una fila en `fixture_lineups` por cada entrada de `rosters[].roster[]` cuyo `athlete.id` resuelva a un `Player.match_data_id` conocido.
   - Borra y recrea las filas de `fixture_events` de ese fixture a partir de `competitions[0].details[]`.

3. Si la llamada a un fixture falla (red, 404) o si el estado devuelto por la API pasa a ser `post` (partido terminado) fuera de los 4h... no aplica, ya que el filtro de ventana ya lo cubre — un partido `post` sigue estando dentro de las 4h desde su inicio la mayoría de las veces; si se sale de la ventana antes de terminar (partido con muchas prórrogas/retrasos), simplemente deja de sincronizarse, igual que ya acepta `FiltersLiveFixtures` para el resto de comandas en vivo del proyecto — no es una situación nueva que esta fase deba resolver de otra forma.

### Mapeo de `state`

`competitions[0].status.type.name` de la API (`STATUS_SCHEDULED`, `STATUS_FIRST_HALF`, `STATUS_HALFTIME`, `STATUS_SECOND_HALF`, `STATUS_FULL_TIME`, y variantes que no hemos visto en vivo como retrasos/aplazamientos) se traduce a `FixtureState` con un nuevo método `FixtureState::fromWorldcup26Name(string $name): self`, análogo al `fromFantasyId()` que ya existe para el mapeo del proveedor actual — con `default => self::Scheduled` para cualquier nombre no reconocido, mismo criterio conservador que el mapeo existente.

## Modelo de datos

### `fixture_lineups` (una fila por jugador por partido)

| columna | tipo | notas |
|---|---|---|
| `fixture_id` | FK `fixtures` | |
| `player_id` | FK `players` | |
| `team_id` | FK `teams` | equipo con el que jugó ese partido — igual que `PlayerScore.team_id`, nunca el equipo actual del jugador |
| `starter` | bool | |
| `position` | string | `position.displayName` de la API |
| `jersey` | string | |
| `subbed_in` | bool | |
| `subbed_out` | bool | |
| `counterpart_player_id` | FK `players`, nullable | jugador con el que se produjo el cambio (`subbedInFor`/`subbedOutFor`) |
| `sub_minute` | int, nullable | |
| `stats` | JSON | los ~13 contadores de `stats[]` tal cual vienen de la API, sin seleccionar campos a mano — mismo patrón que `PlayerScore.stats` |

Clave única `(fixture_id, player_id)` — upsert vía `updateOrCreate`, coherente con la idempotencia exigida por correr cada minuto.

### `fixture_events` (una fila por gol/tarjeta)

| columna | tipo | notas |
|---|---|---|
| `fixture_id` | FK `fixtures` | |
| `team_id` | FK `teams` | |
| `player_id` | FK `players`, nullable | null si el evento no resuelve a un jugador enlazado |
| `type` | string | `goal`, `yellow_card`, `red_card`, `penalty_missed` — ver mapeo abajo |
| `minute` | int | |
| `is_own_goal` | bool | |
| `is_penalty` | bool | |

Mapeo del `type` texto libre de la API a nuestro `type` + flags (única fuente de verdad, para no dejarlo ambiguo):

| texto de la API | nuestro `type` | `is_own_goal` | `is_penalty` |
|---|---|---|---|
| `"Goal"` | `goal` | false | false |
| `"Own Goal"` | `goal` | true | false |
| `"Penalty - Scored"` | `goal` | false | true |
| `"Penalty - Missed"` | `penalty_missed` | false | true |
| `"Yellow Card"` | `yellow_card` | false | false |
| `"Red Card"` | `red_card` | false | false |
| cualquier otro texto no visto en la validación | se salta ese evento (no se inserta), se loguea con el texto literal para poder ampliar el mapeo luego | — | — |

Sin clave única — se borran y recrean todas las filas de un `fixture_id` en cada sync, ya que `details[]` es siempre la lista completa y autoritativa de eventos hasta ese momento (no hay endpoint de "solo lo nuevo desde X").

### `fixtures`: dos columnas nuevas

`local_formation`/`guest_formation` (string, nullable) — un único escalar por equipo por partido no justifica tabla propia.

## Manejo de errores

- Fallo de red/404 al pedir `getEvent` de un fixture concreto: se salta ese fixture, se loguea (`Log::warning`) y se lista en el output del comando (`$this->warn(...)`) — no bloquea el resto de fixtures de la ventana. Mismo patrón que `LinkMatchDataFixtures`/`LinkMatchDataPlayers`.
- Entrada de roster/evento cuyo `athlete.id` no resuelve a ningún `Player.match_data_id` conocido: se salta esa fila (no se inventa el `player_id`) y se acumula en un resumen final de "sin resolver" en el output — no aborta el resto del fixture.
- Todo el trabajo de un fixture (fixture + lineups + events) va en una única transacción de BD, para no dejar datos a medias si algo falla a mitad de proceso.

## `SyncCurrentSeasonFixtures` deja de escribir `state`/`local_score`/`guest_score`

Esta vez el reemplazo (`season:sync-live-match-data`, programado `everyMinute()`) entra en la misma rama que la retirada. `SyncCurrentSeasonFixtures` sigue escribiendo el resto de campos (`fantasy_id`, `date`, `team_local_id`, `team_guest_id`) sin cambios — sigue siendo la fuente de qué fixtures existen y cuándo se juegan, solo deja de ser la fuente de su resultado en vivo.

## Fuera de alcance de esta fase

- El rediseño de la fitxa de partit y de la fixture card para mostrar estos datos (fase 4).
- Backfill histórico de `fixture_lineups`/`fixture_events` para partidos ya jugados antes de que este comando existiera — solo sincroniza fixtures dentro de la ventana de las 4h desde su despliegue en adelante.

## Testing

- Migraciones: `fixture_lineups`, `fixture_events`, columnas `local_formation`/`guest_formation` en `fixtures`.
- `FixtureState::fromWorldcup26Name()`: un caso por estado conocido + default para desconocido.
- `season:sync-live-match-data`, con mock del conector:
  - Selecciona correctamente la ventana de fecha (fixture dentro/fuera de las 4h, sin `match_data_id`).
  - Actualiza `state`/`local_score`/`guest_score`/`local_formation`/`guest_formation` del fixture.
  - Hace upsert de `fixture_lineups` (correr dos veces con el mismo payload no duplica filas; un cambio de `stats` entre ejecuciones actualiza la fila existente).
  - Borra y recrea `fixture_events` en cada ejecución, con el mapeo de `type` (tabla de arriba) aplicado correctamente, incluyendo el caso de texto no reconocido (se salta y se loguea).
  - Jugador del roster sin `match_data_id` enlazado: se salta esa fila, no rompe el resto del fixture, aparece en el resumen de sin resolver.
  - Fallo de red en un fixture: se salta, el resto de fixtures de la ventana se procesan igualmente.
- `SyncCurrentSeasonFixturesTest`: se actualiza para reflejar que ya NO escribe `state`/`local_score`/`guest_score` (solo `fantasy_id`/`date`/equipos).
