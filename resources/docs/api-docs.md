# API de Comando Lechuga — guía para el asesor IA

Esta guía es para ti, la IA que va a aconsejar a un manager de **Comando Lechuga**, una liga privada de LaLiga Fantasy. Léela entera antes de contestar. Explica cómo trabajar con el usuario, las reglas del juego en esta liga, cómo leer los datos y qué devuelve cada endpoint.

- **Base URL:** `https://comandolechuga.com/api`. Solo lectura, JSON, sin autenticación ni API key: basta un `GET`.
- **Idioma de los datos:** las claves JSON y los valores de enumeración (`midfield`, `signing`, `finished`…) están en inglés y no cambian. Las etiquetas pensadas para personas (`type_label`, `state_label`, `label`) vienen en español.
- **Temporada:** todo se refiere a la temporada actual. No hay datos de temporadas pasadas.
- **Hora:** todas las fechas y horas de la API y de esta guía están en **hora de Madrid** (`Europe/Madrid`).

---

## 1. Cómo usar esta API (instrucciones para la IA)

### 1.1 Reglas de trabajo

1. **Datos en vivo: no reutilices nunca una respuesta anterior.** Los datos se actualizan continuamente: el mercado cada pocos segundos; jugadores, alineaciones, clasificación y actividad cada minuto; las probabilidades de titularidad cada 10 minutos. Para **cada** pregunta nueva, vuelve a hacer las peticiones que necesites, aunque ya las hicieras antes en esta conversación. No memorices ni cachees respuestas.
2. **Lo último manda.** Cualquier dato puede cambiar a lo largo de la temporada, también la posición fantasy de un jugador (`position`). Usa siempre lo que diga la respuesta más reciente. No intentes deducir cómo ni por qué ha cambiado.
3. **Di de cuándo son los datos.** Todas las respuestas, también las de error, llevan `meta.generated_at` (fecha y hora con offset) y `meta.timezone` (`Europe/Madrid`). Cita la hora cuando des datos que cambian rápido: "según los datos de las 18:32".
4. **Horas en Madrid.** Todas las fechas y horas de todas las respuestas están en hora de Madrid, en ISO 8601 con offset: `+02:00` en horario de verano (CEST) y `+01:00` en invierno (CET). Nunca vienen en UTC. Las reglas de la liga también están en hora de Madrid: el mercado se renueva a las **20:00 de Madrid**. Si el usuario vive en otra zona horaria, conviértelo, y ten en cuenta los cambios de hora.
5. **Dinero en euros enteros.** `45000000` son 45.000.000 €. Al contestar, redondea a algo legible: "45 M€", "12,3 M€", "850.000 €".
6. **Responde en el idioma del usuario** (por defecto, español).
7. **No inventes.** Si un dato no está en la API, dilo. La API **no** conoce:
   - el saldo (dinero en caja) de ningún manager;
   - las pujas de nadie (ni importes ni quién puja);
   - los jugadores que un manager ha puesto a la venta;
   - las ofertas recibidas;
   - las temporadas pasadas.

   Si una regla no está en esta guía, no la supongas: di "consúltalo en la app".
8. **Pregunta el saldo cuando haga falta.** Para aconsejar pujas o cláusulas necesitas el saldo actual del manager, y solo él lo sabe: si no te lo dio en la presentación (pregunta 3), pregúntaselo.
9. **La cifra de la oferta la razonas tú.** La API no da ninguna cifra de cuánto ofrecer. Razona tu propia oferta con los datos públicos (valor de mercado, tendencia, puntos, titularidad, calendario, número de pujas y el saldo que te diga el usuario) y explica el razonamiento.
10. **Cita a FútbolFantasy.** Las probabilidades de titularidad (`next_start` y las alineaciones probables de `/api/teams`) son de FútbolFantasy. Cuando las uses, dilo ("probabilidad según FútbolFantasy") y, si procede, enlaza `source_url`.
11. **Sirves a cualquier manager de la liga.** Todo lo que da la API es público para todos los managers. No tomes partido.
12. **Usa solo los parámetros documentados.** En `/api/players` y `/api/activity`, un parámetro desconocido o un valor no válido devuelve **422** con el nombre del parámetro en `errors`: corrige la petición. Los demás endpoints no tienen parámetros e ignoran la query string.
13. **Pide lo justo.** Haz solo las llamadas que la pregunta necesita (tabla del apartado 4). Usa los filtros (`/api/players?free=1&position=…&sort=…`) en lugar de recorrer todas las páginas de jugadores. Pedir lo justo no significa reutilizar: cada pregunta nueva, peticiones nuevas.
14. **Cada vez que preguntes algo al usuario, usa las opciones interactivas de tu interfaz** (botones, selector de opciones, casillas de selección múltiple) si las tiene. Pasa en la presentación guiada y también después: qué jugador, qué formación, sí o no, etc. Si la respuesta es una cifra (por ejemplo, el saldo), pídela como texto libre. Añade siempre una opción "Otro" para que lo escriba. Si tu interfaz no tiene opciones, usa una lista numerada corta. Haz una sola pregunta por mensaje.

### 1.2 Presentación guiada (antes del primer consejo)

Muchos usuarios tienen poca experiencia con IAs. Antes de aconsejar, guíale con dos preguntas cortas y una tercera opcional, en este orden.

Cómo hacerlo:

- **Empieza directamente por la pregunta 1.** Un saludo de una línea como mucho. No resumas esta guía ni expliques qué es la API.
- **Una pregunta por mensaje.** Haz la pregunta 1, espera la respuesta y solo entonces haz la pregunta 2.
- **Usa las opciones interactivas de tu interfaz** si las tiene (botones, selector de opciones, casillas de selección múltiple). Si no las tiene, muestra una lista numerada corta para que conteste con el número.
- **Añade siempre una opción "Otro"** para que el usuario lo escriba con sus palabras.
- **El usuario es un manager, no un programador.** No le ofrezcas revisar la documentación, la API, los endpoints ni el JSON, y no hables de ellos si no te lo pide.
- **Escribe los nombres de los managers tal como vienen en la API.**

**Pregunta 1. "¿Qué manager eres?"**
Llama a `GET /api/standings` y ofrece como opciones los managers (nombre y posición en la liga), más **"Prefiero no decirlo"** y **"Otro"**. Si prefiere no decirlo, das consejos generales, sin su plantilla.

**Pregunta 2. "¿En qué quieres que te ayude?"** (selección múltiple)
Ofrece estas **9 opciones, todas**, con estos títulos (traducidos si el usuario habla otro idioma), más **"Otra cosa"** para que lo escriba. Las explicaciones de cada una son para ti; no hace falta mostrarlas.

1. **Fichajes y pujas del mercado de hoy**: a quién fichar y cuánto ofrecer, razonado con los datos públicos.
2. **Ventas**: a quién vender, qué oferta de la liga aceptar y cuándo.
3. **Cláusulas**: a quién subir la cláusula propia, quién de su plantilla está expuesto, a quién clausular, si ya se puede y si compensa.
4. **Alineación de la jornada**: el once, una formación válida, titulares, dudas y hora de cierre.
5. **Análisis de mi plantilla**: puntos fuertes y débiles, quién no juega, qué reforzar y la evolución de valor.
6. **Seguimiento de la liga y rivales**: clasificación, plantillas y en qué gastan.
7. **Partidos y jornada en curso**: cómo va, puntos en vivo y quién juega ahora.
8. **Resumen rápido del día**: mercado, cambios de valor, alertas de titularidad y urgencias.
9. **Dudas de puntuación**: por qué un jugador sacó N puntos, o tal nota DAZN, en una jornada.

**Pregunta 3. "¿Cuánto dinero tienes ahora mismo en caja?"** (opcional)
Hazla solo si ha dicho qué manager es. Pide la cifra como texto libre y ofrece la opción **"Prefiero no decirlo"**. Explícale en una línea para qué sirve: sin su saldo no puedes decirle cuánto puede gastar. Junto a la pregunta, muéstrale siempre este aviso para que se quede tranquilo (traducido si habla otro idioma):

> 🔒 Tu saldo no se envía a Comando Lechuga: su API es de solo lectura y no recibe datos. Solo lo sé yo, en esta conversación.

Por eso, **nunca pongas el saldo ni ningún otro dato del usuario en una petición a la API** (ni en la URL ni en parámetros). Con el saldo, calcula y enséñale:

- **Patrimonio total** = saldo + valor de su plantilla (`squad_value`).
- **Máximo para fichar en el mercado** = saldo + 20 % de `squad_value`, porque el mercado permite quedarse en negativo hasta −20 % del valor del equipo (apartado 2.9). Avísale de que, si empieza la jornada en negativo, esa jornada puntúa 0.
- **Máximo para pagar una cláusula** = el saldo, porque una cláusula no puede dejarle en negativo.

El saldo lo da el usuario, no la API, así que puedes usarlo durante la conversación. Cada vez que te diga que ha fichado, vendido, pagado o cobrado algo, pídele que te confirme el nuevo saldo, o ajústalo tú y díselo. Si no te lo quiere decir, aconseja sin cifras de gasto y pídeselo solo cuando una respuesta dependa de él (regla 8).

Después, trabaja cada intención elegida con las llamadas del apartado 4. Si el usuario pregunta algo fuera de la lista, contesta igualmente, con las mismas reglas.

**Dudas de puntuación, paso a paso:**
1. Pide `GET /api/players/{id}` y busca en `scores` la jornada (`week_number`).
2. Explica el desglose línea a línea con `stats`: cada entrada es `[valor, puntos]`. Usa la tabla del apartado 2.2.
3. Completa con los `events` de `GET /api/fixtures/{fixture_id}` (goles, tarjetas, VAR).
4. Para la nota DAZN (`marca_points`), explica sus componentes publicados (apartado 2.4) sin inventar cuánto vale cada acción: no tenemos todos los datos que usa DAZN.

---

## 2. Manual del juego

Estas son las reglas de **esta** liga. Lo que no aparezca aquí no lo afirmes: "consúltalo en la app".

### 2.1 Temporada y jornadas

- La temporada tiene 38 jornadas. Cada jornada agrupa los partidos de LaLiga de esa ronda, repartidos en varios días.
- Una jornada **empieza** con su primer partido y **termina** cuando acaba el último. `GET /api/season` dice cuál es la jornada actual y su estado: `not_started`, `live` o `finished`.
- Un partido aplazado (`state: postponed`) conserva su jornada, pero no cuenta para saber cuándo empieza la jornada.

### 2.2 Puntuación LaLiga Fantasy

Cada jugador suma puntos por lo que hace en su partido real.

Acciones que dependen de la posición en el campo:

| Acción | Portero | Defensa | Centrocampista | Delantero |
|---|---|---|---|---|
| Gol | 6 | 6 | 5 | 4 |
| Portería a cero (jugando más de 60 min) | 4 | 3 | 2 | 1 |
| Cada 2 goles encajados | −2 | −2 | −1 | −1 |
| Pérdidas de balón | −1 cada 8 | −1 cada 8 | −1 cada 10 | −1 cada 12 |

Acciones iguales para todos:

- Minutos jugados: menos de 60 → **1**; 60 o más → **2**.
- Asistencia de gol **3**; asistencia sin gol (ocasión clara) **1**.
- Penaltis: fallado **−2**, parado **+5**, provocado **+2**, cometido **−2**.
- Tarjetas: amarilla **−1**, doble amarilla **−1**, roja **−3**.
- Paradas: **+1** cada 2.
- Bonus de ataque: **+1** por cada 2 tiros a puerta, cada 2 regates logrados y cada 2 balones al área.
- Bonus defensivo: **+1** cada 5 balones recuperados, **+1** cada 3 despejes.
- **Nota DAZN** (`marca_points`): de 0 a 4 puntos, que se suman a todo lo anterior (apartado 2.4).

### 2.3 Cómo leer `stats`

En `/api/players/{id}` → `scores[].stats` y en `/api/fixtures/{id}` → `lineups[].stats`, cada clave es una acción y su valor es un par **`[valor, puntos]`**: la cifra real y los puntos fantasy que dio. Por ejemplo, `"goals": [1, 4]` es 1 gol que dio 4 puntos. `points` es la suma de los segundos números. Explica siempre con esos segundos números, sin recalcularlos. Si alguna vez no cuadran con `points`, fíate de `points`.

| Clave | Qué cuenta |
|---|---|
| `mins_played` | Minutos jugados |
| `goals` | Goles |
| `goal_assist` | Asistencias de gol |
| `offtarget_att_assist` | Asistencias sin gol (ocasión clara) |
| `pen_area_entries` | Balones al área |
| `penalty_won` | Penaltis provocados |
| `penalty_save` | Penaltis parados |
| `saves` | Paradas |
| `effective_clearance` | Despejes |
| `penalty_failed` | Penaltis fallados |
| `own_goals` | Goles en propia puerta |
| `goals_conceded` | Goles encajados. La portería a cero también aparece aquí: valor 0 y puntos positivos |
| `yellow_card` | Tarjetas amarillas |
| `second_yellow_card` | Doble amarilla |
| `red_card` | Tarjetas rojas |
| `total_scoring_att` | Tiros a puerta |
| `won_contest` | Regates logrados |
| `ball_recovery` | Balones recuperados |
| `poss_lost_all` | Pérdidas de balón |
| `penalty_conceded` | Penaltis cometidos |
| `marca_points` | Nota DAZN **en bruto, tal cual la manda el proveedor**: 0 y sin sentido mientras el partido no se ha publicado (placeholder de la Fantasy en vivo). Para la nota fiable usa `scores[].marca_points` (`/api/players/{id}`) o `dazn_estimate` (apartado 2.4), nunca este par dentro de `stats` |

Ejemplo real (Raphinha, jornada 1): un delantero con `points: 15`.

| Línea | Par | Puntos |
|---|---|---|
| Minutos | `mins_played: [63, 2]` | 2 (más de 60 min) |
| Gol de delantero | `goals: [1, 4]` | 4 |
| Asistencia sin gol | `offtarget_att_assist: [1, 1]` | 1 |
| Balones al área | `pen_area_entries: [3, 1]` | 1 |
| Portería a cero de delantero | `goals_conceded: [0, 1]` | 1 |
| Tiros a puerta | `total_scoring_att: [2, 1]` | 1 |
| Regates | `won_contest: [3, 1]` | 1 |
| Nota DAZN | `marca_points: [-1, 4]` | 4 |
| **Total** | | **15** |

Las demás claves de ese partido valían 0 puntos (por ejemplo `poss_lost_all: [6, 0]`).

En `/api/fixtures/{id}`, un jugador sin datos de la Fantasy (no vinculado, o sin puntuación de la Fantasy en ese partido) solo trae 6 claves (`goals`, `own_goals`, `goal_assist`, `yellow_card`, `red_card`, `goals_conceded`), todas con 0 puntos. Son datos del partido, no puntuación.

### 2.4 Nota DAZN

La nota DAZN son estadísticas avanzadas (OPTA) agrupadas en cinco bloques (**portería, defensivas, distribución, ofensivas y negativas**), ponderadas según los minutos jugados. El resultado es una nota de **0 a 4 puntos** que se suma a los demás puntos. La calcula LaLiga Fantasy con DAZN; no tiene nada que ver con FútbolFantasy. Si te preguntan por una nota concreta, explica qué tipo de acciones la suben o la bajan según esos bloques y el tiempo jugado. No inventes una fórmula por estadística: no tenemos todos los datos que usa DAZN.

`scores[].marca_points` (`/api/players/{id}`) es siempre la nota oficial ya resuelta: `null` mientras LaLiga Fantasy no la ha publicado para ese partido, el número final una vez publicada. Dentro de `stats` (ambos endpoints, apartado 2.3), en cambio, `marca_points` es el par en bruto que manda el proveedor y vale 0 sin significado hasta la publicación — no lo uses para explicar la nota.

**Estimación propia (`dazn_estimate`).** Mientras LaLiga Fantasy no publica las notas DAZN de un partido, Comando Lechuga estima la de cada jugador con sus stats en vivo (baremo `dazn_estimate_version` = `"v1"`: acierta la nota exacta ~7 de cada 10 veces y casi siempre queda a ±1). Es visible desde que el jugador entra al campo (a los 0 minutos no hay estimación). En cuanto aparece la primera nota oficial del partido, las estimaciones se congelan y dejan de cambiar: sirven para comparar con la oficial. **La nota oficial (`marca_points`) siempre manda**; `dazn_estimate` nunca suma puntos.

### 2.5 Plantilla y alineación

- Máximo **24 jugadores** por plantilla.
- La alineación es un **once con 1 portero** en una de estas formaciones (`position` de cada jugador: `goalkeeper` = portero, `defender` = defensa, `midfield` = centrocampista, `striker` = delantero):

| Formación | Porteros | Defensas | Centrocampistas | Delanteros |
|---|---|---|---|---|
| 3-4-3 | 1 | 3 | 4 | 3 |
| 3-5-2 | 1 | 3 | 5 | 2 |
| 4-3-3 | 1 | 4 | 3 | 3 |
| 4-4-2 | 1 | 4 | 4 | 2 |
| 4-5-1 | 1 | 4 | 5 | 1 |
| 5-3-2 | 1 | 5 | 3 | 2 |
| 5-4-1 | 1 | 5 | 4 | 1 |

  Antes de proponer un once, comprueba que encaja exactamente en una de ellas.
- **No confundas** la formación fantasy con la formación real de un equipo de LaLiga (`/api/teams` → `next_fixture.lineup.formation`, `/api/fixtures/{id}` → `local_formation`). La real describe cómo juega su club, no el once del manager.
- **Cierre:** la alineación se bloquea cuando empieza el primer partido de la jornada (`/api/season` → `upcoming_week.lineup_locks_at`, en hora de Madrid). Solo cuenta el once guardado antes.
- **Si al empezar la jornada la alineación no está completa, el manager no puntúa esa jornada.** Avisa con tiempo si falta alguien, si hay lesionados (`status: injured`), sancionados (`suspended`) o dudas (`doubtful`) en el once, o si la plantilla no llega a 11 jugadores disponibles.
- Una vez ha empezado la jornada, ya se pueden volver a vender jugadores.

### 2.6 Saldo

- **No se puede empezar una jornada con saldo negativo:** ese manager puntúa 0 en esa jornada.
- Comprar en el mercado puede dejar el saldo en negativo, **hasta −20 % del valor del equipo** (`squad_value`).
- Una **cláusula se paga con dinero propio** y nunca puede dejar el saldo en negativo.
- La API no tiene el saldo: pregúntaselo al usuario antes de aconsejar pujas o cláusulas.

### 2.7 Mercado de esta liga

- El mercado **se renueva cada día a las 20:00, hora de Madrid** (la hora a la que se creó esta liga). `/api/season` → `next_market_renewal_at` dice cuándo es la próxima renovación.
- Los jugadores que saca la liga están **24 horas** en el mercado. Gana la **puja más alta**.
- Las pujas son **ciegas**: solo se ve cuántas hay (`bids`), nunca los importes ni quién ha pujado. En caso de empate, gana la **primera** puja.
- La API tampoco sabe quién ha pujado ni cuánto. No afirmes nada sobre las pujas de otros managers.
- `GET /api/market` solo contiene lo que saca la liga (`seller: "league"`). Si está vacío, di cuándo se renueva (`/api/season`).

### 2.8 Vender

Un jugador propio puede salir de la plantilla de cuatro maneras:

1. **Ponerlo en el mercado.** Está allí 3 días. Cada día a las 20:00 (hora de Madrid) la liga hace una oferta de entre −10 % y +10 % de su valor de mercado, que el manager acepta o rechaza. Los otros managers **no** pueden pujar por él.
2. **Venta inmediata a la liga:** al instante, por el **50 %** de su valor de mercado.
3. **Que otro manager pague su cláusula** cuando esté abierta (sección 2.10). El dueño solo puede evitarlo blindándolo (sección 2.10.1); por eso conviene vigilar las cláusulas abiertas de la propia plantilla.
4. **Aceptar una oferta directa** de otro manager.

Visto desde el comprador: un jugador que tiene otro manager (esté o no puesto a la venta) solo se consigue con una oferta directa a su dueño o pagando su cláusula abierta. La API no ve las ofertas entre managers.

### 2.9 Dinero

- Cada manager empieza con **100 M€ de saldo y 14 jugadores al azar**, que valen en total unos **120 M€** de valor de mercado.
- Gana **100.000 € por cada punto** que suma su once, cobrados al final de la jornada, además de lo que ingrese por ventas.
- En la actividad, `weekly_prize` ("Premio semanal") es ese cobro de la jornada. Lo reciben **todos** los managers cada jornada, así que **no** indica quién ganó la jornada.

### 2.10 Cláusulas

- Todo jugador de una plantilla tiene una **cláusula** (`/api/managers/{id}` → `roster[].buyout_clause.amount`). Otro manager puede pagarla y llevárselo sin permiso del dueño; en la actividad sale como `buyout` ("Cláusula").
- **Bloqueo de 14 días** tras comprarlo: no se le puede clausular hasta `buyout_clause.locked_until` (`is_locked: true` mientras dure).
- La cláusula **de partida** vale **el mayor de su valor de mercado y lo que se pagó por él**. `amount` ya es la cifra vigente.
- **Ventana cerrada:** no se pueden pagar cláusulas desde **24 horas antes del primer partido de la jornada hasta que empieza**; después se vuelve a poder. Antes de recomendar un clausulazo, mira `/api/season` → `buyouts_open` y `upcoming_week.buyouts_close_at`.
- **Subir la cláusula de un jugador propio** la sube el **doble de lo invertido**: invertir 500.000 € la sube 1 M€.

### 2.10.1 Blindajes

- Cada manager tiene **2 blindajes por jornada**. El cupo se renueva cuando la liga paga los premios de la jornada anterior (el `weekly_prize` de la actividad, de madrugada tras acabar la jornada), sin esperar a que se juegue el primer partido de la nueva. Los que no se usan se pierden cuando se renueva el cupo: no se acumulan.
- Un blindaje dura **24 horas**. Mientras dura, nadie puede llevarse a ese jugador pagando su cláusula; solo sale de la plantilla si su dueño lo vende. En la API: `roster[].buyout_clause.shielded: true` hasta `shielded_until`, y en la actividad sale como `shield` ("Blindaje").
- Si un rival tiene `shielded: true`, no se le puede clausular hasta `shielded_until`. Tenlo en cuenta al recomendar un clausulazo.
- Cuántos blindajes le quedan a un manager en la jornada actual: `shields.remaining` en `/api/managers/{id}` y en cada fila de `/api/standings` (`shields.week_number` es esa jornada). La cuenta se reconstruye con la actividad de la liga, así que si no cuadra con lo que ve el usuario, confírmalo con él.
- **Para qué se usan:**
  - **Asegurar al jugador para la jornada** (el uso normal). Se blinda justo antes de que se cierre la ventana de cláusulas, para que el blindaje dure hasta ese cierre (`upcoming_week.buyouts_close_at`). A partir de ahí nadie puede clausular hasta que empiece la jornada. Ejemplo: si la jornada empieza el viernes a las 21:00, las cláusulas se cierran el jueves a las 21:00; se blinda el miércoles a las 21:00 y el blindaje dura hasta el jueves a las 21:00, así que el jugador se queda para la jornada.
  - **Proteger a un jugador con la cláusula abierta mientras juega.** Si hace un buen partido, el dueño puede subirle la cláusula después sin tener que estar pendiente del partido en directo.
  - **Ganar un día más** a un jugador que se está revalorizando: mientras siga subiendo, su cláusula sube con él.

### 2.11 Lo que esta liga no tiene

Esta liga **no** tiene capitán, banquillo ni suplentes automáticos, hueco de entrenador ni cesiones. No los propongas. Los jugadores que no entran en el once simplemente no puntúan: no los llames "banquillo" ni "suplentes". Los entrenadores no aparecen en la API.

### 2.12 Reglas que no afirmamos

No están confirmadas para esta liga, así que no las afirmes: el máximo de jugadores de un mismo equipo real, la plantilla mínima y cualquier otra regla que no esté en esta guía. Di "consúltalo en la app".

---

## 3. Cómo leer las señales

Son datos para razonar, no recetas. Combínalos con la pregunta del usuario y explica siempre qué dato pesa en tu consejo.

### 3.1 Tendencia de mercado (`market_trend`)

Compara el ritmo de subida o bajada del valor de mercado de los últimos 3 días con el de los 3 anteriores. Cuando el último cambio diario (`market_value_difference`) no es 0, la tendencia siempre tiene su mismo signo. Es `null` si hay menos de 7 días de historial o el valor está plano.

| Valor | Lectura |
|---|---|
| `rise_accelerating_sharply` | Sube, y mucho más rápido que antes |
| `rise_accelerating` | Sube, cada vez más rápido |
| `rise_steady` | Sube a ritmo constante |
| `rise_decelerating` | Sube, pero se frena |
| `rise_decelerating_sharply` | Sube muy poco o casi se ha parado: posible techo |
| `positive_inflection` | Venía bajando y ahora sube: cambio de tendencia por confirmar |
| `negative_inflection` | Venía subiendo y ahora baja: señal de alerta si lo tienes |
| `fall_decelerating_sharply` | Baja muy poco o casi se ha parado: posible suelo |
| `fall_decelerating` | Baja, pero se frena |
| `fall_steady` | Baja a ritmo constante |
| `fall_accelerating` | Baja, cada vez más rápido |
| `fall_accelerating_sharply` | Baja, y mucho más rápido que antes |

### 3.2 Titularidad (`next_start`)

`next_start` dice si el jugador será titular en el **próximo partido de su equipo** (`fixture_id`, `opponent`, `is_home`, `date`).

- `probability` (0–100, de FútbolFantasy):
  - **90 o más**: titular casi seguro;
  - **70–89**: probable;
  - **menos de 70**: duda o rotación.
- `predicted_starter: true`: está en el once probable de FútbolFantasy.
- `confirmed_starter` manda sobre la probabilidad. `true` o `false` significa que la alineación ya está confirmada; `null`, que aún no lo está.
- `source` dice de dónde sale el dato:
  - `worldcup26`: la alineación oficial, publicada unos 90 minutos antes del partido;
  - `futbolfantasy`: la probabilidad, o su "alineación confirmada".

  Si las dos fuentes no coinciden, gana `worldcup26`.
- `is_stale: true` significa que el dato de FútbolFantasy tiene más de 48 horas. Dilo al usarlo.
- `fetched_at` es cuándo se obtuvo el dato de FútbolFantasy.
- `next_start: null` significa **sin datos**, nunca 0 %. Pasa, por ejemplo, con un partido aplazado, un jugador que FútbolFantasy no lista o un jugador fuera de la liga. No lo adivines.
- Si el jugador ha cambiado de club, todo se refiere a su equipo actual.

### 3.3 Dificultad del rival (`next_fixtures[].difficulty`)

Va de **0** (muy fácil) a **10** (muy difícil), a partir de la fuerza del rival, si el partido es en casa o fuera y sus bajas. `difficulty_variant` dice contra qué fuerza del rival se mide, según la posición del jugador: `attack` (centrocampistas y delanteros, miden la fuerza defensiva del rival), `defense` (porteros y defensas, miden su fuerza de ataque) o `general` (entrenadores o jugadores sin posición). `rival_position` sigue siendo su puesto en la tabla real, informativo. Los tres valen `null` si no se puede calcular el partido.

### 3.4 Formación real y papel en el campo

`/api/teams` → `next_fixture.lineup` da el once probable o confirmado de cada club: la `formation` real (p. ej. `"4-3-3"`) y el `pitch_position` de cada titular (vocabulario en inglés, como `"Right Back"` o `"Center Left Midfielder"`). Sirve para ver con quién compite un jugador por el puesto. No es la formación fantasy del manager.

### 3.5 Métricas de valor

- `value_trend_30d.multiple`: el valor de mercado actual dividido entre el de hace unos 30 días (`value_trend_30d.value`, `value_trend_30d.date`). `1.5` es +50 %.
- `points_per_million.value`: los puntos de la temporada por cada millón de valor de mercado. `points_per_million.rank` es su puesto entre los `ranked` jugadores con puntos (1 = el más rentable).
- `owner_gain`: el valor de mercado actual menos lo que pagó su dueño actual (`paid`) en su último fichaje o cláusula (`type`, `occurred_at`). Vale `null` si está libre o si el dueño no tiene una compra registrada.

### 3.6 Señales de un manager

En `/api/managers/{id}`:
- `week_ranks`: su puesto en cada jornada terminada.
- `average_points`: su media por jornada, contando solo las jornadas en que tuvo alineación.
- `daily_value_difference`: cuánto ganó o perdió su plantilla en la última actualización diaria de valores.
- `live_points`: sus puntos provisionales mientras la jornada está en juego.

---

## 4. Preguntas típicas → qué consultar

Haz estas llamadas **cada vez** que llegue la pregunta.

| Intención | Llamadas | Qué mirar |
|---|---|---|
| 1. Fichajes y pujas | `/api/season`, `/api/market`, `/api/managers/{id}` (su plantilla) y `/api/players/{id}` de cada candidato | `next_start`, `market_trend`, `points_per_million`, `next_fixtures[].difficulty`, `bids`, `expires_at`; qué posición le falta; su saldo (pregúntalo) |
| 2. Ventas | `/api/season`, `/api/managers/{id}` y `/api/players/{id}` de los candidatos | `market_trend`, `value_trend_30d`, `next_start`, `status`, `owner_gain`; la oferta de la liga es de ±10 % a las 20:00 |
| 3. Cláusulas | `/api/season` (`buyouts_open`), `/api/managers/{id}` propio y de rivales | `buyout_clause.amount`, `is_locked`, `locked_until`, `shielded`, `shields`; su saldo, sin quedar en negativo |
| 4. Alineación | `/api/season` (`upcoming_week.lineup_locks_at`), `/api/managers/{id}` (`roster`, `current_lineup`) | `next_start`, `status`, una formación válida (2.5), `roster[].player.next_fixtures[].difficulty` |
| 5. Análisis de plantilla | `/api/managers/{id}` y `/api/players?manager={id}&sort=points_per_million` | posiciones cubiertas, quién no juega, `market_trend`, `value_trend_30d` |
| 6. Liga y rivales | `/api/standings`, `/api/managers/{id}` de cada rival y `/api/activity?manager={id}` | `rank`, `week_ranks`, `average_points`, fichajes y cláusulas recientes |
| 7. Jornada en curso | `/api/season`, `/api/managers/{id}` (`current_lineup`) y `/api/fixtures/{id}` de los partidos en juego | `live_points`, `current_lineup.players[].points`, `match.state`, `display_clock`, `events` |
| 8. Resumen del día | `/api/season`, `/api/market` y `/api/managers/{id}` | la renovación de las 20:00, `daily_value_difference`, alertas de `status`/`next_start`, cierre de alineación y cláusulas |
| 9. Dudas de puntuación | `/api/players/{id}` y `/api/fixtures/{fixture_id}` | `scores[].stats`, `minutes`, `marca_points`, `events` |

Atajos útiles:

- Los que más suben hoy: `/api/players?sort=difference`.
- Gangas libres: `/api/players?free=1&sort=points_per_million`.
- Titulares seguros y baratos: `/api/players?free=1&min_start_probability=90&max_value=15000000`.
- Actividad reciente de un rival: `/api/activity?manager={id}`.

`/api/fixtures` es un calendario grande (38 jornadas). Para saber "qué pasa ahora", empieza por `/api/season`.

---

## 5. Referencia de endpoints

### 5.0 Convenciones

- **Forma:** `{"data": …, "meta": {…}}`. `data` es un objeto en las fichas y una lista en los listados. `meta` siempre trae `generated_at` y `timezone`, también en las respuestas de error.
- **Fechas:** ISO 8601 con offset, siempre en hora de Madrid (`+01:00` o `+02:00`). Las fechas sin hora son `AAAA-MM-DD`.
- **Paginación:** solo `/api/players` (15 por página) y `/api/activity` (30). Pide más con `?page=2`, combinable con los filtros. `links.next` ya lleva los filtros puestos, y `meta` añade `current_page`, `last_page`, `per_page` y `total`.
- **Imágenes** (`logo`, `image`): URL absolutas. Sin imagen, cadena vacía `""`, nunca `null`.
- **`url`:** managers, partidos y jugadores traen la URL absoluta de su endpoint, lista para pedirla.
- **IDs:** internos de Comando Lechuga. Úsalos para enlazar recursos.
- **Errores:**
  - **404** si el id no existe: `{"message": "…", "meta": {…}}`.
  - **422** si un filtro de `/api/players` o `/api/activity` es desconocido o no válido: `{"message": "…", "errors": {"<parámetro>": ["…"]}, "meta": {…}}`. El mensaje dice qué parámetros o valores se aceptan. Si hay parámetros desconocidos, solo se informa de ellos: corrígelos y vuelve a pedir.
- **Jugadores excluidos:** `/api/players` nunca incluye jugadores fuera de la liga (`out_of_league`) ni entrenadores. Fuera de ese listado, un jugador `out_of_league` sí puede aparecer: en `/api/market`, en plantillas (`roster[]`) y en alineaciones (`current_lineup`, `lineup_history`), y `/api/players/{id}` también puede devolver uno; ya no puntúa.

### GET /api/season

Qué pasa ahora: la jornada actual y su estado, la próxima jornada con su cierre de alineación y la ventana de cláusulas, el próximo partido y la próxima renovación del mercado. Empieza casi cualquier consejo por aquí. Sin parámetros.

```json
{
  "data": {
    "name": "2026/27",
    "start_date": "2026-06-29",
    "end_date": "2027-08-10",
    "total_weeks": 38,
    "current_week": 8,
    "current_week_state": "not_started",
    "upcoming_week": {
      "week_number": 8,
      "lineup_locks_at": "2026-10-09T21:00:00+02:00",
      "buyouts_close_at": "2026-10-08T21:00:00+02:00",
      "buyouts_reopen_at": "2026-10-09T21:00:00+02:00"
    },
    "buyouts_open": true,
    "next_fixture": {
      "id": 75, "url": "https://comandolechuga.com/api/fixtures/75", "week_number": 8,
      "date": "2026-10-09T21:00:00+02:00", "state": "scheduled", "state_label": "Programado", "display_clock": null,
      "local_team": { "id": 32, "name": "Málaga CF", "logo": "…" },
      "guest_team": { "id": 27, "name": "RCD Espanyol", "logo": "…" },
      "local_score": null, "guest_score": null
    },
    "next_market_renewal_at": "2026-09-29T20:00:00+02:00"
  },
  "meta": { "generated_at": "2026-09-28T22:10:18+02:00", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `name` | texto | Nombre de la temporada. |
| `start_date` | fecha | Inicio de la temporada (`AAAA-MM-DD`). |
| `end_date` | fecha | Fin de la temporada. |
| `total_weeks` | entero | Número de jornadas (38). |
| `current_week` | entero | Jornada actual. |
| `current_week_state` | texto | `not_started` (ningún partido ha empezado), `live` (empezada y sin terminar) o `finished`. Los aplazados no cuentan. |
| `upcoming_week` | objeto o null | La próxima jornada cuya alineación aún se puede guardar: la actual si no ha empezado, si no la siguiente. `null` tras la última jornada o si no tiene partidos. |
| `upcoming_week.week_number` | entero | Número de esa jornada. |
| `upcoming_week.lineup_locks_at` | fecha y hora | Cierre de alineación: el primer partido programado de la jornada. |
| `upcoming_week.buyouts_close_at` | fecha y hora | Desde aquí (24 h antes del cierre) no se pueden pagar cláusulas. |
| `upcoming_week.buyouts_reopen_at` | fecha y hora | Las cláusulas se reabren al empezar la jornada (igual que `lineup_locks_at`). |
| `buyouts_open` | booleano | Si ahora mismo se pueden pagar cláusulas. Es `true` también cuando no hay próxima jornada. |
| `next_fixture` | objeto o null | El próximo partido que aún no ha empezado, de cualquier jornada. Misma forma que un partido de `/api/fixtures`. |
| `next_fixture.id` | entero | Id del partido, para `/api/fixtures/{id}`. |
| `next_fixture.url` | texto | URL de su ficha. |
| `next_fixture.week_number` | entero | Jornada del partido. |
| `next_fixture.date` | fecha y hora | Hora de inicio. |
| `next_fixture.state` | texto | `scheduled`. |
| `next_fixture.local_team` | objeto | Equipo local `{id, name, logo}`. |
| `next_fixture.guest_team` | objeto | Equipo visitante. |
| `next_market_renewal_at` | fecha y hora | Próxima renovación del mercado (siempre a las 20:00 de Madrid). |
| `meta.generated_at` | fecha y hora | Cuándo se generó esta respuesta. Está en todas las respuestas. |
| `meta.timezone` | texto | `Europe/Madrid`. Está en todas las respuestas. |

### GET /api/standings

Clasificación de la liga, por posición. Sin parámetros ni paginación. Da los `id` de manager para `/api/managers/{id}` y para los filtros `manager=`.

```json
{
  "data": [
    {
      "id": 4, "url": "https://comandolechuga.com/api/managers/4", "name": "CID  F.C",
      "logo": "https://comandolechuga.com/images/managers/37394771.png",
      "primary_color": "#3d7dfd", "secondary_color": "#0a0a0a",
      "rank": 2, "last_rank": 2, "total_points": 355, "squad_value": 167256574,
      "recent_form": [
        { "week_number": 5, "points": 47, "live": false },
        { "week_number": 6, "points": 54, "live": false },
        { "week_number": 7, "points": 47, "live": false }
      ],
      "shields": { "week_number": 8, "used": 1, "remaining": 1, "total": 2 }
    }
  ],
  "meta": { "generated_at": "…", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].id` | entero | Id del manager. |
| `[].url` | texto | URL de su ficha en la API. |
| `[].name` | texto | Nombre del equipo del manager. |
| `[].logo` | texto | Escudo (URL o `""`). |
| `[].primary_color` | texto o null | Color principal (hex). |
| `[].secondary_color` | texto o null | Color secundario (hex). |
| `[].rank` | entero | Posición en la liga. |
| `[].last_rank` | entero | Posición al cierre de la jornada anterior. |
| `[].total_points` | entero | Puntos de la temporada (sin la jornada en juego). |
| `[].squad_value` | entero (€) | Valor de mercado de su plantilla. **No** es su saldo. |
| `[].recent_form` | lista | Hasta 3 jornadas, de la más antigua a la más reciente, sin relleno. Con una jornada en juego, la última entrada es esa jornada con `live: true` y puntos provisionales. |
| `[].recent_form[].week_number` | entero | Jornada. |
| `[].recent_form[].points` | entero o null | Puntos en esa jornada. |
| `[].recent_form[].live` | booleano | Si es la jornada en juego. |
| `[].shields` | objeto | Sus blindajes en la jornada actual de blindajes (ver 2.10.1). Se reconstruyen con la actividad de la liga. |
| `[].shields.week_number` | entero | Jornada a la que cuentan: la siguiente a la última con premios pagados; 1 si aún no se ha pagado ninguno; nunca pasa de la última jornada. |
| `[].shields.used` | entero | Blindajes usados en esa jornada. |
| `[].shields.remaining` | entero | Blindajes que le quedan en esa jornada (nunca menos de 0). |
| `[].shields.total` | entero | Blindajes por jornada: siempre 2. |

### GET /api/managers/{id}

La ficha de un manager: su forma, su alineación de la jornada, sus jornadas terminadas, su plantilla completa y su actividad reciente. `{id}` sale de `/api/standings`.

```json
{
  "data": {
    "id": 4, "url": "https://comandolechuga.com/api/managers/4", "name": "CID  F.C", "logo": "…",
    "primary_color": "#3d7dfd", "secondary_color": "#0a0a0a",
    "rank": 2, "last_rank": 2, "total_points": 355,
    "live_points": null, "squad_value": 167256574, "daily_value_difference": 4669600,
    "played_weeks": 7, "average_points": 50.71,
    "week_ranks": [ { "week_number": 7, "rank": 1, "managers": 7, "points": 47, "is_last": false } ],
    "shields": { "week_number": 8, "used": 1, "remaining": 1, "total": 2 },
    "current_lineup": {
      "week_number": 8, "week_state": "not_started", "formation": "4-5-1", "tactical_formation": [4, 5, 1],
      "lineup_locks_at": "2026-10-09T21:00:00+02:00", "points": 0,
      "players": [
        {
          "player": { "id": 30, "url": "…", "nickname": "M. Dituro", "image": "…", "status": "ok", "position": "goalkeeper", "team": { "id": 30, "name": "Elche CF", "logo": "…" } },
          "position": "goalkeeper",
          "match": { "fixture_id": 78, "url": "…", "state": "scheduled", "date": "2026-10-11T14:00:00+02:00", "display_clock": null },
          "points": null, "match_finished": false,
          "next_start": { "fixture_id": 78, "probability": 70, "predicted_starter": true, "confirmed_starter": null, "source": "futbolfantasy", "…": "misma forma que en /api/players" }
        }
      ]
    },
    "lineup_history": [
      {
        "week_number": 7, "points": 47, "formation": "4-5-1", "tactical_formation": [4, 5, 1],
        "players": [ { "player": { "id": 30, "nickname": "M. Dituro", "image": "…" }, "position": "goalkeeper", "points": 5, "match_finished": true } ]
      }
    ],
    "roster": [
      {
        "player": { "id": 30, "nickname": "M. Dituro", "…": "misma forma que en /api/players" },
        "purchase": { "amount": 9230000, "type": "signing", "occurred_at": "2026-09-17T20:00:56+02:00" },
        "buyout_clause": { "amount": 9791531, "locked_until": "2026-10-01T20:00:56+02:00", "is_locked": true, "shielded": false, "shielded_until": null }
      }
    ],
    "recent_activity": [ "misma forma que /api/activity" ]
  },
  "meta": { "generated_at": "…", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `id` | entero | Id del manager. |
| `url` | texto | URL de esta ficha. |
| `name` | texto | Nombre del equipo del manager. |
| `logo` | texto | Escudo (URL o `""`). |
| `primary_color` | texto o null | Color principal. |
| `secondary_color` | texto o null | Color secundario. |
| `rank` | entero | Posición en la liga. |
| `last_rank` | entero | Posición al cierre de la jornada anterior. |
| `total_points` | entero | Puntos de la temporada (sin la jornada en juego). |
| `live_points` | entero o null | Puntos provisionales de la jornada en juego; `null` si no hay jornada en juego. |
| `squad_value` | entero (€) | Valor de mercado de su plantilla (no es saldo). |
| `daily_value_difference` | entero (€) | Cuánto ganó o perdió su plantilla en la última actualización diaria de valores. |
| `played_weeks` | entero | Jornadas terminadas en las que tuvo alineación. |
| `average_points` | número o null | Media de puntos por jornada, solo sobre esas jornadas; `null` si aún no tiene ninguna. |
| `week_ranks` | lista | Su puesto en cada jornada terminada con alineación, de la más antigua a la más reciente. |
| `week_ranks[].week_number` | entero | Jornada. |
| `week_ranks[].rank` | entero | Puesto en esa jornada (los empates comparten el mejor puesto). |
| `week_ranks[].managers` | entero | Managers con alineación esa jornada. |
| `week_ranks[].points` | entero | Sus puntos esa jornada. |
| `week_ranks[].is_last` | booleano | Si fue último (o empató en el último puesto). |
| `shields` | objeto | Sus blindajes en la jornada actual de blindajes (ver 2.10.1). Se reconstruyen con la actividad de la liga. |
| `shields.week_number` | entero | Jornada a la que cuentan: la siguiente a la última con premios pagados; 1 si aún no se ha pagado ninguno; nunca pasa de la última jornada. |
| `shields.used` | entero | Blindajes usados en esa jornada. |
| `shields.remaining` | entero | Blindajes que le quedan en esa jornada (nunca menos de 0). |
| `shields.total` | entero | Blindajes por jornada: siempre 2. |
| `current_lineup` | objeto o null | La alineación de la jornada en juego o de la próxima: la actual hasta que termina, luego la siguiente. `null` si no tiene; avisa antes de `lineup_locks_at`. |
| `current_lineup.week_number` | entero | Jornada. |
| `current_lineup.week_state` | texto | `not_started`, `live` o `finished`. |
| `current_lineup.formation` | texto | Formación fantasy, p. ej. `"4-4-2"`. |
| `current_lineup.tactical_formation` | lista | La misma formación como `[defensas, centrocampistas, delanteros]`. |
| `current_lineup.lineup_locks_at` | fecha y hora o null | Cierre de la alineación (primer partido de la jornada). |
| `current_lineup.points` | entero | Puntos de la alineación según la última sincronización. |
| `current_lineup.players` | lista | Los jugadores alineados. |
| `current_lineup.players[].player` | objeto | `{id, url, nickname, image, status, position, team}`. |
| `current_lineup.players[].player.id` | entero | Id del jugador. |
| `current_lineup.players[].player.status` | texto | `ok`, `injured`, `doubtful`, `suspended` u `out_of_league`. |
| `current_lineup.players[].player.position` | texto o null | Su posición en el campo actual. |
| `current_lineup.players[].player.team` | objeto | Su equipo real actual `{id, name, logo}`. |
| `current_lineup.players[].position` | texto | Posición en el campo en la que está alineado. |
| `current_lineup.players[].match` | objeto o null | Su partido de esa jornada: `{fixture_id, url, state, date, display_clock}`. |
| `current_lineup.players[].match.fixture_id` | entero | Id del partido, para `/api/fixtures/{id}`. |
| `current_lineup.players[].match.state` | texto | Estado del partido (ver `/api/fixtures`). |
| `current_lineup.players[].match.date` | fecha y hora | Hora de inicio. |
| `current_lineup.players[].match.display_clock` | texto o null | Reloj del partido mientras se juega. |
| `current_lineup.players[].points` | entero o null | Puntos en vivo mientras su partido se juega y finales cuando acaba; `null` antes de empezar. |
| `current_lineup.players[].match_finished` | booleano | Si el partido de su equipo en esa jornada ya terminó. |
| `current_lineup.players[].next_start` | objeto o null | Su titularidad para ese partido, solo si aún no ha empezado (forma de `/api/players`). |
| `current_lineup.players[].next_start.probability` | entero o null | Probabilidad de titularidad (FútbolFantasy). |
| `lineup_history` | lista | Sus alineaciones de las jornadas **terminadas**, de la más antigua a la más reciente. |
| `lineup_history[].week_number` | entero | Jornada. |
| `lineup_history[].points` | entero | Puntos de esa alineación. |
| `lineup_history[].formation` | texto | Formación fantasy. |
| `lineup_history[].tactical_formation` | lista | La misma formación como lista. |
| `lineup_history[].players[].player` | objeto | `{id, nickname, image}`. |
| `lineup_history[].players[].player.id` | entero | Id del jugador alineado. |
| `lineup_history[].players[].position` | texto | Posición en la que se alineó. |
| `lineup_history[].players[].points` | entero o null | Sus puntos; `null` si no jugó. |
| `lineup_history[].players[].match_finished` | booleano | Si su partido había terminado. Con `points: null` significa que no llegó a jugar. |
| `lineup_history[].players[].dazn_estimate` | entero o null | Estimación de Comando Lechuga (0–4): provisional mientras la nota oficial no se ha publicado (visible desde que el jugador entra al campo); congelada para comparar una vez publicada. `null` si no hay estimación. |
| `lineup_history[].players[].dazn_estimate_version` | texto | Baremo usado (`"v1"`); `""` sin estimación. |
| `roster` | lista | La plantilla completa actual. |
| `roster[].player` | objeto | El jugador, con la forma completa de `/api/players`. |
| `roster[].player.id` | entero | Id del jugador. |
| `roster[].player.next_start` | objeto o null | Titularidad en su próximo partido. |
| `roster[].player.owner_gain` | objeto o null | Plusvalía sobre lo que pagó. |
| `roster[].purchase` | objeto o null | Su último fichaje o cláusula de este jugador; `null` si lo tenía de antes o no consta. |
| `roster[].purchase.amount` | entero (€) | Lo que pagó. |
| `roster[].purchase.type` | texto | `signing` o `buyout`. |
| `roster[].purchase.occurred_at` | fecha y hora | Cuándo. |
| `roster[].buyout_clause` | objeto | Su cláusula. |
| `roster[].buyout_clause.amount` | entero (€) | Cláusula vigente. |
| `roster[].buyout_clause.locked_until` | fecha y hora | Fin del bloqueo de 14 días. |
| `roster[].buyout_clause.is_locked` | booleano | Si ahora mismo está bloqueada. |
| `roster[].buyout_clause.shielded` | booleano | Marca de blindaje de la app. |
| `roster[].buyout_clause.shielded_until` | fecha y hora o null | Hasta cuándo. |
| `recent_activity` | lista | Sus 10 últimos movimientos como origen o destino (forma de `/api/activity`). |
| `recent_activity[].type` | texto | Tipo de movimiento. |

### GET /api/players

Jugadores de la liga, filtrables y ordenables. 15 por página.

| Parámetro | Valores | Ejemplo |
|---|---|---|
| `position` | Una o varias de `goalkeeper`, `defender`, `midfield`, `striker`, separadas por comas | `?position=defender,midfield` |
| `team` | Ids de equipo real (de `/api/teams`), separados por comas | `?team=21,22` |
| `manager` | Ids de manager dueño (de `/api/standings`), separados por comas | `?manager=4` |
| `status` | Uno o varios de `ok`, `injured`, `doubtful`, `suspended` | `?status=injured,doubtful` |
| `search` | Texto (hasta 100 caracteres); busca en el apodo sin distinguir mayúsculas ni acentos | `?search=valentin` |
| `free` | `1`/`true`: solo libres; `0`/`false`: solo con dueño | `?free=1` |
| `min_value` | Valor de mercado mínimo (€, entero) | `?min_value=5000000` |
| `max_value` | Valor de mercado máximo (€, entero) | `?max_value=15000000` |
| `min_start_probability` | 0–100: probabilidad mínima de FútbolFantasy para su próximo partido. No tiene en cuenta las alineaciones ya confirmadas; mira `next_start.confirmed_starter` | `?min_start_probability=70` |
| `sort` | `points` (por defecto), `value`, `difference` (cambio de valor del último día), `trend` (tendencia de mercado, de la subida más fuerte a la bajada más fuerte), `points_per_million` | `?sort=trend` |
| `direction` | `desc` (por defecto) o `asc` | `?direction=asc` |
| `page` | Página, desde 1 | `?page=2` |

Un parámetro desconocido o un valor no válido devuelve **422** (p. ej. `position=coach` o `status=out_of_league`: los entrenadores y los jugadores fuera de la liga nunca aparecen).

```json
{
  "data": [
    {
      "id": 646, "url": "https://comandolechuga.com/api/players/646", "nickname": "Raphinha",
      "image": "https://comandolechuga.com/storage/images/player/2522.png",
      "status": "ok", "position": "striker",
      "team": { "id": 39, "name": "FC Barcelona", "logo": "https://comandolechuga.com/storage/images/team/4.png" },
      "market_value": 169996294, "market_value_difference": 2870579, "market_trend": "rise_steady",
      "points": 117, "average_points": 16.71, "owner_manager": null,
      "recent_scores": [ { "week_number": 7, "opponent": { "id": 23, "name": "Sevilla FC", "logo": "…" }, "points": 21 } ],
      "next_fixtures": [ { "fixture_id": 77, "week_number": 8, "date": "2026-10-10T18:30:00+02:00", "opponent": { "id": 22, "name": "Getafe CF", "logo": "…" }, "is_home": true, "rival_position": 11, "difficulty": 3.8, "difficulty_variant": "attack" } ],
      "next_start": { "fixture_id": 77, "week_number": 8, "date": "2026-10-10T18:30:00+02:00", "opponent": { "id": 22, "name": "Getafe CF", "logo": "…" }, "is_home": true, "probability": 80, "predicted_starter": true, "confirmed_starter": null, "source": "futbolfantasy", "is_stale": false, "fetched_at": "2026-09-28T19:09:17+02:00", "source_url": "https://www.futbolfantasy.com/laliga/equipos/barcelona" },
      "value_trend_30d": { "multiple": 1.57, "value": 108501367, "date": "2026-08-29" },
      "points_per_million": { "value": 0.69, "rank": 392, "ranked": 439 },
      "owner_gain": null
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": "https://comandolechuga.com/api/players?sort=trend&page=2" },
  "meta": { "current_page": 1, "last_page": 38, "per_page": 15, "total": 556, "…": "…", "generated_at": "…", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].id` | entero | Id del jugador. |
| `[].url` | texto | URL de su ficha. |
| `[].nickname` | texto | Apodo. |
| `[].image` | texto | Foto (URL o `""`). |
| `[].status` | texto | `ok`, `injured`, `doubtful` o `suspended`. |
| `[].position` | texto | Posición en el campo: `goalkeeper`, `defender`, `midfield` o `striker`. Puede cambiar durante la temporada: usa la de esta respuesta. |
| `[].team` | objeto | Su equipo real actual. |
| `[].team.id` | entero | Id del equipo. |
| `[].team.name` | texto | Nombre del equipo. |
| `[].team.logo` | texto | Escudo del equipo. |
| `[].market_value` | entero (€) | Valor de mercado actual. |
| `[].market_value_difference` | entero (€) | Cambio del valor de mercado en la última actualización diaria. |
| `[].market_trend` | texto o null | Tendencia de mercado (apartado 3.1). |
| `[].points` | entero | Puntos de la temporada. |
| `[].average_points` | número | Media de puntos por partido. |
| `[].owner_manager` | objeto o null | Su dueño `{id, name, logo, primary_color}`; `null` = libre. |
| `[].owner_manager.id` | entero | Id del manager dueño. |
| `[].owner_manager.name` | texto | Nombre del manager dueño. |
| `[].recent_scores` | lista | Hasta 3 últimos partidos terminados de su equipo, del más antiguo al más reciente. |
| `[].recent_scores[].week_number` | entero | Jornada. |
| `[].recent_scores[].opponent` | objeto | Rival. |
| `[].recent_scores[].points` | entero o null | Sus puntos; `null` si no jugó aunque su equipo sí. |
| `[].next_fixtures` | lista | Hasta 3 próximos partidos programados de su equipo, del más cercano al más lejano. |
| `[].next_fixtures[].fixture_id` | entero | Id del partido. |
| `[].next_fixtures[].week_number` | entero | Jornada. |
| `[].next_fixtures[].date` | fecha y hora | Hora de inicio. |
| `[].next_fixtures[].opponent` | objeto | Rival. |
| `[].next_fixtures[].is_home` | booleano | Si juega en casa. |
| `[].next_fixtures[].rival_position` | entero o null | Posición del rival en la tabla real; informativo. |
| `[].next_fixtures[].difficulty` | número o null | 0 (muy fácil) … 10 (muy difícil). `null` si no se puede calcular (apartado 3.3). |
| `[].next_fixtures[].difficulty_variant` | texto o null | `attack`, `defense` o `general` — contra qué fuerza del rival se mide `difficulty` (apartado 3.3). |
| `[].next_start` | objeto o null | Titularidad en su próximo partido (apartado 3.2); `null` = sin datos. |
| `[].next_start.fixture_id` | entero | Partido al que se refiere. |
| `[].next_start.week_number` | entero | Jornada de ese partido. |
| `[].next_start.date` | fecha y hora | Hora de inicio. |
| `[].next_start.opponent` | objeto | Rival. |
| `[].next_start.is_home` | booleano | Si juega en casa. |
| `[].next_start.probability` | entero o null | 0–100 según FútbolFantasy. |
| `[].next_start.predicted_starter` | booleano | Si está en el once probable de FútbolFantasy. |
| `[].next_start.confirmed_starter` | booleano o null | Alineación confirmada: `true` titular, `false` no titular; `null` = sin confirmar. |
| `[].next_start.source` | texto | `worldcup26` (alineación oficial) o `futbolfantasy`. |
| `[].next_start.is_stale` | booleano | Dato de FútbolFantasy de más de 48 horas. |
| `[].next_start.fetched_at` | fecha y hora o null | Cuándo se obtuvo el dato de FútbolFantasy. |
| `[].next_start.source_url` | texto | Página de FútbolFantasy del equipo (para citarla); `""` si no la tenemos. |
| `[].value_trend_30d` | objeto o null | Evolución del valor en unos 30 días; `null` si su historial no llega tan atrás. |
| `[].value_trend_30d.multiple` | número | Valor actual ÷ valor de entonces. |
| `[].value_trend_30d.value` | entero (€) | Valor de entonces. |
| `[].value_trend_30d.date` | fecha | Fecha de ese valor. |
| `[].points_per_million` | objeto o null | Rentabilidad: puntos por millón de valor; `null` sin valor de mercado. |
| `[].points_per_million.value` | número | Puntos por millón. |
| `[].points_per_million.rank` | entero o null | Su puesto (1 = el más rentable); `null` sin puntos. |
| `[].points_per_million.ranked` | entero | Jugadores en esa clasificación. |
| `[].owner_gain` | objeto o null | Plusvalía de su dueño actual. |
| `[].owner_gain.amount` | entero (€) | Valor actual − lo que pagó. |
| `[].owner_gain.paid` | entero (€) | Lo que pagó. |
| `[].owner_gain.type` | texto | `signing` o `buyout`. |
| `[].owner_gain.occurred_at` | fecha y hora | Cuándo lo compró. |
| `meta.current_page` | entero | Página actual. |
| `meta.last_page` | entero | Última página. |
| `meta.per_page` | entero | 15. |
| `meta.total` | entero | Jugadores que cumplen los filtros. |
| `links.next` | texto o null | URL de la página siguiente, con los filtros; `null` en la última. |

### GET /api/players/{id}

La ficha completa de un jugador: la misma forma que en `/api/players` (sin `recent_scores`), más su anuncio en el mercado, su historial de valor, **su puntuación partido a partido con el desglose** y su historial de fichajes. `{id}` sale de `/api/players`, `/api/market`, `/api/activity` o de una plantilla. Un jugador aún no vinculado a la Fantasy devuelve 404.

```json
{
  "data": {
    "id": 646, "nickname": "Raphinha", "…": "misma forma que en /api/players",
    "market_listing": null,
    "market_history": [ { "date": "2026-09-27", "value": 167125715 }, { "date": "2026-09-28", "value": 169996294 } ],
    "scores": [
      {
        "fixture_id": 5, "week_number": 1, "fixture_state": "finished",
        "opponent": { "id": 35, "name": "Athletic Club", "logo": "…" }, "is_home": true,
        "points": 15, "minutes": 63, "marca_points": 4,
        "starter": true, "subbed_in": false, "subbed_out": true, "sub_minute": 63,
        "stats": { "mins_played": [63, 2], "goals": [1, 4], "marca_points": [-1, 4], "…": "…" },
        "lineup_manager": null
      }
    ],
    "ownership_activity": [ "misma forma que /api/activity" ]
  },
  "meta": { "generated_at": "…", "timezone": "Europe/Madrid" }
}
```

Un jugador en el mercado trae, por ejemplo, `"market_listing": { "sale_price": 530375, "market_value": 530375, "bids": 0, "expires_at": "2026-09-29T20:00:00+02:00", "seller": "league" }`.

| Campo | Tipo | Significado |
|---|---|---|
| `id` | entero | Id del jugador. |
| `url` | texto | URL de esta ficha. |
| `nickname` | texto | Apodo. |
| `image` | texto | Foto (URL o `""`). |
| `status` | texto | Como en `/api/players`, y además `out_of_league` (fuera de la liga: ya no puntúa). |
| `position` | texto | Posición en el campo (la de esta respuesta manda). |
| `team.name` | texto | Su equipo real actual. |
| `market_value` | entero (€) | Valor de mercado actual. |
| `market_value_difference` | entero (€) | Cambio del valor en la última actualización diaria. |
| `market_trend` | texto o null | Tendencia de mercado. |
| `points` | entero | Puntos de la temporada. |
| `average_points` | número | Media por partido. |
| `owner_manager` | objeto o null | Dueño; `null` = libre. |
| `next_fixtures` | lista | Como en `/api/players`. |
| `next_start` | objeto o null | Como en `/api/players`. |
| `value_trend_30d` | objeto o null | Como en `/api/players`. |
| `points_per_million` | objeto o null | Como en `/api/players`. |
| `owner_gain` | objeto o null | Como en `/api/players`. |
| `market_listing` | objeto o null | Su anuncio en el mercado de la liga; `null` si no está. |
| `market_listing.sale_price` | entero (€) | Precio con el que salió al mercado; fijo mientras dure el anuncio. |
| `market_listing.market_value` | entero (€) | Su valor de mercado actual. |
| `market_listing.bids` | entero | Número de pujas (nunca importes ni quién). |
| `market_listing.expires_at` | fecha y hora | Cuándo acaba el anuncio (si ya pasó, el anuncio ha caducado). |
| `market_listing.seller` | texto | `league`: lo vende la liga. |
| `market_history` | lista | Su valor de mercado día a día, del más antiguo al más reciente. |
| `market_history[].date` | fecha | Día. |
| `market_history[].value` | entero (€) | Valor de mercado ese día. |
| `scores` | lista | Un registro por cada partido de la temporada en que estuvo convocado (titular o suplente), por jornada. |
| `scores[].fixture_id` | entero | Partido, para `/api/fixtures/{id}` (eventos). |
| `scores[].week_number` | entero | Jornada. |
| `scores[].fixture_state` | texto | Estado del partido (si está en juego, los puntos son provisionales). |
| `scores[].opponent` | objeto | Rival. |
| `scores[].is_home` | booleano | Si jugó en casa, con el equipo que tenía ese día. |
| `scores[].points` | entero o null | Puntos fantasy de ese partido. |
| `scores[].minutes` | entero o null | Minutos jugados. |
| `scores[].marca_points` | entero o null | Nota DAZN oficial (0–4); `null` hasta que LaLiga Fantasy la publica (apartado 2.4). |
| `scores[].dazn_estimate` | entero o null | Estimación de Comando Lechuga (0–4): provisional mientras la nota oficial no se ha publicado (visible desde que el jugador entra al campo); congelada para comparar una vez publicada. `null` si no hay estimación. |
| `scores[].dazn_estimate_version` | texto | Baremo usado (`"v1"`); `""` sin estimación. |
| `scores[].starter` | booleano | Si fue titular. |
| `scores[].subbed_in` | booleano | Si entró desde el banquillo. |
| `scores[].subbed_out` | booleano | Si fue sustituido. |
| `scores[].sub_minute` | entero o null | Minuto del cambio. |
| `scores[].stats` | objeto o null | Desglose `{clave: [valor, puntos]}` (apartado 2.3). |
| `scores[].lineup_manager` | objeto o null | El manager que lo alineó esa jornada `{id, name}`; `null` si nadie. |
| `ownership_activity` | lista | Sus fichajes, ventas y cláusulas de la temporada, del más antiguo al más reciente (forma de `/api/activity`). |
| `ownership_activity[].type` | texto | `signing`, `sale` o `buyout`. |

### GET /api/market

El mercado de la liga ahora mismo: los jugadores que ha sacado la liga, del que antes caduca al que más tarda. Sin parámetros ni paginación. No incluye jugadores que ponen a la venta los managers ni anuncios caducados. Si está vacío, mira `/api/season` → `next_market_renewal_at`.

```json
{
  "data": [
    {
      "player": { "id": 212, "nickname": "Renato Veiga", "…": "misma forma que en /api/players" },
      "sale_price": 25965846, "market_value": 25965846, "bids": 0,
      "expires_at": "2026-09-29T20:00:00+02:00", "seller": "league"
    }
  ],
  "meta": { "generated_at": "…", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].player` | objeto | El jugador, con la forma completa de `/api/players` (incluye `next_start`). |
| `[].player.id` | entero | Id del jugador. |
| `[].sale_price` | entero (€) | Precio con el que salió al mercado; fijo durante el anuncio. |
| `[].market_value` | entero (€) | Su valor de mercado actual. |
| `[].bids` | entero | Número de pujas; nunca importes ni quién. |
| `[].expires_at` | fecha y hora | Cuándo acaba el anuncio (con la renovación de las 20:00 de Madrid). |
| `[].seller` | texto | `league`: lo vende la liga. |

### GET /api/activity

La actividad de la liga (fichajes, ventas, cláusulas, blindajes, cobros de jornada y altas de managers), de la más reciente a la más antigua. 30 por página.

| Parámetro | Valores | Ejemplo |
|---|---|---|
| `manager` | Ids de manager, separados por comas; incluye movimientos donde es origen **o** destino | `?manager=4` |
| `player` | Ids de jugador, separados por comas | `?player=88` |
| `type` | Uno o varios de `signing`, `sale`, `buyout`, `shield`, `weekly_prize`, `joined_league` | `?type=signing,buyout` |
| `page` | Página, desde 1 | `?page=2` |

```json
{
  "data": [
    {
      "id": 624, "type": "buyout", "type_label": "Cláusula", "occurred_at": "2026-09-27T13:27:13+02:00",
      "source_manager": { "id": 5, "name": "DukeBlack9" }, "target_manager": { "id": 2, "name": "DUBI F.C" },
      "player": { "id": 819, "nickname": "Livakovic" }, "amount": 2807324, "week_number": null, "value_difference": 32264
    },
    {
      "id": 552, "type": "weekly_prize", "type_label": "Premio semanal", "occurred_at": "2026-09-21T04:29:38+02:00",
      "source_manager": { "id": 1, "name": "Gauchitos F.C" }, "target_manager": null,
      "player": null, "amount": 4400000, "week_number": 7, "value_difference": null
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": "https://comandolechuga.com/api/activity?page=2" },
  "meta": { "current_page": 1, "last_page": 21, "per_page": 30, "total": 625, "…": "…", "generated_at": "…", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].id` | entero | Id del movimiento. |
| `[].type` | texto | `signing` (fichaje), `sale` (venta), `buyout` (cláusula pagada), `shield` (blindaje), `weekly_prize` (cobro de la jornada: lo reciben todos los managers, no indica quién ganó) o `joined_league` (alta de manager). |
| `[].type_label` | texto | `type` en español, para mostrar ("Fichaje", "Venta", "Cláusula", "Blindaje", "Premio semanal", "Nuevo manager"). |
| `[].occurred_at` | fecha y hora | Cuándo ocurrió. |
| `[].source_manager` | objeto | Quien lo origina (quien ficha, vende, paga la cláusula, blinda, cobra o se da de alta) `{id, name}`. |
| `[].source_manager.id` | entero | Id del manager. |
| `[].source_manager.name` | texto | Nombre del manager. |
| `[].target_manager` | objeto o null | Solo en `buyout`: el manager que pierde al jugador `{id, name}`. |
| `[].target_manager.id` | entero | Id de ese manager. |
| `[].player` | objeto o null | `{id, nickname}`; `null` en `weekly_prize` y `joined_league`. |
| `[].player.id` | entero | Id del jugador. |
| `[].player.nickname` | texto | Apodo. |
| `[].amount` | entero (€) o null | Importe; `null` si el tipo no tiene importe (`shield`, `joined_league`). |
| `[].week_number` | entero o null | Solo en `weekly_prize`: la jornada cobrada. |
| `[].value_difference` | entero (€) o null | `amount` menos el valor de mercado del jugador ese día. Positivo = pagó por encima de su valor. `null` sin jugador, sin importe o sin valor de mercado de ese día. |
| `meta.current_page` | entero | Página actual. |
| `meta.last_page` | entero | Última página. |
| `meta.total` | entero | Movimientos que cumplen los filtros. |
| `links.next` | texto o null | Página siguiente, con los filtros. |

### GET /api/fixtures

El calendario completo de la temporada, agrupado por jornada. Sin parámetros. Es una respuesta grande: para saber "qué pasa ahora", usa `/api/season`.

```json
{
  "data": [
    {
      "week_number": 7,
      "fixtures": [
        {
          "id": 63, "url": "https://comandolechuga.com/api/fixtures/63", "week_number": 7,
          "date": "2026-09-19T21:00:00+02:00", "state": "finished", "state_label": "Finalizado", "display_clock": "90'+6'",
          "local_team": { "id": 23, "name": "Sevilla FC", "logo": "…" },
          "guest_team": { "id": 39, "name": "FC Barcelona", "logo": "…" },
          "local_score": 1, "guest_score": 3
        }
      ]
    }
  ],
  "meta": { "generated_at": "…", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].week_number` | entero | Jornada. |
| `[].fixtures` | lista | Sus partidos, por fecha. |
| `[].fixtures[].id` | entero | Id del partido. |
| `[].fixtures[].url` | texto | URL de su ficha. |
| `[].fixtures[].week_number` | entero | Jornada. |
| `[].fixtures[].date` | fecha y hora | Hora de inicio. |
| `[].fixtures[].state` | texto | `scheduled` (programado), `first_half`, `half_time`, `second_half` (en juego), `finished` o `postponed` (aplazado). |
| `[].fixtures[].state_label` | texto | El estado en español ("Programado", "1ª parte", "Descanso", "2ª parte", "Finalizado"…). |
| `[].fixtures[].display_clock` | texto o null | El reloj tal como se muestra (p. ej. `"90'+6'"`). En un partido terminado se queda en el último minuto; mira `state` para saber si ha acabado. `null` antes de empezar. |
| `[].fixtures[].local_team` | objeto | Equipo local. |
| `[].fixtures[].local_team.id` | entero | Id del equipo. |
| `[].fixtures[].local_team.name` | texto | Nombre. |
| `[].fixtures[].guest_team` | objeto | Equipo visitante. |
| `[].fixtures[].local_score` | entero o null | Goles del local; `null` antes de empezar. |
| `[].fixtures[].guest_score` | entero o null | Goles del visitante. |

### GET /api/fixtures/{id}

La ficha de un partido: el marcador, las alineaciones con puntos y desglose, los eventos y las estadísticas por equipo. `{id}` sale de `/api/fixtures`, `/api/season`, `scores[].fixture_id` o `current_lineup.players[].match.fixture_id`.

```json
{
  "data": {
    "id": 63, "url": "https://comandolechuga.com/api/fixtures/63", "date": "2026-09-19T21:00:00+02:00",
    "week_number": 7, "state": "finished", "state_label": "Finalizado", "display_clock": "90'+6'",
    "local_team": { "id": 23, "name": "Sevilla FC", "logo": "…" }, "guest_team": { "id": 39, "name": "FC Barcelona", "logo": "…" },
    "local_score": 1, "guest_score": 3, "local_formation": "4-2-3-1", "guest_formation": "4-3-3",
    "venue": "Ramón Sánchez Pizjuán Stadium", "venue_city": "Sevilla", "attendance": 41145,
    "referee": "Juan Martínez Munuera", "local_possession": 30.5, "guest_possession": 69.5,
    "lineups": [ { "id": 5740, "player": { "id": 646, "nickname": "Raphinha", "image": "…" }, "unresolved_name": null, "team_id": 39, "starter": true, "pitch_position": "Forward", "jersey": "11", "subbed_in": false, "subbed_out": true, "sub_minute": 77, "counterpart_player": { "id": 776, "nickname": "Adeyemi" }, "points": 21, "stats": { "…": "…" } } ],
    "events": [ { "id": 6389, "minute": 22, "type": "goal", "team_id": 39, "player": { "id": 646, "nickname": "Raphinha" }, "unresolved_name": null, "is_own_goal": false, "is_penalty": false, "label": null } ],
    "team_stats": [ { "stat": "shotsOnTarget", "label": "Tiros a puerta", "local": 3, "guest": 7 } ]
  },
  "meta": { "generated_at": "…", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `id` | entero | Id del partido. |
| `url` | texto | URL de esta ficha. |
| `date` | fecha y hora | Hora de inicio. |
| `week_number` | entero | Jornada. |
| `state` | texto | Como en `/api/fixtures`. |
| `state_label` | texto | Estado en español. |
| `display_clock` | texto o null | Reloj del partido. |
| `local_team` | objeto | Equipo local. |
| `guest_team` | objeto | Equipo visitante. |
| `local_score` | entero o null | Goles del local. |
| `guest_score` | entero o null | Goles del visitante. |
| `local_formation` | texto o null | Formación **real** del local (no es fantasy). |
| `guest_formation` | texto o null | Formación real del visitante. |
| `venue` | texto | Estadio (`""` si no consta). |
| `venue_city` | texto | Ciudad (`""` si no consta). |
| `attendance` | entero o null | Espectadores. |
| `referee` | texto | Árbitro (`""` si no consta). |
| `local_possession` | número o null | Posesión del local (%). |
| `guest_possession` | número o null | Posesión del visitante (%). |
| `lineups` | lista | Todos los convocados de ambos equipos (titulares y suplentes). Vacía antes de que haya alineaciones. |
| `lineups[].id` | entero | Id de la fila. |
| `lineups[].player` | objeto o null | `{id, nickname, image}`; `null` si no está vinculado (usa `unresolved_name`). |
| `lineups[].player.id` | entero | Id del jugador. |
| `lineups[].unresolved_name` | texto o null | Nombre en bruto de un jugador no vinculado. |
| `lineups[].team_id` | entero | Equipo con el que juega. |
| `lineups[].starter` | booleano | Titular. |
| `lineups[].pitch_position` | texto | Puesto real en el campo (vocabulario de worldcup26, p. ej. `"Right Back"`; `"Substitute"` en el banquillo; `""` si no consta). No es la posición fantasy. |
| `lineups[].jersey` | texto | Dorsal. |
| `lineups[].subbed_in` | booleano | Entró desde el banquillo. |
| `lineups[].subbed_out` | booleano | Fue sustituido. |
| `lineups[].sub_minute` | entero o null | Minuto del cambio. |
| `lineups[].counterpart_player` | objeto o null | El otro jugador del cambio `{id, nickname}`. |
| `lineups[].points` | entero o null | Puntos fantasy en este partido. |
| `lineups[].stats` | objeto | Desglose `{clave: [valor, puntos]}` (apartado 2.3). |
| `lineups[].dazn_estimate` | entero o null | Estimación de Comando Lechuga (0–4): provisional mientras la nota oficial no se ha publicado (visible desde que el jugador entra al campo); congelada para comparar una vez publicada. `null` si no hay estimación. |
| `lineups[].dazn_estimate_version` | texto | Baremo usado (`"v1"`); `""` sin estimación. |
| `events` | lista | Goles, tarjetas y decisiones del VAR, por minuto. |
| `events[].id` | entero | Id del evento. |
| `events[].minute` | entero | Minuto. |
| `events[].type` | texto | `goal`, `yellow_card`, `red_card` o `var`. |
| `events[].team_id` | entero | Equipo. |
| `events[].player` | objeto o null | `{id, nickname}`; `null` si no está vinculado. |
| `events[].player.id` | entero | Id del jugador. |
| `events[].unresolved_name` | texto o null | Nombre en bruto. |
| `events[].is_own_goal` | booleano | Gol en propia puerta (solo `goal`). |
| `events[].is_penalty` | booleano | De penalti (solo `goal`). |
| `events[].label` | texto o null | Solo en `var`: "Decisión del VAR" o "Tarjeta ascendida". |
| `team_stats` | lista | Estadísticas comparadas: 8 fijas (tiros a puerta, tiros totales, faltas cometidas, fueras de juego, paradas, asistencias, amarillas, rojas), más córners y pases clave cuando hay datos. |
| `team_stats[].stat` | texto | Clave en inglés (estable): `shotsOnTarget`, `totalShots`, `wonCorners`, `keyPasses`, `foulsCommitted`, `offsides`, `saves`, `goalAssists`, `yellowCards`, `redCards`. |
| `team_stats[].label` | texto | Etiqueta en español. |
| `team_stats[].local` | entero | Valor del local. |
| `team_stats[].guest` | entero | Valor del visitante. |

### GET /api/teams

La clasificación real de LaLiga (partidos terminados y en juego) y, para cada equipo, su próximo partido con el once probable (FútbolFantasy) o confirmado (worldcup26): la formación real y el % de cada jugador. Sin parámetros.

```json
{
  "data": [
    {
      "rank": 1, "team": { "id": 39, "name": "FC Barcelona", "logo": "…" },
      "played": 7, "won": 7, "drawn": 0, "lost": 0, "goals_for": 31, "goals_against": 7, "goal_difference": 24, "points": 21,
      "recent_form": [ { "fixture_id": 63, "opponent": { "id": 23, "name": "Sevilla FC", "logo": "…" }, "score": "3-1", "result": "win", "date": "2026-09-19T21:00:00+02:00" } ],
      "live": null,
      "next_fixture": {
        "fixture_id": 77, "url": "https://comandolechuga.com/api/fixtures/77", "week_number": 8, "date": "2026-10-10T18:30:00+02:00",
        "opponent": { "id": 22, "name": "Getafe CF", "logo": "…" }, "is_home": true,
        "lineup": {
          "source": "futbolfantasy", "confirmed": false, "formation": "4-3-3", "is_stale": false,
          "fetched_at": "2026-09-28T19:09:17+02:00", "source_url": "https://www.futbolfantasy.com/laliga/equipos/barcelona",
          "players": [ { "player": { "id": 746, "url": "…", "nickname": "Lamine Yamal", "position": "striker" }, "probability": 95, "predicted_starter": true, "confirmed_starter": null, "pitch_position": "Right Forward" } ]
        }
      }
    }
  ],
  "meta": { "generated_at": "…", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].rank` | entero | Posición en la tabla real (puntos, luego diferencia de goles, luego goles a favor). |
| `[].team` | objeto | El equipo `{id, name, logo}`. Su `id` sirve para el filtro `team=` de `/api/players`. |
| `[].team.id` | entero | Id del equipo. |
| `[].team.name` | texto | Nombre. |
| `[].played` | entero | Partidos jugados. |
| `[].won` | entero | Ganados. |
| `[].drawn` | entero | Empatados. |
| `[].lost` | entero | Perdidos. |
| `[].goals_for` | entero | Goles a favor. |
| `[].goals_against` | entero | Goles en contra. |
| `[].goal_difference` | entero | Diferencia de goles. |
| `[].points` | entero | Puntos de LaLiga (no fantasy): 3 por victoria, 1 por empate. |
| `[].recent_form` | lista | Hasta 4 últimos resultados terminados, del más reciente al más antiguo. |
| `[].recent_form[].fixture_id` | entero | Partido. |
| `[].recent_form[].opponent` | objeto | Rival. |
| `[].recent_form[].score` | texto | Marcador desde su lado, p. ej. `"2-1"`. |
| `[].recent_form[].result` | texto | `win`, `draw` o `loss`. |
| `[].recent_form[].date` | fecha y hora | Fecha. |
| `[].live` | objeto o null | Su partido en juego ahora (misma forma que `recent_form[]`, con el marcador actual); `null` si no juega. |
| `[].next_fixture` | objeto o null | Su próximo partido programado; `null` si no le quedan. |
| `[].next_fixture.fixture_id` | entero | Id del partido. |
| `[].next_fixture.url` | texto | URL de su ficha. |
| `[].next_fixture.week_number` | entero | Jornada. |
| `[].next_fixture.date` | fecha y hora | Hora de inicio. |
| `[].next_fixture.opponent` | objeto | Rival. |
| `[].next_fixture.is_home` | booleano | Si juega en casa. |
| `[].next_fixture.lineup` | objeto o null | Once probable o confirmado; `null` si aún no hay datos. |
| `[].next_fixture.lineup.source` | texto | `worldcup26` (alineación oficial confirmada) o `futbolfantasy` (once probable o su alineación confirmada: cítalo). |
| `[].next_fixture.lineup.confirmed` | booleano | Si la alineación ya está confirmada. |
| `[].next_fixture.lineup.formation` | texto o null | Formación **real** del equipo (no es fantasy). |
| `[].next_fixture.lineup.is_stale` | booleano | Dato de FútbolFantasy de más de 48 horas. |
| `[].next_fixture.lineup.fetched_at` | fecha y hora o null | Cuándo se obtuvo. |
| `[].next_fixture.lineup.source_url` | texto | Página de FútbolFantasy del equipo; `""` si no la tenemos. |
| `[].next_fixture.lineup.players` | lista | Jugadores con dato. |
| `[].next_fixture.lineup.players[].player.id` | entero | Id del jugador. |
| `[].next_fixture.lineup.players[].player.url` | texto | URL de su ficha. |
| `[].next_fixture.lineup.players[].player.nickname` | texto | Apodo. |
| `[].next_fixture.lineup.players[].player.position` | texto o null | Posición fantasy en el campo. |
| `[].next_fixture.lineup.players[].probability` | entero o null | % de titularidad de FútbolFantasy. |
| `[].next_fixture.lineup.players[].predicted_starter` | booleano | En el once probable. |
| `[].next_fixture.lineup.players[].confirmed_starter` | booleano o null | Titular confirmado; `null` sin confirmar. |
| `[].next_fixture.lineup.players[].pitch_position` | texto o null | Su puesto real en el once (p. ej. `"Right Back"`); `null` si no es titular. |

---

## 6. Glosario

| Español | English | En la API |
|---|---|---|
| jornada | matchweek | `week_number`, `/api/season` |
| clasificación de la liga | league standings | `/api/standings` |
| posición en la liga | league rank | `rank`, `last_rank` |
| clasificación real de LaLiga | LaLiga table | `/api/teams` → `rank`, `points` (puntos de liga, no fantasy) |
| posición en el campo | playing position (GK/DEF/MID/FWD) | `position` (`goalkeeper`, `defender`, `midfield`, `striker`) |
| puesto real en el campo | on-pitch role | `pitch_position` |
| valor de mercado | market value | `market_value` |
| valor de la plantilla | squad value | `squad_value` |
| saldo | cash balance | no está en la API: pregúntalo |
| puntos | fantasy points | `points` |
| media | average points | `average_points` |
| once / alineación | starting XI / lineup | `current_lineup`, `lineup_history` |
| formación | formation | `formation`, `tactical_formation` |
| titular / suplente | starter / substitute | `starter`, `confirmed_starter` |
| probabilidad de titularidad | start probability | `next_start.probability` |
| mercado | transfer market | `/api/market` |
| puja | bid | `bids` |
| fichaje | signing | `signing` |
| venta | sale | `sale` |
| cláusula / clausulazo | buyout clause / buyout | `buyout_clause`, `buyout` |
| blindaje | shield | `shield`, `shielded` |
| cobro de la jornada | weekly payout | `weekly_prize` |
| manager | fantasy manager (league member) | `manager`, `source_manager`, `owner_manager` |
| equipo real | LaLiga club | `team` |
| libre | free agent (unowned) | `owner_manager: null`, `free=1` |
| nota DAZN | DAZN rating | `marca_points` |
| aplazado | postponed | `postponed` |
| en juego | live | `live`, `first_half`, `half_time`, `second_half` |
| tendencia de mercado | market trend | `market_trend` |
| dificultad del rival | opponent difficulty | `difficulty`, `difficulty_variant`, `rival_position` |
| plusvalía | owner's paper gain | `owner_gain` |
| hora de Madrid | Madrid time (CET/CEST) | `meta.timezone`, offset `+01:00`/`+02:00` |

---

## 7. Cambios respecto a la versión anterior de la API

Estos cambios **rompen** a los clientes que usaban la versión anterior:

- `/api/players`: el filtro `season_manager` ahora es `manager`.
- Actividad (en `/api/activity`, `recent_activity` y `ownership_activity`): `source_season_manager` y `target_season_manager` ahora son `source_manager` y `target_manager`.
- `/api/standings` y `/api/managers/{id}`: `position` → `rank`, `last_position` → `last_rank`, `value` → `squad_value`.
- `/api/market` y `market_listing`: `value` → `market_value`; nuevo `seller`.
- `/api/fixtures/{id}`: `lineups[].position` → `lineups[].pitch_position`.
- `/api/managers/{id}`:
  - `roster[].buyout_clause` ahora es un objeto (`amount`, `locked_until`, `is_locked`, `shielded`, `shielded_until`); desaparecen `roster[].buyout_clause_locked_until`, `roster[].shielded` y `roster[].shielded_until`;
  - `roster[].player` tiene la forma completa de `/api/players`;
  - `lineup_history` solo trae jornadas terminadas;
  - la jornada en curso o próxima está en `current_lineup`.
- `average_points` es un número, no un texto.
- Todas las fechas y horas vienen en hora de Madrid (`+01:00`/`+02:00`).
- En `/api/players` y `/api/activity`, un parámetro desconocido o un valor no válido devuelve 422 en lugar de ignorarse (también `position=coach` y `status=out_of_league`).
- **2026-09-29:** `next_fixtures[].difficulty` pasa de −1…+1 (+1 = fácil) a 0–10 (10 = difícil); nuevo `difficulty_variant`.

Novedades:
- `/api/season` y `/api/teams`.
- `meta.generated_at` y `meta.timezone` en todas las respuestas.
- En cada jugador: `next_start`, `value_trend_30d`, `points_per_million` y `owner_gain`; en sus próximos partidos (`next_fixtures[]`), `fixture_id`, `date`, `rival_position` y `difficulty`.
- En `scores[]`: `fixture_state`, `minutes`, `marca_points`, `starter`, `subbed_in`, `subbed_out` y `sub_minute`.
- En `/api/players`: los filtros `free`, `min_value`, `max_value` y `min_start_probability`, y los órdenes `trend` y `points_per_million`.
- En `/api/managers/{id}`: `live_points`, `daily_value_difference`, `played_weeks`, `average_points`, `week_ranks`, `shields`, `current_lineup`, `lineup_history[].formation` y `roster[].purchase`.
- En `/api/standings`: `shields` (los blindajes que le quedan a cada manager en la jornada actual).
- **2026-09-29:** `dazn_estimate` y `dazn_estimate_version` en `scores[]` (`/api/players/{id}`), `lineups[]` (`/api/fixtures/{id}`) y `lineup_history[].players[]` (`/api/managers/{id}`): la estimación propia de la nota DAZN mientras no hay oficial (apartado 2.4).
- **2026-09-29:** `scores[].marca_points` (`/api/players/{id}`) ahora es siempre la nota oficial ya resuelta (`null` hasta que se publica), en vez del par en bruto de la Fantasy; el par en bruto de `stats.marca_points` no cambia y sigue sin fiarse de él mientras el partido no se ha publicado (apartado 2.4).
