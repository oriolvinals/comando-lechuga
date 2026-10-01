# Previsión del valor de mañana (modo god)

## Motivación

LaLiga Fantasy publica cada madrugada el valor nuevo de cada jugador. Saber hoy si mañana sube o baja, y cuánto, decide
cuándo comprar, vender o pujar. La investigación (`comando-lechuga-research/value-forecast/`) midió que un modelo
**híbrido "persistencia + choque de partido"** acierta el signo el **95,2 %** de las veces (error medio 44 k€, intervalo
80 % calibrado, walk-forward 16-ago → 29-sep, 22 986 previsiones), frente al 94,3 % de la persistencia pura y el 91,4 %
del momentum de la puja máxima.

La previsión y el día 1 de la proyección de la puja máxima son casi el mismo número calculado dos veces. Este spec:

- calcula la previsión a diario (servicio + tablas + comando programado);
- la usa como **día 1 de la puja máxima** (variante B "re-anclada", backtest en `backtest-maxbid-day1.md`);
- **recalibra la confianza de la puja**, que hoy es optimista (P real 57,8 % con 75 % elegido);
- sustituye la tarjeta de la puja por **una sola sección god «Mercado»** en la ficha (mañana + puja);
- empieza a guardar **instantáneas diarias** de estado y titularidad para medirlas más adelante.

Fuentes: `PLAN.md` (v2 + decisiones del usuario), `README.md`, `informe.md`, `backtest-maxbid-day1.md`,
`backtest.js` / `coef.json` (modelo de referencia). Mock: `public/_prevision-valor.html`, **variante A**.

## Decisiones del usuario (vinculantes)

1. **Variante A**: una sola sección god «Mercado» en la ficha (`players/show.tsx`) que junta mañana + puja máxima y
   absorbe la tarjeta actual (`HqMaxBidCard`). Marco `hq-god-frame` (cinta ámbar/negra fina), **sin texto GOD MODE**.
   Ninguna otra ubicación por ahora.
2. Modelo **híbrido** (`informe.md` §3), reentrenado a diario; intervalo 80 %; motivos con su impacto; contexto sin peso
   (titularidad prevista, dificultad 0–10 del próximo rival).
3. La previsión es el **día 1 de la puja** con la variante B: día 1 = previsión, días 2–14 desplazados con la misma
   diferencia. Solo cuando la `reference_date` de la previsión coincide con la de la puja. `season:backtest-max-bid`
   usa también la previsión walk-forward. La variante D (rehacer la pendiente) queda fuera.
4. **Recalibración de la confianza de la puja** (añadida el 30-sep): la confianza elegida debe ser honesta. Validada con
   `season:backtest-max-bid` walk-forward: calibración a ±3 pp de la confianza elegida en 50 / 75 / 90 %, con el cambio
   en el error de la puja y en los cambios rentable/no rentable. Si no se cumple, se mantiene el comportamiento actual y
   se informan los números (decide el usuario).
5. **Instantáneas diarias** de estado y titularidad (`player_daily_signals`) desde una tarea temprana.
6. **Reglas por estado solo informativas**: lesión/sanción/duda no mueven la cifra ni el intervalo hasta validarlas.
7. **Veredicto del comparador**: tarea pequeña y **opcional, pendiente de confirmación del usuario**: la previsión como
   prueba nueva (+0,5 Fichar, +0,8 Vender, normalizada entre los comparados).
8. **Privacidad**: todo lo de la previsión es solo god. Nunca en `/api/*` ni en `resources/docs/api-docs.md`; el guard
   test se amplía.
9. UI: todo lo clicable con `cursor-pointer`; **sin notas al pie ni letra pequeña explicativa**; valores y tendencias con
   el estilo del resto de la app (`HqMarketValueDifference` / icono de tendencia, verde/rojo); 390 px sin scroll
   horizontal; convenciones de `AGENTS.md` (columnas string no enum no nulas con default `''`).

## Alcance

Dentro:
- `player_daily_signals` + comando horario `season:snapshot-player-signals`.
- Modelo híbrido: filas, vector de variables, regresión ridge incremental, intervalo, P(sube), motivos.
- Tablas `value_forecasts` y `value_forecast_fits`; comando `season:forecast-values` cada 15 min con huella de entradas.
- `season:backtest-value-forecast` (walk-forward) que reproduce `informe.md`.
- Día 1 de la puja = previsión (variante B), en la app y en `season:backtest-max-bid`.
- Recalibración de la confianza de la puja con su backtest como puerta.
- Sección god «Mercado» en la ficha.
- Guard de privacidad.
- (Opcional, con OK) prueba «Mañana» en el veredicto god del comparador.

Fuera (más adelante):
- Listado (`players/index.tsx`, tercera línea en la celda de valor, orden `forecast`), panel de mercado de la home,
  fila «Mañana» en el comparador vista A, página `/jugadores/prevision` (variantes B-fila, C y D del mock).
- Reglas por estado que muevan la cifra; titularidad o dificultad como variables del modelo (se reevalúan con ~4 semanas
  de instantáneas, hacia la J12–J15).
- Variante D de la puja (re-pendiente) y cualquier cambio de la regla de rentabilidad.

## 1. Modelo

### 1.1 Filas

Una fila por jugador y día `T` (la `reference_date`): lo que se sabe al final de `T` para prever el valor fechado
`T + 1` (`target_date`). Solo días `T ≥ season.start_date + 13 días` (antes hay relleno plano). Se necesitan los valores
de `T`, `T−1`, `T−2` y `T−3` (con valor > 0). El objetivo `y` = cambio % de `T+1`, `null` si aún no se conoce.

`cambio(d) = (v[d] − v[d−1]) / v[d−1]`; `p0 = cambio(T)`, `p1 = cambio(T−1)`, `p2 = cambio(T−2)`.

Partido del jugador en un día `D` (`T−1`, `T`, `T−2`), con partidos **terminados** de la temporada:
- si tiene alineación (`fixture_lineups`) en un partido terminado ese día: `team = sí`, `played = minutos > 0`
  (`fantasy_stats.mins_played[0]`), `points = fantasy_points ?? 0`;
- si no, si su equipo actual (`players.team_id`) jugó un partido terminado ese día: `team = sí`, `played = no`;
- si no: sin partido.

Tramo: sin partido · `nomin` (no jugó) · `low` (≤ 2 pts) · `mid` (3–5) · `good` (6–9) · `great` (10+).

Otros: media del mercado en `T` (media simple de `cambio(T)` de todos los jugadores con valor en `T` y `T−1`); días al
próximo partido de su equipo (cualquier estado, fecha > `T`, tope 30; 30 si no hay); media de puntos de sus partidos
con minutos anteriores a `T`. Las fechas de partido son la fecha UTC de `fixtures.date` (como el export de la
investigación; la app corre en UTC).

### 1.2 Variables (30, orden fijo, idéntico a `backtest.js::feats`)

`1, p0, p1, p2, mercado_hoy, p0−p1`, tramo de ayer (5 indicadores), de hoy (5), de anteayer (5),
`(pts_ayer − media)/10` si jugó ayer, `días ≤ 1`, `días ≤ 3`, `días > 7`, `lv = log10(valor) − 6,5`, `p0·lv`,
`p0 < −3 %`, `p0` si su equipo jugó ayer, `clamp(pts_ayer, −5, 20)/10 · lv` si jugó ayer.

### 1.3 Ajuste y previsión

- Objetivo del ajuste: `clamp(y − p0, −10 %, +10 %)`. Ridge λ = 1e-4 (sumado `λ·n` a la diagonal salvo el término
  independiente), por ecuaciones normales acumuladas (una pasada; el walk-forward suma filas día a día).
- Entrenamiento para prever `T+1`: todas las filas con `target_date ≤ T` e `y` conocido. Mínimo 100 filas; si no, no hay
  previsión ese día.
- `cambio_mañana = max(SUELO, p0 + w·x)`, SUELO = −3,46 %.
- Intervalo 80 %: cuantiles 10/90 de los residuos `y − previsión` de las filas con `target_date` en los últimos 35 días
  (`T−34 … T`), en dos grupos: su equipo jugó ayer / no (si el grupo está vacío, el grupo "no").
  `low = max(SUELO, pred + q10)`, `high = pred + q90`. Cuantil `q(p) = orden[floor(p·(n−1))]`.
- P(sube) = fracción de residuos del grupo con `pred + residuo > 0`.
- Dirección: sube si `> +0,5 %`, baja si `< −0,5 %`, si no estable.
- Estado (`injured`, `suspended`, `doubtful`): **no cambia nada** (solo contexto en la UI). No se prevé para
  `out_of_league`.

### 1.4 Motivos

Contribución de cada variable = coeficiente × valor, agrupada:

| kind | Qué agrupa | Etiqueta |
|---|---|---|
| `inertia` | `p0` (la base de persistencia, no un coeficiente) | «Inercia: cambio de hoy» |
| `streak` | `p0, p1, p2, p0−p1, p0·lv, p0<−3 %, p0 si partido ayer` | «Racha» / «Freno de racha» (según signo) |
| `market` | mercado hoy | «Mercado general» |
| `match_yesterday` | tramo de ayer + pts vs media + pts·lv | «Partido del dd/mm · N pts» / «… · sin jugar» |
| `match_today` | tramo de hoy | «Partido de hoy · N pts» / «… · sin jugar» |
| `match_before` | tramo de anteayer | «Partido del dd/mm · N pts» / «… · sin jugar» |
| `calendar` | días al próximo partido | «Próximo partido en N días» / «Sin partido próximo» |
| `baseline` | término independiente + `lv` | «Nivel de valor» |
| `floor` | recorte al suelo (`SUELO − bruto`) | «Suelo diario −3,46 %» |

Se guardan `inertia` y los 3 de mayor |impacto| (≥ 0,1 pp), en pp con 2 decimales.

### 1.5 Precisión esperada (puerta de reproducción)

`season:backtest-value-forecast --from=2026-08-16 --to=2026-09-29` debe dar para el híbrido: signo 95,2 % ± 0,5 pp,
error medio ≈ 44,1 k€ (± 3 %), cobertura 80 % ≈ 81 % ± 2 pp. Si no, el port tiene un error: no se sigue.

## 2. Persistencia

- `player_daily_signals`: `season_id`, `player_id`, `date`, `status` (enum `PlayerStatus`), `next_fixture_id`
  (nullable), `start_probability` (0–100, nullable), `predicted_starter` (bool), `confirmed_starter` (bool nullable),
  `next_difficulty` (0–10, nullable), `listed` (bool). Única por `(player_id, date)`; se sobrescribe durante el día (queda
  la última foto). Jugadores de los equipos de la temporada, salvo `out_of_league`.
- `value_forecasts`: `season_id`, `player_id`, `reference_date`, `target_date`, `value`, `predicted_value`, `low`, `high`,
  `change_pct`, `up_probability`, `reasons` (json). Única por `(season_id, player_id, target_date)`.
- `value_forecast_fits`: `season_id`, `reference_date`, `inputs_hash` (string, default `''`), `coefficients`,
  `quantiles`, `metrics` (json). Única por `(season_id, reference_date)`. Auditoría y huella.

## 3. Procesos

- `season:snapshot-player-signals` cada hora (`withoutOverlapping`, `onOneServer`, `runInBackground`). Usa
  `StartProbabilities::nextFixtures` / `forPlayersNextFixture`, `MatchDifficulty::forMany` (variante por posición) y
  `market_players`.
- `season:forecast-values` cada 15 min (igual). `reference_date` = último día con valores en `player_markets`. Huella =
  sha1 de: `reference_date`, número y suma de valores de ese día, número de partidos terminados de la temporada, número
  y suma de `fantasy_points` de sus alineaciones. Si coincide con la del ajuste guardado para esa fecha, no hace nada
  (`--force` lo salta). Si no: ajusta, escribe `value_forecasts` del `target_date` (borra las del mismo `target_date` que
  ya no toquen) y el ajuste, en una transacción. Así se actualiza al publicarse el valor y otra vez al terminar los
  partidos del día.
- `season:backtest-value-forecast {--from} {--to}`: walk-forward de solo lectura; tabla persistencia / momentum puja /
  híbrido (signo, 3 clases, error € medio y mediano, error pp, cobertura), por segmentos (D+2 de partido, sin partido
  ayer, valor ≥ 5 M, giros) y calibración de P(sube).

## 4. Puja máxima

### 4.1 Día 1 = previsión (variante B)

- `MaxBidInputs::$dayOneForecast` (`?int`, último parámetro) y `withDayOneForecast(?int)`.
- `MaxBidCalculator::gatherInputs()` lo rellena con `value_forecasts.predicted_value` de
  `(season, player, reference_date = $referenceDate, target_date = $referenceDate + 1)`; sin fila (o con otra
  `reference_date`) queda `null` y la puja es la de hoy. Solo en el camino de estimación completa (no en
  `unavailable` / `no_data`).
- `estimateFromInputs()`: tras `project()`, si hay previsión, `offset = previsión − projection[1]` y se suma a los días
  1–14. La rentabilidad (`increment > 0`) y los factores no cambian. `MaxBidEstimate` expone `day_one_forecast` y
  `day_one_offset`.
- `season:backtest-max-bid` inyecta la previsión walk-forward (`withDayOneForecast`) en ambos modos (normal y grid);
  `--without-forecast` reproduce el modelo anterior. Añade el error frente a la **puja ideal** (la de `solveBid` sobre el
  camino real con la misma confianza). Referencia (`backtest-maxbid-day1.md`, 15-ago → 15-sep): error puja 23,12 →
  22,52 %, P real 57,8 %.

### 4.2 Recalibración de la confianza

Hallazgo: con 75 % elegido la P real de que la mejor oferta supere la puja es 57,8 % (≈ 4 371 pujas), antes y después
del día 1. La puja es sistemáticamente optimista.

Cambio (en `MaxBidParameters`, explícito):
- `confidenceCalibration` (`array<int, float>`, por defecto `[]` = identidad): mapa confianza elegida (%) → confianza con
  la que se resuelve la puja. Nudos cada 5 % de 50 a 95; interpolación lineal. `effectiveConfidence(float): float`.
- `incrementShrink` (`float`, por defecto `1.0`): factor sobre el incremento diario antes de proyectar (la previsión del
  día 1 re-ancla igual; la rentabilidad no cambia porque el signo del incremento no cambia).
- `estimateFromInputs()` resuelve con `effectiveConfidence($confidence)`; `MaxBidEstimate::confidence` sigue siendo la
  elegida (el stepper no cambia).

Validación: `season:backtest-max-bid --calibrate` (con la previsión walk-forward). Divide las fechas de referencia en dos
mitades: ajusta el mapa (para cada `incrementShrink` de {1,0; 0,9; 0,8; 0,7; 0,6; 0,5}) en la primera y lo valida en la
segunda. Puerta: P real de validación a ±3 pp de 50, 75 y 90 %. Informa, por cada combinación, P real en 50/75/90,
error de la puja frente a la ideal (media y mediana, antes/después), número de pujas y cambios rentable/no rentable
(0 por construcción; se imprime para comprobarlo). Elige la combinación que cumple la puerta con menor error de puja e
imprime los valores a copiar en `MaxBidParameters`. Si ninguna cumple, se deja la identidad y `1.0` y se entregan los
números al usuario.

## 5. Ficha: sección «Mercado» (variante A)

- Prop `valueForecast` (solo god, `null` sin él, también en partial reload): la fila de `value_forecasts` del jugador
  cuya `reference_date` es el último día de mercado publicado; `null` si no la hay (previsión antigua).
  Forma: `reference_date, target_date, value, predicted_value, change, change_pct, low, high, up_probability,
  direction, trend, reasons[{kind, label, impact_pct}]`. `trend` = `MarketTrend::fromDailyValues` de sus últimos 6
  valores + el previsto (para pintar el icono de tendencia como en el resto de la app).
- `HqMaxBidCard` pasa a ser `HqGodMarketSection` (`hq-god-market-section.tsx`) con `estimate`, `forecast` y
  `playerStatus` (la titularidad sale de `estimate.next_start_probability`), dentro de `hq-god-frame`:
  - cabecera «Mercado»;
  - lectura doble: **Mañana · dd/mm** (LED con el valor previsto, chip `P(sube) N %`, cambio € con
    `HqMarketValueDifference` + %, rango 80 %) y **Puja máxima rentable** (como hoy, con el stepper de confianza);
  - gráfico de 14 días: la proyección ya viene re-anclada; en el día 1 un bigote con el rango 80 %; el tooltip del día 1
    añade «Previsión · rango …»;
  - columnas Mercado / Deportivo / Próximos rivales, cada fila con chips `MAÑ` (la usa la previsión), `PUJA` (la usa la
    puja), `INFO` (contexto sin peso medido). Motivos de la previsión en Mercado (inercia, racha, mercado, nivel,
    suelo) y Deportivo (partidos, calendario); estado y titularidad como `INFO`; rivales con `HqDifficultyBars` (0–10);
  - sin la línea de pie actual («Mejor oferta esperada…») ni ninguna nota explicativa.
- Sin previsión: la sección es la puja como hoy (sin filas `MAÑ`). Sin proyección (lesionado, sin datos) pero con
  previsión: lectura de mañana + titular de la puja + columnas solo con filas `MAÑ`/`INFO`.
- 390 px: todo en una columna, sin scroll horizontal; tooltips y stepper con `cursor-pointer` / táctiles.

## 6. Comparador (opcional, requiere OK del usuario)

`ComparedPlayers::forIds(..., withForecast: HandleGodMode::isEnabled)` añade `forecast {predicted_value, change_pct,
up_probability}` (o `null`). En `derive.ts`: Fichar `+ 0,5 · norm(change_pct)`; Vender `+ 0,8 · norm(change_pct,
menor = peor)`; fila de prueba «Mañana» en ambos (`mark` best / worst). Sin god, `forecast` no se envía.

## 7. Privacidad

- `MaxBidGuardTest`: lista prohibida ampliada con `predicted_value`, `up_probability`, `change_pct`, `impact_pct`,
  `target_date`, `day_one_forecast`, `day_one_offset`, `value_forecast`, `forecast`, `next_difficulty`.
- `ApiDocsDriftTest`: la doc no nombra `previsión del valor`, `prevision del valor`, `value forecast`, `value_forecast`,
  `forecast`.
- Ningún recurso ni controlador de `/api` lee las tablas nuevas.

## 8. Tests (Pest)

- Unit: regresión (coeficientes conocidos, ridge), vector de variables (orden, tramos, interacciones), modelo (suelo,
  cuantiles, P(sube), motivos), `effectiveConfidence`, `estimateFromInputs` con previsión y con calibración.
- Feature: filas desde la BD (tramos D−1/D/D−2, sin minutos, puntos pendientes = 0, días al próximo, valores 0 o
  huecos), comandos (escritura, huella, reescritura al terminar un partido), `gatherInputs` ignora una previsión con otra
  `reference_date`, ficha con y sin god, guard, backtests (salida y solo lectura).

## 9. Orden

1. Instantáneas (pequeño, primero). 2. Modelo + backtest (puerta de reproducción). 3. Tablas + comando programado.
4. Día 1 de la puja + backtest. 5. Recalibración (puerta). 6. Privacidad + ficha. 7. (Opcional) comparador.

Rama propia desde `main`, **después de mergear la rama del radar** (`feature/god-radar`).
