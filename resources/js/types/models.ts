export interface Team {
    id: number;
    name: string;
    short_name: string;
    logo: string;
}

export type PlayerPosition =
    'goalkeeper' | 'defender' | 'midfield' | 'striker' | 'coach';

export type PlayerStatus =
    'ok' | 'injured' | 'out_of_league' | 'suspended' | 'doubtful';

export interface OwnerManager {
    id: number;
    name: string;
    logo: string;
    primary_color: string | null;
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
    points: number;
    average_points: string;
    owner_manager: OwnerManager | null;
    /** Points for the last 3 played matches, oldest first, ordered by fixture date — null-padded at the end when fewer than 3 exist. */
    recent_scores: (number | null)[];
    /** Per recent_scores slot, whether a real finished fixture exists there — false means the team hasn't played that many matches yet, never "not called up" (a finished fixture with no score is still true). */
    recent_scores_finished: boolean[];
    /** Per recent_scores slot, whether the player was in a given manager's lineup that week. Only present on the manager ficha. */
    recent_scores_used?: (boolean | null)[];
}

export type FixtureState =
    'scheduled' | 'first_half' | 'half_time' | 'second_half' | 'finished';

export interface Fixture {
    id: number;
    week_number: number;
    date: string;
    local_score: number | null;
    guest_score: number | null;
    state: FixtureState;
    local_team: Team;
    guest_team: Team;
}

export interface PlayerScore {
    id: number;
    team_id: number;
    team: Team;
    points: number;
    stats: JornadaStats;
    ideal_formation: boolean;
    player: Player;
    lineup_manager: SeasonManager | null;
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
    points: number;
    stats: JornadaStats;
    ideal_formation: boolean;
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
