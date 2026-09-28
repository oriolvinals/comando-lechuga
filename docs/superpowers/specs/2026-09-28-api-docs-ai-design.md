# API docs for the managers' AI advisor — design

Date: 2026-09-28 · Status: agreed in conversation, pending written-spec review

## Goal

Every manager of the league can hand `/api-docs` to an AI assistant and get good advice on
signings and bids, sales, clauses, lineups and league tracking. The AI must understand the game's
real rules, always work with fresh data, and reason from public data — never from our private
max-bid model.

Audit that motivated this (session scratchpad, `api-docs-audit/informe.md`): ~16 doc errors,
a developer-oriented tone, no game primer, and several data points the app computes but the API
does not expose.

## Decisions (user)

1. The max bid ("puja máxima rentable", god mode) is NOT exposed in the API, nor described in the
   docs (no formula, parameters, 14-day projection or confidence), nor may the docs ask the AI to
   reproduce it. The AI reasons its own bid from public data.
2. The advisor serves ALL managers of the league (no per-manager private data; onboarding asks who
   the user is).
3. FútbolFantasy start probabilities are republished through the API, always credited.
4. Inconsistent field/filter names are fixed even if it breaks existing clients; the docs say so.

## Part 1 — `resources/docs/api-docs.md` (served at `/api-docs`)

Language: Spanish, with a Spanish ↔ English glossary. Written for an AI advisor, not a developer.
One naming convention throughout ("valor de mercado" vs "puntos" vs "posición en la liga" vs
"posición en el campo" never overloaded).

Sections, in order:

1. **Cómo usar esta API (instrucciones para la IA)**
   - No cache: the endpoints are live; never reuse an earlier response — every new question
     repeats the requests.
   - Guided onboarding before advising, aimed at users with little AI experience:
     1. "¿Qué manager eres?" — pick from the league managers (`/api/standings`); allow "prefiero
        no decirlo" → general advice without a squad.
     2. "¿En qué quieres que te ayude?" — multi-select:
        1. Fichajes y pujas del mercado de hoy — a quién fichar y cuánto ofrecer, razonado con
           los datos públicos.
        2. Ventas — a quién vender, qué oferta de la liga aceptar y cuándo.
        3. Cláusulas — a quién subir la cláusula propia, quién está expuesto, a quién clausular,
           si ya se puede y si compensa.
        4. Alineación de la jornada — once, formación válida, titulares, dudas, hora de cierre.
        5. Análisis de mi plantilla — puntos fuertes/débiles, quién no juega, qué reforzar,
           evolución de valor.
        6. Seguimiento de la liga y rivales — clasificación, plantillas, en qué gastan.
        7. Partidos y jornada en curso — cómo va, puntos en vivo, quién juega ahora.
        8. Resumen rápido del día — mercado, cambios de valor, alertas de titularidad, urgencias.
   - Serves any manager; no max bid; credit FútbolFantasy when using probabilities.
2. **Manual del juego**
   - Scoring: LaLiga Fantasy points per action by position (goals 6/6/5/4, clean sheet 4/3/2/1…),
     DAZN rating (`marca_points`), how `stats` entries read (`[value, fantasy points]`).
   - Squad: max 24 players. The fantasy lineup must be set before the jornada's first match
     kicks off (it locks then). **A manager whose lineup isn't complete when the jornada starts
     doesn't score that jornada.** Once the jornada has started, players can be sold again.
   - Rules the user hasn't confirmed (negative balance, captain, automatic subs, per-club limits,
     selling price, minimum squad, coach scoring, bid visibility/ties, starting money) are NOT
     asserted: the docs tell the AI to say "consúltalo en la app" instead of guessing. Valid fantasy formations: 5-4-1, 5-3-2, 4-5-1, 4-4-2, 4-3-3,
     3-5-2, 3-4-3. Distinguish from a real team's formation derived from its probable/confirmed XI.
   - Market: renews daily at 20:00; each listing lasts 24 h; the highest bid wins. The league
     makes a daily offer for owned players (uniform ±10 % around value, valid 24 h).
   - Clauses: 14-day lock after buying; clause = max(value, price paid); no buyouts from 24 h
     before the jornada starts until it starts (allowed again afterwards); raising your own
     player's clause raises it by double what you invest (500 k → +1 M).
3. **Cómo leer las señales** — market trend (12 states), start probability (tiers ≥ 90 / 70–89 /
   < 70, probable vs confirmed, stale after 48 h, source), rival difficulty, real formation and
   on-pitch roles, value metrics (30-day ×, pts/M€ with rank, owner gain). Framed as "reason with
   these data", with no recipe resembling the private max-bid model.
4. **Preguntas típicas → qué consultar** — a table mapping each onboarding intention (and common
   questions) to the calls to make.
5. **Referencia de endpoints** — per endpoint: purpose, parameters, fields, short example, error
   behaviour; all audit errors fixed (weekly_prize is every manager each jornada; team_stats count;
   event types incl. `var`; `postponed` state; `market_trend`; `display_clock`; venue/referee/
   attendance/possession; `/market` is the league's own market; manager cash not available).
6. **Glosario** Spanish ↔ English.

## Part 2 — API changes

- **New `GET /api/season`:** current jornada + state (not started / live / finished), next fixture,
  next market renewal (20:00), lineup lock time (first kickoff of the jornada), buyout-blocked
  window (24 h before the jornada's first kickoff → kickoff).
- **New `GET /api/teams`:** the real LaLiga table (position, points, goals, form) and, per team,
  its next fixture with the probable or confirmed XI, real formation and each player's %.
- **`next_start` on every player shape** (`/players`, `/players/{id}`, `/market`, manager rosters
  and lineups): probability, predicted XI, confirmed starter, fetched_at (data age), source, and
  the fixture it refers to (rival, home/away, date).
- **Next fixtures:** each entry adds the rival's LaLiga position and difficulty (−1…+1).
- **Value metrics on players:** 30-day value ×, points per M€ with rank, owner gain (current value −
  last signing/buyout price, with date).
- **`/players` filters/sorts:** sort by market trend and by points per M€; filters: free agents,
  value range, minimum start probability. Fix `position=coach`.
- **`/managers/{id}`:**
  - `current_lineup` — the current jornada's lineup: jornada number + state, fantasy formation,
    lineup lock time, and per player: on-pitch slot, `next_start`, live points while his match is
    live, status.
  - `lineup_history` — finished jornadas only.
  - Roster: full player shape incl. `next_start` (probability to play his next match + in XI),
    purchase price, clause (amount, locked + until, shielded).
  - Per-jornada rank, average per jornada, live points, daily squad value change.
- **Consistency cleanup (breaking, documented):** one naming convention, `average_points` as a
  number, invalid filters return a 422 with a clear message instead of being ignored.
- **Out:** max bid (and anything derived from it); manager cash (the app doesn't have it).

## Part 3 — Testing and validation

- Pest feature tests for every new/changed endpoint and filter, incl. 422 on invalid filters,
  `current_lineup` vs `lineup_history`, `next_start` shapes, `/season` windows and `/teams`.
- A guard test that fails if any max-bid field appears in any API response.
- A doc-drift test: every endpoint and field named in `api-docs.md`'s reference exists in the real
  responses (parse the doc's field lists; compare against factories-backed responses).
- An AI dry run after implementation: an agent reads only `/api-docs` and answers 5–6 typical
  manager questions; check it runs the onboarding, re-requests on every question, respects the
  rules (formations, clause windows, 24 h market) and never mentions the max bid.

## Improvements folded in

- **Freshness metadata:** every API response carries `meta.generated_at` (ISO 8601 with offset)
  and `meta.timezone` ("Europe/Madrid"); the docs tell the AI to quote how fresh the data is.
- **Times:** every datetime is ISO 8601 with offset; rules phrased in Madrid local time
  (20:00 market) and the docs remind the AI about DST (CET/CEST).
- **Money:** always integer euros; the docs say so and show how to render ("12,3 M€").
- **Efficient calls:** the docs recommend the minimal call set per intention (e.g. "Resumen del
  día" = `/season` + `/market` + `/managers/{id}`) and to prefer filters over paging all players,
  while still never reusing earlier responses.
- **Answer language:** the AI answers in the user's language (default Spanish).
- **Fantasy formation check:** the docs give the position counts of each valid formation
  (always 1 goalkeeper) so the AI validates a proposed lineup.
- **Ownership clarity:** every player shape says who owns him (manager or free) and market
  listings say whether the seller is the league or a manager.

## Edge cases the API and docs must handle

- **No upcoming jornada** (season break or end): `/season` returns the last jornada and nulls for
  next fixture / lineup lock / buyout window; docs explain it.
- **Postponed fixtures:** a postponed match keeps its jornada; lineup lock and buyout window use
  the first *scheduled* kickoff of the jornada; a player whose match is postponed has no
  `next_start` for it.
- **Jornada in progress:** `current_lineup` shows live points for live matches, final points for
  finished ones, `next_start` only for players whose match hasn't kicked off.
- **Manager without a lineup** for the current jornada: `current_lineup` is null, and the docs
  tell the AI to warn before the lock time.
- **Incomplete squads** (fewer than 11 available players, injured/suspended starters): the docs
  tell the AI to flag it.
- **Player without probability** (FF doesn't list him, unlinked, coach, out of league):
  `next_start` null (or confirmed-only once worldcup26 publishes); stale (> 48 h) flagged via
  `is_stale`; never guessed.
- **Confirmed lineup sources disagree:** worldcup26 wins over FF; `next_start.source` says which.
- **Player changed clubs:** `next_start` and next fixtures follow his current team.
- **Empty market** (between expiry and the 20:00 renewal, or none listed): `/market` returns an
  empty list plus the next renewal time via `/season`.
- **Clause edge cases:** lock until a timestamp (not just "locked"), shielded players, buyout
  window closed right now — the docs make the AI check `/season` before recommending a buyout.
- **Invalid filters / unknown ids:** 422 with the offending parameter, 404 for unknown ids.
- **Ties in the standings / managers who joined mid-season:** averages per jornada count only the
  jornadas the manager has a lineup for.

## Out of scope

Authentication, rate limiting, manager cash, the max bid, historical start probabilities in the
API, OpenAPI/Swagger generation.
