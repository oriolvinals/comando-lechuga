export interface Team {
    id: number;
    name: string;
    short_name: string;
    logo: string;
}

export interface Player {
    id: number;
    nickname: string;
    image: string;
    team: Team;
}

export interface Fixture {
    id: number;
    date: string;
    local_score: number | null;
    guest_score: number | null;
    state: 'scheduled' | 'finished';
    local_team: Team;
    guest_team: Team;
}

export interface SeasonTeam {
    id: number;
    name: string;
    logo: string;
    total_points: number;
    live_points: number;
    position: number;
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
}

export interface Season {
    current_week: number;
    total_weeks: number;
}
