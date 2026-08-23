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

export interface OwnerTeam {
    id: number;
    name: string;
    logo: string;
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
    owner_team: OwnerTeam | null;
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
    lineup_team: SeasonTeam | null;
}

export interface SeasonTeam {
    id: number;
    name: string;
    logo: string;
    total_points: number;
    live_points: number;
    position: number;
    last_position: number;
    value: number;
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

export interface SeasonActivity {
    id: number;
    type: SeasonActivityType;
    amount: number | null;
    week_number: number | null;
    occurred_at: string;
    source_season_team: SeasonTeam;
    target_season_team: SeasonTeam | null;
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

export interface SeasonTeamLineupPlayerEntry {
    id: number;
    points: number | null;
    stats: JornadaStats | null;
    position: PlayerPosition;
    player: Player;
}

export interface SeasonTeamLineup {
    id: number;
    points: number;
    week_number: number;
    tactical_formation: number[];
    season_team: SeasonTeam;
    players: SeasonTeamLineupPlayerEntry[];
}

export interface SeasonTeamPlayer {
    id: number;
    buyout_clause: number;
    buyout_clause_locked_until: string;
    shielded: boolean;
    player: Player;
}

export interface PlayerOwnership {
    id: number;
    buyout_clause: number;
    buyout_clause_locked_until: string;
    shielded: boolean;
    season_team: SeasonTeam;
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
    lineup_team: SeasonTeam | null;
}

export interface OwnershipActivity {
    id: number;
    type: SeasonActivityType;
    occurred_at: string;
    amount: number | null;
    source_season_team: SeasonTeam;
    target_season_team: SeasonTeam | null;
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
