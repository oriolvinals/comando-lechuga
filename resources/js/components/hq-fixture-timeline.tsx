import { cn } from '@/lib/utils';
import type { FixtureEventEntry } from '@/types/models';

const EVENT_ICON: Record<FixtureEventEntry['type'], string> = {
    goal: '⚽',
    yellow_card: '',
    red_card: '',
    penalty_missed: 'P✗',
};

function EventIcon({ event }: { event: FixtureEventEntry }) {
    if (event.type === 'yellow_card' || event.type === 'red_card') {
        return (
            <span
                className={cn(
                    'inline-block h-3 w-2 rounded-[1px]',
                    event.type === 'yellow_card' ? 'bg-hq-gold' : 'bg-hq-live',
                )}
            />
        );
    }

    if (event.type === 'goal' && event.is_own_goal) {
        return <span className="text-xs whitespace-nowrap">⚽ PP</span>;
    }

    return <span className="text-xs">{EVENT_ICON[event.type]}</span>;
}

interface HqFixtureTimelineProps {
    events: FixtureEventEntry[];
    localTeamId: number;
}

export function HqFixtureTimeline({ events, localTeamId }: HqFixtureTimelineProps) {
    if (events.length === 0) {
        return (
            <p className="border border-dashed border-hq-border-strong px-4 py-6 text-center font-mono text-[11px] text-hq-moss-dim">
                Sin eventos todavía
            </p>
        );
    }

    return (
        <div className="border border-hq-border bg-hq-panel">
            {events.map((event) => {
                const label = event.player?.nickname ?? event.unresolved_name ?? 'Sin jugador vinculado';
                // An own goal's team_id is the scorer's own team (see
                // SyncLiveSeasonMatchData), but the goal actually counts for
                // the other side — render it on the side it benefits, not
                // the scorer's own side.
                const isLocal =
                    event.type === 'goal' && event.is_own_goal
                        ? event.team_id !== localTeamId
                        : event.team_id === localTeamId;

                return (
                    <div key={event.id} className="flex items-center border-b border-hq-border px-3 py-2 text-[12.5px] last:border-b-0">
                        <span className={cn('flex-1 pr-2.5 text-right', isLocal ? 'text-hq-paper' : 'text-hq-moss-dim italic')}>
                            {isLocal ? label : ''}
                        </span>
                        <span className="w-6 text-center">
                            <EventIcon event={event} />
                        </span>
                        <span className="w-8.5 font-mono text-[11px] text-hq-moss">{event.minute}'</span>
                        <span className="w-6" />
                        <span className={cn('flex-1 pl-2.5', !isLocal ? 'text-hq-paper' : 'text-hq-moss-dim italic')}>
                            {!isLocal ? label : ''}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
