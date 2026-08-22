import { Link } from '@inertiajs/react';
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
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqSection } from '@/components/hq-section';
import {
    formatCurrency,
    formatFullDateTime,
    formatRelativeTime,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as activityIndex } from '@/routes/activity';
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

const TYPE_LABELS: Record<SeasonActivityType, string> = {
    signing: 'Fichaje',
    sale: 'Venta',
    buyout: 'Cláusula',
    shield: 'Blindaje',
    weekly_prize: 'Premio semanal',
    joined_league: 'Nuevo manager',
};

const TYPE_COLORS: Record<SeasonActivityType, string> = {
    signing: 'text-hq-lime',
    sale: 'text-hq-ember',
    buyout: 'text-hq-med',
    shield: 'text-hq-lime',
    weekly_prize: 'text-hq-gold',
    joined_league: 'text-hq-moss',
};

function describeActivityBody(activity: SeasonActivity): ReactNode {
    const team = activity.source_season_team.name;
    const player = activity.player?.nickname;

    switch (activity.type) {
        case 'signing':
            return (
                <>
                    <b>{team}</b> fichó a <b>{player}</b>
                </>
            );
        case 'sale':
            return (
                <>
                    <b>{team}</b> vendió a <b>{player}</b>
                </>
            );
        case 'buyout':
            return (
                <>
                    <b>{team}</b> pagó la cláusula de <b>{player}</b> a{' '}
                    <b>{activity.target_season_team?.name}</b>
                </>
            );
        case 'shield':
            return (
                <>
                    <b>{team}</b> blindó a <b>{player}</b>
                </>
            );
        case 'weekly_prize':
            return (
                <>
                    <b>{team}</b> ganó el premio de la jornada{' '}
                    {activity.week_number}
                </>
            );
        case 'joined_league':
            return (
                <>
                    <b>{team}</b> se unió a la liga
                </>
            );
    }
}

function isFavorableDifference(activity: SeasonActivity): boolean {
    if (activity.value_difference === null) {
        return false;
    }

    return activity.type === 'signing' || activity.type === 'buyout'
        ? activity.value_difference <= 0
        : activity.value_difference >= 0;
}

function ActivityCard({ activity }: { activity: SeasonActivity }) {
    const Icon = TYPE_ICONS[activity.type];

    return (
        <div className="hq-card-cut px-4 py-3.5">
            <div className="mb-2.5 flex items-center justify-between">
                <span
                    className={cn(
                        'flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-wider uppercase',
                        TYPE_COLORS[activity.type],
                    )}
                >
                    <Icon className="h-3.5 w-3.5" />
                    {TYPE_LABELS[activity.type]}
                </span>
                <time
                    dateTime={activity.occurred_at}
                    title={formatFullDateTime(activity.occurred_at)}
                    className="font-mono text-[10px] text-hq-moss-dim"
                >
                    {formatRelativeTime(activity.occurred_at)}
                </time>
            </div>
            <div className="flex items-center gap-2.5">
                <EntityImage
                    src={activity.source_season_team.logo}
                    alt={activity.source_season_team.name}
                    fallback={Shield}
                    shape="square"
                    className="hq-crest-cut h-11 w-11 shrink-0 bg-hq-border p-1 text-hq-khaki"
                />
                {activity.player && (
                    <EntityImage
                        src={activity.player.image}
                        alt={activity.player.nickname}
                        fallback={User}
                        className="h-12 w-12 shrink-0 border border-hq-border-strong bg-hq-panel-alt"
                    />
                )}
                {activity.type === 'buyout' && activity.target_season_team && (
                    <EntityImage
                        src={activity.target_season_team.logo}
                        alt={activity.target_season_team.name}
                        fallback={Shield}
                        shape="square"
                        className="hq-crest-cut h-11 w-11 shrink-0 bg-hq-border p-1 text-hq-khaki"
                    />
                )}
                <p className="flex-1 text-sm text-hq-paper/90">
                    {describeActivityBody(activity)}
                </p>
                {activity.amount !== null && (
                    <div className="shrink-0 text-right">
                        <span className="hq-tag-cut inline-block bg-hq-khaki px-2.5 py-1 font-mono text-xs font-bold text-hq-ink">
                            {formatCurrency(activity.amount)}
                        </span>
                        {activity.value_difference !== null && (
                            <p
                                className={cn(
                                    'mt-1 font-mono text-[10px] font-bold',
                                    isFavorableDifference(activity)
                                        ? 'text-hq-lime'
                                        : 'text-hq-live',
                                )}
                            >
                                {activity.value_difference >= 0 ? '+' : ''}
                                {formatCurrency(activity.value_difference)}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

export function ActivityPanel({ activity }: ActivityPanelProps) {
    return (
        <HqSection title="Actividad">
            <div className="mb-4 flex justify-end">
                <Link
                    href={activityIndex().url}
                    className="font-mono text-[11px] font-bold text-hq-lime hover:underline"
                >
                    VER TODO →
                </Link>
            </div>

            {activity.length === 0 ? (
                <p className="text-sm text-hq-moss">
                    Todavía no hay actividad esta temporada.
                </p>
            ) : (
                <div className="grid grid-cols-1 gap-2.5 md:grid-cols-2">
                    {activity.map((entry) => (
                        <ActivityCard key={entry.id} activity={entry} />
                    ))}
                </div>
            )}
        </HqSection>
    );
}
