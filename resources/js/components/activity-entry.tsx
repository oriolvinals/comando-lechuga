import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Repeat,
    Shield,
    ShieldCheck,
    Trophy,
    User,
    UserPlus,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency, formatRelativeTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SeasonActivity, SeasonActivityType } from '@/types/models';

export const TYPE_ICONS: Record<SeasonActivityType, LucideIcon> = {
    signing: ArrowDownToLine,
    sale: ArrowUpFromLine,
    buyout: Repeat,
    shield: ShieldCheck,
    weekly_prize: Trophy,
    joined_league: UserPlus,
};

export const TYPE_LABELS: Record<SeasonActivityType, string> = {
    signing: 'Fichaje',
    sale: 'Venta',
    buyout: 'Cláusula',
    shield: 'Blindaje',
    weekly_prize: 'Premio semanal',
    joined_league: 'Alta en la liga',
};

export function describeActivity(activity: SeasonActivity): string {
    const team = activity.source_season_team.name;
    const player = activity.player?.nickname;
    const amount =
        activity.amount !== null ? formatCurrency(activity.amount) : null;

    switch (activity.type) {
        case 'signing':
            return amount
                ? `${team} fichó a ${player} por ${amount}`
                : `${team} fichó a ${player}`;
        case 'sale':
            return amount
                ? `${team} vendió a ${player} por ${amount}`
                : `${team} vendió a ${player}`;
        case 'buyout':
            return amount
                ? `${team} pagó la cláusula de ${player} a ${activity.target_season_team?.name} por ${amount}`
                : `${team} pagó la cláusula de ${player} a ${activity.target_season_team?.name}`;
        case 'shield':
            return `${team} blindó a ${player}`;
        case 'weekly_prize':
            return amount
                ? `${team} ganó el premio de la jornada ${activity.week_number} (${amount})`
                : `${team} ganó el premio de la jornada ${activity.week_number}`;
        case 'joined_league':
            return `${team} se unió a la liga`;
    }
}

interface ActivityEntryProps {
    activity: SeasonActivity;
}

export function ActivityEntry({ activity }: ActivityEntryProps) {
    const Icon = TYPE_ICONS[activity.type];

    return (
        <li className="flex items-center gap-3 py-3">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
                <Icon className="h-4 w-4" />
            </span>

            <div className="flex shrink-0 items-center -space-x-2">
                <EntityImage
                    src={activity.source_season_team.logo}
                    alt={activity.source_season_team.name}
                    fallback={Shield}
                    className="h-8 w-8 ring-2 ring-white"
                />
                {activity.player && (
                    <EntityImage
                        src={activity.player.image}
                        alt={activity.player.nickname}
                        fallback={User}
                        className="h-8 w-8 ring-2 ring-white"
                    />
                )}
                {activity.type === 'buyout' && activity.target_season_team && (
                    <EntityImage
                        src={activity.target_season_team.logo}
                        alt={activity.target_season_team.name}
                        fallback={Shield}
                        className="h-8 w-8 ring-2 ring-white"
                    />
                )}
            </div>

            <p className="flex-1 text-sm">{describeActivity(activity)}</p>

            {activity.value_difference !== null && (
                <span
                    className={cn(
                        'shrink-0 text-xs font-medium',
                        activity.value_difference >= 0
                            ? 'text-emerald-600'
                            : 'text-rose-600',
                    )}
                    title="Diferencia entre el importe y el valor de mercado en esa fecha"
                >
                    {activity.value_difference >= 0 ? '+' : ''}
                    {formatCurrency(activity.value_difference)}
                </span>
            )}

            <span className="shrink-0 text-xs text-neutral-400">
                {formatRelativeTime(activity.occurred_at)}
            </span>
        </li>
    );
}
