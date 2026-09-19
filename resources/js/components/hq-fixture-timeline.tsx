import { cn } from '@/lib/utils';
import type { FixtureEventEntry, Team } from '@/types/models';

// A VAR review has no icon of its own: it renders as a full-width band, not
// on either side of the minute (see VarDecisionRow).
const EVENT_ICON: Record<Exclude<FixtureEventEntry['type'], 'var'>, string> = {
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
        return (
            <span
                title="Autogol"
                className="border border-hq-live px-1 py-px font-mono text-[9px] font-bold text-hq-live"
            >
                PP
            </span>
        );
    }

    if (event.type === 'var') {
        return null;
    }

    return <span className="text-xs">{EVENT_ICON[event.type]}</span>;
}

function VarDecisionRow({
    event,
    team,
}: {
    event: FixtureEventEntry;
    team: Team;
}) {
    const subject = event.player?.nickname ?? event.unresolved_name;

    return (
        <div className="flex items-center justify-center gap-3 border-b border-l-2 border-hq-border border-l-hq-azure bg-hq-azure/10 px-3 py-2 text-[12.5px] last:border-b-0">
            <span className="border border-hq-azure px-1.5 py-px font-mono text-[10px] font-bold tracking-widest text-hq-azure">
                VAR
            </span>
            <span className="font-mono text-[11px] text-hq-moss">
                {event.minute}'
            </span>
            <span className="flex items-center gap-1.5 text-hq-paper">
                <img
                    src={team.logo}
                    alt={team.main_name}
                    className="h-4 w-4 object-contain"
                />
                {event.label}
                {subject && (
                    <>
                        <span className="text-hq-moss">·</span>
                        {subject}
                    </>
                )}
            </span>
        </div>
    );
}

interface HqFixtureTimelineProps {
    events: FixtureEventEntry[];
    localTeam: Team;
    guestTeam: Team;
}

export function HqFixtureTimeline({
    events,
    localTeam,
    guestTeam,
}: HqFixtureTimelineProps) {
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
                if (event.type === 'var') {
                    return (
                        <VarDecisionRow
                            key={event.id}
                            event={event}
                            team={
                                event.team_id === localTeam.id
                                    ? localTeam
                                    : guestTeam
                            }
                        />
                    );
                }

                const label =
                    event.player?.nickname ??
                    event.unresolved_name ??
                    'Sin jugador vinculado';
                // An own goal's team_id is the scorer's own team (see
                // SyncLiveSeasonMatchData), but the goal actually counts for
                // the other side — render it on the side it benefits, not
                // the scorer's own side.
                const isLocal =
                    event.type === 'goal' && event.is_own_goal
                        ? event.team_id !== localTeam.id
                        : event.team_id === localTeam.id;

                return (
                    <div
                        key={event.id}
                        className="flex items-center border-b border-hq-border px-3 py-2 text-[12.5px] last:border-b-0"
                    >
                        <span
                            className={cn(
                                'flex-1 pr-3 text-right',
                                isLocal
                                    ? 'text-hq-paper'
                                    : 'text-hq-moss-dim italic',
                            )}
                        >
                            {isLocal ? label : ''}
                        </span>
                        <span className="flex w-16 shrink-0 items-center justify-center">
                            <span className="flex w-6 shrink-0 justify-end">
                                {isLocal && <EventIcon event={event} />}
                            </span>
                            <span className="mx-1 shrink-0 font-mono text-[11px] text-hq-moss">
                                {event.minute}'
                            </span>
                            <span className="flex w-6 shrink-0 justify-start">
                                {!isLocal && <EventIcon event={event} />}
                            </span>
                        </span>
                        <span
                            className={cn(
                                'flex-1 pl-3',
                                !isLocal
                                    ? 'text-hq-paper'
                                    : 'text-hq-moss-dim italic',
                            )}
                        >
                            {!isLocal ? label : ''}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
