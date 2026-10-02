import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
    index as playersIndex,
    rankings as playersRankings,
} from '@/routes/players';
import type { PlayerPosition } from '@/types/models';

type PlayersView = 'list' | 'rankings';

const VIEW_TABS: { view: PlayersView; label: string }[] = [
    { view: 'list', label: 'Listado' },
    { view: 'rankings', label: 'Rankings' },
];

/**
 * The Jugadores page's «Listado | Rankings» switch, styled like the Equipos
 * view tabs. The position and team filters both views share carry over.
 */
export function HqPlayersViewTabs({
    view,
    position,
    team,
}: {
    view: PlayersView;
    position: PlayerPosition[];
    team: number[];
}) {
    const select = (next: PlayersView) => {
        if (next === view) {
            return;
        }

        router.get(
            next === 'list' ? playersIndex().url : playersRankings().url,
            {
                position: position.join(',') || undefined,
                team: team.join(',') || undefined,
            },
            { preserveScroll: true },
        );
    };

    return (
        <div
            role="group"
            aria-label="Vista de jugadores"
            className="flex border-b border-hq-border-strong px-1.5 sm:px-3"
        >
            {VIEW_TABS.map((tab) => (
                <button
                    key={tab.view}
                    type="button"
                    aria-pressed={view === tab.view}
                    onClick={() => select(tab.view)}
                    className={cn(
                        '-mb-px inline-flex min-h-11 cursor-pointer items-center border-b-2 px-3.5 font-mono text-[11.5px] font-bold tracking-[0.07em] uppercase transition-colors',
                        view === tab.view
                            ? 'border-hq-lime text-hq-lime'
                            : 'border-transparent text-hq-moss hover:text-hq-paper',
                    )}
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}
