# Separar `Player` en `Player` + `PlayerSeason`

## Motivación

`Player` mezcla dos tipos de dato: identidad estable del jugador (`fantasy_id`, `nickname`, `position`, `image`, `status`, `team_id`) y datos que solo tienen sentido por temporada (`market_value`, `market_value_difference`, `points`, `average_points`). Es el primer paso de un trabajo más amplio para poder enlazar datos de partido de una API externa (worldcup26.ir) contra jugadores propios — trabajo que se especificará por separado. Esta fase solo cubre el split del modelo; no toca la API externa.

## Reparto de campos

**`players`** (identidad — no cambia entre temporadas):
- `fantasy_id`, `nickname`, `status`, `image`, `team_id`

**`player_seasons`** (nueva tabla, una fila por `(player_id, season_id)`):
- `player_id`, `season_id`, `position`, `market_value`, `market_value_difference`, `points`, `average_points`

`status` se queda en `Player` a petición explícita — se trata como estado actual del jugador, no como histórico de temporada. `position` se mueve a `PlayerSeason` (a petición explícita) junto con los datos de temporada.

## Modelo `PlayerSeason`

```php
#[Table(name: 'player_seasons', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['player_id', 'season_id', 'position', 'market_value', 'market_value_difference', 'points', 'average_points'])]
class PlayerSeason extends Model
{
    public function player(): BelongsTo { return $this->belongsTo(Player::class); }
    public function season(): BelongsTo { return $this->belongsTo(Season::class); }
}
```

`timestamps: false`, siguiendo el patrón ya usado en `Fixture`/`SeasonManager`/`PlayerScore` (datos sincronizados, no autorados). Índice único `(player_id, season_id)`.

`Player` gana:
```php
public function seasons(): HasMany { return $this->hasMany(PlayerSeason::class); }
```

## Migración y backfill

Una migración:
1. Crea `player_seasons` con FKs a `players` y `seasons`, único `(player_id, season_id)`.
2. Backfill: copia `position`, `market_value`, `market_value_difference`, `points`, `average_points` de cada `players` existente a una fila nueva en `player_seasons` con `season_id = Season::current()->id`.
3. Elimina esas cinco columnas de `players`.

Todo en la misma migración (orden: crear tabla → backfill con `DB::table` → eliminar columnas), para que no haya una ventana intermedia con el dato duplicado o ausente.

## Serialización: mantener el contrato del frontend

El código ya usa el patrón de "propiedad calculada a query-time, no columna de BD" — documentado en el PHPDoc de `Player` para `owner_manager`, `recent_scores`, etc., y poblado por los controllers antes de pasar el modelo a Inertia.

Reutilizamos el mismo patrón: los controllers que devuelven `Player` a una vista (`PlayersController::index/show`, y cualquier sitio que serialice `Player` con datos de temporada) cargan `PlayerSeason` de la temporada actual y lo cuelgan sobre el modelo como propiedades computadas (`position`, `points`, `average_points`, `market_value`, `market_value_difference`), documentadas igual que las existentes. El frontend (`players/index.tsx`, `players/show.tsx`, `market-panel.tsx`, `roster-list.tsx`, `types/models.ts`) no cambia su forma de leer estos campos.

## Puntos de impacto ya localizados en el código

- **`SyncCurrentSeasonPlayers`**: pasa de un solo `updateOrCreate` a dos — uno sobre `Player` (identidad) y uno sobre `PlayerSeason` (`updateOrCreate(['player_id' => ..., 'season_id' => $season->id], [...])`).
- **`PlayersController::index`**: filtra por `status` (columna real, sin cambios) y por `position` (`whereIn('position', $positions)`) — `position` ya no vive en `players`, así que ese filtro pasa a `whereHas('seasons', ...)` contra la temporada actual. También **ordena** por `PlayerSort::column()` (`points`, `market_value`, `market_value_difference`) — mismo motivo, requiere `join`/`whereHas` contra `player_seasons` de la temporada actual. `attachOwnership`/`attachRecentScores` no tocan estos campos, no cambian.
- **`PlayersController::show`**: ya carga `$season = Season::current()`; añade la carga de `PlayerSeason` de esa temporada para colgarla sobre `$player`.
- **`SyncCurrentSeasonMarket`** y demás comandos que hacen `Player::query()->where('fantasy_id', ...)`: son búsquedas de identidad puras, no cambian.
- Revisar también (no confirmado con lectura completa, a verificar en la implementación): cualquier factory/seeder de `Player` que rellene los campos que se mueven, y los tests que los cubran.

## Fuera de alcance de esta fase

Enlace con IDs de worldcup26.ir, nuevas tablas de eventos/alineaciones de partido, y el rediseño de la ficha de partido — se especificarán como fases separadas cuando se aborden.

## Testing

- Test de migración: backfill correcto (valores antiguos de `players` aparecen en `player_seasons` con el `season_id` actual, columnas eliminadas de `players`).
- Test de `SyncCurrentSeasonPlayers`: crea `Player` y `PlayerSeason` correctamente, y actualiza ambos en syncs sucesivos sin duplicar filas.
- Test de `PlayersController::index`: filtro por `status` y orden por `points`/`market_value`/`market_value_difference` siguen devolviendo el orden esperado tras el split.
- Test de `PlayersController::show`: la ficha de jugador sigue exponiendo `points`, `average_points`, `market_value`, `market_value_difference` con los valores de la temporada actual.
