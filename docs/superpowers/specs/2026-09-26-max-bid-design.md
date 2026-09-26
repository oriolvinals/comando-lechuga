# Puja máxima rentable (ficha de jugador, acceso oculto)

## Motivación

Al fichar un jugador en LaLiga Fantasy hay que pujar por encima de su valor de mercado, y la duda siempre es **cuánto se puede pagar sin perder dinero**. Este spec añade a la ficha del jugador (`/jugadores/{player}`) una cifra orientativa: la **puja máxima rentable**, o el texto **"Sin rentabilidad"** cuando no conviene ficharlo por dinero.

Inspirado en la "puja máxima rentable" de FútbolFantasy, pero con las mecánicas concretas del juego que usa la liga:

- **Blindaje de 14 días:** al fichar a un jugador, nadie te lo puede clausular durante 14 días. Después, la cláusula es `max(valor actual, precio pagado)`, así que un clausulazo nunca hace perder dinero. La ventana en la que la inversión se gana o se pierde es, por tanto, **14 días**.
- **Oferta diaria del mercado:** si pones al jugador en el mercado, cada día a las 20:00 el juego te hace una oferta **aleatoria y uniforme entre el −10 % y el +10 % de su valor de ese día**. Puedes aceptarla hasta las 19:59 del día siguiente, y después la sustituye otra nueva. En 14 días hay unas 14 ofertas.

El modelo es conservador. No puede prever lesiones futuras, expulsiones ni fichajes de última hora: solo usa lo ocurrido hasta hoy. Del estado del jugador solo cuenta el **estado actual** que da el proveedor (lesionado, en duda, sancionado…). No se tiene historial de lesiones.

## Alcance

Dentro:
- Servicio de cálculo con fecha de referencia.
- Extracción de la clasificación real a un servicio reutilizable.
- Prop oculto en la ficha.
- Bloque visual (variantes "B · proyección" + "C · desglose" combinadas).
- Comando de backtest.
- Tests.

Fuera:
- Mostrarlo en listados o en el mercado de la home.
- Persistir estimaciones.
- Ajustar las constantes con datos. Eso se hará después, a partir de lo que diga el backtest.
- Cualquier sistema de autenticación.

## Acceso oculto

El bloque solo existe si la URL de la ficha lleva el parámetro `puja` (p. ej. `/jugadores/268?puja`). Sin él, `PlayersController::show()` **ni calcula ni envía** el prop: `maxBid` es `null` y la página no pinta nada.

No es una medida de seguridad (no hay login en la app). Sirve para que el público general no lo vea.

## Modelo

Todas las constantes viven en el calculador como constantes con nombre, para poder ajustarlas tras el backtest.

### Entradas

Todas se toman **hasta la fecha de referencia** (por defecto `now()`), nunca después:

1. **Histórico de valor** (`player_markets`): hacen falta al menos los 4 últimos días.
2. **Índice de mercado:** variación del valor total del mercado en los mismos 3 días. Se calcula como la suma de valores de los jugadores de la temporada que no están fuera de la liga y tienen valor en ambos extremos. Está ponderado por valor, no es una mediana: la mediana de % la hunden los jugadores baratos.
3. **Forma:** `fantasy_points` de sus últimas 3 alineaciones en partidos terminados frente a su `average_points` de temporada.
4. **Participación:** los últimos 3 partidos terminados **de su equipo** (`fixture_lineups`), con el titular y los minutos (`fantasy_stats.mins_played[0]`). No estar en la alineación cuenta como 0 minutos.
5. **Rivales:** los próximos 3 partidos de su equipo después de la fecha de referencia, con la **posición del rival en la clasificación real** en esa fecha y los **días que faltan** hasta cada uno.
6. **Estado actual** del jugador (`players.status`).

### Cálculo

```
momentum     = (valor_hoy − valor_hace_3_días) / 3                     € al día
mercado      = −ritmo_mercado_3d × valor_hoy                           € al día (corrige "sube/baja todo")

cercanía(d)  = 0,5^(d / 7)                                             semivida de 7 días

forma        = clamp((media_últimas_3 − media_temporada) / max(media_temporada, 2), −1, 1)
participación_partido = 0,5 × titular + 0,5 × min(minutos / 90, 1)
participación = 0,5·último + 0,3·anterior + 0,2·antepenúltimo          0…1
participación_norm = 2 × participación − 1                             −1…1
dificultad(rival) = (posición − (N+1)/2) / ((N−1)/2)                   −1 (líder) … +1 (colista)
rivales      = Σ cercanía(días_i) × dificultad_i / 3

S = cercanía(días_al_próximo_partido) × (0,4·forma + 0,3·participación_norm) + 0,3 × 3 × rivales
si estado = en duda → S = min(S, −0,5)

deportivo    = valor_hoy × S × 1 %                                     € al día
incremento   = momentum + mercado + deportivo
si participación < 0,4 y incremento > 0 → incremento / 2

valor_t      = valor_{t−1} + incremento × 0,9^t        para t = 1…14   (el incremento se apaga un 10 % diario)
```

Con N = número de equipos de la clasificación. La forma y la participación pesan según lo cerca que esté el próximo partido; cada rival pesa según lo cerca que esté su partido. En un parón (hoy, 14 días hasta la J8) manda el momentum; en semana de partido manda lo deportivo.

**Puja:** es la cifra `x` que **la mejor de las 14 ofertas** (una al día, uniforme en `[0,9·valor_t, 1,1·valor_t]`) supera con un **75 % de probabilidad**:

```
P(mejor oferta ≤ x) = Π_t clamp((x / valor_t − 0,9) / 0,2, 0, 1) = 0,25
```

Se resuelve por bisección entre `0,9·min(valor_t)` y `1,1·max(valor_t)`.

### Estados

| Estado | Condición | Se muestra |
|---|---|---|
| `unavailable` | Lesionado, sancionado o fuera de la liga | "Sin rentabilidad" + motivo (estado) |
| `no_data` | Menos de 4 días de histórico de valor | "Sin datos suficientes" |
| `unprofitable` | `incremento ≤ 0` (la proyección baja) | "Sin rentabilidad" + pérdida proyectada a 14 días |
| `profitable` | En cualquier otro caso | Puja + % sobre valor |

Si la proyección baja, **la oferta aleatoria no lo rescata**. Lo que ganaras sería suerte, no rentabilidad. Solo lo deportivo puede girar la proyección.

### Referencias con datos reales (26/09, parón de 14 días hasta la J8)

| Jugador | Valor | Puja | |
|---|---|---|---|
| Koski | 20,87 M€ | 29,18 M€ (+39,8 %) | El usuario pagaría "unos 30 M" |
| Álex Baena | 71,52 M€ | 86,02 M€ (+20,3 %) | |
| Arda Güler | 106,90 M€ | 121,56 M€ (+13,7 %) | |
| Satriano | 18,73 M€ | 20,66 M€ (+10,3 %) | |
| Olmo | 48,06 M€ | Sin rentabilidad | Proyección ligeramente a la baja |
| Fermín | 96,93 M€ | Sin rentabilidad | −10,75 M€ en 14 días |

## Arquitectura

### `App\Services\LeagueStandings` (extracción)

`TeamsController::standingsFixtures()` y `standingsFor()` pasan a un servicio con el mismo comportamiento: partidos terminados más los que están en juego, y desempate por puntos → diferencia de goles → goles a favor → nombre. `TeamsController` delega en él y su salida no cambia (los tests existentes de `TeamsControllerTest` lo cubren). Se añade un parámetro opcional `?CarbonInterface $until` que limita a partidos con `date <= $until`, para calcular la clasificación en una fecha pasada.

### `App\Services\MaxBidCalculator`

```php
public function estimate(Player $player, Season $season, ?CarbonInterface $at = null): MaxBidEstimate
```

- Carga los datos del jugador hasta `$at` (por defecto `now()`).
- Calcula el índice de mercado y la clasificación en `$at` una sola vez por fecha, cacheados en la instancia para que el backtest no los recalcule por jugador.
- Devuelve el DTO.

### `App\Services\MaxBidEstimate` (DTO de solo lectura) y enum `App\Enums\MaxBidStatus`

`MaxBidStatus`: `Profitable`, `Unprofitable`, `Unavailable`, `NoData`.

`MaxBidEstimate::toArray()` (snake_case, como el resto de props):

```
status, value, bid (int|null), bid_premium (float|null),
projection: list<int> (15 valores: día 0 = valor de hoy … día 14),
projected_day7, projected_day14,
momentum_increment, market_adjustment, sport_adjustment, sport_score,
form, participation, recent_participation: list<{starter: bool, minutes: int}>,
rivals_effect, upcoming_rivals: list<{team: Team, position: int, days_until: int, difficulty: float, weight: float}> (los próximos 3 partidos, del más cercano al más lejano)
```

Los campos de proyección y de factores son `null` en `no_data`/`unavailable`. El DTO vive junto a su servicio en `app/Services/`: el proyecto no tiene carpeta de DTOs y AGENTS.md prohíbe crear carpetas base nuevas sin aprobación.

### `PlayersController::show()`

Añade el prop `maxBid`: `$request->has('puja') ? $calculator->estimate($player, $season)->toArray() : null`.

### Frontend

- **Tipos:** `MaxBidEstimate` y `MaxBidStatus` en `resources/js/types/models.ts`.
- **Componente `HqMaxBidCard`** (`resources/js/components/hq-max-bid-card.tsx`): las variantes **B + C** de la maqueta combinadas.
  - Cabecera "PUJA MÁX. RENTABLE" con una etiqueta discreta `?PUJA`.
  - Cifra grande en lima con el `+x %` sobre el valor, o bien "SIN RENTABILIDAD" en rojo con la pérdida proyectada o el motivo del estado.
  - **Mini gráfico de proyección** (SVG inline, sin librería) a partir de `projection`:
    - línea de valor de hoy → día 14 (lima si es rentable, roja si no);
    - franja de ofertas ±10 % sombreada;
    - línea base del valor de hoy;
    - puja como línea dorada discontinua con la etiqueta "puja";
    - eje con "hoy · día 7 · día 14 (valor)".

    No se muestra en `no_data`/`unavailable`.
  - Desglose de filas: Momentum (3 días), Mercado general y Deportivo (S). Deportivo se desglosa en forma, participación (con los minutos de los 3 partidos) y rivales (una línea por cada uno de los próximos 3 partidos: escudo, rival, posición, días hasta el partido y su peso por cercanía, con el efecto total al lado).
  - Proyección a los días 7 y 14.
  - Pie: "Mejor oferta esperada durante los 14 días de blindaje · 75 % de confianza".
  - Tinte verde o rojo según el estado, con `hq-card-cut`.
  - Texto funcional a **11 px como mínimo** (la maqueta usaba 9–10 px en el pie y las filas).
- **`players/show.tsx`:** lo pinta en la columna izquierda, **justo debajo de `HqPlayerPropertyCard`**, solo si `maxBid !== null`.

### Comando `season:backtest-max-bid {--from=} {--to=}`

Para cada fecha D del rango (por defecto, todo el histórico que tenga D+14 disponible) y cada jugador de la temporada con estado `ok` o `doubtful`:

1. Calcula `estimate(player, season, D)`.
2. Con los valores reales de D+1…D+14:
   - **Error de proyección:** `|valor_real_día14 − proyectado_día14| / valor_D`.
   - **Si `profitable`:** calcula la probabilidad real de que la mejor oferta, con los valores reales, supere la puja (`1 − Π_t clamp((bid / real_t − 0,9) / 0,2, 0, 1)`). La media debería rondar el 75 %.
   - **Si `unprofitable`:** % de casos en que el valor real del día 14 acabó por encima del de D, es decir, falsos negativos.
3. Muestra una tabla con el nº de estimaciones, el error medio y la mediana de la proyección, la probabilidad media real en los `profitable` y la tasa de falsos negativos en los `unprofitable`, desglosada:
   - por `market_trend`;
   - por "parón" (próximo partido a más de 7 días) frente a "semana de partido".

No escribe nada en la base de datos.

## Tests

- **`LeagueStandings`:** los tests actuales de `TeamsControllerTest` siguen verdes. Se añade uno nuevo con `$until`, que excluye los partidos posteriores.
- **`MaxBidCalculator`** (feature, con factories):
  - un jugador que sube da `profitable` con puja > valor;
  - uno que baja da `unprofitable`;
  - lesionado, sancionado o fuera de la liga dan `unavailable`;
  - menos de 4 días de histórico da `no_data`;
  - en duda da S ≤ −0,5;
  - un suplente (participación < 0,4) ve su incremento reducido a la mitad;
  - un rival fuerte cercano pesa más que uno lejano;
  - la fecha de referencia ignora datos posteriores;
  - la puja cumple `P(mejor oferta ≥ puja) = 0,75` con una proyección conocida.
- **`PlayersControllerTest`:** sin `?puja`, `maxBid` es `null`; con `?puja`, llega con `status` y `bid`.
- **Backtest:** con un histórico pequeño construido a mano, el comando termina bien y muestra las métricas esperadas.

## Rama y entrega

Iniciativa nueva, así que va en su propia rama `feature/max-bid`, con un commit por funcionalidad, y se mergea a `main` solo tras la aprobación explícita del usuario.
