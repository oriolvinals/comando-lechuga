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
