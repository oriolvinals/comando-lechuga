# Rediseño de la fitxa de partit (fase 4)

## Motivación

Fase 3 (ya en `main`) sincroniza datos reales de partido desde worldcup26.ir — alineaciones, formaciones, sustituciones, goles/tarjetas con minuto — en `fixture_lineups`/`fixture_events`. Esta fase (4) es la que estaba deliberadamente aplazada: mostrar esos datos en la fitxa de partit (`resources/js/pages/fixtures/show.tsx`), sustituyendo la actual lista de dos columnas (`TeamColumn`/`PlayerRow`) por un campo táctico horizontal con la alineación real, más un bloque de pestañas (suplentes / datos del partido / cronología), y actualizando el modal de detalle de jugador (`HqPlayerStatsModal`).

Diseñado de forma iterativa en un companion visual (mockups en Artifact) contra datos reales del partido Alavés 1–0 Villarreal, sincronizado a mano para esta sesión. Todo lo descrito abajo está validado visualmente contra ese partido real.

## Fuente de datos: qué sale de dónde

Esta es la decisión más importante de la fase y la que más se discutió — **no toda la página usa la misma fuente**:

- **Campo, banquillo, esquinas buena/mala (gol/asistencia/tarjetas), cronología**: worldcup26 (`fixture_lineups`, `fixture_events`). Es la fuente "de verdad" de lo que pasó en el partido.
- **Puntos, DAZN, badge de posición (POR/DEF/MED/DEL)**: siempre LaLiga Fantasy (`PlayerScore`, `PlayerSeason.position`), en todas partes — campo, banquillo y modal. Worldcup26 no tiene puntuación Fantasy ni concepto de posición Fantasy.
- **Modal de jugador (`HqPlayerStatsModal`)**: excepción explícita — **toda** la rejilla de stats sale de Fantasy (`PlayerScore.stats`, la lista completa `JORNADA_STAT_ORDER` que ya usa hoy), no de worldcup26. Ahí importa explicar la puntuación Fantasy, no el box score real. La única stat que sigue viniendo de worldcup26 dentro del modal es el minuto de entrada/salida (más preciso que lo que da Fantasy).
- **3 iconos que se quedan en Fantasy en todo el resto de la página** (worldcup26 no los tiene, no da atribución de quién provoca/para un penalti): penalti provocado, penalti cometido, penalti parado. El resto de eventos (gol, autogol, asistencia vía `goalAssists`, amarilla, roja, penalti fallado) migra a worldcup26.

## Qué sustituye a qué

`TeamColumn`/`PlayerRow` (la lista de dos columnas actual) **desaparece por completo**, sustituida por: cabecera (sin cambios) → campo táctico → leyenda → pestañas (Suplentes / Datos del partido / Cronología). El modal de jugador se mantiene como componente (se abre al pulsar un jugador, igual que hoy) pero con la rejilla de stats reordenada según la sección de fuentes.

## Cambio de modelo de datos: `fixture_lineups.player_id` nullable

Hoy (fase 3), un jugador del roster de worldcup26 que no resuelve a un `Player` local se salta — no se guarda fila. Eso deja un roster incompleto sin forma de saber dónde iba ese jugador en el campo. Cambia:

- Migración: `fixture_lineups.player_id` pasa a nullable (se mantiene la FK, solo se quita el `NOT NULL`).
- `SyncLiveSeasonMatchData::upsertLineup()` (o el método que resuelva el roster) deja de saltarse la fila cuando el atleta no resuelve — la crea igualmente con `player_id: null`, guardando `team_id`, `starter`, `position`, `jersey`, `stats` (todo lo que sí tenemos, aunque no sepamos quién es).
- Idempotencia: la clave única `(fixture_id, player_id)` no sirve para distinguir varias filas `player_id IS NULL` del mismo fixture. Solución explícita, sin ambigüedad: antes de procesar el roster de un fixture, borrar todas las filas `fixture_lineups` de ese `fixture_id` con `player_id IS NULL`, y luego insertarlas de nuevo (no `updateOrCreate`) según el roster de esa pasada — igual que ya hace `fixture_events`, que tampoco tiene clave única. Las filas con `player_id` conocido siguen usando `updateOrCreate` sobre `(fixture_id, player_id)` sin cambios.
- El resumen de "sin vincular" que ya reporta el comando (`$this->warn(...)`) no cambia de comportamiento — sigue avisando; simplemente ahora el jugador sin vincular también deja rastro en la tabla para poder dibujarlo.

## Campo táctico

Componente nuevo, p.ej. `resources/js/components/hq-match-pitch.tsx`, horizontal, split por la mitad: equipo local en su mitad (ataca a la derecha), equipo visitante en la suya (ataca a la izquierda, espejado). Fondo esquemático oscuro con líneas lima tenues (no césped literal) — encaja con la estética "sala de guerra" ya establecida (`hq-texture`, paleta `--color-hq-*`).

### Posicionamiento (algoritmo, no coordenadas guardadas)

worldcup26 da un `formationPlace` (slot numérico) que **no capturamos** — solo tenemos `position` (texto libre tipo "Center Right Defender", "Left Back"). El posicionamiento es una función determinista en el frontend, no datos guardados:

1. Clasificar cada titular por **línea**, a partir de palabras clave en `position`: contiene "Goalkeeper" → portero; contiene "Back" o "Defender" → defensa; contiene "Midfielder" → centrocampo; contiene "Forward" → delantera. `player_id === null` (hueco sin vincular) usa la línea de su `position` igualmente si la tenemos (si el roster raw la traía) — si no hay ni posición, va a la línea con menos ocupantes respecto al recuento esperado de la formación.
2. Dentro de cada línea, ordenar por pista L/C/R: contiene "Left" → izquierda; contiene "Right" → derecha; si no, centro. Repartir uniformemente en el eje vertical según cuántos haya en esa línea.
3. Profundidad (eje horizontal) fija por línea y equipo: portero cerca de su propia portería, defensa/centrocampo/delantera escalonados hacia el centro; equipo visitante espejado.

Esto es exactamente lo validado en el mockup contra los 5-3-2 / 4-4-2 reales del partido de referencia.

### Token de jugador (campo)

Cada jugador: círculo de foto (`Player.image`, fallback iniciales/ícono) con tres insignias superpuestas — todas **rectangulares** (no círculos), reusando el look de `HqPositionTag` ya existente:

- **Arriba-izquierda, fondo sólido rojo tenue**: eventos "malos" (tarjetas) — solo si hay alguno.
- **Arriba-derecha, fondo sólido lima tenue**: eventos "buenos" (gol, asistencia) — solo si hay alguno. Ambas esquinas se expanden **hacia fuera** (lejos del centro de la foto) si hay varios iconos, nunca se solapan entre sí ni tapan la foto.
- **Abajo-izquierda**: badge de posición Fantasy (POR/DEF/MED/DEL), mismos colores que `HqPositionTag`.
- **Abajo-derecha**: badge de sustitución con el minuto (solo si aplica) — rojo si salió, lima si entró.

Debajo del círculo: dorsal en lima + nombre (`<b>{jersey}</b>{nombre}`, no al revés). Debajo de eso: badge de puntos (colores de `matchPointsBadgeClass`) + badge DAZN (logo real `/images/dazn-logo.png` + número, colores de `daznPointsBadgeClass`).

Un jugador `unresolved` (roster real sin `player_id`) se dibuja como círculo punteado con "?" y sin badges, en la posición que le corresponda por el algoritmo de arriba.

## Leyenda de actividades

Franja justo debajo del campo, antes de las pestañas. Solo cosas que pueden pasar en el partido (eventos), no posiciones ni el badge de sustitución "sale": gol, autogol (PP), amarilla, roja, penalti fallado, entra (min., lima), DAZN.

## Pestañas (debajo de la leyenda)

Tres pestañas, una visible a la vez, mismo patrón visual que el resto de la app (subrayado lima en la activa):

### Suplentes

Dos columnas (local/visitante), lista de banquillo completo. Cada fila: foto pequeña con badge de posición (abajo-izquierda) y, si entró, badge de sustitución con minuto (abajo-derecha) — mismas insignias rectangulares que el campo, a escala reducida. Debajo del nombre: si tiene eventos (p. ej. tarjeta tras entrar), insignias buena/mala en su propia franja con espacio, no superpuestas a la foto (el banquillo tiene sitio de sobra, a diferencia del campo). Debajo de eso, en la misma columna (no a la derecha): badge de puntos + badge DAZN. Un suplente que no jugó se muestra atenuado (opacidad reducida), con "no jugó" bajo el nombre en vez de la línea de eventos.

### Datos del partido

Comparativa de barras por equipo — mismo patrón visual en dos colores (lima=local, azul=visitante) que ya usa la barra de posesión en otros sitios de la app. Fuente: los stats reales por jugador de `fixture_lineups.stats` (worldcup26), **sumados por equipo** en el backend (no hay bloque de stats de equipo guardado, se agrega igual que ya se agregan otras cosas en `FixturesController`). Métricas: tiros a puerta, tiros totales, faltas cometidas, paradas, asistencias, tarjetas amarillas — todas derivables sumando las claves ya presentes en `stats` (`shotsOnTarget`, `totalShots`, `foulsCommitted`, `saves`, `goalAssists`, `yellowCards`).

### Cronología

Lista de eventos (`fixture_events`) ordenados por minuto, alineados a la izquierda (local) o derecha (visitante) según el equipo, con icono + minuto + nombre del jugador (o "sin jugador vinculado" en gris si `player_id` es null).

## Modal de jugador (`HqPlayerStatsModal`)

Cabecera rediseñada con el mismo lenguaje que el campo: foto con insignia buena/mala pegada (fuente: `PlayerScore.stats`, no worldcup26 — ver sección de fuentes) y badge de sustitución (fuente: worldcup26, único dato de esta fase que se queda ahí dentro del modal). Dorsal+nombre debajo, luego posición real de worldcup26 como texto (p. ej. "Lateral izquierdo") junto al badge de posición Fantasy (POR/DEF/MED/DEL) — mostrar ambos es intencional: pueden diferir (ejemplo real validado: un jugador con posición Fantasy "Delantero" jugando de lateral izquierdo real), y ocultar la discrepancia sería peor que mostrarla. Debajo, puntos + DAZN.

Cuerpo: la rejilla completa `JORNADA_STAT_ORDER` (sin cambios respecto a hoy, ver `resources/js/lib/player-labels.ts`) — minutos jugados, goles, asistencias, balones al área, los 4 stats de penalti (provocado/parado/fallado/cometido), paradas, despejes, gol en propia, goles en contra, amarillas, segundas amarillas, rojas, tiros a puerta, regates, balones recuperados, posesiones perdidas. Sin dividir por fuente visualmente — para el usuario es una sola tabla de stats Fantasy, coherente con lo que ya conoce.

## Fuera de alcance de esta fase

- Rediseño de `hq-fixture-card.tsx` (listado de partidos) para mostrar rojas/minuto en vivo — ideas ya anotadas para una fase futura, no se tocan aquí.
- Rediseño de cómo se presenta el once/banquillo en formato lista tradicional (se descartó explícitamente esa idea a favor del campo+pestañas, per decisión del usuario en esta sesión).
- Tabla de stats de equipo propia (guardada, no agregada) — se agrega en caliente por ahora; si algún día se guarda el bloque `competitors[].statistics` de la API, esta pestaña pasaría a leerlo directamente sin cambiar su forma visual.

## Testing

- Migración: `fixture_lineups.player_id` nullable, columna sigue siendo FK.
- `SyncLiveSeasonMatchData`: test de que un atleta no resuelto crea fila con `player_id: null` (no se salta); test de que un segundo sync no duplica esas filas sin `player_id`.
- `FixturesController::show`: test de que la respuesta incluye lineups (con jugador null cuando aplica), events, y el bloque de stats de equipo agregado con los totales correctos a partir de fixtures de prueba.
- Frontend: función pura de posicionamiento (texto de posición → línea + L/C/R) con casos de las variantes reales vistas ("Center Right Defender", "Left Back", "Right Midfielder", "Center Left Forward", sin coincidencia → fallback razonable).
- `HqPlayerStatsModal`: test de que la rejilla sigue siendo 100% `PlayerScore.stats` y que el badge de sustitución usa el minuto de `FixtureLineup`, no de `PlayerScore`.
