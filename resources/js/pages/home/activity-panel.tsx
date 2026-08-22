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
import type { SeasonActivity, SeasonActivityType } from '@/types/models';

interface ActivityPanelProps {
    activity: SeasonActivity[];
}

const TYPE_ICONS: Record<SeasonActivityType, LucideIcon> = {
    signing: ArrowDownToLine,
    sale: ArrowUpFromLine,
    buyout: Repeat,
    shield: ShieldCheck,
    weekly_prize: Trophy,
    joined_league: UserPlus,
};

function describeActivity(activity: SeasonActivity): string {
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

export function ActivityPanel({ activity }: ActivityPanelProps) {
    return (
        <section aria-labelledby="activity-heading">
            <h2 id="activity-heading" className="text-lg font-semibold">
                Actividad reciente
            </h2>

            {activity.length === 0 ? (
                <p className="mt-4 text-neutral-500">
                    Todavía no hay actividad esta temporada.
                </p>
            ) : (
                <ul className="mt-4 divide-y divide-neutral-200">
                    {activity.map((entry) => {
                        const Icon = TYPE_ICONS[entry.type];

                        return (
                            <li
                                key={entry.id}
                                className="flex items-center gap-3 py-3"
                            >
                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-neutral-500">
                                    <Icon className="h-4 w-4" />
                                </span>

                                <div className="flex shrink-0 items-center -space-x-2">
                                    <EntityImage
                                        src={entry.source_season_team.logo}
                                        alt={entry.source_season_team.name}
                                        fallback={Shield}
                                        className="h-8 w-8 ring-2 ring-white"
                                    />
                                    {entry.player && (
                                        <EntityImage
                                            src={entry.player.image}
                                            alt={entry.player.nickname}
                                            fallback={User}
                                            className="h-8 w-8 ring-2 ring-white"
                                        />
                                    )}
                                    {entry.type === 'buyout' &&
                                        entry.target_season_team && (
                                            <EntityImage
                                                src={
                                                    entry.target_season_team
                                                        .logo
                                                }
                                                alt={
                                                    entry.target_season_team
                                                        .name
                                                }
                                                fallback={Shield}
                                                className="h-8 w-8 ring-2 ring-white"
                                            />
                                        )}
                                </div>

                                <p className="flex-1 text-sm">
                                    {describeActivity(entry)}
                                </p>

                                <span className="shrink-0 text-xs text-neutral-400">
                                    {formatRelativeTime(entry.occurred_at)}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
