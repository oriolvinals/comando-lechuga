# Start probabilities ("titularidades") — design

Date: 2026-09-27 · Status: approved in conversation, pending written-spec review

## Goal

Show, for every LaLiga player, how likely he is to START his team's next match, and use it in the
max bid model. Confirmed lineups already exist (worldcup26 → `fixture_lineups`, from 1 h before
kickoff); this feature adds the **predicted** part before that.

## Source

- **FútbolFantasy team pages** `https://www.futbolfantasy.com/laliga/equipos/{slug}` (server-rendered
  HTML, ~2.4 MB each). Each player block: `<div class="jugador_{ffId}" data-probabilidad="NN%"
  data-valor-laliga-fantasy="…" data-puntos-totales-laliga-fantasy="…" data-rival="…">` plus status
  flags. Blocks appear twice (desktop + mobile) → dedupe by `ffId`. Pre-season pages have no %;
  once a lineup is known the value becomes `Titular` / `Suplente` (stored as a fallback confirmation;
  worldcup26 stays the primary confirmed-lineup source — see Sync).
- Same source LaLigaApp (GPL) uses; we take the idea, not its code.
- Injury / suspension status keeps coming from LaLiga Fantasy (`players.status`), not from FF.
- Attribution on every surface: "Probabilidades: FútbolFantasy" linking to the team page.

## Linking FF players to ours

Order, first hit wins:
1. `players.futbolfantasy_id` (stored after the first successful link).
2. Same team + **exact LaLiga Fantasy market value** (`data-valor-laliga-fantasy`) against
   `player_markets` in the last 3 days; tie-break by total points, then position.
3. Same team + normalised name (accents, case, punctuation stripped; nickname and full name).
4. Manual map constant (like `PLAYER_MAP` in `season:link-match-data-players`) for leftovers.

Measured: 151/151 on 6 teams by rule 2 alone; name-only was 80–96 %. Unlinked FF players are
logged, never guessed. Team slug → `team_id` is a fixed 20-entry map (FF uses `RMD` where we use `RMA`).

## Storage

- `players.futbolfantasy_id` — nullable unsigned int, unique.
- `player_start_probabilities`: `id`, `player_id` (fk), `fixture_id` (fk, see "Which fixture"),
  `probability` (nullable unsigned tinyint 0–100 — the last predicted %, kept after
  confirmation for the "Sorpresa / Se cae · era N %" marks), `confirmed_starter` (nullable bool —
  set from an "Alineación confirmada" page: true = Titular, false = Suplente), `fetched_at`,
  timestamps; unique (`player_id`, `fixture_id`); upserted each cycle. No enum-less string columns
  are needed; if one is added it defaults to `''` (project rule).

## Which fixture

The FF team page titles its prediction "Posible alineación J{n}", and "Alineación confirmada J{n}"
once the official lineup is out (then each player's value is `Titular` / `Suplente` instead of a
%). Both headings give the jornada. The probabilities are stored on
**that team's fixture with `week_number = n`** (team + jornada), so a postponed match keeps its
jornada (e.g. the J6 match played on 21 Oct is still "the J6 one"). `data-rival` is only a sanity
check: if the rival doesn't match that fixture's opponent, nothing is stored for the team and a
warning is logged. No heading or no jornada number → skip the team, log a warning.

Surfaces read by fixture: the match page shows its own fixture's rows; the team page and the
manager page use each team's / player's next fixture (the one already shown as "próximo partido").

## Sync

- Command `season:sync-start-probabilities` (Saloon connector, gzip, **anonymous project
  User-Agent — never personal data**, 10–30 s between requests, one attempt per page).
- Scheduled every 10 min; per team it only fetches when due:
  - next fixture within 48 h → every run (every 10 min);
  - further away → at most every 6 h;
  - after kickoff → never.
- Probabilities keep showing until a confirmed lineup exists for that fixture (no fixed cut-off).
- **Confirmed lineups:** the existing worldcup26 live sync (`season:sync-live-match-data`, every
  20 s) widens its pre-match window from 1 h to **1 h 30 min** before kickoff and keeps re-reading
  the official lineup on every run until kickoff, so late changes or corrections replace the stored
  one. Once a fixture has confirmed lineups, the pages show Titular/Suplente instead of the %.
- **Confirmed-lineup sources, in order:** worldcup26 (`fixture_lineups`, primary); FF's
  "Alineación confirmada" (`confirmed_starter`) as the fallback when worldcup26 has nothing yet.
  If both exist and disagree, worldcup26 wins. FF pages keep being fetched every 10 min until
  kickoff, so an FF confirmation is picked up within 10 min.
- A page that fails or parses 0 players leaves that team's rows untouched and logs a warning.
- Data older than 48 h is shown as stale ("Datos de hace N días", muted bars).

## Max bid

`MaxBidCalculator` participation factor: when a fresh (≤ 48 h) probability exists for the player's
next fixture, use `probability / 100` as the participation input; otherwise keep today's
last-3-matches starts + minutes. Not backtestable (no history) — note it in the card's breakdown
("titularidad FF" vs "últimos 3 partidos").

## Display (mocks: `public/_titulares.html`, untracked; builder in the session scratchpad)

- **Ficha de partido, upcoming match — variant A.**
  - Pitch view (landscape `HqMatchPitch`): both probable XIs; the token's points badge shows the
    **%** instead; doubts (< 60 %) dimmed with a dashed frame.
  - List view: two columns of XI rows with a 10-cell bar and the %.
  - Under both: bench/doubts (≥ 30 %), a "+N < 30 %" line, and bajas from our status
    (e.g. "Sorloth · Lesión · FF aún le da 20 %"). Mobile always uses the list.
  - Once confirmed (worldcup26): Titular / Suplente chips, gold "Sorpresa · era N %" and ember
    "Se cae · era N %".
- **Ficha de equipo — variant B:** in the jornada aside, a probable-XI half pitch replaces the
  "Sin alineación" empty state for the upcoming match.
- **Ficha de mánager — variant A, placed BELOW each roster player's next-match cell** (not beside
  it): % chip per player, plus "J{n} · X/Y XI prob." in the roster header.
- **Every pitch** that shows probabilities: % badge on the token; **≥ 90 % in lilac**
  (`--color-hq-violet`), otherwise the existing tone scale.
- Stale state and attribution line on each block.

## Testing

- Parser unit tests on saved HTML fixtures (dedupe, `%`, missing %, `Titular`/`Suplente`, rival,
  "Posible alineación J{n}" vs "Alineación confirmada J{n}" headings, missing heading).
- Linker tests for each rule, including ties and the manual map.
- Command feature tests with `Http::fake` / Saloon mocks: due-logic per team, failure keeps rows,
  upsert, fetch every run within 48 h and never after kickoff, FF confirmation stored as fallback;
  live sync picks up lineups from 1 h 30 min before kickoff.
- Controller tests for the new props on match, team and manager fichas; max bid participation
  switch (fresh vs stale vs missing).

## Out of scope

Historical probabilities / backtesting, scraping other sources, showing FF injury flags as truth.
