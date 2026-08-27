import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { show as playersShow } from '@/routes/players';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { SeasonActivity, SeasonActivityType } from '@/types/models';

export const TYPE_LABELS: Record<SeasonActivityType, string> = {
    signing: 'Fichaje',
    sale: 'Venta',
    buyout: 'Cláusula',
    shield: 'Blindaje',
    weekly_prize: 'Premio semanal',
    joined_league: 'Nuevo manager',
};

/** Solid background classes for the activity timeline's left accent bar. */
export const TYPE_BAR_CLASSES: Record<SeasonActivityType, string> = {
    signing: 'bg-hq-lime',
    sale: 'bg-hq-ember',
    buyout: 'bg-hq-med',
    shield: 'bg-hq-def',
    weekly_prize: 'bg-hq-gold',
    joined_league: 'bg-hq-moss',
};

/** Text colors matching TYPE_BAR_CLASSES — same palette, for the type label. */
export const TYPE_COLORS: Record<SeasonActivityType, string> = {
    signing: 'text-hq-lime',
    sale: 'text-hq-ember',
    buyout: 'text-hq-med',
    shield: 'text-hq-def',
    weekly_prize: 'text-hq-gold',
    joined_league: 'text-hq-moss',
};

export function describeActivityBody(activity: SeasonActivity): ReactNode {
    const team = (
        <Link
            href={seasonManagersShow(activity.source_season_manager.id).url}
            className="font-bold text-hq-khaki hover:underline"
        >
            {activity.source_season_manager.name}
        </Link>
    );
    const player = activity.player && (
        <Link
            href={playersShow(activity.player.id).url}
            className="font-bold text-hq-khaki hover:underline"
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
                    {activity.target_season_manager && (
                        <Link
                            href={
                                seasonManagersShow(
                                    activity.target_season_manager.id,
                                ).url
                            }
                            className="font-bold text-hq-khaki hover:underline"
                        >
                            {activity.target_season_manager.name}
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

export function isFavorableDifference(activity: SeasonActivity): boolean {
    if (activity.value_difference === null) {
        return false;
    }

    return activity.type === 'signing' || activity.type === 'buyout'
        ? activity.value_difference <= 0
        : activity.value_difference >= 0;
}
