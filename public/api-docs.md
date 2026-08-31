# API pública de Comando Lechuga

Referencia de la API de solo lectura de [comandolechuga.com](https://comandolechuga.com), pensada para que un agente de IA (o cualquier cliente automatizado) pueda consultar los datos de la liga sin tener que renderizar JavaScript. Todos los endpoints ya se muestran de una forma u otra en la web — esta API expone los mismos datos en JSON.

## Conceptos generales

- **Base URL**: `https://comandolechuga.com/api`
- **Acceso**: pública, sin autenticación, sin API key, sin cookies ni sesión. Basta un `GET` directo a la URL.
- **Formato**: JSON. Todas las respuestas tienen la forma `{"data": ...}` — un objeto para la ficha de un recurso concreto, un array para un listado.
- **Temporada**: todos los endpoints trabajan siempre sobre la temporada actual de la liga. No existe forma de consultar temporadas pasadas a través de la API.
- **Sin versión en la URL**: las rutas son `/api/<recurso>`, no `/api/v1/<recurso>`. Es una API pequeña, pensada para un consumidor controlado.
- **Sin rate limiting**: no hay límite de peticiones por IP ni por token.
- **Fechas**: siempre en ISO 8601 con offset horario, p. ej. `2026-08-30T18:05:00+02:00`.
- **Dinero**: siempre en euros como unidad entera (nunca céntimos), p. ej. `45000000` son 45.000.000 €.
- **Imágenes** (`logo`, `image`): siempre URLs absolutas y completas, listas para usar directamente en un `<img src>`. Cuando un recurso no tiene imagen, el campo es una cadena vacía `""`, nunca `null`.
- **IDs**: los `id` de cada recurso son los IDs internos de Comando Lechuga (no los de la Fantasy oficial ni los de ningún proveedor externo). Para enlazar entre recursos (p. ej. de una actividad a un manager) usa siempre estos IDs.

### Paginación

Solo paginan `/api/activity` y `/api/players` (30 y 15 elementos por página respectivamente). El resto de listados devuelven todo de una vez porque son pequeños (la clasificación tiene un puñado de managers, el mercado ~10 jugadores al día, etc.).

Un endpoint paginado añade `meta` y `links` junto a `data`:

```json
{
  "data": [ ... ],
  "links": {
    "first": "https://comandolechuga.com/api/activity?page=1",
    "last": "https://comandolechuga.com/api/activity?page=12",
    "prev": null,
    "next": "https://comandolechuga.com/api/activity?page=2"
  },
  "meta": {
    "current_page": 1,
    "last_page": 12,
    "per_page": 30,
    "total": 349
  }
}
```

Para pedir la página siguiente añade `?page=2` a la URL (combinable con cualquier otro filtro del mismo endpoint).

### Errores

Un recurso que no existe (un manager, partido o jugador con un ID inválido) devuelve **404** con un JSON del tipo:

```json
{ "message": "No query results for model [App\\Models\\Player] 999999" }
```

No hay otros códigos de error relevantes para un cliente de solo lectura sin autenticación (no hay 401/403 posibles, no hay validaciones de escritura).

### Endpoints disponibles

| Método | Ruta | Qué es |
|---|---|---|
| GET | `/api/standings` | Clasificación general |
| GET | `/api/activity` | Actividad de la liga (fichajes, ventas, cláusulas...), paginada |
| GET | `/api/managers/{id}` | Ficha de un manager fantasy: plantilla, histórico de alineaciones, actividad |
| GET | `/api/fixtures` | Calendario de partidos, agrupado por jornada |
| GET | `/api/fixtures/{id}` | Ficha de un partido: alineaciones, eventos, estadísticas |
| GET | `/api/players` | Listado de jugadores, filtrable y ordenable |
| GET | `/api/players/{id}` | Ficha de un jugador: mercado, puntuaciones, actividad de propiedad |
| GET | `/api/market` | Jugadores en el mercado ahora mismo |

---

## `GET /api/standings`

La clasificación general de la liga, ordenada por posición.

```
GET https://comandolechuga.com/api/standings
```

Sin filtros ni paginación — devuelve todos los managers de la temporada actual.

```json
{
  "data": [
    {
      "id": 1,
      "name": "Gauchitos F.C",
      "logo": "https://comandolechuga.com/images/managers/37394521.png",
      "primary_color": null,
      "secondary_color": null,
      "position": 1,
      "last_position": 3,
      "total_points": 127,
      "value": 233996889,
      "recent_form": [
        { "week_number": 1, "points": 34, "live": false },
        { "week_number": 2, "points": 47, "live": false },
        { "week_number": 3, "points": 46, "live": true }
      ]
    }
  ]
}
```

**`recent_form`**: las últimas jornadas relevantes de ese manager, de la más antigua a la más reciente. Nunca tiene relleno artificial — si la temporada solo lleva 1 jornada jugada, el array tiene 1 elemento, no 3. Cada entrada dice explícitamente a qué jornada corresponde (`week_number`) y si es la jornada en curso (`live: true`) o una ya terminada (`live: false`). Cuando hay una jornada en curso, `recent_form` muestra las 2 últimas jornadas terminadas más esa jornada en vivo (3 entradas en total) — nunca las 3 últimas terminadas más la que está en curso aparte.

---

## `GET /api/activity`

El registro de actividad de la liga: fichajes, ventas, cláusulas pagadas, blindajes, premios semanales y altas de manager. Paginado, 30 por página, más reciente primero.

```
GET https://comandolechuga.com/api/activity
GET https://comandolechuga.com/api/activity?page=2
```

### Filtros (todos combinables, todos opcionales)

Parámetro | Valores | Ejemplo
---|---|---
`manager` | Uno o varios IDs de manager fantasy (de `/api/standings`), separados por comas — filtra actividad donde ese manager es `source_season_manager` **o** `target_season_manager` (p. ej. una cláusula pagada por otro manager a él también cuenta) | `?manager=4`
`player` | Uno o varios IDs de jugador, separados por comas | `?player=88`
`type` | Uno o varios de: `signing`, `sale`, `buyout`, `shield`, `weekly_prize`, `joined_league`, separados por comas | `?type=signing,sale`

Ejemplo combinando los tres, con paginación:

```
GET https://comandolechuga.com/api/activity?manager=4&player=88&type=signing,buyout&page=1
```

```json
{
  "data": [
    {
      "id": 123,
      "type": "signing",
      "type_label": "Fichaje",
      "occurred_at": "2026-08-30T18:05:00+02:00",
      "source_season_manager": { "id": 4, "name": "Comando Lechuga" },
      "target_season_manager": null,
      "player": { "id": 88, "nickname": "Pedri" },
      "amount": 12500000,
      "week_number": null,
      "value_difference": -300000
    }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." }
}
```

Campo | Significado
---|---
`type` | Uno de: `signing` (fichaje), `sale` (venta), `buyout` (pago de cláusula), `shield` (blindaje), `weekly_prize` (premio semanal), `joined_league` (alta de manager nuevo).
`type_label` | La etiqueta en español de `type`, ya traducida — úsala si solo necesitas mostrar/leer el tipo, no razonar sobre él.
`source_season_manager` | El manager que origina la actividad (quien ficha, quien vende, quien paga la cláusula...). Nunca `null`.
`target_season_manager` | Solo relevante en `buyout`: el manager al que se le paga la cláusula (el dueño anterior del jugador). `null` en el resto de tipos.
`player` | `null` solo en `joined_league` (un alta de manager no involucra a ningún jugador).
`amount` | El importe en euros de la operación. `null` cuando el tipo no tiene importe asociado (`shield`, `joined_league`).
`week_number` | Solo relevante en `weekly_prize` (qué jornada ganó). `null` en el resto.
`value_difference` | Diferencia entre `amount` y el valor de mercado del jugador ese día. `null` si no hay jugador, no hay importe, o no hay cotización de mercado registrada para esa fecha.

---

## `GET /api/managers/{id}`

La ficha completa de un manager fantasy: quién es, su plantilla actual, el histórico completo de alineaciones jornada a jornada, y su actividad reciente.

```
GET https://comandolechuga.com/api/managers/4
```

`{id}` es el ID del manager — se obtiene de `data[].id` en `/api/standings` o de `source_season_manager.id`/`target_season_manager.id` en `/api/activity`.

```json
{
  "data": {
    "id": 4,
    "name": "Comando Lechuga",
    "logo": "https://comandolechuga.com/images/managers/37394771.png",
    "primary_color": null,
    "secondary_color": null,
    "position": 4,
    "last_position": 2,
    "total_points": 120,
    "value": 162236068,
    "roster": [
      {
        "player": {
          "id": 8,
          "nickname": "Sivera",
          "image": "https://comandolechuga.com/storage/images/player/988.png",
          "position": "goalkeeper",
          "team": { "id": 21, "name": "Deportivo Alavés", "logo": "https://comandolechuga.com/storage/images/team/21.png" },
          "market_value": 38910520,
          "points": 30,
          "average_points": "10.00"
        },
        "buyout_clause": 45306429,
        "buyout_clause_locked_until": "2026-08-25T20:00:49+02:00",
        "shielded": false,
        "shielded_until": null
      }
    ],
    "lineup_history": [
      {
        "week_number": 1,
        "points": 34,
        "tactical_formation": [4, 4, 2],
        "players": [
          {
            "player": { "id": 8, "nickname": "Sivera", "image": "https://comandolechuga.com/storage/images/player/988.png" },
            "position": "goalkeeper",
            "points": 12,
            "match_finished": true
          }
        ]
      }
    ],
    "recent_activity": [ "...misma forma que /api/activity, últimas 10 entradas donde este manager es source o target..." ]
  }
}
```

Sección | Notas
---|---
`roster` | La plantilla actual completa (todos los jugadores que posee ahora mismo), sin paginar.
`lineup_history` | Una entrada por cada jornada jugada, de la 1 en adelante. `tactical_formation` es la formación como array de líneas (`[4, 4, 2]` = 4 defensas, 4 centrocampistas, 2 delanteros). Dentro de `players`, `points` es `null` cuando ese jugador no llegó a jugar ese partido (aunque su equipo sí jugara) — usa `match_finished` para distinguir "el partido de su equipo ya terminó pero él no puntuó" de "el partido de su equipo aún no se ha jugado".
`recent_activity` | Igual forma que cada entrada de `/api/activity` — reutilízala si ya conoces ese esquema.

---

## `GET /api/fixtures`

El calendario completo de la temporada actual, agrupado por jornada.

```
GET https://comandolechuga.com/api/fixtures
```

Sin filtros ni paginación — son 38 jornadas, un volumen pequeño.

```json
{
  "data": [
    {
      "week_number": 1,
      "fixtures": [
        {
          "id": 10,
          "date": "2026-08-15T19:30:00+02:00",
          "state": "finished",
          "state_label": "Finalizado",
          "local_team": { "id": 21, "name": "Deportivo Alavés", "logo": "https://comandolechuga.com/storage/images/team/21.png" },
          "guest_team": { "id": 22, "name": "Getafe CF", "logo": "https://comandolechuga.com/storage/images/team/9.png" },
          "local_score": 3,
          "guest_score": 0
        }
      ]
    }
  ]
}
```

`state` es uno de: `scheduled` (programado, aún no empezado), `first_half`, `half_time`, `second_half` (en juego), `finished`. `state_label` es la etiqueta en español ya traducida. Mientras el partido no ha empezado, `local_score`/`guest_score` son `null`.

---

## `GET /api/fixtures/{id}`

La ficha completa de un partido concreto: alineaciones, eventos (goles/tarjetas) y estadísticas comparadas por equipo.

```
GET https://comandolechuga.com/api/fixtures/10
```

`{id}` se obtiene de `data[].fixtures[].id` en `/api/fixtures`.

```json
{
  "data": {
    "id": 10,
    "date": "2026-08-15T19:30:00+02:00",
    "week_number": 1,
    "state": "finished",
    "state_label": "Finalizado",
    "local_team": { "id": 21, "name": "Deportivo Alavés", "logo": "..." },
    "guest_team": { "id": 22, "name": "Getafe CF", "logo": "..." },
    "local_score": 3,
    "guest_score": 0,
    "local_formation": "4-3-3",
    "guest_formation": "4-4-2",
    "lineups": [
      {
        "id": 3878,
        "player": { "id": 197, "nickname": "Djene", "image": "https://comandolechuga.com/storage/images/player/2661.png" },
        "unresolved_name": null,
        "team_id": 22,
        "starter": true,
        "position": "CentralDefender",
        "jersey": "3",
        "subbed_in": false,
        "subbed_out": true,
        "sub_minute": 75,
        "counterpart_player": { "id": 254, "nickname": "Suárez" },
        "points": 6,
        "stats": { "goals": [0, 0], "yellow_card": [1, 0], "...": "..." }
      }
    ],
    "events": [
      {
        "id": 2106,
        "minute": 83,
        "type": "goal",
        "team_id": 33,
        "player": { "id": 672, "nickname": "Pablo García" },
        "unresolved_name": null,
        "is_own_goal": false,
        "is_penalty": false
      }
    ],
    "team_stats": [
      { "stat": "shotsOnTarget", "label": "Tiros a puerta", "local": 3, "guest": 3 },
      { "stat": "totalShots", "label": "Tiros totales", "local": 16, "guest": 15 },
      { "stat": "foulsCommitted", "label": "Faltas cometidas", "local": 10, "guest": 11 },
      { "stat": "saves", "label": "Paradas", "local": 2, "guest": 3 },
      { "stat": "goalAssists", "label": "Asistencias", "local": 0, "guest": 1 },
      { "stat": "yellowCards", "label": "Tarjetas amarillas", "local": 2, "guest": 1 }
    ]
  }
}
```

Campo | Notas
---|---
`lineups` | Todos los jugadores de ambos equipos (titulares y suplentes), no solo los que jugaron. `player` es `null` cuando ese jugador de la alineación no se ha podido enlazar a la base de datos de la liga — en ese caso usa `unresolved_name` (su nombre tal cual, en texto) en su lugar.
`lineups[].position` | La posición táctica cruda de la fuente de datos (p. ej. `CentralDefender`, `Substitute`), **no** es el mismo vocabulario que `position` en `/api/players` (`goalkeeper`/`defender`/`midfield`/`striker`).
`lineups[].points`/`stats` | Puntos y estadísticas fantasy de ese jugador en ese partido. `stats` siempre trae las mismas claves (goles, asistencias, tarjetas, minutos jugados...); cuando no hay datos de Fantasy para un jugador no vinculado, se rellena con un fallback más limitado a partir de la fuente cruda.
`events` | Solo goles y tarjetas, ordenados por minuto. `is_own_goal`/`is_penalty` solo aplican a `type: "goal"`.
`team_stats` | Comparativa agregada de 6 estadísticas fijas. `stat` es la clave en inglés (estable, para procesar); `label` es la etiqueta en español (para mostrar).

---

## `GET /api/players`

El listado completo de jugadores de la liga, filtrable y ordenable. Paginado, 15 por página.

```
GET https://comandolechuga.com/api/players
```

### Filtros (todos combinables, todos opcionales)

Parámetro | Valores | Ejemplo
---|---|---
`position` | Una o varias de: `goalkeeper`, `defender`, `midfield`, `striker`, `coach`, separadas por comas | `?position=goalkeeper,striker`
`team` | Uno o varios IDs de equipo real (de `local_team.id`/`guest_team.id` en `/api/fixtures`), separados por comas | `?team=21,22`
`season_manager` | Uno o varios IDs de manager fantasy (de `/api/standings`), separados por comas — filtra por **propietario** del jugador | `?season_manager=4`
`status` | Uno o varios de: `ok`, `injured`, `doubtful`, `suspended`, `out_of_league`, separados por comas | `?status=injured,doubtful`
`search` | Texto libre, busca por apodo del jugador. Insensible a mayúsculas y a acentos (`valentin` encuentra a "Valentín") | `?search=pedri`

**Importante**: los jugadores con `status: out_of_league` (fuera de la liga — ya no elegibles) se excluyen siempre del listado, incluso si los pides explícitamente con `?status=out_of_league`. No hay forma de listarlos vía esta API.

### Orden

Parámetro | Valores | Por defecto
---|---|---
`sort` | `points` (puntos totales), `value` (valor de mercado), `difference` (diferencia de valor respecto al día anterior) | `points`
`direction` | `asc`, `desc` | `desc`

Ejemplo: los jugadores que más están subiendo de precio primero:

```
GET https://comandolechuga.com/api/players?sort=difference&direction=desc
```

### Paginación

```
GET https://comandolechuga.com/api/players?page=2
```

### Combinando todo

Centrocampistas o delanteros del Real Madrid o el Barcelona, ordenados por valor de mercado descendente:

```
GET https://comandolechuga.com/api/players?position=midfield,striker&team=38,39&sort=value&direction=desc
```

### Forma de cada jugador

```json
{
  "id": 646,
  "nickname": "Raphinha",
  "image": "https://comandolechuga.com/storage/images/player/2522.png",
  "status": "ok",
  "position": "striker",
  "team": { "id": 39, "name": "FC Barcelona", "logo": "https://comandolechuga.com/storage/images/team/4.png" },
  "market_value": 111065934,
  "market_value_difference": 2377272,
  "points": 39,
  "average_points": "19.50",
  "owner_manager": null,
  "recent_scores": [
    { "week_number": 1, "opponent": { "id": 35, "name": "Athletic Club", "logo": "..." }, "points": 15 },
    { "week_number": 2, "opponent": { "id": 30, "name": "Elche CF", "logo": "..." }, "points": 24 }
  ],
  "next_fixtures": [
    { "week_number": 3, "opponent": { "id": 24, "name": "Rayo Vallecano", "logo": "..." }, "is_home": true }
  ]
}
```

Campo | Notas
---|---
`status` | `ok`, `injured`, `doubtful`, `suspended` (nunca `out_of_league`, excluidos del listado).
`owner_manager` | `null` si el jugador está libre (nadie lo tiene fichado). Si no es `null`: `{id, name, logo, primary_color}`.
`recent_scores` | Los últimos (hasta 3) partidos ya terminados de su equipo, del más antiguo al más reciente. Sin relleno — 0 a 3 entradas según los partidos que lleve jugados la temporada. `points` es `null` si el jugador no llegó a puntuar ese partido (lesión, no convocado...), aunque su equipo sí jugara.
`next_fixtures` | Los próximos (hasta 3) partidos programados de su equipo, del más próximo al más lejano. Igual de sin relleno que `recent_scores`.

---

## `GET /api/players/{id}`

La ficha completa de un jugador: quién lo tiene fichado, si está en el mercado, su historial de valor de mercado, sus puntuaciones partido a partido y su historial de fichajes/ventas/cláusulas.

```
GET https://comandolechuga.com/api/players/646
```

`{id}` se obtiene de `data[].id` en `/api/players` o `/api/market`, o de `player.id` en `/api/activity`.

```json
{
  "data": {
    "id": 646,
    "nickname": "Raphinha",
    "image": "https://comandolechuga.com/storage/images/player/2522.png",
    "status": "ok",
    "position": "striker",
    "team": { "id": 39, "name": "FC Barcelona", "logo": "..." },
    "market_value": 111065934,
    "market_value_difference": 2377272,
    "points": 39,
    "average_points": "19.50",
    "owner_manager": null,
    "market_listing": null,
    "market_history": [
      { "date": "2026-08-19", "value": 4400000 },
      { "date": "2026-08-20", "value": 4500000 }
    ],
    "scores": [
      {
        "fixture_id": 3,
        "week_number": 1,
        "opponent": { "id": 34, "name": "Real Sociedad", "logo": "..." },
        "is_home": true,
        "points": 15,
        "stats": { "goals": [0, 0], "goal_assist": [2, 6], "...": "..." },
        "lineup_manager": null
      }
    ],
    "ownership_activity": [ "...misma forma que /api/activity, solo signing/sale/buyout de este jugador, del más antiguo al más reciente..." ],
    "next_fixtures": [ "...misma forma que en /api/players..." ]
  }
}
```

Campo | Notas
---|---
`market_listing` | `null` si el jugador no está en el mercado ahora mismo. Si lo está: `{sale_price, value, bids, expires_at}`.
`market_history` | Su cotización día a día, de la más antigua a la más reciente. Puede estar vacío si nunca se le ha registrado valor.
`scores` | Uno por cada partido que ha jugado (o al menos ha tenido datos de alineación) esta temporada, de la jornada 1 en adelante — a diferencia de `recent_scores` en `/api/players`, aquí es el historial **completo**, no solo los últimos 3. `is_home` se calcula sobre el equipo con el que jugó ese partido en concreto, no su equipo actual (puede haber cambiado de club a media temporada). `lineup_manager` es el manager fantasy que lo alineó esa jornada (`{id, name}`), o `null` si nadie lo alineó o no estaba fichado.
`ownership_activity` | Solo actividad de tipo `signing`, `sale` o `buyout` — el historial de "quién ha tenido a este jugador". No incluye `shield` ni `weekly_prize`.

Un jugador sin `fantasy_id` (aún no vinculado a la Fantasy oficial) devuelve **404** aunque exista internamente — igual que en la web.

---

## `GET /api/market`

Los jugadores en el mercado de fichajes ahora mismo (libres, con puja abierta), del que antes caduca al que más tarda.

```
GET https://comandolechuga.com/api/market
```

Sin filtros ni paginación — es una lista corta (~10 jugadores al día). Excluye entrenadores (`position: coach`) y cualquier oferta ya caducada.

```json
{
  "data": [
    {
      "player": { "...": "misma forma completa que cada entrada de /api/players..." },
      "sale_price": 12934570,
      "value": 13355720,
      "bids": 2,
      "expires_at": "2026-08-31T20:00:00+02:00"
    }
  ]
}
```

`sale_price` es el precio al que salió a la venta ese listado (no se actualiza mientras dura la oferta, aunque el mercado cambie cada día); `value` es la valoración de mercado del jugador; `bids` es el número de pujas recibidas hasta ahora.
