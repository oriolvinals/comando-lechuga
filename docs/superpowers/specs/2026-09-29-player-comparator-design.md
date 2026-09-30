# Comparador de jugadores: diseño

**Fecha:** 2026-09-29 · **Rama:** `feature/player-comparator`, que se crea después de mergear `feature/team-strength` (el comparador usa su dificultad 0–10).

**Referencia visual:** el mock final `C:/Users/Uri/code/comando-lechuga-research/comparador/mocks/_comparador-final.html` (copia viva en `public/_comparador-final.html`), con su README (sección "Final" y sus dos pasadas). El mock es la **fuente de verdad visual e interactiva**: la implementación lo reproduce en React con los componentes HQ de la app.

## 1. Objetivo

Comparar **2 o 3 jugadores** para decidir fichar, vender o alinear, con tres vistas intercambiables sobre la misma selección:

- **A · Cara a cara:** una columna por jugador, agrupada por secciones.
- **B · Pistas:** cada métrica sobre la nube de toda la liga.
- **C · Carriles:** jornada a jornada, del pasado a los próximos partidos.

**Decisiones del usuario:**
- Entrada tipo D: casilla "comparar" en el listado y una bandeja fija abajo, desde la que se puede quitar cada jugador con ×.
- Las tres vistas se mantienen, con un selector de 3 iconos.
- En la vista A, "Próximos 3" rehecho y el selector de jugador en un modal centrado.
- Gráficos interactivos.
- Sin el veredicto de D.

## 2. Rutas y estado

- **Ruta web:** `GET /jugadores/comparar?ids=646,745,746&vista=a|b|c`, con nombre `players.compare`.
  - Va en `routes/web.php` **antes** de `/jugadores/{player}`, o bien se pone `->whereNumber('player')` a esa ruta. Si no, `{player}` la captura y da 404.
- **Controlador:** `PlayersController::compare()`, o un `PlayerComparisonController` si el primero crece demasiado.
- **Validación de `ids`:** hasta 3 enteros distintos. Los ids inexistentes o repetidos se descartan en silencio y un `ids` vacío es válido: muestra el comparador con un estado vacío y "Elegir en la lista". `vista` por defecto es `a`.
- **Estado en el cliente:**
  - la selección en curso vive en `localStorage` (`cmp-ids`, envuelto en try/catch) para sobrevivir a la paginación y los filtros del listado;
  - la URL del comparador (`ids` y `vista`) es la que se comparte ("Copiar enlace");
  - la vista elegida también se recuerda en `localStorage` (`cmp-vista`).
  - Cambiar de vista actualiza la URL con `router.get(..., { preserveState: true, preserveScroll: true, replace: true })`, o con `history.replaceState` si no hace falta pedir datos.

## 3. Entrada

**Listado** (`pages/players/index.tsx` y `hq-player-row.tsx`):
- `PlayerRow` recibe una prop opcional `comparable`. Con ella, la fila muestra una casilla "comparar" antes de la foto, con `aria-pressed` y el nombre accesible "Comparar <nombre>".
- La casilla usa `stopPropagation`, porque la fila entera navega a la ficha, y se desactiva al haber 3 elegidos.
- **Dónde aparece la casilla** (con la misma bandeja fija abajo en todas):
  1. el listado de jugadores (`players/index.tsx`);
  2. el **mercado**, en las filas de jugadores en venta;
  3. la **plantilla del mánager** (`season-managers`, `roster-list.tsx`);
  4. la **plantilla en la ficha del equipo** (`teams/show.tsx`, que ya usa `PlayerRow`).
  - Además, el **modal del jugador** (`hq-player-stats-modal.tsx`, abierto desde un partido, Mánagers o Equipos) lleva un botón "Comparar" como el de la ficha.
  - Si alguna de esas listas no usa `PlayerRow`, su fila recibe el mismo componente de casilla (`HqCompareToggle`).

**Bandeja fija abajo** (`HqCompareTray`):
- hasta 3 huecos con foto, nombre y × (`aria-label="Quitar <nombre>"`);
- "Vaciar" y "Comparar N →", desactivado con menos de 2;
- se oculta sin selección;
- en móvil solo se ven las fotos con su ×, y "Vaciar" se oculta por debajo de 480 px;
- quitar un jugador aquí desmarca su fila; el foco pasa al siguiente × o, si no queda ninguno, al buscador;
- hay una región de avisos: "N de 3 en el comparador: …".

**Ficha del jugador** (`pages/players/show.tsx`, cabecera):
- botón "Comparar", que añade el jugador a la selección y abre el comparador si ya hay otro elegido;
- si no, deja la bandeja visible con "Añade otro jugador para comparar".

## 4. Datos (props de Inertia)

`players/compare` recibe:

```
currentWeek: number
view: 'a' | 'b' | 'c'
players: ComparedPlayer[]        // 0–3, en el orden de ids
league: LeagueCloudRow[]         // todos los jugadores listados de la temporada activa
managers: { id, name, logo, color }[]
```

**`ComparedPlayer`**: detalle de cada jugador comparado. Reutiliza lo que ya calcula la ficha (`PlayersController::show`) y la API (`ApiPlayerShapes`), sin duplicar lógica. Campos:
- identidad: `id`, `name`, `image`, `position`, `status` y `team { id, name, short_name, logo }`. `short_name` se añade donde haga falta.
- mercado:
  - `value`, `difference`, `trend`;
  - `value_trend_30d { multiple, value, date }` de `PlayerMarketMetrics::valueTrend`;
  - `market_history`: los últimos 31 días de `PlayerMarket`, como `[date, value]`.
- rendimiento:
  - `points`, `average_points`;
  - `points_per_million { value, rank, ranked }` de `PlayerMarketMetrics`.
- `scores[]` de todas las jornadas de la temporada: `week_number`, rival, casa/fuera, `points`, `minutes`, `starter`, `fixture_state` y los campos DAZN de `DaznEstimatePresenter::present()` (`dazn_points`, `dazn_estimate`, etc.). Es la misma construcción que ya usa la ficha.
- `next_fixtures` ×3: `week_number`, `date`, rival (nombre, logo), `is_home`, `rival_position`, `difficulty` (0–10, variante según la posición del jugador) y `difficulty_components`, del modelo de fuerza de equipos. `AttachesNextFixtures` gana `date`.
- `next_start` de `StartProbabilities::forPlayersNextFixture`: probabilidad, confirmado, obsoleto y fecha de recogida.
- propiedad:
  - `owner { id, name, logo, color }`;
  - `clause { amount, locked_until, is_locked, shielded, shielded_until, purchase }`, con el formato de `Api/ManagerController`, que se extrae a un helper compartido;
  - `listing { sale_price, bids, expires_at, seller }` de `MarketPlayer`.

**`LeagueCloudRow`**: una por jugador listado, unos 556.
- Campos: `id`, `name`, `image`, `position`, `team_short`, `owner_id`, `points`, `average_points`, `ppm`, `start_probability`, `value_trend_30d`, `value` y `difference`.
- Sirve a la vez para las nubes de B, la búsqueda del modal y la tarjeta al pasar el ratón.
- Pesa unos 20 KB con gzip.
- **Cálculo:** `App\Services\LeagueCloud::rows(Season)` en consultas en lote, con `PlayerMarketMetrics` y `StartProbabilities` en lote. Se cachea con `Cache::remember("league-cloud:{season}:{fecha-hora}", 15 min)`, porque cargar el `valueTrend` de toda la liga es caro.
- Rangos, mediana y "supera al X %" se calculan en el cliente sobre `league`, igual que en el mock, filtrando por población:
  - "toda la liga" (`points > 0` en puntos, media y Pts/M€; `start_probability != null` en titularidad; `value_trend_30d > 0` en subida);
  - "su puesto" (las posiciones de los comparados).

**DAZN:**
- **"Media DAZN" (A y C) solo cuenta notas oficiales** (`dazn_points` no nulo), como el KPI de la ficha.
- En las celdas de jornada de C, una jornada en juego o sin publicar muestra la estimación con `HqDaznBadge` (pulso y tooltip), igual que en el resto de la app.

## 5. Vistas

Se reproducen tal cual el mock. El detalle de cada fila está en el contrato de datos que se resume abajo.

**Comunes:**
- Cabecera con "← Jugadores", el título, "Copiar enlace" y el **selector de vista de 3 iconos**: control segmentado, `role="tablist"`, flechas, Inicio y Fin. Los nombres se ven desde 1100 px; por debajo, solo el icono con tooltip, y al lado el nombre de la vista activa.
- **Un tooltip interactivo compartido** (`HqChartTooltip`), con posición fija y dentro de la pantalla. Funciona con ratón, foco y toque.
- **Resaltado de jugador**: `data-hl-slot` atenúa a los demás al 30 %.
- Colores por hueco (1/2/3), iguales en todas las vistas.
- Estado vacío si hay menos de 2 jugadores.

**A · Cara a cara:**
- Secciones:
  - **Mercado:** Valor, Hoy, 30 días ×, gráfico de evolución interactivo.
  - **Rendimiento:** Puntos, Media, Media DAZN oficial, Pts/M€ con rango, Minutos y % posibles.
  - **Forma:** últimas 5 jornadas (3 en móvil) y Últimas 3 · puntos · minutos.
  - **Calendario:** Titularidad J{CW} y Próximos 3.
  - **Propiedad:** dueño y cláusula, o mercado, o Libre.
- **Ganador por fila:** el único mejor valor, subrayado en lima; con empate, ninguno. Cada sección cuenta las filas ganadas y la cabecera de columna muestra el total.
- Cabeceras de jugador fijas.
- **Próximos 3** (rehecho en el mock):
  - resumen "DIFICULTAD ALTA · rival medio 6.º · 2 en casa";
  - un indicador con la misma escala en todas las columnas;
  - las tres jornadas alineadas entre columnas.
  - Usa la **dificultad 0–10 del modelo nuevo**, con las barras de la opción B elegida. Gana la **media más baja**.
- **Selector de jugador en un modal centrado** (`<dialog>` con `showModal`):
  - con foco atrapado, Esc, clic en el fondo y la tecla `/`;
  - al cerrar, el foco vuelve al botón que lo abrió;
  - sugiere 6 jugadores del mismo puesto con el valor más cercano, y busca en `league`.
- Gráfico de evolución: una sola parada de tabulación, ←/→, AvPág/RePág de 7 días, Inicio/Fin, y cada día se anuncia a lectores de pantalla.

**B · Pistas:**
- 6 pistas: Puntos, Media, Pts/M€ (raíz), Titularidad J{CW} (0–100), Subida 30 días (log) y Valor (raíz, sin "mejor").
- Conmutador de población: toda la liga o su puesto.
- Cada pista muestra la nube de la liga, la mediana y los marcadores de los comparados con "N.º de N" y "supera al X %".
- Tarjeta al pasar el ratón por la nube: qué jugador es y su rango; hacer clic lo añade a la comparación (si hay hueco).
- Teclado: una parada, ↑/↓ cambia de pista y ←/→ de jugador. Los puntos de la liga no reciben foco.
- Tarjetas "Lo que no cabe en una pista": Últimas 3, Próximos, DAZN y Propiedad.

**C · Carriles:**
- Eje: J1 … J(CW−1), la columna HOY, las 3 próximas jornadas y la columna de resumen.
- Selector de métrica: Puntos, DAZN o Minutos.
- **Celdas pasadas:** valor por tramo, escudo del rival, barra de minutos marcada si fue titular y "mejor de la jornada".
- **Celdas futuras:** escudo, casa/fuera, barras de dificultad y, en la primera, el % de titularidad.
- Al pasar por una jornada se marca en todos los carriles. El tooltip va junto a la celda en escritorio.
- Teclado con roving tabindex: ←/→ cambia de jornada y ↑/↓ de carril.
- Una fila de mercado y propiedad por jugador, con minigráfico interactivo.

**Implementación del frontend:**
- `resources/js/pages/players/compare.tsx` más `resources/js/components/compare/` (`view-switch`, `tray`, `picker-dialog`, `chart-tooltip`, `view-a`, `view-b`, `view-c`, `value-chart`, `derive.ts`).
- Los derivados (`starts`, `mins`, `daznAvg`, `last3`, `winner`, niveles…) van en `derive.ts` como funciones puras.
- Sin librerías nuevas de gráficos: SVG propio, como el mock.
- Se respeta `prefers-reduced-motion`.

## 5b. Veredicto (solo en modo god)

**Decisión del usuario (2026-09-29):** el veredicto de la dirección D se incluye, pero **solo en modo god**, con la misma puerta que la puja máxima: `?god_mode=<GOD_MODE_KEY>`, reutilizando el mecanismo existente.

- **Sin la clave**, no se calcula, no se envía en las props y no aparece nada.
- **Con la clave**, el comparador muestra encima de la vista activa un bloque "Veredicto" con tres pestañas: **Fichar**, **Vender** y **Alinear J{CW}**. Cada pestaña muestra:
  - el jugador recomendado;
  - un motivo de una línea por jugador;
  - "Por qué": 4–5 filas de evidencia, con el mejor subrayado en Fichar y Alinear, y la peor señal en rojo en Vender.
- **Puntuación:** se porta tal cual la del mock D (`generator/src/d.html`, `lensDef`), con los valores normalizados entre los comparados:
  - **Fichar:** `(se puede fichar ? 1 : −2) + 0,9·ppm + media + 0,6·subida 30 d + 0,4·calendario fácil`.
    - "Se puede fichar": está en el mercado o su cláusula está abierta.
    - "Calendario fácil": dificultad media baja de los próximos 3.
  - **Vender:** `1,2·baja hoy + 0,7·calendario difícil + titularidad baja + 0,8·mala forma (últimas 3) + 0,6 si la tendencia es negativa`.
  - **Alinear:** `(no juega ? −5 : 0) + 1,4·titularidad + 0,8·rival fácil + forma (últimas 3) + 0,6·Media DAZN oficial`.
  - Con la dificultad nueva 0–10, "fácil" es la dificultad más baja, al revés que en el mock, que usaba −1…+1 con +1 = fácil.
- **Dónde se calcula:** en el cliente, en `derive.ts` (`verdict(lens, players)`), porque usa los mismos datos que ya reciben las vistas. Lo único que exige modo god es la prop `godMode: true`, que el controlador solo pone con la clave correcta.
- **Tests:** la prop `godMode` es `false` sin clave o con una clave incorrecta, y `true` con la correcta. En el frontend, `verdict()` es una función pura que se revisa en la review.

## 6. Tests

- **PHP:**
  - `compare` con 0, 2 y 3 ids, con ids inválidos o repetidos, más de 3 ids, y la ruta que no choca con `/jugadores/{player}`;
  - forma de las props y el número de consultas acotado;
  - `LeagueCloud` (poblaciones, caché) y el helper de formato de la cláusula;
  - la Media DAZN solo con oficiales.
- **Frontend:**
  - `npm run types:check`, `lint:check` y `format:check`;
  - si el proyecto añade tests de JS, `derive.ts` es el candidato; hoy no los hay.
- **Verificación manual en el navegador**, sin arrancar `npm run dev` (el usuario lo arranca): escritorio y 390 px, las tres vistas, la bandeja y la paginación, y teclado.

## 7. Fuera de alcance

- El veredicto fuera del modo god.
- Un endpoint de API para comparar (descartado por el usuario: las IAs ya consultan `/api/players/{id}`).
- Comparar más de 3 jugadores.
