import type { Season, SeasonManager } from '@/types/models';

export type PrizeManager = Pick<
    SeasonManager,
    'id' | 'name' | 'logo' | 'primary_color' | 'position'
>;

export interface PrizePlayer {
    id: number;
    nickname: string;
    image: string;
}

/** Every key a prize may put in a row's context (see the prize calculators). */
export interface PrizeContext {
    week_number?: number;
    weeks?: number[];
    favourite?: { season_manager_id: number; count: number } | null;
    nemesis?: { season_manager_id: number; count: number } | null;
    worst?: { player_id: number; overpaid: number } | null;
    player_id?: number;
    from_week?: number;
    to_week?: number;
    alive?: boolean;
    top_miss?: {
        fixture_lineup_id: number;
        fixture_id: number;
        player_id: number;
        week_number: number;
        points: number;
    } | null;
}

export interface PrizeRowData {
    season_manager_id: number;
    place: number | null;
    value: number | null;
    context: PrizeContext;
}

/** A player in the running for most_owned_player, with every manager who held them. */
export interface OwnedPlayerCandidate {
    player_id: number;
    chain: number[];
    owners: number[];
    transfers: number;
    on_market: boolean;
    weeks_held: Record<string, number>;
    winners: number[];
}

export type SeasonPrizeKey =
    | 'best_night'
    | 'most_buyouts_made'
    | 'sunday_king'
    | 'bench_points'
    | 'most_overpaid'
    | 'most_buyouts_suffered'
    | 'worst_weeks'
    | 'longest_partnership'
    | 'most_owned_player'
    | 'worst_night';

export interface PrizeStanding {
    key: SeasonPrizeKey;
    name: string;
    amount: number;
    rule: string;
    leaders: number[];
    shares: Record<string, number>;
    rows: PrizeRowData[];
    candidates: OwnedPlayerCandidate[];
}

export interface PrizesPageProps {
    season: Season;
    lastFinishedWeek: number;
    managers: PrizeManager[];
    prizes: PrizeStanding[];
    players: Record<string, PrizePlayer>;
}
