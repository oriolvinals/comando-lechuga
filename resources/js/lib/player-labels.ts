import type { JornadaStats, PlayerPosition, PlayerStatus } from '@/types/models';

export const POSITION_LABELS: Record<PlayerPosition, string> = {
    goalkeeper: 'Portero',
    defender: 'Defensa',
    midfield: 'Centrocampista',
    striker: 'Delantero',
    coach: 'Entrenador',
};

export const POSITION_ABBREVIATIONS: Record<PlayerPosition, string> = {
    goalkeeper: 'POR',
    defender: 'DEF',
    midfield: 'MED',
    striker: 'DEL',
    coach: 'ENT',
};

export const POSITION_BADGE_STYLES: Record<PlayerPosition, string> = {
    goalkeeper: 'bg-orange-100 text-orange-700',
    defender: 'bg-violet-100 text-violet-700',
    midfield: 'bg-blue-100 text-blue-700',
    striker: 'bg-amber-100 text-amber-800',
    coach: 'bg-emerald-100 text-emerald-700',
};

export const STATUS_LABELS: Record<PlayerStatus, string> = {
    ok: 'Disponible',
    injured: 'Lesionado',
    out_of_league: 'Fuera de la liga',
    suspended: 'Sancionado',
    doubtful: 'Duda',
};

export const STATUS_COLORS: Record<PlayerStatus, string> = {
    ok: 'text-emerald-600',
    injured: 'text-rose-600',
    suspended: 'text-red-600',
    doubtful: 'text-amber-500',
    out_of_league: 'text-neutral-400',
};

export const JORNADA_STAT_ORDER = [
    'mins_played',
    'goals',
    'goal_assist',
    'offtarget_att_assist',
    'pen_area_entries',
    'penalty_won',
    'penalty_save',
    'penalty_failed',
    'penalty_conceded',
    'saves',
    'effective_clearance',
    'own_goals',
    'goals_conceded',
    'yellow_card',
    'second_yellow_card',
    'red_card',
    'total_scoring_att',
    'won_contest',
    'ball_recovery',
    'poss_lost_all',
    'marca_points',
] as const;

export function didNotPlayMatch(stats: JornadaStats): boolean {
    return (stats.mins_played?.[0] ?? 0) === 0;
}

export const JORNADA_STAT_LABELS: Record<string, string> = {
    mins_played: 'Minutos jugados',
    goals: 'Goles',
    goal_assist: 'Asistencias de gol',
    offtarget_att_assist: 'Asistencias sin gol',
    pen_area_entries: 'Balones al área',
    penalty_won: 'Penaltis provocados',
    penalty_save: 'Penaltis parados',
    penalty_failed: 'Penaltis fallados',
    penalty_conceded: 'Penaltis cometidos',
    saves: 'Paradas',
    effective_clearance: 'Despejes',
    own_goals: 'Gol en propia puerta',
    goals_conceded: 'Goles en contra',
    yellow_card: 'Tarjetas amarillas',
    second_yellow_card: 'Segundas amarillas',
    red_card: 'Tarjetas rojas',
    total_scoring_att: 'Tiros a puerta',
    won_contest: 'Regates',
    ball_recovery: 'Balones recuperados',
    poss_lost_all: 'Posesiones perdidas',
    marca_points: 'Puntos DAZN',
};
