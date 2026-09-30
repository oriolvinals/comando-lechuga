# Premios de fin de temporada

## Decisiones del usuario (2026-09-30)

Cerradas con el usuario. Mandan sobre cualquier otra parte de este documento.

1. **Empates.** Se enseñan como empate: los empatados comparten el primer puesto. El dinero del premio se reparte a
   partes iguales entre ellos.
2. **Sin tope.** Un mánager puede ganar tantos premios como lidere.
3. **El Banquillo de Oro.** La plantilla de cada jornada se reconstruye con el historial completo de actividad (lo
   tenemos desde el día 1). Un test valida la reconstrucción contra una jornada conocida. **No** hay fotos de plantilla
   por jornada.
4. **El Criminal.** Solo cuentan las **compras** (`signing`: mercado, pujas ganadas incluidas) y las **cláusulas
   pagadas** (`buyout`) del mánager. Sobreprecio de cada una = importe pagado − valor de mercado del jugador ese día
   (o el último anterior), con mínimo 0. Nada más: ni ventas ni otros tipos.
5. **El Fichaje del Pueblo.**
   - El dueño del reparto inicial cuenta como primer dueño.
   - Una compra y venta el mismo día suma un dueño.
   - El tiempo con el jugador se cuenta en **jornadas**: es dueño de una jornada quien lo tiene en el cierre de la
     alineación, que es el primer partido del bloque principal de la jornada (`SeasonClock::lineupLock`).
6. **Solo cuentan las jornadas terminadas** (`SeasonClock::finishedWeekNumbers`), en todos los premios por jornada:
   Noche Mágica, La Noche Negra, Rey del Domingo, El Pupas, Matrimonio, El Banquillo de Oro y los tiempos del Fichaje del Pueblo.
7. **La Noche Negra.** El que menos puntos saca en una sola jornada terminada (lo contrario de Noche Mágica). Una jornada
   sin alineación no cuenta para ese mánager. Los empates comparten el primer puesto y se reparten los 5 €.
8. **Acceso desde Inicio.** Es un enlace ancho y sencillo bajo la clasificación, sin avance ni datos del mánager.

## Motivación

Los 70 € del bote de sanciones se reparten en 10 premios (4 de 10 € y 6 de 5 €). El campeón no cobra por serlo. Todo se
decide al acabar la temporada (J38), y ningún premio se cierra antes. Hasta entonces, la página enseña la
**clasificación actual** de cada premio, con los 7 mánagers ordenados de más a menos.

- Mock aprobado: `public/_premios-d.html`, variante **D1 · Lista**. No se commitea.
- Investigación y datos reales de partida: `comando-lechuga-research/premios/PLAN.md` y `probe.php`.

## Alcance

Dentro:
- El cálculo de los 10 premios.
- La página `/premios`.
- El detalle de cada premio.
- El enlace desde Inicio.
- La caché del cálculo y su invalidación tras las sincronizaciones.

Fuera, para más adelante:
- Una orden `season:settle-prizes` que congele los ganadores al acabar la J38 (tabla `season_prize_winners`).
- La API pública (`/api/prizes`). Por ahora no se expone.
- El reparto «si acabase hoy» en euros. El mock aprobado no lo enseña.

## Acceso y ruta

- **No va en el menú.** Se entra desde un **enlace ancho justo debajo de la tabla de clasificación de Inicio**, dentro
  del mismo `HqSection` de `resources/js/pages/home/standings-table.tsx`.
- **Contenido del enlace:**
  - un icono de trofeo en un recuadro dorado;
  - el título «Premios de fin de temporada»;
  - el subtítulo «**70 €** en 10 premios · el campeón no cobra»;
  - el botón «Ver premios →».
- **En 390 px** el botón se queda en la flecha y toda la franja es el enlace.
- La franja tiene hover y `cursor-pointer`.
- **Ruta:** `GET /premios` → `PrizesController@index`, con nombre `prizes.index`. Página Inertia `prizes/index`.
  Wayfinder genera `@/routes/prizes`.
- **Cabecera** con `HqPageHeader`:
  - código «BOTE DE SANCIONES», título «Premios»;
  - una sola línea: «El campeón no se lleva nada: esto va de otra cosa. Clasificación tras la J{n}; se reparten al
    acabar la temporada.»;
  - datos a la derecha: Bote **70 €** (dorado), Premios **10**, Jornada **{n}/38**. `{n}` es la última jornada
    terminada (0 si no hay ninguna).
- Encima de la cabecera hay un enlace de vuelta «← Clasificación» a Inicio.

## La página (D1 · Lista)

### Selector «Soy»

- Una fila de botones, uno por mánager (escudo + nombre corto), con desplazamiento horizontal en móvil.
- Pulsar un mánager lo marca en toda la página: una barra blanca a la izquierda de su fila en las listas, la etiqueta
  «TÚ» junto a su nombre en el bloque del primero y su fila marcada en el detalle.
- **Pulsar el ya marcado lo desmarca** y la página vuelve al estado neutro, sin nadie. También funciona con teclado,
  porque son `<button>` con `aria-pressed`.
- Es preferencia de cada visitante: se guarda en `localStorage` (`premios-me`, con el valor `none` para «nadie»).
  Todas las lecturas y escrituras van en `try/catch`, y la página funciona igual sin almacenamiento.
- Por defecto no hay nadie marcado.

### Grupos

Dos cabeceras de grupo, «10 € · Premios grandes · 4 · 40 €» y «5 € · Premios pequeños · 6 · 30 €». Los premios van en
el orden del enum `SeasonPrize`.

### Fila de premio (una por premio)

Tres columnas en escritorio. En móvil pasan a tres bandas apiladas.

1. **Premio.** El nombre (display, mayúsculas), la etiqueta de € (dorada si es de 10 €, caqui si es de 5 €) y la regla
   en una línea de texto.
2. **El primero.** Un bloque con fondo lima tenue. Lleva el escudo y el nombre del mánager (o la pareja, o el jugador),
   el valor grande en LED lima con su unidad pequeña, y el contexto solo cuando ayuda (ver el mapa).
3. **Los otros 6.** Una lista ordenada en dos columnas de tres: puesto (LED), escudo, nombre corto (con una segunda línea
   pequeña si hace falta) y el valor corto.
   - Un mánager sin valor («no lo tuvo», o cero en recuentos) sale al final: con «–» en el puesto si no tiene valor, y
     con «—» atenuado si su recuento es cero.

- Toda la fila es un botón (`role="button"`, `tabindex="0"`, Enter y Espacio) con hover y `cursor-pointer`. Abre el
  **detalle**.
- **Nada de textos de distancia** («+1 sobre el 2º», «a N del primero»), ni de notas al pie en las filas.

### Empates arriba

- Los empatados **comparten el hueco del primero, uno al lado del otro y del mismo tamaño**, en 2 o 3 columnas iguales
  separadas por una línea fina.
- **No hay sello «Empate».** El valor compartido sale **una sola vez** arriba (p. ej. «9 veces»). Debajo, cada columna
  lleva escudo, nombre corto (con «…» si no cabe) y su propio contexto: su jornada, sus etiquetas o su jugador.
- Con 4 o más empatados solo salen los escudos y el nombre, sin contexto. El detalle da el resto.
- Si nadie tiene valor (> 0), no hay primero: el bloque dice «Nadie todavía» y los 7 salen en la lista.

### Etiquetas de jornada

- **Rey del Domingo** y **El Pupas** enseñan sus jornadas como etiquetas «J3», «J5»… en orden cronológico. Las del Pupas
  van en rojo (`hq-neg`).
- **Tope:** con más de 12 etiquetas se enseñan las **11 más recientes** precedidas de una etiqueta «+N» con las más
  antiguas. El `title` de esa etiqueta lista las ocultas. En un empate, el tope por columna es 5 (4 recientes + «+N»).
- **Todas** las jornadas se ven en el detalle.
- **Matrimonio** enseña su racha como dos etiquetas de inicio y fin («J9 – J31»), más «Sigue» (LED lima) si la racha
  llega a la última jornada terminada.

### Detalle

- Es un diálogo **centrado** en todas las anchuras. Tiene fondo oscurecido y se cierra con Esc, con el fondo o con la
  «×». Mientras está abierto retiene el foco, y al cerrarse lo devuelve a la fila.
- **Contenido:**
  - la etiqueta de €, el nombre y la regla;
  - la clasificación completa de los 7 (puesto, escudo, nombre, «TÚ», el contexto de cada uno y el valor);
  - las notas que aplican: empate arriba («Hoy se repartiría a partes iguales») y el orden de dueños del Fichaje del
    Pueblo.
- **El Fichaje del Pueblo** añade arriba su **pasaporte**, uno por jugador candidato, uno al lado del otro si hay empate:
  - la foto y el nombre del jugador;
  - «N dueños · T traspasos» (y «hoy en el mercado» si está libre);
  - un **sello por dueño distinto** (como mucho 7), en el orden en que lo tuvieron. Cada sello lleva el escudo y está
    algo girado; lleva «×N» si el mánager lo tuvo en varias etapas, y debajo sus jornadas con él;
  - el sello del ganador va en lima;
  - la línea «Se lo lleva {escudo} {mánager}, el que más tiempo lo tuvo (N jornadas).»
- **Nueva regla del proyecto:** cualquier puntuación de un jugador en una jornada que salga en pantalla abre
  `HqPlayerStatsModal` al pulsarla. Aquí aplica a El Banquillo de Oro. Su detalle enseña, por mánager, «el que más dejó»
  (jugador · J · puntos) como botón que abre el modal de esa jornada. El equipo del modal es el del partido
  (`fixture_lineups.team_id`), no el club actual del jugador.

### Móvil (390 px)

- **El primero**, en una línea: escudo y nombre a la izquierda, valor a la derecha. Las etiquetas y el contexto van
  debajo, a todo el ancho.
- **Los otros 6**, en dos columnas de tres.
- **Empates**, en columnas de unos 170 px (dos) o unos 110 px (tres).
- Sin desbordes horizontales (salvo el selector «Soy», que se desplaza). Gutter de 14 px.

## Qué enseña cada premio

| Premio | € | Valor principal | Contexto del primero | En los otros 6 | Detalle (todos) |
|---|---|---|---|---|---|
| Noche Mágica | 10 | Puntos de su mejor jornada («71 pts») | La jornada («J5») | Puntos + jornada pequeña | Puntos · «en la J5» |
| El Atracador | 10 | Cláusulas pagadas | «Su víctima favorita: DUBI ×7» | Recuento | «Más a X (×N)» |
| Rey del Domingo | 10 | Veces primero de la jornada | Etiquetas de jornada (tope 12) | Recuento o «—» | Todas las jornadas |
| El Banquillo de Oro | 10 | Puntos sin alinear | Ninguno | Puntos | «El que más dejó»: jugador · J · pts, que abre el modal |
| El Criminal | 5 | M€ pagados de más (1 decimal) | «El peor: Camello, +23,1 M€» | M€ | Su peor operación |
| La Víctima | 5 | Cláusulas que le pagan | «Su verdugo: DUBI ×7» | Recuento | «Su verdugo: X (×N)» |
| El Pupas | 5 | Veces último de la jornada | Etiquetas rojas (tope 12) | Recuento o «—» | Todas las jornadas |
| Matrimonio | 5 | Racha («23 jornadas seguidas») | Escudo + foto del jugador; «J9 – J31»; «Sigue» | Mánager, jugador (2ª línea), racha | Jugador · J–J · sigue/rota |
| El Fichaje del Pueblo | 5 | **Dueños** del jugador («4 dueños») | Foto y nombre del jugador + «Se lo lleva {escudo} {mánager}» | Jornadas con el jugador (y cuál, si hay empate de jugador); «no lo tuvo» | Pasaporte + tiempos |
| La Noche Negra | 5 | Puntos de su peor jornada («12 pts») | La jornada («J5») | Puntos + jornada pequeña | Puntos · «en la J5» |

- El número va en LED lima con su unidad pequeña al lado. Lo que no es número (jornadas, jugador) va como etiqueta, foto
  o escudo, nunca como frase.
- El contexto solo lo lleva el primero. El de todos está en el detalle.
- **Fichaje del Pueblo con empate de jugador** (dos jugadores con los mismos dueños): los dos jugadores comparten el
  hueco, cada uno con su mánager ganador, y el valor «4 dueños» sale una vez. Los líderes del premio son los ganadores
  de cada jugador.

## Cálculo de cada premio

- Todas las cifras salen de tablas que ya existen. No hay migraciones.
- «Jornadas terminadas» = `SeasonClock::finishedWeekNumbers($season)`.
- En los premios por recuento o por máximo, el orden es de mayor a menor. Los empates comparten puesto (1, 1, 3) y se
  ordenan entre sí por la posición en la liga. Si el mejor valor es 0, no hay líder.

| Premio | Cálculo |
|---|---|
| Noche Mágica | `max(manager_lineups.points)` del mánager en jornadas terminadas. Con dos jornadas iguales, la más antigua. |
| La Noche Negra | `min(manager_lineups.points)` del mánager en jornadas terminadas; sin alineación, esa jornada no cuenta. Con dos jornadas iguales, la más antigua. Gana el valor más bajo (incluido 0). |
| El Atracador | `count(activities type=buyout, source=mánager)`. Víctima favorita: el `target` más repetido. |
| Rey del Domingo | Jornadas terminadas en las que sus puntos son el máximo de la jornada. Si empatan a puntos, cuentan todos. Solo jornadas con al menos 2 alineaciones. |
| El Pupas | Igual, con el mínimo. |
| El Banquillo de Oro | Por jornada terminada W: plantilla del mánager en el cierre de W (`SeasonClock::lineupLock`, `SquadHistory::squadAt`) menos sus jugadores en `manager_lineup_players` de W. Se suman los `fixture_lineups.fantasy_points` (nulos = 0) de esos jugadores en partidos de W. «El que más dejó» es la mayor puntuación suelta. |
| El Criminal | Suma de `max(0, importe − valor)` en `signing` y `buyout` con `source=mánager`. El valor es `player_markets.value` del día de la operación o el último anterior; sin valor, la operación no cuenta. «El peor» es la operación con más sobreprecio. |
| La Víctima | `count(activities type=buyout, target=mánager)`. Verdugo: el `source` más repetido. |
| Matrimonio | La racha más larga de jornadas terminadas **consecutivas** con el mismo jugador en su alineación. Con dos rachas iguales, la más reciente. «Sigue» si acaba en la última jornada terminada. |
| El Fichaje del Pueblo | 1) Los jugadores con más dueños distintos según `SquadHistory` (dueño inicial incluido; una etapa de 0 jornadas también cuenta). 2) De cada uno, las jornadas terminadas que tuvo cada dueño (dueño en `lineupLock` de la jornada). Gana quien más tuvo. |

### Historial de plantillas (`SquadHistory`)

Reproduce, por jugador, sus **etapas** de propiedad a partir de `activities` (`signing`, `sale`, `buyout`, en orden
`occurred_at`, `id`) y de `manager_players`:
- `signing`: la etapa del `source` empieza en `occurred_at`.
- `sale`: la etapa del `source` acaba en `occurred_at` (el jugador va al mercado).
- `buyout`: la etapa del `target` acaba y la del `source` empieza, las dos en `occurred_at`.
- **Dueño inicial:** si el primer movimiento de un jugador es una venta o una cláusula, quien lo cedió lo tenía desde el
  principio (etapa con `from = null`).
- Un jugador de `manager_players` sin actividad es de ese mánager toda la temporada.

La reconstrucción se valida con dos tests: una jornada conocida, con su plantilla esperada, y el estado actual, que debe
coincidir con `manager_players`.

## Arquitectura

- **`App\Enums\SeasonPrize`** (TitleCase, en inglés; las etiquetas visibles siguen en castellano): `BestNight` (Noche
  Mágica), `MostBuyoutsMade` (El Atracador), `SundayKing` (Rey del Domingo), `BenchPoints` (El Banquillo de Oro),
  `MostOverpaid` (El Criminal), `MostBuyoutsSuffered` (La Víctima), `WorstWeeks` (El Pupas), `LongestPartnership`
  (Matrimonio), `MostOwnedPlayer` (El Fichaje del Pueblo), `WorstNight` (La Noche Negra). Tiene `label()`, `amount()`
  (euros), `rule()` e `ranksLowestFirst()` (true solo en La Noche Negra). El orden de los casos es el orden de la página.
- **`App\Services\Prizes\PrizeCalculator`** (interfaz): `rows(Season): list<PrizeRow>`, una fila por mánager de la
  temporada.
- **`App\Services\Prizes\PrizeRow`** (readonly): `seasonManagerId`, `value` (`int|float|null`) y `context` (array).
- **`App\Services\Prizes\PrizeRanking`**: ordena, asigna puestos con empates y saca los líderes.
- **Una calculadora por premio** en `app/Services/Prizes/` con el nombre del caso (`BestNight`, `MostBuyoutsMade`,
  `SundayKing`, `BenchPoints`, `MostOverpaid`, `MostBuyoutsSuffered`, `WorstWeeks`, `LongestPartnership`,
  `MostOwnedPlayer`), más `WeeklyExtremes` (compartida por `SundayKing` y `WorstWeeks`) y `SquadHistory`.
- **Cierre de alineación de los premios:** `SeasonClock::lineupLock($season, $week)`, el primer partido del bloque
  principal de la jornada. Los partidos se agrupan en bloques separados por más de `LINEUP_BLOCK_GAP_DAYS` (3 días); el
  bloque con más partidos es el principal (el más antiguo si empatan). Un partido adelantado o aplazado semanas forma su
  propio bloque y no cuenta, y un partido jugado antes de que acabe el bloque principal de la jornada anterior tampoco.
  `BenchPoints` y `MostOwnedPlayer` lo usan; `firstKickoff` no cambia (escudos y API siguen con él).
- **`App\Services\SeasonPrizeStandings`**: calcula los 10 y los devuelve listos para la página. Cada líder lleva su parte
  en euros (`amount / líderes`), que queda preparada para la orden de cierre. Construye `SquadHistory` una vez y la
  comparte con `BenchPoints` y `MostOwnedPlayer`.
  - **Caché:** `Cache::remember("season-prizes:{season}:{huella}", 60 min)`. La huella
    (`App\Services\Prizes\PrizeDataFingerprint`) resume con recuentos y sumas baratas todo lo que leen los premios:
    jornadas terminadas y sus partidos (fecha y estado), posiciones de los mánagers, `activities` de la temporada,
    `manager_lineups` y `manager_lineup_players` de las jornadas terminadas, `fixture_lineups` de esas jornadas,
    `player_markets` y `manager_players`. Si algo cambia, la clave cambia y se recalcula; si no, se sirve la caché. El
    TTL solo cubre lo que la huella no mira (nombre y foto de los jugadores). No hay listener de invalidación.
- **`PrizesController@index`**: carga el cálculo en caché y los mánagers. «El que más dejó» de El Banquillo de Oro
  lleva `fixture_id` y abre la hoja de la jornada (`useJornadaSheet().openMatch`), sin datos de modal propios.

## Convenciones

- Se cumplen las de `AGENTS.md`:
  - `declare(strict_types=1)`, tipos de retorno y PHPDoc con array shapes;
  - llaves siempre;
  - enums en TitleCase;
  - `vendor/bin/pint --dirty --format agent`;
  - tests Pest con factorías;
  - cadenas que no son enum no nulas y con `''` por defecto (aquí no hay columnas nuevas).
- **Frontend:**
  - se reutilizan `HqPageHeader`, `HqSection`, `HqChannelHeader`, `HqLed`, `EntityImage` + `crestTintStyle`,
    `HqPlayerStatsModal` y el patrón de diálogo de `HqScoringLegendDialog`;
  - tokens `hq-*`;
  - **todo lo que se pulsa lleva `cursor-pointer`**;
  - la página funciona a 390 px.
- El frontend no tiene tests: se verifica con `npm run types:check`, `npm run lint:check`, `npm run build` y el
  navegador.

## Riesgos

- **La reconstrucción de plantillas** depende de que el historial esté completo. Los dos tests de validación y la
  comprobación manual contra la base de datos local (plan, tarea 6) lo cubren.
- **Las cifras cambian con la sincronización.** La caché de 10 minutos y la invalidación lo acotan.
