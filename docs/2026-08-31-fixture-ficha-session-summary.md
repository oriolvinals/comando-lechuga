# Ficha de partido — resumen de la sesión (2026-08-31)

Trabajo hecho sobre `2026-08-30-sync-commands-refactor`, seis commits:

```
f041626 feat: campo/lista toggle and player-token redesign for the fixture ficha
ece20cd fix: pitch line positioning and match-data linking for fixture events
89b9e58 feat: show each team's match-day kit colors in the fixture score header
dc636b9 fix: align pitch mowing stripes to the field and penalty box edges
00af0f8 fix: center the timeline's icon/minute cluster and tighten name spacing
eb806d3 feat: sort the bench by played-first, then position
```

## 1. Toggle campo/lista y rediseño del player-token

- `HqLineupPlayerToken` unificado (`variant="pitch" | "bench"`) usado en
  campo, banquillo y lista — un único origen para el layout del jugador.
- Toggle campo/lista arriba a la derecha de la cabecera del marcador;
  forzado a lista por debajo de `xl` (1280px, no `lg`).
- `HqFixtureLineupList` (nuevo): solo titulares, sin agrupar por
  posición, con tabs de equipo (nombre real, no "Local"/"Visitante")
  para mobile.
- Leyenda estática eliminada; cada icono de evento lleva `title` (hover
  tooltip) en su lugar.
- Insignias de esquina en el campo (sustitución, puntos, eventos
  buenos/malos) todas crecen hacia afuera desde el mismo punto de
  anclaje, no hacia el centro de la foto.
- Nombre del manager en modo campo pasado a `position: absolute` para
  que no afecte la altura/alineación entre líneas del campo.
- Modal de stats de jugador: quitada la posición, añadida la leyenda de
  eventos (`MatchEventIcons`, misma fuente `fantasy_stats`).

## 2. Posicionamiento en el campo

- **Mirroring del lado**: el equipo visitante ataca en dirección
  contraria, así que su "izquierda"/"derecha" se invierte respecto al
  local (bug real: el lateral izquierdo salía arriba en vez de abajo).
- **Líneas de mediocampo dinámicas**: portero/defensa/delantero fijos;
  el hueco entre defensa y delantero se reparte a partes iguales según
  cuántas líneas de medio tenga *esa* alineación en concreto
  (defensivo/mixto/ofensivo — 1, 2 o 3 líneas), no un valor fijo por
  formación.
- Rayas de siega del campo: 16 bandas fijas por porcentaje (no por
  píxel), ancladas al rectángulo de juego real (no al margen exterior)
  para que una raya caiga justo en la línea de medio campo.
- Áreas ajustadas a 12.5% (antes 12%) para que su borde coincida con un
  cambio de raya.
- Borde del rectángulo de juego con color sólido en vez del token
  translúcido, porque ahora tiene rayas detrás.

## 3. Cronología (`HqFixtureTimeline`)

- El grupo icono+minuto no estaba centrado (había espaciador solo a un
  lado, y el minuto iba alineado a la izquierda dentro de su caja) —
  ahora es una sola unidad centrada, con el mismo hueco a ambos lados.
- Autogol (PP) usa la misma insignia que las leyendas del campo/lista
  (borde, `hq-live`, mono) en vez de texto plano.

## 4. Banquillo

- Orden: primero los suplentes que jugaron (por posición/dorsal),
  luego los que no jugaron (mismo criterio) — sin separarlos en
  secciones ni añadir una categoría nueva, solo cambia el orden.

## 5. Vinculación jugador↔match_data en `fixture_events`

- `fixture_events` ahora guarda `match_data_id` y `unresolved_name`,
  igual que ya hacía `fixture_lineups` — `LinkMatchDataPlayers` los
  rellena a los dos cuando se añade una entrada nueva a `PLAYER_MAP`.
- Eventos sin `athletesInvolved` en absoluto (típicamente una tarjeta a
  un entrenador/staff, no a un jugador) ya no se crean en blanco — se
  descartan, porque no hay nombre que mostrar de todas formas.
- Confirmado con datos reales: sin 429 en las dos sincronizaciones
  completas hechas hoy (28 fixtures cada vez).

## 6. Colores del partido

- `fixtures.local_color` / `local_alternate_color` / `guest_color` /
  `guest_alternate_color` (nullable), sincronizados desde
  `competitors[].team.color` / `alternateColor` de worldcup26 en cada
  sync de fixture — **por partido, no por equipo**: comprobado con
  datos reales que el color alterno cambia entre partidos del mismo
  equipo (aunque el color principal se mantiene estable).
- Mostrado como una franja de 80×8px debajo del nombre de cada equipo
  en la cabecera del marcador, mitad `color` / mitad `alternate_color`.

## Pendiente / no resuelto

- `PlayerMarket.fantasy_id` en realidad guarda el `lfpId` de la API de
  mercado de LaLiga Fantasy, que **no** coincide con `Player.fantasy_id`
  (offset consistente por jugador, múltiplo de 1.000.000 — no son datos
  corruptos, es un espacio de ids distinto). No se usa en ninguna
  consulta actual, así que no rompe nada, pero el nombre de columna
  engaña. Decisión: **se deja como está** por ahora.
