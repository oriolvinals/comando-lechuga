# worldcup26 como fuente principal de jugadores y partidos (fase A)

## Motivación

Hoy el roster (`Player`) lo crea LaLiga Fantasy, y worldcup26 se enlaza *a posteriori* contra ese roster con un matcher de nombres heurístico (`MatchDataPlayerMatcher`) que ya ha demostrado ser frágil — un crash real por `UNIQUE constraint failed: players.match_data_id` al vincular todos los fixtures de golpe, y puntos/stats inconsistentes según si un jugador se buscaba por nombre o por id. Además, los datos reales de partido (`fixture_lineups`/`fixture_events`, fase 3) y los datos Fantasy (`player_scores`, `manager_lineup_players`) viven en tablas separadas con la misma granularidad (un jugador, un partido) pero se mantienen y consultan por separado, duplicando `points`/`stats` entre `player_scores` y `manager_lineup_players`.

Esta fase invierte la fuente de identidad: worldcup26 pasa a ser quien crea el roster (jugadores, vía las alineaciones reales de cada partido) y quien manda en los datos de partido; LaLiga Fantasy pasa a un rol estrictamente de enriquecimiento sobre jugadores que worldcup26 ya conoce, nunca de creación. Esto elimina la clase de bug de matching (dos entradas de roster resolviendo heurísticamente al mismo jugador local) por construcción, y de paso mata la duplicación `points`/`stats` fusionando `player_scores` y `manager_lineup_players` dentro de `fixture_lineups`.

## Alcance

Esta fase es **solo el modelo de datos** — tablas, columnas, quién puede escribir qué. Explícitamente fuera de alcance, para una fase posterior:

- Cómo se sincroniza cada pieza (qué comando corre cuándo, con qué API, en qué orden). El usuario ha señalado que probablemente esta fase permita eliminar o simplificar varios comandos de sincronización existentes (`SyncCurrentSeasonPlayers`, `LinkMatchDataPlayers`, `SyncCurrentSeasonPlayerScores`, `SyncCurrentSeasonManagerLineups`, etc.) — cierto, pero se diseña en su propia fase, una vez el modelo esté asentado.
- Un endpoint de roster completo en worldcup26 — se comprobó en vivo (2026-08-29) que no existe (`teams`, `teams/{id}`, `teams/{id}/roster`, `teams/{id}/athletes`, `athletes`, `athletes/{id}`, `squads/{id}`, `rosters` devuelven todos "Route not found"). El roster de worldcup26 solo puede construirse incrementalmente, un partido a la vez, vía `GetEventRequest` (que ya devuelve la convocatoria completa — titulares + banquillo — de ambos equipos). Esto es una restricción real que la fase de sincronización tendrá que asumir, no algo que resolver aquí.
- Actualizar controllers/servicios que hoy consultan `PlayerScore`/`ManagerLineupPlayer` — se lista el impacto conocido más abajo, pero la reescritura es trabajo del plan de implementación, no de este spec.

## Reset de datos: migración de borrado

En vez de reconciliar los `Player`/`PlayerSeason`/`PlayerScore`/etc. ya creados por Fantasy contra el nuevo modelo worldcup26-first (lo que obligaría a reconstruir la misma lógica de matching heurístico que esta fase busca eliminar), se borran y se reconstruyen desde cero. Es aceptable porque estamos pre-lanzamiento / inicio de temporada, y porque el linking de equipos ya es determinista (tabla 1:1 fija, sin heurística) y el de fixtures también (fecha + equipos).

Migración: `TRUNCATE` (o `DELETE`) de todas las tablas **excepto** `seasons` y `season_managers`. Incluye explícitamente: `teams`, `players`, `player_seasons`, `player_scores`, `player_markets`, `market_players`, `manager_players`, `manager_lineups`, `manager_lineup_players`, `fixtures`, `fixture_lineups`, `fixture_events`. El orden de borrado debe respetar FKs (hijos antes que padres) o desactivar temporalmente la comprobación de FKs durante la migración.

## `Season.match_data_season_slug`

`GetFixturesRequest` (`get/soccer/esp.1/fixtures?status=all&page=N`) no filtra por temporada — cada evento devuelto trae su propio `season: { year, type_id, slug }` (comprobado en vivo: `{"year": 2026, "slug": "2026-27-laliga"}`), lo que sugiere que temporadas distintas pueden venir mezcladas en el mismo listado paginado.

Nueva columna `seasons.match_data_season_slug` (string, nullable). Se rellena a mano en esta fase para la temporada actual: `"2026-27-laliga"`. La fase de sincronización futura la usará para descartar eventos cuyo `season.slug` no coincida, en vez de asumir que todo lo que devuelve el endpoint pertenece a la temporada en curso.

## `Player`

- `fantasy_id` pasa a nullable (hoy es `int` obligatorio). Un `Player` puede existir sin ningún dato Fantasy — worldcup26 lo crea igual, vía la convocatoria de un partido.
- El resto de columnas (`match_data_id`, `nickname`, `status`, `image`, `team_id`) no cambian de forma, pero sí de origen: **worldcup26 crea la fila** (con `match_data_id`, `team_id`, y un nombre provisional derivado de `athlete.displayName`); **Fantasy solo enriquece una fila ya existente** — nunca crea (`nickname` definitivo, `status`, `image`, `fantasy_id`). Si Fantasy conoce un jugador que worldcup26 aún no ha visto en ninguna convocatoria, ese jugador no puede enriquecerse todavía — es un problema de orden de sincronización, explícitamente fuera de alcance de este spec.
- Regla de negocio ya confirmada por el usuario, no nueva pero relevante aquí: **la ficha de jugador solo es accesible para jugadores con datos Fantasy** (`fantasy_id` no nulo). Un `Player` worldcup26-only (sin vincular a Fantasy todavía) puede existir en la base de datos — aparece en alineaciones/eventos de partido — pero no tiene página de ficha.

`PlayerSeason.position` (posición Fantasy, POR/DEF/MED/DEL) no cambia — sigue siendo puramente Fantasy, sin equivalente nuevo a nivel de temporada. La posición real de partido ya existe a nivel de partido (`fixture_lineups.position`, texto libre de worldcup26, fase 3) y no necesita duplicarse en `Player`/`PlayerSeason`.

## Fusión de `fixture_lineups` + `manager_lineup_players` + `player_scores`

`player_scores` y `manager_lineup_players` desaparecen como tablas. Todo lo que aportaban se absorbe en `fixture_lineups`, que ya tiene la granularidad correcta (una fila por jugador y partido) para lo que venía de `player_scores`.

**`fixture_lineups` — columnas nuevas** (además de las que ya tiene de fase 3: `fixture_id`, `player_id` nullable, `team_id`, `starter`, `position`, `jersey`, `subbed_in`, `subbed_out`, `counterpart_player_id`, `sub_minute`, `stats`):

- `fantasy_points` (int, nullable) — antes `player_scores.points`.
- `fantasy_stats` (json, nullable) — antes `player_scores.stats`. Convive con la columna `stats` ya existente sin conflicto: `stats` son los contadores reales de worldcup26 (goles, tiros, faltas...), `fantasy_stats` es el desglose de puntuación Fantasy (`marca_points` y demás claves que ya usa `PlayerScore.stats` hoy). Son dos JSON distintos con distinto origen y distinto propósito, ambos en la misma fila.
- `season_manager_id` (FK nullable a `season_managers`) — antes la relación `manager_lineup_players.manager_lineup_id → manager_lineups.season_manager_id`. Un jugador puede en teoría estar en la plantilla de varios managers de la liga la misma semana; esta columna, al ser un único valor, colapsa a un manager por jugador y partido — igual que hace hoy `FixturesController::show()` de facto (`keyBy('player_id')` sobre `ManagerLineupPlayer`, que ya se queda con uno solo). No es una regresión, es formalizar el comportamiento actual.

**Lo que no se lleva nada:**

- `player_scores.ideal_formation` — se elimina sin sustituto. No se usa en ningún sitio que justifique conservarlo.
- `manager_lineup_players.position` — se elimina sin columna equivalente en `fixture_lineups`. Cualquier vista que hoy agrupe la alineación de un manager por posición (p. ej. una mini-formación en la ficha de manager) pasa a usar `PlayerSeason.position` (la posición Fantasy de temporada del jugador), que no varía según qué manager lo fichó — la fase de implementación deberá localizar y actualizar esos usos.

**Lo que no cambia:** `manager_lineups` (el resumen semanal por manager — `season_manager_id`, `tactical_formation`, `points`, `week_number`) sigue existiendo tal cual. Es una granularidad distinta (manager + semana, no jugador + partido) que no puede colapsar dentro de `fixture_lineups`.

## Impacto conocido en el resto del código (para el plan de implementación, no se resuelve aquí)

Todo lo que hoy consulta `PlayerScore` o `ManagerLineupPlayer` necesita reescribirse contra `FixtureLineup`. Puntos de partida conocidos, sin pretender ser exhaustivo:

- `FixturesController::show()` — ya construye `lineups` desde `FixtureLineup` (fase 3/4); pasa a leer `fantasy_points`/`fantasy_stats`/`season_manager_id` directamente de esa misma fila en vez de hacer join contra `PlayerScore`/`ManagerLineupPlayer`.
- `PlayersController` (ficha de jugador, recent_scores) — hoy recorre `PlayerScore` por jugador; pasa a recorrer `FixtureLineup`.
- `SeasonManagersController` (ficha de manager) — hoy usa `ManagerLineupPlayer` para la alineación semanal de un manager; pasa a filtrar `FixtureLineup` por `season_manager_id` + semana (vía join a `Fixture.week_number`).
- Cualquier cálculo de puntos/ranking que sume `PlayerScore.points` o `ManagerLineupPlayer.points` pasa a sumar `FixtureLineup.fantasy_points`.
- Factories/tests: `PlayerScoreFactory`, `ManagerLineupPlayerFactory` desaparecen; `FixtureLineupFactory` gana los campos nuevos.

## Qué no resuelve esta fase (explícito)

- No rediseña ni elimina ningún comando de sincronización — solo el modelo que esos comandos, en su próxima fase, tendrán que rellenar de otra manera.
- No resuelve el caso borde de un jugador fichado por un manager que luego no aparece en ninguna convocatoria real (lesión de última hora tras el cierre del mercado Fantasy, etc.) — sin fila `FixtureLineup` para ese partido, no hay dónde colgar `season_manager_id`. Se deja para la fase de sincronización.
- No añade ningún dato nuevo de jugador desde worldcup26 más allá de identidad (`match_data_id`, nombre) — se comprobó en vivo que el objeto `athlete` de worldcup26 no trae foto (`jerseyImages` con `href` vacío), fecha de nacimiento, nacionalidad ni altura/peso. La foto sigue siendo responsabilidad exclusiva de Fantasy.
