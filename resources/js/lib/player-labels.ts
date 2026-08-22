import type { PlayerPosition, PlayerStatus } from '@/types/models';

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
    'total_scoring_att',
    'won_contest',
    'pen_area_entries',
    'ball_recovery',
    'poss_lost_all',
    'effective_clearance',
    'saves',
    'goals_conceded',
    'penalty_save',
    'penalty_won',
    'penalty_conceded',
    'penalty_failed',
    'own_goals',
    'yellow_card',
    'second_yellow_card',
    'red_card',
    'marca_points',
] as const;

export const JORNADA_STAT_LABELS: Record<string, string> = {
    mins_played: 'Minutos jugados',
    goals: 'Goles',
    goal_assist: 'Asistencias',
    offtarget_att_assist: 'Asistencias sin gol',
    total_scoring_att: 'Tiros a puerta',
    won_contest: 'Regates',
    pen_area_entries: 'Entradas al área',
    ball_recovery: 'Recuperaciones',
    poss_lost_all: 'Pérdidas de balón',
    effective_clearance: 'Despejes',
    saves: 'Paradas',
    goals_conceded: 'Goles encajados',
    penalty_save: 'Penaltis parados',
    penalty_won: 'Penaltis provocados',
    penalty_conceded: 'Penaltis cometidos',
    penalty_failed: 'Penaltis fallados',
    own_goals: 'Goles en propia puerta',
    yellow_card: 'Tarjetas amarillas',
    second_yellow_card: 'Segundas amarillas',
    red_card: 'Tarjetas rojas',
    marca_points: 'Puntos Marca',
};
