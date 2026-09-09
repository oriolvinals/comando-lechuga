# Ficha de equipo real y listado de equipos

## Motivación

La app ya tiene fichas para jugadores (`/jugadores/{player}`) y para equipos *fantasy* (`/managers/{seasonManager}`), pero no hay ninguna página dedicada al club real de LaLiga (`Team`) más allá de aparecer como logo/nombre incidental en otras páginas. Este spec añade:

- `GET /equipos` — tabla de clasificación real de LaLiga para la temporada actual.
- `GET /equipos/{team}` — ficha del club: alineación real de una jornada en un campo vertical, su plantilla completa (vista fantasy) y su calendario de partidos jugados y por jugar.

Nota de nomenclatura: `/equipos` fue el nombre histórico de lo que hoy es `/managers` (el equipo fantasy, renombrado hace tiempo — ver memoria `season-team → season-manager`). Esa ruta está libre y es el nombre natural en español para el club real, así que se reutiliza aquí sin conflicto.

## Alcance

Dentro: listado con clasificación real, ficha con sidebar + campo + plantilla + calendario, extracción de `PlayerRow` a componente compartido, entrada de navegación "Equipos", enlaces desde el escudo del club en `players/index.tsx` y `players/show.tsx`.

Fuera (explícitamente no en este spec): flecha de tendencia de posición (▲/▼) en el sidebar — requeriría persistir una foto de la clasificación de la jornada anterior, que hoy no existe; franjas de color de zona (Champions/descenso) en la tabla; pestañas de navegación dentro de la ficha (se descartó, todo en un solo scroll).

## Rutas y estructura

```php
Route::get('/equipos', [TeamsController::class, 'index'])->name('teams.index');
Route::get('/equipos/{team}', [TeamsController::class, 'show'])->name('teams.show');
```

Nuevo `app/Http/Controllers/TeamsController.php`. Nuevas páginas `resources/js/pages/teams/index.tsx` y `resources/js/pages/teams/show.tsx`. `{team}` se vincula por id (route model binding por defecto), igual que `{player}` y `{seasonManager}` — no por `slug`, para ser consistente con el resto de la app aunque `Team` tenga un campo `slug` disponible.

`resources/js/routes/teams.ts` se genera automáticamente (Wayfinder), como el resto de `resources/js/routes/*` — no se escribe a mano.

**Navegación:** nuevo ítem `{ label: 'Equipos', href: teamsIndex().url }` en `main-nav.tsx` (`navItems`), entre "Managers" y "Jugadores".

**Enlaces de entrada:** el escudo+nombre de equipo real que hoy aparece mudo en `players/index.tsx` (`PlayerRow`) y `players/show.tsx` (sidebar) pasa a envolver con `<Link href={teamsShow(player.team.id).url}>`.

## Listado `/equipos`: clasificación real

`TeamsController::index()`:

1. `$season = Season::current()`.
2. `$teams = $season->teams` — las plantillas de LaLiga de esta temporada (relación `Season::teams()` ya existente vía `season_team`).
3. `$fixtures = Fixture::where('season_id', $season->id)->where('state', FixtureState::Finished)->get(['team_local_id', 'team_guest_id', 'local_score', 'guest_score'])`.
4. Por cada equipo, se agregan sobre `$fixtures` (en PHP, colección pequeña — una temporada son ~380 partidos como máximo): partidos jugados, ganados, empatados, perdidos, goles a favor, goles en contra. Puntos = `ganados * 3 + empatados`.
5. Orden: puntos desc → diferencia de goles (`gf - ga`) desc → goles a favor desc → `main_name` asc (desempate final estable, sin depender de reglas de enfrentamiento directo).
6. Un equipo sin partidos finalizados aparece con todos los valores a 0, ordenado por el criterio anterior (en la práctica, al final o agrupado alfabéticamente con otros equipos en su misma situación).

Prop a Inertia: `standings` — array ordenado de `{ position: number, team: Team, played, won, drawn, lost, goalsFor, goalsAgainst, goalDifference, points }`.

**Frontend (`teams/index.tsx`):** tabla con columnas Pos. / Equipo (escudo + nombre, enlaza a `teams.show`) / PJ / PG / PE / PP / GF-GC / DG / Pts. Incluye vista compacta para móvil (colapsando columnas menos críticas), siguiendo el patrón responsive ya usado en `players/index.tsx` (fila desktop vs. fila móvil). Sin franjas de color de zona.

## Ficha `/equipos/{team}`

`TeamsController::show(Team $team, Request $request)`:

### Semana a mostrar

Mismo ajuste aplicado recientemente en `PlayersController::show()` (ver commit `8b3b69c`): `season.current_week` puede ir por detrás de los datos ya sincronizados de una jornada. Se resuelve la semana con `ResolvesRequestedWeek::resolveWeek($request, $season)` (query param `?week=`), pero el **valor por defecto** que recibe ese trait no es directamente `$season->current_week`, sino `max($season->current_week, $latestSyncedWeekForThisTeam)` — la última jornada con al menos un `FixtureLineup` (`starter = true`) para este equipo. Así la ficha puede mostrar de entrada la alineación de una jornada ya jugada aunque el contador oficial de temporada no se haya movido todavía.

### Campo vertical (alineación real de la jornada seleccionada)

1. `$fixture = Fixture::where('season_id', $season->id)->where('week_number', $week)->where(fn ($q) => $q->where('team_local_id', $team->id)->orWhere('team_guest_id', $team->id))->first()`.
2. Si existe, `$starters = FixtureLineup::where('fixture_id', $fixture->id)->where('team_id', $team->id)->where('starter', true)->with('player')->get()`.
3. Se mapea cada `FixtureLineup` al shape que ya consume `HqLineupPitch` (`ManagerLineupPlayerEntry`): `id`, `position` = la posición fantasy del jugador (`player.position` vía `player_seasons`, no el texto libre de `fixture_lineups.position`, para agrupar igual que el resto de la app), `points` = `fantasy_points`, `match_finished` = `$fixture->state === FixtureState::Finished`, `player` = `{ nickname, image, team: { logo, main_name } }`.
4. `tactical_formation` sale de `$fixture->local_formation` o `guest_formation` según si el equipo jugó en casa o fuera (mismo string `"4-3-3"` que ya parsea `HqLineupPitch`).
5. Si no hay `$fixture` para esa jornada, o no hay `$starters`, el frontend muestra un estado vacío ("Alineación aún no disponible"), mismo patrón visual que la ficha de jugador para "aún no jugada" — nunca un error.

**Frontend:** `HqWeekScrollPicker` (sin `weekPoints` — aquí no aplica el modo "puntos del manager fantasy", se usa su coloreado por defecto de progreso de la jornada) encima de `HqLineupPitch`, reutilizados tal cual sin modificar. `onSelectPlayer` puede reutilizar `HqPlayerStatsModal` si ya acepta este shape de entrada (a confirmar en el plan de implementación; si no encaja limpiamente, un simple `router.visit` a `teamsShow` no aplica aquí — se decide al implementar, sin bloquear el resto del diseño).

### Plantilla

`Player::query()->join('player_seasons', ...)->where('team_id', $team->id)->whereNotNull('fantasy_id')->where('status', '!=', PlayerStatus::OutOfLeague)->with('team')->get()` — misma forma de query que `PlayersController::index()` pero sin filtros de posición/manager/estado/búsqueda ni paginación (una plantilla son ~20-25 jugadores, cabe entera). Se le aplica `attachOwnerManager`, `attachCurrentSeason`, `attachRecentScores`, `attachNextFixtures` — los mismos concerns que ya usa `PlayersController::index()`.

**Frontend:** agrupada por posición (Porteros / Defensas / Centrocampistas / Delanteros), un encabezado simple por grupo (sin repetir el badge de posición por fila, ya lo dice el encabezado del grupo). Las filas reutilizan **`PlayerRow`**, extraído de `players/index.tsx` a `resources/js/components/hq-player-row.tsx` (mismo componente, sin cambios de comportamiento) para no duplicar el JSX en ambas páginas.

### Calendario

`Fixture::where('season_id', $season->id)->where(fn ($q) => $q->where('team_local_id', $team->id)->orWhere('team_guest_id', $team->id))->with(['localTeam', 'guestTeam'])->orderBy('week_number')->get()` — **sin** el tope `<= displayWeek` que sí tiene `teamFixtures` en la ficha de jugador: aquí queremos ver también los partidos futuros programados.

**Frontend:** nuevo componente `resources/js/components/hq-team-fixture-strip.tsx`, una tira horizontal (`HqScrollRow`, mismo patrón que la fila de jornadas de `HqPlayerMatchTimeline`) de badges J1..J38, cada uno con el escudo del rival y coloreado por resultado si ya se jugó (verde/gris/rojo para V/E/D, ganador determinado comparando `local_score`/`guest_score` desde la perspectiva de `$team`) o neutro si es futuro. Cada badge es un `<Link>` que navega directo a `fixtures.show` — a diferencia del timeline de la ficha de jugador, aquí no hay un detalle por estadística de jugador que mostrar en la propia página, así que no hace falta el patrón de click-para-seleccionar-y-mostrar-debajo.

### Sidebar

- Escudo grande + nombre del club.
- Bloque "posición": el puesto actual en la clasificación real (mismo cálculo que el listado, pero sólo para este equipo — se puede derivar reutilizando la misma función de agregación del `index`, extraída a un método privado compartido o a un pequeño *concern*, a decidir en el plan) junto con filas PJ/PG/PE/PP y GF-GC/Pts, mismo estilo `label / valor` que el sidebar de `players/show.tsx`. Sin flecha de tendencia (fuera de alcance).
- "PRÓXIMOS": `HqNextFixtures`, alimentado por los próximos 3 partidos programados (`state = Scheduled`) del equipo — consulta directa e inline en el controlador (no vale la pena generalizar `AttachesNextFixtures`, que está pensado para colecciones de `Player` keyadas por `team_id`), mapeada al mismo shape `{ week_number, opponent, is_home }` que ya consume `HqNextFixtures`.

## Testing

**Backend (Pest), nuevo `tests/Feature/Http/Controllers/TeamsControllerTest.php`:**
- Clasificación: orden correcto por puntos/DG/GF; equipo sin partidos jugados aparece con todo a 0; el escudo de cada fila enlaza a `teams.show`.
- Ficha: plantilla agrupada e incluye a todos los jugadores del club (y excluye `out_of_league`); calendario incluye partidos pasados y futuros; selector de semana usa el máximo entre `current_week` y la última jornada sincronizada para este equipo (mismo patrón que el test ya añadido en `PlayersControllerTest` para el bug de `current_week`); jornada sin `Fixture`/`FixtureLineup` → prop de alineación vacía, sin error; equipo inexistente → 404.

**Frontend:** `tsc --noEmit` y `eslint` sobre los archivos nuevos y editados. No hay suite de tests JS en este repo. No se levanta `npm run dev` para verificación visual salvo petición explícita del usuario.

## Riesgos / decisiones abiertas para el plan de implementación

- Si `HqPlayerStatsModal` (o un modal equivalente) encaja limpiamente al pulsar un jugador del campo de la ficha de equipo, o si por ahora el campo es puramente informativo (sin `onClick`) — no bloquea el resto del diseño, se decide al implementar.
- Dónde vive el cálculo de agregación de clasificación (PJ/PG/PE/PP/GF/GC/Pts) para no duplicarlo entre `index()` (todos los equipos) y `show()` (posición de uno solo) — método privado reutilizable en el controlador, o extraído a un *concern* si crece.
