# Radar (modo god): balances y cláusulas

## Motivación

En la liga privada, lo que decide un clausulazo es **cuánto dinero tiene cada mánager** y **qué cláusulas se pueden pagar
ahora o dentro de poco**. LaLiga Fantasy no enseña el saldo de los rivales. Este spec añade una página oculta, `/radar`,
que:

- estima el saldo de cada rival como un **rango**;
- usa el **saldo real** de la cuenta conectada;
- suma el valor de cada plantilla para dar un **total**;
- lista las cláusulas abiertas y las que se abren pronto, con quién puede pagarlas.

Mock aprobado: `public/_radar-a.html` (se genera con `comando-lechuga-research/god-mode/radar-a.template.html`). La
investigación y los datos de partida están en `comando-lechuga-research/god-mode/PLAN.md`.

## Alcance

Dentro:
- Snapshots de `teamMoney` (el saldo real de la cuenta conectada) y el historial de cláusulas (subidas exactas).
- Modelo de balance: actividad, premio diario, subidas de cláusula y rango.
- Radar de cláusulas: estado, oportunidad y quién puede pagar.
- La página `/radar` (variante A · Consola), con el chip GOD como acceso.
- Subidas de cláusula manuales (solo god), que mandan sobre la inferencia.
- El test de privacidad.

Fuera:
- Aplicar a los rivales el residuo sin explicar de la cuenta conectada (ver «Residuo»).
- Cualquier dato de esta página en la API pública.

## Acceso: solo modo god

- La ruta es `GET /radar`, con nombre `god.radar`. Sin modo god (`HandleGodMode::isEnabled()`) responde **404**, sin
  revelar que existe. La página lleva `noindex`.
- **El chip GOD de la barra superior pasa a ser el enlace a `/radar`** (`GodChip` en `app-layout.tsx`, en escritorio y
  en móvil). Tiene `cursor-pointer` y hover; en `/radar` lleva `aria-current="page"` y se rellena de ámbar con texto en
  tinta. Fuera del modo god sigue sin mostrarse.
- El enlace es una ruta limpia. El modo god se mantiene gracias a la cookie cifrada `god_mode` de `HandleGodMode`.
- **Toda la página es god**, así que dentro **no hay marco de cinta ámbar** (`hq-god-frame`) ni etiquetas GOD. Es
  estilo HQ normal.

## Privacidad (regla obligatoria)

Balances (estimados, rangos y reales), snapshots de `teamMoney`, premio diario, subidas inferidas, totales y todo el
radar son **privados**:
- solo se sirven en `/radar` y en modo god;
- **ningún endpoint `/api/*` los expone** de ninguna forma, ni directos, ni derivados, ni renombrados;
- **`resources/docs/api-docs.md` no los menciona**, y debe seguir diciendo que la API no tiene el saldo.

Lo vigila `GodPrivacyGuardTest`, con el mismo patrón que `MaxBidGuardTest`:
- recorre todas las rutas GET `api/*` y falla si aparece una clave privada;
- falla si aparece el importe exacto de un snapshot sembrado, aunque el campo tenga otro nombre;
- lee `api-docs.md`.

## Modelo de balance

### Actividad (seguro)

```
actividad = 100.000.000
          + Σ sale.amount            (source = M)
          + Σ buyout.amount          (target = M: le pagaron una cláusula)
          + Σ weekly_prize.amount    (source = M)
          − Σ signing.amount         (source = M)
          − Σ buyout.amount          (source = M: pagó una cláusula)
```

- Cada mánager empieza con 100 M€ y una plantilla inicial **gratis**. La plantilla no genera `signing`, y
  `joined_league` no lleva importe, así que no resta nada.
- El resultado puede ser negativo y **no se recorta a 0**.

### Premio diario (reclamado el 67 % de los días)

- Vale **100.000 €** al día, y **200.000 €** en días de parón.
- Los parones son una **lista fija de rangos inclusivos**, fácil de ampliar. Hoy solo hay uno: `2026-09-21 → 2026-10-04`.
- El usuario estima que se reclama **el 67 % de los días**, así que se suma **0,67 × el premio del calendario** en los
  dos extremos (`ManagerBalances::DAILY_BONUS_CLAIM_RATE`). El calendario (`DailyBonusCalendar`) sigue siendo exacto:
  cuenta cada día desde el `joined_league` del mánager (incluido) hasta hoy. Sin `joined_league`, desde el `start_date`
  de la temporada.
- Comprobación: el saldo real de DUBI subió exactamente 200.000 € entre el 29-09 y el 30-09.

### Subidas de cláusula (subir X cuesta X/2)

LaLiga Fantasy no registra las subidas. El proveedor solo manda por jugador `buyoutClause`,
`buyoutClauseLockedEndTime`, `isShielded`, `shieldedEndDate` y `playerMaster.marketValue`. No hay historial ni fecha de
subida.

**Hacia delante (exacto).**
- Nueva tabla `manager_player_clause_snapshots`: mánager, jugador, cláusula, fin del bloqueo, valor de mercado y
  `captured_at`.
- `season:sync-manager-players`, que se ejecuta cada minuto, escribe una fila **solo cuando cambian la cláusula o el
  bloqueo**.
- Entre dos filas seguidas del mismo mánager y jugador, `salto = nueva − máx(anterior, valor de mercado nuevo)`. Si es
  mayor que 0, es una subida pagada **exacta**, fechada y atribuida a ese mánager, que cuesta `salto/2`.
- La subida automática nunca pasa del valor, así que no se confunde con una subida pagada. Con una cadencia de un
  minuto no se pierden eventos.

**Hacia atrás (inferencia, validada con dos casos que da el usuario).**
- **Titularidad**:
  - Tras una compra, la base es `máx(precio pagado, 1 M)`.
  - En la plantilla inicial, la base es `máx(1 M, 5/3 × valor el día de alta)` y la fecha de inicio es el
    `joined_league` (16 de los 19 pagos de cláusula de jugadores iniciales dan exactamente 5/3 × ese valor, ±1 €). Sin
    historial de mercado ese día, la titularidad se salta.
  - Una venta termina la titularidad.
- **Cláusula automática**: sigue al valor hacia arriba y nunca baja:
  `ref(t) = máx(base, valor máximo de la compra a t)` (`player_markets`).
- **Cuándo se sube**: **siempre el último día, justo antes de que se abra**. Subirla antes es tirar dinero, porque
  la cláusula ya sube sola con el valor. La cláusula justo antes de abrirse es `ref(día del desbloqueo)`, o
  `ref(día anterior)` si el desbloqueo es antes de la actualización diaria del mercado. Se prueban, en orden:
  - el día del desbloqueo (compra + 14 días) y el día anterior;
  - como respaldo, cada blindaje (el momento del blindaje y blindaje + 24 h).

  La subida se atribuye al dueño en el momento del desbloqueo.

  El primer ancla donde `cláusula − ref(ancla)` sea múltiplo de 100.000 € (±1.000) da una **subida segura** de ese
  importe. Si ninguna encaja, es una **subida posible** de `cláusula − ref(desbloqueo)`. Si la cláusula solo sigue al
  valor, no hay subida.
- **Clausulazos**: se usa la cláusula de la víctima en ese momento. Solo un `buyout` **dentro del bloqueo de 14 días de
  la víctima** es una **oferta aceptada entre mánagers**, no un pago de cláusula, y se descarta. Fuera del bloqueo el
  importe pagado *es* la cláusula, aunque sea un total redondo: los mánagers suben las cláusulas a cifras redondas
  (Zubeldia 14 M, Fran García 35.999.999, Dituro 19 M).
- **Casos de validación**:
  - **Koski** (Gauchitos): comprado el 10-09 a 13.765.656; desbloqueo el 24-09; cláusula al desbloquear 18.769.376;
    ahora 34.269.528. Da **+15.500.152** (coste 7,75 M).
  - **Otto** (CID): clausulado el 14-09 a 16.667.688; desbloqueo el 28-09 a las 00:54; cláusula al desbloquear
    17.623.163 (valor del 27-09); ahora 59.623.163. Da **+42.000.000** (coste 21 M), que cuadra con «hasta los 59 M».
  - **Zubeldia** (Duke): comprado a 12 M; DUBI pagó 14 M fuera del bloqueo → subida segura de 2 M de Duke.
  - **Marc Roca** (Duke): valor del 26-09 17.614.010, cláusula 24.314.010 → **+6,7 M** seguros (coste 3,35 M).
- **Límites**:
  - las subidas deshechas con una venta no dejan rastro si no se clausuló al jugador;
  - el umbral «redondo» puede fallar alguna vez: por azar pasa ~2 % por ancla.

**Subidas manuales (autoritativas).** «En el historial de cláusulas debería haber un sitio donde yo manualmente las
añada.»
- Las filas del historial tienen `source`: `sync` (las escribe el sync) o `manual` (las mete el usuario). También
  guardan `raise_amount` y `note`.
- Una entrada manual guarda el jugador, el mánager dueño, la fecha y hora, y la **nueva cláusula** o el **importe
  pagado**. Lo otro se deriva:
  - `subida = nueva − anterior`, o `subida = pagado × 2`;
  - `coste = subida/2`;
  - «anterior» es la última fila del historial antes de ese momento, nunca por debajo de `máx(1 M, precio pagado,
    valor máximo desde la compra)` (en la plantilla inicial, también 5/3 × el valor el día de alta);
  - la fecha tiene que caer cuando el mánager tenía al jugador; si no, se rechaza (así nunca se cuenta dos veces).
- **Mandan sobre lo demás.** En una titularidad con entrada manual:
  - la subida manual cuenta como segura;
  - no se infiere nada;
  - los saltos del sync que acaban en la misma cláusula en un margen de 24 h no se cuentan.

  Así no hay doble conteo, y el resultado alimenta `ManagerBalances`.
- Rutas solo god: `POST /radar/subidas`, `PUT/DELETE /radar/subidas/{id}`. Solo se pueden editar o borrar las filas
  `manual`. Nunca aparecen en la API ni en `api-docs.md`, y el test de privacidad lo cubre.

### Rango, saldo real y residuo

```
base      = actividad + 0,67 × premio diario
pesimista = base − (seguras + posibles)/2
optimista = base − seguras/2
medio     = (pesimista + optimista)/2
```

- **Cuenta conectada**: es el mánager con el snapshot de `teamMoney` más reciente, hoy DUBI. Su saldo real es el último
  snapshot más la actividad posterior, menos la mitad de las subidas seguras posteriores al snapshot. En ese caso
  pesimista = optimista = real.
- **Residuo**: `real − (actividad + premio − seguras/2)` de la cuenta conectada. Se calcula y se guarda
  (`ManagerBalance::residual`) para estudiarlo más adelante, pero **no se aplica a los rivales** ni se muestra: sale de
  un solo snapshot, no tiene causa conocida y es sensible a errores. Sin snapshot, no hay residuo.
- **Valor de plantilla**: la suma de `player_seasons.market_value` de sus jugadores en `manager_players`.
- **Total** = saldo + plantilla. Los rivales lo muestran como rango con «~» delante del punto medio; la cuenta conectada,
  como dato real.

### Snapshots de `teamMoney`

- **Origen**: la respuesta de `GetLeagueTeamRequest`, que `season:sync-manager-players` ya pide cada minuto para cada
  mánager. `teamMoney` solo viene relleno para la cuenta autenticada.
- **Tabla nueva `manager_balance_snapshots`**: `season_manager_id`, `money` y `captured_at`, como mucho una fila por
  mánager y hora.
- **Usos**: el saldo real de la cuenta conectada y su residuo.

### Aproximación de hoy (30-09-2026)

Datos frescos (plantillas y cláusulas sincronizadas), con premio diario reclamado, subidas el día del desbloqueo a X/2
y calibración de +89.000 €/día (M€):

| Mánager | Caja (rango · ~medio) | Plantilla | Total ~ |
|---|---|---:|---:|
| DUBI F.C | **210,0 real** | 156,4 | **366,4** |
| DukeBlack9 | 208,6–217,6 · ~213,1 | 166,3 | ~379,4 |
| Gauchitos F.C | 103,1–141,8 · ~122,5 | 235,6 | ~358,1 |
| Cruza FC | 227,0–245,5 · ~236,3 | 119,8 | ~356,1 |
| Ariobretxa | 199,8–209,1 · ~204,5 | 91,3 | ~295,8 |
| CID F.C | 71,9–82,4 · ~77,2 | 216,0 | ~293,2 |
| planuky | 113,6–118,1 · ~115,9 | 162,6 | ~278,5 |

**Ojo:** en local el registro de actividad llegaba solo hasta el 29-09 a las 21:30, mientras que las plantillas son de
hoy. Faltan las compras de hoy: DUBI pasó de 255,2 a 210 M y CID ganó +25 M de plantilla, así que la caja de quien fichó
hoy está sobrestimada. Hay que ejecutar `herd php artisan season:sync-activity`. La calibración usa el par coherente de
ayer (real 255,2 con el feed completo).

## Radar de cláusulas

- **Estado**, comprobado en este orden:
  1. **En venta**: el jugador está en `market_players`. No se puede pagar mientras está a la venta.
  2. **Blindada**: `shielded` y `shielded_until > now`.
  3. **Bloqueada**: `buyout_clause_locked_until > now`.
  4. **Abierta**: cualquier otro caso.
- **Oportunidad (0–100)**: `round(100 × min(1,2, valor/cláusula)/1,2 × (0,25 + 0,75 × min(1, media/5)))`. Los pesos van
  en `ClauseOpportunityParameters`.
- **Quién puede pagar**: para cada rival (sin contar al dueño ni a la cuenta conectada):
  - **seguro** si el pesimista (o el real) llega a la cláusula;
  - **quizá** si solo llega el optimista;
  - **no** si ni el optimista llega.

## Página (variante A · Consola)

- **Cabecera**: `HqPageHeader` con el título «Radar» y, a la derecha, los recuentos de abiertas, bloqueadas, blindadas
  y en venta, cada uno con su icono de estado.
- **Aviso** en una línea: «Balances estimados: rango pesimista – optimista; solo el tuyo es real.» Al lado, un
  desplegable compacto «Qué no sabemos» con tres puntos:
  - qué subidas son reales;
  - qué días reclama cada uno el premio diario (se cuenta el 67 %);
  - qué es la parte del saldo real que el modelo no explica (no se suma a los rivales).

  Sin notas al pie.
- **Balances**:
  - **Eje común**: una tira con los 7 mánagers, ordenados de mayor a menor, sobre el mismo eje. Los rivales se pintan
    como barra de rango y la cuenta conectada como marca azul. Hay una línea discontinua de referencia «tu total» /
    «tu caja».
  - **Conmutador Total / Caja** (por defecto Total), que cambia el eje y la referencia.
  - Pulsar una fila filtra la tabla por ese pagador.
  - **Tarjetas** (7, alineadas):
    - escudo a 28 px y nombre;
    - **TOTAL** en grande (Doto; «~» y punto medio en los rivales; azul y con `BadgeCheck` en la cuenta conectada);
    - debajo, pequeños, **Caja** (rango, o real) y **Plantilla**;
    - la última fila siempre abajo: «paga X/Y» (abiertas que paga seguro) y los blindajes con `HqShieldCount`.
  - Pulsar una tarjeta filtra la tabla por ese pagador.
- **Cláusulas**:
  - **Filtros**: Paga (select con icono `Wallet`), Dueño (select con `User`), posición (Todas/POR/DEF/MED/DEL), estado
    (Abiertas / ≤72 h / Todas, con los iconos `LockOpen`, `Timer` y `List`) y orden (mejor oportunidad, cláusula más
    baja, mejor media, se abre antes).
  - **Columnas**:
    - Jugador: `HqPositionTag`, nombre, equipo y puntos.
    - Dueño: cuadrado de color y nombre. Es clicable y filtra por dueño.
    - Cláusula, con «cláusula − valor» debajo: gris si está por encima del valor, lima si está igual o por debajo.
    - Valor: el importe y `HqMarketValueDifference` (icono de tendencia y diferencia del día, en lima o rojo como en el
      resto de la web).
    - Media.
    - Oportunidad: barra y número.
    - Pueden pagar: solo rivales, 5 como máximo. Cuadrados de 11 px en el `primary_color` de cada uno: relleno =
      seguro, contorno = quizá, discontinuo = no; más un contador.
    - Estado.
  - **Iconos de estado**, como en el comparador:
    - Abierta: `LockOpen` en lima.
    - Bloqueada: `Lock` en oro con cuenta atrás en vivo.
    - Blindada: `ShieldCheck` en azul con cuenta atrás.
    - En venta: `Tag`.
  - «Ver N más» a partir de 14 filas. Con un pagador seleccionado, la columna «Pueden pagar» pasa a ser «Le queda».
- **Lateral**: «Se abren» (las 10 bloqueadas más próximas, con cuenta atrás) y «Blindados».
- **Subidas conocidas** (bajo la tabla de cláusulas), un bloque compacto:
  - un formulario en una fila con mánager, jugador (filtrado por el dueño), fecha y hora, un conmutador «Nueva cláusula
    / Pagado», el importe, una nota y el botón «Añadir» (o «Guardar» al editar);
  - la lista de entradas: jugador, cuadrado del mánager, fecha, «→ nueva cláusula», «+subida · coste» y los botones
    editar (`Pencil`) y borrar (`Trash2`);
  - los errores de validación en una línea;
  - a 390 px el formulario pasa a 2 columnas.
- **Cuentas atrás en vivo**: «2 d 14 h 03 min», y en la última hora «42 min 07 s». Al llegar a 0 se recargan las props.
- **Colores**:
  - Los escudos pequeños se sustituyen por **cuadrados del `primary_color`** del mánager (khaki si es null), como
    `HqManagerChip`.
  - El escudo real solo aparece en las tarjetas, a 28 px.
- **Interacción y diseño**:
  - Todo lo clicable lleva `cursor-pointer`.
  - Densidad compacta.
  - A 390 px, la tabla pasa a tarjetas y las tarjetas de balance a 2 columnas, sin scroll horizontal.
  - Se reutilizan los componentes `Hq*`.

## Nota sobre `season_managers.value`

Parecía desfasado, pero era la base de datos local sin sincronizar. Después de sincronizar, coincide exactamente con la
suma de la plantilla. El radar usa la suma, que es la misma cifra.

## Dudas abiertas (con el valor por defecto que se aplica)

- **Subidas de DUBI**: dice «no subo mucho». El detector le encuentra 0 seguras y 4 posibles (10,1 M, solo en el extremo pesimista). Si alguna es real, puede meterla a mano.
- **Los +5,5 M sin explicar de DUBI**: se guardan como residuo interno y no se aplican a los rivales.
- **Colores casi idénticos**: DUBI (#2f5fd8) y CID (#3d7dfd) en azul, DukeBlack9 (#7a2fd6) y planuky (#5c1f8a) en
  morado. Por defecto, **la inicial del mánager dentro del cuadrado** allí donde se ven juntos (pueden pagar, eje común).
