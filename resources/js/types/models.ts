export interface Team {
    id: number;
    main_name: string;
    name: string;
    short_name: string;
    logo: string;
}

export type PlayerPosition =
    'goalkeeper' | 'defender' | 'midfield' | 'striker' | 'coach';

export type PlayerStatus =
    'ok' | 'injured' | 'out_of_league' | 'suspended' | 'doubtful';

export type MarketTrend =
    | 'positive_inflection'
    | 'rise_accelerating_sharply'
    | 'rise_accelerating'
    | 'rise_steady'
    | 'rise_decelerating'
    | 'rise_decelerating_sharply'
    | 'negative_inflection'
    | 'fall_decelerating_sharply'
    | 'fall_decelerating'
    | 'fall_steady'
    | 'fall_accelerating'
    | 'fall_accelerating_sharply';

export interface OwnerManager {
    id: number;
    name: string;
    logo: string;
    primary_color: string | null;
}

export interface NextFixtureSlot {
    week_number: number;
    opponent: Team;
    is_home: boolean;
    /** The rival's current real LaLiga standings position (1 = leader). */
    rival_position: number;
    /** −1 against the leader, 0 mid table, +1 against the last team — see `@/lib/rival-difficulty`. */
    difficulty: number;
}

export interface Player {
    id: number;
    nickname: string;
    image: string;
    team: Team;
    position: PlayerPosition;
    status: PlayerStatus;
    market_value: number;
    market_value_difference: number;
    /** How the market value is moving over the last week (recent 3-day pace vs. the 3 days before) — null without enough history or movement. */
    market_trend: MarketTrend | null;
    points: number;
    average_points: string;
    owner_manager: OwnerManager | null;
    /** Points for the last 3 played matches, oldest first, ordered by fixture date — null-padded at the end when fewer than 3 exist. */
    recent_scores: (number | null)[];
    /** Per recent_scores slot, whether a real finished fixture exists there — false means the team hasn't played that many matches yet, never "not called up" (a finished fixture with no score is still true). */
    recent_scores_finished: boolean[];
    /** Per recent_scores slot, whether the player was in a given manager's lineup that week. Only present on the manager ficha. */
    recent_scores_used?: (boolean | null)[];
    /** Per recent_scores slot, the rival the player's team faced in that match. */
    recent_scores_opponents: (Team | null)[];
    /** The team's next 3 upcoming (not yet started) fixtures, soonest first — null-padded at the end when fewer than 3 remain on the calendar. */
    next_fixtures: (NextFixtureSlot | null)[];
    /** Start probability (or confirmed lineup) for the team's next match. Only present on the manager and team fichas; null without data. */
    next_start?: PlayerNextStart | null;
}

export type FixtureState =
    | 'scheduled'
    | 'first_half'
    | 'half_time'
    | 'second_half'
    | 'finished'
    | 'postponed';

export interface Fixture {
    id: number;
    week_number: number;
    date: string;
    local_score: number | null;
    guest_score: number | null;
    state: FixtureState;
    display_clock: string | null;
    local_formation: string | null;
    guest_formation: string | null;
    local_color: string | null;
    local_alternate_color: string | null;
    guest_color: string | null;
    guest_alternate_color: string | null;
    venue: string;
    venue_city: string;
    attendance: number | null;
    referee: string;
    local_possession: number | null;
    guest_possession: number | null;
    local_team: Team;
    guest_team: Team;
}

export interface FixtureLineupEntry {
    id: number;
    player: Player | null;
    unresolved_name: string | null;
    wc26_id: number;
    team_id: number;
    starter: boolean;
    position: string;
    jersey: string;
    subbed_in: boolean;
    subbed_out: boolean;
    sub_minute: number | null;
    counterpart_player: Player | null;
    points: number | null;
    stats: JornadaStats | null;
    dazn_points: number | null;
    x: number | null;
    y: number | null;
    lineup_manager: SeasonManager | null;
}

export interface FixtureFantasySide {
    points: number;
    /** The FixtureLineupEntry id of the side's best fantasy player. */
    best_lineup_id: number | null;
}

/** The match page's "Marcador fantasy" — only sent for finished fixtures. */
export interface FixtureFantasyScoreboard {
    local: FixtureFantasySide;
    guest: FixtureFantasySide;
    managers: {
        id: number;
        name: string;
        primary_color: string | null;
        points: number;
    }[];
}

export type FixtureEventType =
    'goal' | 'yellow_card' | 'red_card' | 'penalty_missed' | 'var';

export interface FixtureEventEntry {
    id: number;
    minute: number;
    type: FixtureEventType;
    team_id: number;
    is_own_goal: boolean;
    is_penalty: boolean;
    player: Player | null;
    unresolved_name: string | null;
    /** What a VAR review decided — null for every other event type. */
    label: string | null;
}

export interface FixtureTeamStat {
    stat: string;
    label: string;
    local: number;
    guest: number;
}

export interface SeasonManager {
    id: number;
    name: string;
    logo: string;
    primary_color: string | null;
    secondary_color: string | null;
    total_points: number;
    live_points: number | null;
    position: number;
    last_position: number;
    value: number;
    recent_form: (number | null)[];
    /** What the current squad gained or lost in the latest daily market update (sum of its players' daily value differences). */
    daily_value_difference: number;
}

export interface MarketPlayer {
    id: number;
    expires_at: string;
    bids: number;
    sale_price: number;
    value: number;
    player: Player;
}

export type SeasonActivityType =
    'buyout' | 'shield' | 'weekly_prize' | 'joined_league' | 'signing' | 'sale';

/** Activity count per type over a whole filtered set, not just one page. */
export type ActivityTypeCounts = Record<SeasonActivityType, number>;

export interface Activity {
    id: number;
    type: SeasonActivityType;
    amount: number | null;
    week_number: number | null;
    occurred_at: string;
    source_season_manager: SeasonManager;
    target_season_manager: SeasonManager | null;
    player: Player | null;
    value_difference: number | null;
}

export interface Season {
    id: number;
    name: string;
    current_week: number;
    total_weeks: number;
}

export type JornadaStats = Record<string, [number, number]>;

export interface ManagerLineupPlayerEntry {
    id: number;
    points: number | null;
    stats: JornadaStats | null;
    position: PlayerPosition;
    player: Player;
    /** Whether this player's team fixture for that week has finished — distinguishes "not called up" from "not played yet" when points is null. */
    match_finished: boolean;
    /** The fixture this player's team played that week, resolved by team + week rather than a possibly-unset fixture_id. Null when no fixture exists for that team/week yet. */
    fixture: Fixture | null;
    /**
     * Real match-role coordinates (top/left %) for the team ficha's pitch —
     * present only there, derived from the actual worldcup26 lineup line
     * (which can have more lines than the fantasy position's 3 buckets).
     * Absent for a fantasy manager's lineup, which uses the row-based
     * layout driven by `tacticalFormation` instead.
     */
    pitch_top?: number;
    pitch_left?: number;
    /**
     * This player's REAL match role that week, from `fixture_lineups` —
     * `starter` combined with `sub_minute`/`subbed_out` gives one of four
     * states (titular / titular sustituido / suplente que entró / banquillo).
     * All three are null when no `FixtureLineup` resolves for this player
     * that week: on a fantasy manager's pick, that means "not called up"
     * once the match has finished, or just "not played yet" otherwise — the
     * team ficha's own `players`/`substitutes` always resolve one, since
     * every entry there already comes from a real `FixtureLineup` row.
     */
    starter: boolean | null;
    subbed_out: boolean | null;
    sub_minute: number | null;
    /** This pick's start probability (or confirmed lineup) for its own fixture that week — null once it kicked off, before backend data exists, or without any data. */
    start: LineupPlayerStart | null;
}

export interface ManagerLineup {
    id: number;
    points: number;
    week_number: number;
    tactical_formation: number[];
    season_manager: SeasonManager;
    players: ManagerLineupPlayerEntry[];
}

export interface ManagerPlayer {
    id: number;
    buyout_clause: number;
    buyout_clause_locked_until: string;
    shielded: boolean;
    shielded_until: string | null;
    player: Player;
}

export interface PlayerOwnership {
    id: number;
    buyout_clause: number;
    buyout_clause_locked_until: string;
    shielded: boolean;
    shielded_until: string | null;
    season_manager: SeasonManager;
}

export interface PlayerMarketPoint {
    date: string;
    value: number;
}

export interface PlayerFichaMarketListing {
    id: number;
    expires_at: string;
    bids: number;
    sale_price: number;
    value: number;
}

export interface PlayerFichaScore {
    id: number;
    team_id: number;
    team: Team;
    points: number | null;
    stats: JornadaStats | null;
    fixture: Fixture;
    lineup_manager: SeasonManager | null;
    starter: boolean;
    subbed_in: boolean;
    subbed_out: boolean;
    sub_minute: number | null;
}

/** A finished fixture of the player's club they have no lineup row for, with the manager who fielded them that week (if any). */
export interface PlayerMissedFixture {
    fixture: Fixture;
    lineup_manager: SeasonManager | null;
}

export interface OwnershipActivity {
    id: number;
    type: SeasonActivityType;
    occurred_at: string;
    amount: number | null;
    source_season_manager: SeasonManager;
    target_season_manager: SeasonManager | null;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
}

/** How far along a jornada is, keyed by week number as a string. */
export type WeekProgress = 'none' | 'partial' | 'all';
export type WeekProgressMap = Record<string, WeekProgress>;

export interface StandingsFormEntry {
    fixture_id: number;
    opponent: Team;
    score: string;
    result: 'win' | 'draw' | 'loss';
    date: string;
}

export interface StandingsNext {
    fixture_id: number;
    opponent: Team;
    is_home: boolean;
    date: string;
}

/** Current value ÷ the value on the last snapshot 30+ days before the latest one (player ficha). */
export interface PlayerValueTrend {
    multiple: number;
    /** The older snapshot's value. */
    value: number;
    date: string;
}

/** Season points per million of current value; `rank` is null for a player without points. */
export interface PlayerPointsPerMillion {
    value: number;
    rank: number | null;
    /** League players with points and a value — the rank's field. */
    ranked: number;
}

/** Current value minus what the current owner paid in their latest signing/buyout. */
export interface PlayerCapitalGain {
    amount: number;
    paid: number;
    type: Extract<SeasonActivityType, 'signing' | 'buyout'>;
    occurred_at: string;
}

/** A manager's place among the league's managers in one started jornada, by lineup points. */
export interface ManagerWeekRank {
    rank: number;
    managers: number;
    points: number;
    /** Shares the jornada's lowest score (farolillo rojo). */
    is_last: boolean;
}

/** Keyed by week number; only started jornadas the manager has a lineup for. */
export type ManagerWeekRankMap = Record<number, ManagerWeekRank>;

export interface ManagerWeeklySummary {
    played_weeks: number;
    /** Season points ÷ started jornadas with a lineup; null with none. */
    average_points: number | null;
    best_week: {
        week_number: number;
        points: number;
        rank: number;
        managers: number;
    } | null;
}

/** A team's LaLiga averages per match played (team ficha). */
export interface TeamPerMatchRates {
    goals_for: number;
    points: number;
}

/** The team ficha squad's league footprint: players owned by a manager and their summed fantasy points. */
export interface TeamSquadSummary {
    owned_count: number;
    fantasy_points: number;
}

export interface StandingsRow {
    position: number;
    team: Team;
    played: number;
    won: number;
    drawn: number;
    lost: number;
    goals_for: number;
    goals_against: number;
    goal_difference: number;
    points: number;
    /** Up to the last 4 finished results, newest first. */
    recent_form: StandingsFormEntry[];
    /** This team's fixture in progress right now, or null if it isn't playing. */
    live: StandingsFormEntry | null;
    /** This team's next scheduled fixture — only set when `live` is null. */
    next: StandingsNext | null;
}

export type MaxBidStatus =
    'profitable' | 'unprofitable' | 'unavailable' | 'no_data';

export interface MaxBidRival {
    team: Team;
    /** Real LaLiga standings position on the reference date. */
    position: number;
    days_until: number;
    /** −1 (leader) … +1 (last). */
    difficulty: number;
    /** Proximity weight, 0,5^(days/7). */
    weight: number;
}

/** The hidden "puja máxima rentable" estimate — only sent in god mode. */
export interface MaxBidEstimate {
    status: MaxBidStatus;
    value: number;
    /** The confidence actually used (or requested), e.g. 0.75 — adjustable via the ?confianza stepper. */
    confidence: number;
    /** The clause-lock length actually used, in days. */
    lock_days: number;
    bid: number | null;
    bid_premium: number | null;
    /** Day 0 (today) … day 14 of the clause lock; null without an estimate. */
    projection: number[] | null;
    projected_day7: number | null;
    projected_day14: number | null;
    momentum_increment: number | null;
    market_adjustment: number | null;
    sport_adjustment: number | null;
    daily_increment: number | null;
    sport_score: number | null;
    form: number | null;
    participation: number | null;
    /** Team's last 3 matches, newest first. */
    recent_participation: { starter: boolean; minutes: number }[];
    rivals_effect: number | null;
    /** Next 3 fixtures, soonest first. */
    upcoming_rivals: MaxBidRival[];
    /** The market day the values come from (Y-m-d): the latest published day, null without market data. */
    reference_date: string | null;
}

/** A team as the shell's teletipo shows it: crest and short name. */
export interface TickerTeam {
    id: number;
    short_name: string;
    main_name: string;
    logo: string;
}

export interface TickerFixture {
    id: number;
    state: FixtureState;
    display_clock: string | null;
    local_score: number | null;
    guest_score: number | null;
    local_team: TickerTeam;
    guest_team: TickerTeam;
}

/** A listing of the current daily market, with its player's daily value move. */
export interface TickerListing {
    id: number;
    player_id: number;
    nickname: string;
    value: number;
    bids: number;
    market_value_difference: number;
    market_trend: MarketTrend | null;
}

export interface TickerActivity {
    id: number;
    type: SeasonActivityType;
    manager_name: string;
    player_nickname: string | null;
    amount: number | null;
}

/** The shell's "Teletipo" strip, shared with every page (remembered for a minute). */
export interface Ticker {
    live: TickerFixture[];
    /** The last jornada whose fixtures have all been played — null before the first one ends. */
    finished_week: number | null;
    results: TickerFixture[];
    market: TickerListing[];
    activities: TickerActivity[];
}

export interface JornadaMatchTeam {
    id: number;
    short_name: string;
    logo: string;
}

export interface JornadaMatchPlayer {
    id: number;
    nickname: string;
    image: string;
    position: PlayerPosition;
    /** Live or final points in this match — null while it hasn't kicked off. */
    points: number | null;
}

/** A manager whose jornada lineup has players in one match. */
export interface JornadaMatchManager {
    id: number;
    name: string;
    primary_color: string | null;
    /** Sum of the players' points in this match — null while it hasn't kicked off. */
    points: number | null;
    players: JornadaMatchPlayer[];
}

export interface JornadaMatch {
    id: number;
    state: FixtureState;
    date: string;
    display_clock: string | null;
    local_score: number | null;
    guest_score: number | null;
    local_team: JornadaMatchTeam;
    guest_team: JornadaMatchTeam;
    /** Null before the jornada starts (no lineups yet). */
    managers: JornadaMatchManager[] | null;
}

export type JornadaMatchesStatus =
    'not_started' | 'started' | 'live' | 'finished';

/** The "Ahora" block's matches strip: a few of the current jornada's matches. */
export interface JornadaMatches {
    week: number;
    status: JornadaMatchesStatus;
    matches: JornadaMatch[];
}

/** Who confirmed a lineup: worldcup26 (primary) or FútbolFantasy's "Alineación confirmada" (fallback). */
export type StartConfirmationSource = 'worldcup26' | 'futbolfantasy';

/** One player's start for one fixture. */
export interface StartProbabilityEntry {
    player: Player;
    /** FútbolFantasy's last predicted % (0–100) — kept after confirmation for "era N %"; null when FF gave none. */
    probability: number | null;
    /** In FútbolFantasy's probable XI. */
    predicted_starter: boolean;
    /** Confirmed lineup (worldcup26 first, FF second): true titular, false suplente, null not confirmed yet. */
    confirmed_starter: boolean | null;
    /** Where he plays in the XI shown, in worldcup26's vocabulary ("Right Back", "Center Left Midfielder"…): worldcup26's own once it confirms, else read off where FútbolFantasy draws its probable XI. Null off the XI or without a drawn pitch. */
    pitch_position: string | null;
}

/** One team's side of a fixture's start probabilities. */
export interface StartProbabilityTeamBlock {
    fixture_id: number;
    week_number: number;
    team: Team;
    /** The team's FútbolFantasy page, for the attribution link. */
    source_url: string;
    /** When FútbolFantasy was last read successfully (ISO 8601). */
    fetched_at: string | null;
    /** Older than 48 h: shown as "Datos de hace N días" with muted bars. */
    is_stale: boolean;
    confirmed_source: StartConfirmationSource | null;
    /** "4-3-3": worldcup26's once it confirms the lineup, else approximated from FútbolFantasy's probable XI (shown as "≈4-3-3"). Null when unknown. */
    formation: string | null;
    players: StartProbabilityEntry[];
}

/** The match ficha's start probabilities — a side without data is null. */
export interface FixtureStartProbabilities {
    local: StartProbabilityTeamBlock | null;
    guest: StartProbabilityTeamBlock | null;
}

/** The team ficha's probable XI for its next match. */
export interface TeamNextStartProbabilities extends StartProbabilityTeamBlock {
    opponent: Team;
    is_home: boolean;
}

/** A roster or player-ficha player's start for his team's next match (manager and player fichas). */
export interface PlayerNextStart {
    fixture_id: number;
    week_number: number;
    probability: number | null;
    predicted_starter: boolean;
    confirmed_starter: boolean | null;
    confirmed_source: StartConfirmationSource | null;
    is_stale: boolean;
    fetched_at: string | null;
    source_url: string;
    team_short_name: string;
    opponent: Team;
    is_home: boolean;
    /** The fixture's kickoff (ISO 8601). */
    date: string;
}

/** A lineup pick's start facts for their OWN fixture that week (manager index/ficha pitch) — present only while it hasn't kicked off and either FútbolFantasy or worldcup26 have data. */
export interface LineupPlayerStart {
    probability: number | null;
    predicted_starter: boolean;
    confirmed_starter: boolean | null;
    is_stale: boolean;
}
