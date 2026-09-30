import type { PrizeManager, PrizePlayer, PrizeStanding } from '@/types/prizes';

/**
 * Placeholder for the prize detail (task 13 replaces it). The Banquillo's
 * «el que más dejó» will open the jornada sheet from here with
 * useJornadaSheet()?.openMatch(top_miss.player_id, top_miss.fixture_id).
 */
export function PrizeDetailDialog(props: {
    prize: PrizeStanding | null;
    managers: Map<number, PrizeManager>;
    players: Record<string, PrizePlayer>;
    viewer: number | null;
    onClose: () => void;
}) {
    void props;

    return null;
}
