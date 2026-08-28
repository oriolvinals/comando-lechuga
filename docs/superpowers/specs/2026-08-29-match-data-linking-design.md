# Enllaç d'identificadors amb worldcup26.ir (`match_data_id`)

## Motivación

El split `Player`/`PlayerSeason` (fase 1, ya en `main`) preparó el terreno para enlazar datos reales de partido (alineaciones confirmadas, goles, tarjetas, sustituciones) desde una API externa gratuita, worldcup26.ir — ver `docs/research/laliga-match-data-apis.md`. Esta fase enlaza identidades: `teams`, `fixtures` y `players` ganan un `match_data_id` que apunta al recurso equivalente en worldcup26.ir. Las fases siguientes (conector + sync de datos de partido + rediseño de la fitxa) se especificarán aparte, una vez este enlace exista.

`match_data_id` (no `worldcup26_id`): el dominio worldcup26.ir es una elección de proveedor arbitraria — no hemos podido confirmar ni siquiera que su fuente original sea ESPN (el formato del JSON lo sugiere fuertemente, pero su propio README no lo dice) — y ya se dejó dicho que cambiar de proveedor (p. ej. a API-Football) debía ser solo reescribir el conector. Un nombre de campo atado a la marca del proveedor se quedaría obsoleto en ese cambio; `match_data_id` describe para qué sirve, no de dónde viene.

## Validación empírica (spike, 2026-08-29)

Antes de diseñar el matching se probó contra datos reales — no es un plan teórico:

- **Equipos**: los 20 nombres de worldcup26.ir casan 1:1 sin ambigüedad contra nuestros 20 `Team`. Mapeo completo abajo.
- **Fixtures**: comparadas las jornadas 1-2 (20 partidos) — coinciden exactamente en fecha + equipo local + equipo visitante en el 100% de los casos.
- **Jugadores**: probados ~85 nombres reales (eventos de 4 partidos, 6 equipos distintos — Alavés, Getafe, Espanyol, Real Madrid, Barcelona, Elche) contra nuestros `Player.nickname`. Cero casos genuinamente irresolubles; los nicknames de LaLiga Fantasy siguen un puñado de patrones frente al nombre completo de worldcup26, listados abajo con ejemplos reales.

## Enlace de equipos

Columna `match_data_id` (unsigned int, nullable, unique) en `teams`. Mapeo manual, una sola vez, hardcodeado en la migración/seeder — con solo 20 filas y sin ambigüedad, automatizarlo sería más riesgo que ahorro:

| worldcup26 id | worldcup26 name | our `teams.id` | our `short_name` |
|---|---|---|---|
| 83 | Barcelona | 3 | BAR |
| 86 | Real Madrid | 13 | RMA |
| 243 | Sevilla | 15 | SEV |
| 244 | Real Betis | 4 | BET |
| 96 | Alavés | 18 | ALA |
| 1068 | Atlético Madrid | 1 | ATM |
| 97 | Osasuna | 11 | OSA |
| 88 | Espanyol | 7 | ESP |
| 2922 | Getafe | 8 | GET |
| 102 | Villarreal | 17 | VIL |
| 90 | Deportivo | 19 | RCD |
| 87 | Racing Santander | 20 | RAC |
| 101 | Rayo Vallecano | 12 | RAY |
| 85 | Celta Vigo | 5 | CEL |
| 94 | Valencia | 16 | VAL |
| 99 | Málaga | 10 | MGA |
| 1538 | Levante | 9 | LEV |
| 3751 | Elche | 6 | ELC |
| 89 | Real Sociedad | 14 | RSO |
| 93 | Athletic Club | 2 | ATH |

## Enlace de fixtures

Columna `match_data_id` (unsigned int, nullable, unique) en `fixtures`.

Comando `season:link-match-data-fixtures`: llama a `GET /get/soccer/esp.1/fixtures?status=all`, y para cada entrada resuelve el equipo local/visitante vía el mapeo de equipos (ya en BD por `teams.match_data_id`), y busca el `Fixture` propio con ese mismo par de equipos y una fecha dentro de ±1 día (margen por posibles diferencias de zona horaria — la validación no encontró ninguna, pero no cuesta nada el margen). Con un par de equipos jugando una sola vez por jornada, no hay ambigüedad esperada; si un fixture no encuentra candidato o encuentra más de uno, se deja sin enlazar y se lista en el output del comando — no bloquea el resto.

## Enlace de jugadores

Columna `match_data_id` (unsigned int, nullable, unique) en `players`.

**No hay endpoint de "lista de jugadores"** en worldcup26.ir — los nombres solo aparecen dentro del roster de un partido ya sincronizado (`GET /get/soccer/esp.1/events/{match_data_id}`). El comando `season:link-match-data-players` recorre los fixtures ya enlazados (`match_data_id` no nulo), pide su roster, y para cada entrada intenta casarla contra los `Player` del mismo equipo (`team_id`) que aún no tengan `match_data_id` — el scope por equipo (~20-25 candidatos, no ~800) es lo que hace este matching manejable.

Cadena de heurísticas, en orden, parando en la primera que produzca una única candidata (todas comparan tras "folding": minúsculas + sin acentos, reutilizando el mismo criterio que `PlayersController::foldedNicknameSql()` ya usa para la búsqueda):

1. **Nickname == nombre completo, exacto tras folding.** Ejemplos reales: `Saba Sazonov` ↔ "Saba Sazonov", `Pol Lozano` ↔ "Pol Lozano", `Vanja Drkusic` ↔ "Vanja Drkusic", `Martim Neto` ↔ "Martim Neto", `Xavi Espart` ↔ "Xavi Espart", `Ali Houary` ↔ "Ali Houary". Cubre los nicknames que ya son el nombre completo.
2. **Apellido**: el nickname (o su última palabra, si tiene varias) aparece como palabra completa dentro del nombre completo de worldcup26. Ejemplos: `Sivera` ↔ "Antonio Sivera", `Tenaglia` ↔ "Nahuel Tenaglia", `Cala` ↔ "Cala", `Redondo` ↔ "Federico Redondo", `Kounde` ↔ "Jules Koundé" (folding quita el acento). Es el patrón más común, con diferencia.
3. **Nombre de pila, como prefijo** del primer nombre de worldcup26 (no exacto — cubre diminutivos). Ejemplos: `Youssef` ↔ "Youssef Lekhedim", `Urko` ↔ "Urko González de Zárate", `Fermín` ↔ "Fermín López", `Vini Jr.` ↔ "Vinícius Júnior" (tras quitar el sufijo `Jr.`/`Jr`/`Júnior` y foldear, `vini` es prefijo de `vinicius`).
4. **Inicial + apellido**: el nickname tiene forma "X. Apellido" y worldcup26 tiene un nombre que empieza por esa misma letra y termina en ese apellido. Ejemplos: `T. Martínez` ↔ "Toni Martínez", `M. Aguado` ↔ "Marc Aguado", `E. Ponce` ↔ "Ezequiel Ponce". Nota: cuando el apellido (regla 2) ya identifica un único candidato dentro del equipo, esta regla es redundante — solo hace falta como desempate si dos compañeros comparten apellido, algo que no se ha observado en la muestra pero que es razonable prever.
5. **Sin match**: se deja `match_data_id` nulo y se lista en el output del comando para revisión manual (editar a mano, no hay UI de administración — coherente con el resto de la app, sin auth).

**Desempates**: si una regla encuentra más de un candidato dentro del mismo equipo (p. ej. dos apellidos iguales), no se adivina — se trata como "sin match" y se lista para revisión manual, igual que el caso 5.

## Cambio en `SyncCurrentSeasonFixtures`

Deja de escribir `state`, `local_score` y `guest_score` — sigue siendo la única fuente de `fantasy_id` (necesario para que `SyncsPlayerScores` siga encontrando el fixture correcto al sincronizar puntos), `week_number`, `date` y el par de equipos, pero esos tres campos pasan a ser responsabilidad exclusiva de una comanda futura de worldcup26.ir (fase 3, fuera de esta fase) — así no hay dos fuentes escribiendo el mismo campo según el orden del cron. Hasta que esa comanda de fase 3 exista, esos tres campos simplemente dejan de actualizarse tras el despliegue de esta fase (quedan con el último valor que tenían) — aceptable, ya que esta fase es solo el enlace de identificadores, no el sync de datos en vivo.

## Fuera de alcance de esta fase

- El conector Saloon y las tablas `fixture_lineups`/`fixture_events` (fase 3).
- La comanda que sincroniza `state`/`local_score`/`guest_score` desde worldcup26.ir (fase 3, la misma que deja huérfanos esos campos de `SyncCurrentSeasonFixtures` arriba).
- El rediseño de la fitxa de partit (fase 4).

## Testing

- Migraciones: columnas `match_data_id` nullable/unique en `teams`, `fixtures`, `players`.
- `season:link-match-data-fixtures`: test con mock del conector — enlaza correctamente por equipos+fecha, dentro del margen de ±1 día, no enlaza si no hay candidato único.
- `season:link-match-data-players`: un test por regla de la cadena (1-5), con nombres reales de la muestra de arriba; test de desempate (dos candidatos, ninguno se enlaza); test de scope por equipo (un jugador de otro equipo con nombre parecido no interfiere).
- `SyncCurrentSeasonFixturesTest`: actualizar las aserciones que comprobaban `state`/`local_score`/`guest_score` tras el sync — ya no deben cambiar.
