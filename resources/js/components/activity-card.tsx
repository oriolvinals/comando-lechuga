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
import {
    formatCurrency,
    formatFullDateTime,
    formatRelativeTime,
} from '@/lib/format';
import { crestTintStyle } from '@/lib/season-team-colors';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import { show as seasonTeamsShow } from '@/routes/season-teams';
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
    joined_league: 'Nuevo manager',
};

const TYPE_COLORS: Record<SeasonActivityType, string> = {
    signing: 'text-hq-lime',
    sale: 'text-hq-ember',
    buyout: 'text-hq-med',
    shield: 'text-hq-def',
    weekly_prize: 'text-hq-gold',
    joined_league: 'text-hq-moss',
};

function describeActivityBody(activity: SeasonActivity): ReactNode {
    const team = (
        <Link
            href={seasonTeamsShow(activity.source_season_team.id).url}
            className="font-bold text-hq-lime hover:underline"
        >
            {activity.source_season_team.name}
        </Link>
    );
    const player = activity.player && (
        <Link
            href={playersShow(activity.player.id).url}
            className="font-bold hover:underline"
        >
            {activity.player.nickname}
        </Link>
    );

    switch (activity.type) {
        case 'signing':
            return (
                <>
                    {team} fichó a {player}
                </>
            );
        case 'sale':
            return (
                <>
                    {team} vendió a {player}
                </>
            );
        case 'buyout':
            return (
                <>
                    {team} pagó la cláusula de {player} a{' '}
                    {activity.target_season_team && (
                        <Link
                            href={
                                seasonTeamsShow(
                                    activity.target_season_team.id,
                                ).url
                            }
                            className="font-bold hover:underline"
                        >
                            {activity.target_season_team.name}
                        </Link>
                    )}
                </>
            );
        case 'shield':
            return (
                <>
                    {team} blindó a {player}
                </>
            );
        case 'weekly_prize':
            return (
                <>
                    {team} ganó el premio de la jornada {activity.week_number}
                </>
            );
        case 'joined_league':
            return <>{team} se unió a la liga</>;
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

export function ActivityCard({ activity }: { activity: SeasonActivity }) {
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
                <Link
                    href={seasonTeamsShow(activity.source_season_team.id).url}
                    className="flex shrink-0 flex-col items-center gap-0.5"
                >
                    <EntityImage
                        src={activity.source_season_team.logo}
                        alt={activity.source_season_team.name}
                        fallback={Shield}
                        shape="square"
                        style={crestTintStyle(
                            activity.source_season_team.primary_color,
                        )}
                        className="hq-crest-cut h-11 w-11 bg-hq-border p-1 text-hq-khaki"
                    />
                    {activity.type === 'buyout' && (
                        <span className="font-mono text-[8px] font-bold tracking-wide text-hq-lime uppercase">
                            Ficha
                        </span>
                    )}
                </Link>
                {activity.player && (
                    <Link
                        href={playersShow(activity.player.id).url}
                        className="shrink-0"
                    >
                        <EntityImage
                            src={activity.player.image}
                            alt={activity.player.nickname}
                            fallback={User}
                            className="h-12 w-12 border border-hq-border-strong bg-hq-panel-alt"
                        />
                    </Link>
                )}
                {activity.type === 'buyout' && activity.target_season_team && (
                    <Link
                        href={
                            seasonTeamsShow(activity.target_season_team.id)
                                .url
                        }
                        className="flex shrink-0 flex-col items-center gap-0.5"
                    >
                        <EntityImage
                            src={activity.target_season_team.logo}
                            alt={activity.target_season_team.name}
                            fallback={Shield}
                            shape="square"
                            style={crestTintStyle(
                                activity.target_season_team.primary_color,
                            )}
                            className="hq-crest-cut h-11 w-11 bg-hq-border p-1 text-hq-khaki"
                        />
                        <span className="font-mono text-[8px] font-bold tracking-wide text-hq-moss uppercase">
                            Cede
                        </span>
                    </Link>
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
