# Refactor de comandos de sincronización (fase de syncing)

## Motivación

La fase anterior (worldcup26-as-primary-source) rehízo el modelo de datos: `fixture_lineups` absorbió `player_scores`, `manager_lineup_players` pasó a resolver puntos vía `fixture_id`, y varios comandos que escribían en tablas eliminadas se borraron sin sustituto (explícitamente diferido a esta fase). Esta fase cierra ese hueco: define, comando a comando, quién sincroniza qué, con qué fuente de datos, y con qué frecuencia — de modo que el modelo de datos ya construido se rellene de verdad.

Diseñado de forma iterativa y en español con el usuario, comando por comando (no de una vez), confirmando cada fuente de datos contra la API real de worldcup26.ir en vivo antes de asumir nada.

## Principio general de reparto de fuentes

No hay una regla única "worldcup26 primero" ni "Fantasy primero" para todo — depende de dos cosas, comprobadas en vivo para cada dominio de datos:

- **¿worldcup26 tiene un listado propio y barato de esto?** Equipos y partidos salen gratis de la misma llamada a `get/soccer/esp.1/fixtures` (paginada, sin coste por entidad) — ahí worldcup26 puede ser la fuente principal sin penalización. Jugadores **no**: no existe ningún listado de plantillas en worldcup26 (comprobado varias veces en vivo — `/teams`, `/teams/{id}/roster`, `/athletes`, etc. devuelven todos "Route not found"), la única forma de verlos es entrando partido a partido en `get/soccer/esp.1/events/{id}`, con cientos de llamadas por temporada. Por eso los jugadores se quedan como hoy: los crea Fantasy, worldcup26 solo enlaza `match_data_id` por nombre.
- **¿worldcup26 tiene el dato en absoluto?** worldcup26 no da número de jornada (`week_number`) en ningún sitio (comprobado en vivo, ni en el evento ni en el listado) — por eso `Fixture` sigue creándose desde Fantasy pese a que técnicamente podría venir de worldcup26 igual que los equipos. worldcup26 tampoco da fotos de jugador (comprobado en fase anterior) — por eso las fotos siguen siendo 100% Fantasy.

## Comando 1: `SyncCurrentSeasonTeams` (reescrito)

Pasa a ser worldcup26-primero, sustituyendo su lógica actual (100% Fantasy):

1. Recorre `get/soccer/esp.1/fixtures` (todas las páginas), filtrando cada evento por `event.season.slug === Season::current()->match_data_season_slug` (el endpoint no filtra por temporada, puede mezclar varias).
2. Extrae los equipos únicos de `competitors[].team` de esos eventos.
3. Hace upsert de `Team` **por `match_data_id`** (nueva clave de creación): `name`, `short_name` (de `shortDisplayName`).
4. En la misma pasada, rellena `fantasy_id` usando el mapa hardcodeado ya existente (`fantasy_id => worldcup26_id`, invertido: `worldcup26_id => fantasy_id`) — el mismo mapa que ya se usó en la fase anterior para `LinkMatchDataTeams`.
5. Después, en el mismo comando: recorre el listado de equipos de Fantasy, empareja por `fantasy_id` (ya rellenado en el paso 4), y enriquece — nunca crea — `main_name`, `slug`, `logo`. **El logo se queda 100% de Fantasy** aunque worldcup26 también trae uno (`team.logo`, URL real) — no hay necesidad de montar descarga de assets nueva cuando ya funciona bien con Fantasy.

**`LinkMatchDataTeams`** (comando creado en la fase anterior) se elimina — queda redundante, el mapa se usa directamente aquí.

**Migración:** `teams.match_data_id` pasa a `NOT NULL` — ya no es opcional, siempre estará presente en el momento de creación (igual que `fantasy_id` pasó a nullable en la fase anterior por la razón inversa).

## Comando 2: `SyncCurrentSeasonWeek`

Sin cambios.

## Jugadores (`SyncCurrentSeasonPlayers`, `LinkMatchDataPlayers`, `SyncCurrentSeasonPlayerPhotos`)

Sin cambios en ninguno de los tres, decisión explícita tras evaluar la alternativa:

- `SyncCurrentSeasonPlayers` sigue creando `Player` desde Fantasy, como hoy.
- `LinkMatchDataPlayers` sigue enlazando `match_data_id` recorriendo los partidos ya vinculados y comparando nombres con `MatchDataPlayerMatcher` (sin tocar), como hoy — sigue siendo necesario, es la única forma de saber qué jugador de worldcup26 corresponde a cada `Player`.
- `SyncCurrentSeasonPlayerPhotos` sigue trayendo fotos de Fantasy, como hoy — worldcup26 no tiene fotos (confirmado en la fase anterior).

**Por qué no se invierte (worldcup26-primero) como equipos:** no existe ningún listado de jugadores en worldcup26 — la única forma de verlos es entrando partido a partido, con un coste que crece con la temporada (cientos de llamadas), a diferencia de equipos (20, gratis desde el listado de partidos ya usado para otra cosa). Se evaluó generalizar `MatchDataPlayerMatcher` para poder usarlo también al revés (sus 4 reglas de comparación de nombres asumen que el lado ya-en-base-de-datos tiene el nombre corto de Fantasy y el externo el nombre largo de worldcup26 — invertido, 2 de las 4 reglas dejarían de funcionar bien) — descartado junto con la idea de invertir jugadores, no hace falta tocar el comparador.

## Comandos 4-7: `SyncCurrentSeasonActivity`, `SyncCurrentSeasonStanding`, `SyncCurrentSeasonMarket`, `SyncCurrentSeasonPlayerMarkets`

Sin cambios en ninguno — puramente datos de Fantasy sin ningún equivalente en worldcup26 (actividad de liga, clasificación, mercado).

## Partidos: `Fixture`, `FixtureEvent`, `FixtureLineup`

### `Fixture` — sin cambios

Se evaluó pasar `Fixture` a worldcup26-primero (igual que equipos, ya que también sale gratis del mismo listado) pero se descartó: worldcup26 no da `week_number` en ningún sitio (comprobado en vivo), y las 38 jornadas completas ya vienen garantizadas desde Fantasy tal como funciona hoy. `SyncCurrentSeasonFixtures` sigue creando `Fixture` desde Fantasy; `LinkMatchDataFixtures` sigue enlazando `match_data_id` por fecha+equipos, sin cambios en ninguno de los dos.

### `FixtureEvent` / `FixtureLineup` — tres niveles de sincronización

Los tres reutilizan la misma lógica de fondo (eventos, alineaciones, relleno de `fantasy_points`/`fantasy_stats`, nombre de jugador no vinculado) contra `get/soccer/esp.1/events/{match_data_id}`, difiriendo solo en qué partidos cubren y cada cuánto corren:

1. **`SyncLiveSeasonMatchData`** (ya existe, cada minuto) — partidos en vivo o recién terminados (ventana ya existente, `FiltersLiveFixtures`/`RECENTLY_FINISHED_WINDOW_HOURS`). Se amplía con las dos cosas nuevas de abajo.
2. **`SyncCurrentSeasonMatchData`** (nuevo, cada ~15 min) — partidos terminados en las últimas 48 horas pero ya fuera de la ventana "en vivo", para pillar datos que worldcup26 publica con retraso (correcciones de stats, VAR, etc.). Ventana elegida por quedar claramente cubierta por el backfill diario (comando 3) para cualquier cosa más antigua, sin necesidad de que este comando recorra toda la temporada cada 15 minutos.
3. **`SyncSeasonMatchDataBackfill`** (nuevo, diario a medianoche) — repasa **todos** los partidos ya jugados de la temporada, como red de seguridad completa frente a cualquier dato que se haya quedado atrás en los dos anteriores.

**Dos cosas nuevas en la lógica compartida de los tres:**

- **`fixture_lineups.unresolved_name`** (columna nueva, string nullable): cuando un jugador del roster de worldcup26 no resuelve a un `Player` local, hoy su nombre (`athlete.displayName`) se usa solo para el aviso por consola y se descarta. Se guarda aquí para que la ficha del partido pueda mostrar el nombre real en vez de "No vinculado".
- **`fantasy_points`/`fantasy_stats` en `fixture_lineups`**: se rellenan consultando las puntuaciones en vivo de Fantasy (mismo tipo de llamada que usaban los comandos `SyncLiveSeasonPlayerScores`/`SyncLiveSeasonPlayerScoreStats` eliminados en la fase anterior) y cruzándolas por jugador ya resuelto — esto es lo que sustituye de verdad a esos comandos eliminados, ahora integrado aquí en vez de en comandos aparte, para que partido y fantasy se actualicen siempre juntos, en la misma pasada.

## Comandos 9-10: `SyncCurrentSeasonManagerPlayers`, `SyncCurrentSeasonManagerLineups`

Sin cambios en ninguno de los dos. `SyncCurrentSeasonManagerLineups` ya se corrigió en la fase anterior (resuelve `fixture_id` vía el roster real del jugador en `FixtureLineup`, no vía su equipo actual — evita el problema de un jugador traspasado a mitad de temporada).

## Qué no resuelve esta fase (huecos aceptados, no bugs)

- Un jugador del roster de worldcup26 que no resuelve a ningún `Player` local se queda sin `fantasy_points`/`fantasy_stats` para siempre en ese partido (aunque ahora sí se ve su nombre real) — no hay forma de saber a qué jugador de Fantasy corresponde.
- Un jugador que aparece en un partido de worldcup26 antes de que Fantasy lo haya sincronizado se queda "no resuelto" hasta que Fantasy lo sincronice y `LinkMatchDataPlayers` vuelva a correr — se autocorrige solo, no es un hueco permanente.
- El mapa hardcodeado de equipos (`fantasy_id => worldcup26_id`) necesita actualizarse a mano si algún año cambian los equipos que suben/bajan de categoría.
- `ManagerLineupPlayer.fixture_id` puede quedarse `null` un rato si el manager fija su alineación antes de que `FixtureLineup` tenga datos de ese partido — se autocorrige en la siguiente pasada de `SyncCurrentSeasonManagerLineups` (cada 5 min), sin intervención.
- El horario/orden exacto en `bootstrap/app.php` (qué corre cada cuánto, en qué orden, para que todo encaje bien partiendo de una base de datos vacía) se deja para el plan de implementación, no se fija aquí.
