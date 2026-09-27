import { Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { Fragment } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqTooltip } from '@/components/hq-tooltip';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { FixtureEventEntry, Team } from '@/types/models';

const HALF_TIME_MINUTE = 45;

function TeamCrest({ team, className }: { team: Team; className?: string }) {
    return (
        <EntityImage
            src={team.logo}
            alt=""
            fallback={Shield}
            shape="square"
            className={cn(
                'h-4 w-4 shrink-0 rounded-none bg-transparent',
                className,
            )}
        />
    );
}

const TEXT_GLYPH_CLASS =
    'border border-current px-[3px] py-0.5 font-mono text-[9.5px] leading-none font-bold text-hq-live';

function EventIcon({ event }: { event: FixtureEventEntry }) {
    if (event.type === 'yellow_card' || event.type === 'red_card') {
        return (
            <span
                title={event.type === 'yellow_card' ? 'Amarilla' : 'Roja'}
                className={cn(
                    'inline-block h-[13px] w-[9px] rounded-[1px]',
                    event.type === 'yellow_card' ? 'bg-hq-gold' : 'bg-hq-live',
                )}
            />
        );
    }

    if (event.type === 'goal' && event.is_own_goal) {
        return (
            <HqTooltip label="Autogol" className={TEXT_GLYPH_CLASS}>
                PP
            </HqTooltip>
        );
    }

    if (event.type === 'goal') {
        return (
            <span title="Gol" className="text-[13px] leading-none">
                ⚽
            </span>
        );
    }

    if (event.type === 'penalty_missed') {
        return (
            <HqTooltip label="Penalti fallado" className={TEXT_GLYPH_CLASS}>
                P✗
            </HqTooltip>
        );
    }

    return null;
}

function eventNote(event: FixtureEventEntry): string | null {
    if (event.type === 'goal' && event.is_own_goal) {
        return 'en propia puerta';
    }

    if (event.type === 'goal' && event.is_penalty) {
        return 'de penalti';
    }

    if (event.type === 'penalty_missed') {
        return 'penalti fallado';
    }

    return null;
}

function EventSubject({ event }: { event: FixtureEventEntry }) {
    const note = eventNote(event);

    return (
        <>
            {event.player ? (
                <Link
                    href={playersShow(event.player.id).url}
                    className="text-hq-paper hover:text-hq-lime"
                >
                    {event.player.nickname}
                </Link>
            ) : (
                <span className="text-hq-moss">
                    {event.unresolved_name ?? 'Sin jugador vinculado'}
                </span>
            )}
            {note && (
                <small className="mt-0.5 block font-mono text-[10.5px] leading-tight text-hq-moss-dim">
                    {note}
                </small>
            )}
        </>
    );
}

/** A VAR review renders as a full-width azure band, not on either side of the minute. */
function VarDecisionRow({
    event,
    team,
}: {
    event: FixtureEventEntry;
    team: Team;
}) {
    const subject = event.player?.nickname ?? event.unresolved_name;

    return (
        <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 border-b border-l-[3px] border-hq-border border-l-hq-azure bg-hq-azure/8 px-3 py-[9px] text-[13px]">
            <span className="border border-hq-azure px-[5px] py-[3px] font-mono text-[10px] leading-none font-bold tracking-[0.14em] text-hq-azure">
                VAR
            </span>
            <span className="font-mono text-xs text-hq-moss-dim">
                {event.minute}'
            </span>
            <span className="flex items-center gap-[7px] text-hq-paper">
                <TeamCrest team={team} />
                {event.label}
                {subject && (
                    <>
                        <span className="text-hq-moss-dim">·</span>
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

/**
 * The match log (mock `.tl`): a crest header per side over a minute spine,
 * each goal/card/missed penalty on its team's side (an own goal on the side
 * it benefits), goals washed lime, a DESCANSO rule between halves and VAR
 * reviews as azure bands.
 */
export function HqFixtureTimeline({
    events,
    localTeam,
    guestTeam,
}: HqFixtureTimelineProps) {
    if (events.length === 0) {
        return (
            <p className="m-3.5 border border-dashed border-hq-border-bright px-4 py-6 text-center font-mono text-xs text-hq-moss-dim sm:m-4">
                Sin eventos todavía
            </p>
        );
    }

    const firstSecondHalfIndex = events.findIndex(
        (event) => event.minute > HALF_TIME_MINUTE,
    );

    return (
        <div>
            <div className="grid grid-cols-[1fr_72px_1fr] border-b border-hq-border-strong font-mono text-[10.5px] leading-none font-bold tracking-[0.08em] text-hq-moss uppercase sm:grid-cols-[1fr_84px_1fr]">
                <span className="flex items-center justify-end gap-1.5 px-3 py-[9px]">
                    {localTeam.short_name}
                    <TeamCrest team={localTeam} />
                </span>
                <span className="flex items-center justify-center py-[9px]">
                    Min
                </span>
                <span className="flex items-center gap-1.5 px-3 py-[9px]">
                    <TeamCrest team={guestTeam} />
                    {guestTeam.short_name}
                </span>
            </div>
            {events.map((event, index) => {
                const halfTimeRule = index === firstSecondHalfIndex &&
                    index > 0 && (
                        <div className="border-b border-hq-border bg-hq-well p-1.5 text-center font-mono text-[10px] leading-none font-semibold tracking-[0.2em] text-hq-moss-dim">
                            DESCANSO
                        </div>
                    );

                if (event.type === 'var') {
                    return (
                        <Fragment key={event.id}>
                            {halfTimeRule}
                            <VarDecisionRow
                                event={event}
                                team={
                                    event.team_id === localTeam.id
                                        ? localTeam
                                        : guestTeam
                                }
                            />
                        </Fragment>
                    );
                }

                // An own goal's team_id is the scorer's own team (see
                // SyncLiveSeasonMatchData), but the goal counts for the
                // other side — render it on the side it benefits.
                const isLocal =
                    event.type === 'goal' && event.is_own_goal
                        ? event.team_id !== localTeam.id
                        : event.team_id === localTeam.id;
                const isGoal = event.type === 'goal';

                return (
                    <Fragment key={event.id}>
                        {halfTimeRule}
                        <div
                            className={cn(
                                'grid min-h-[38px] grid-cols-[1fr_72px_1fr] items-stretch border-b border-hq-border text-[13px] leading-tight sm:grid-cols-[1fr_84px_1fr]',
                                isGoal && 'bg-hq-lime/5',
                            )}
                        >
                            <div className="flex flex-col justify-center px-3 py-2 text-right">
                                {isLocal && <EventSubject event={event} />}
                            </div>
                            <div className="grid grid-cols-[20px_1fr_20px] items-center justify-items-center border-x border-hq-border bg-hq-panel sm:grid-cols-[22px_1fr_22px]">
                                <span>
                                    {isLocal && <EventIcon event={event} />}
                                </span>
                                <span
                                    className={cn(
                                        'font-mono text-xs font-bold',
                                        isGoal
                                            ? 'text-hq-lime'
                                            : 'text-hq-moss',
                                    )}
                                >
                                    {event.minute}'
                                </span>
                                <span>
                                    {!isLocal && <EventIcon event={event} />}
                                </span>
                            </div>
                            <div className="flex flex-col justify-center px-3 py-2">
                                {!isLocal && <EventSubject event={event} />}
                            </div>
                        </div>
                    </Fragment>
                );
            })}
        </div>
    );
}
