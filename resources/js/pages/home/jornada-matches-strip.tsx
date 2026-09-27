import { Link } from '@inertiajs/react';
import { Shield, User } from 'lucide-react';
import { useState } from 'react';
import type { CSSProperties } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqPositionTag } from '@/components/hq-position-tag';
import { FIXTURE_STATE_LABELS, isLiveFixtureState } from '@/lib/fixture-state';
import { formatMatchDay, formatTime } from '@/lib/format';
import { formatSignedPoints, pointsToneClass } from '@/lib/points';
import { managerColor } from '@/lib/season-manager-colors';
import { useCountdown } from '@/lib/use-countdown';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type {
    JornadaMatch,
    JornadaMatchManager,
    JornadaMatchTeam,
} from '@/types/models';

function Crest({ team }: { team: JornadaMatchTeam }) {
    return (
        <EntityImage
            src={team.logo}
            alt=""
            fallback={Shield}
            shape="square"
            className="h-[18px] w-[18px] flex-none rounded-none"
        />
    );
}

function TeamName({ team }: { team: JornadaMatchTeam }) {
    return (
        <span className="font-mono text-[13px] font-bold tracking-[0.04em] text-hq-paper group-hover/match:text-hq-lime">
            {team.short_name}
        </span>
    );
}

const CLOCK_CLASSES =
    'ml-auto font-mono text-[10.5px] font-bold tracking-[0.1em] whitespace-nowrap';

function ScheduledClock({ date }: { date: string }) {
    const countdown = useCountdown(date);

    return (
        <span
            className={cn(
                CLOCK_CLASSES,
                'text-hq-moss max-md:ml-0 max-md:basis-full',
            )}
        >
            {formatMatchDay(date).toUpperCase()} · {countdown}
        </span>
    );
}

function MatchClock({ match }: { match: JornadaMatch }) {
    if (match.state === 'scheduled') {
        return <ScheduledClock date={match.date} />;
    }

    if (match.state === 'finished') {
        return (
            <span className={cn(CLOCK_CLASSES, 'text-hq-moss-dim')}>FINAL</span>
        );
    }

    if (match.state === 'half_time') {
        return (
            <span className={cn(CLOCK_CLASSES, 'text-hq-gold')}>
                {FIXTURE_STATE_LABELS.half_time}
            </span>
        );
    }

    return (
        <span
            className={cn(
                CLOCK_CLASSES,
                'inline-flex items-center gap-1.5',
                isLiveFixtureState(match.state)
                    ? 'text-hq-live'
                    : 'text-hq-moss-dim',
            )}
        >
            {isLiveFixtureState(match.state) && (
                <span className="h-1.5 w-1.5 flex-none animate-hq-pulse rounded-full bg-hq-live" />
            )}
            {match.display_clock ?? FIXTURE_STATE_LABELS[match.state]}
        </span>
    );
}

function MatchHeader({ match }: { match: JornadaMatch }) {
    const managerCount = match.managers?.length ?? 0;

    return (
        <Link
            href={fixturesShow(match.id).url}
            className={cn(
                'group/match flex flex-wrap items-center gap-x-2 gap-y-1',
                match.managers && 'mb-1.5',
            )}
        >
            <Crest team={match.local_team} />
            <TeamName team={match.local_team} />
            {match.state === 'scheduled' ? (
                <HqLed className="mx-0.5 text-[20px]">
                    {formatTime(match.date)}
                </HqLed>
            ) : (
                <HqLed className="mx-0.5 text-[24px]">
                    {match.local_score ?? 0}–{match.guest_score ?? 0}
                </HqLed>
            )}
            <TeamName team={match.guest_team} />
            <Crest team={match.guest_team} />
            {match.managers && managerCount > 0 && (
                <span className="font-mono text-[10.5px] text-hq-moss-dim">
                    {managerCount} {managerCount === 1 ? 'mánager' : 'mánagers'}
                </span>
            )}
            <MatchClock match={match} />
        </Link>
    );
}

function ManagerRow({ manager }: { manager: JornadaMatchManager }) {
    const [expanded, setExpanded] = useState(false);
    const upcoming = manager.points === null;

    return (
        <div
            className="border-t border-hq-border"
            style={
                {
                    '--mc': managerColor(manager.primary_color),
                } as CSSProperties
            }
        >
            <button
                type="button"
                aria-expanded={expanded}
                onClick={() => setExpanded((open) => !open)}
                className={cn(
                    'group/row grid h-[30px] w-full cursor-pointer items-center gap-2.5 text-left font-mono text-xs',
                    upcoming
                        ? 'grid-cols-[4px_minmax(0,1fr)_auto]'
                        : 'grid-cols-[4px_minmax(0,1fr)_auto_46px]',
                )}
            >
                <i
                    aria-hidden="true"
                    className="h-[18px] w-1 bg-(--mc) shadow-[0_0_0_1px_rgba(255,255,255,0.1)]"
                />
                <b className="truncate font-bold text-hq-paper group-hover/row:text-hq-lime">
                    {manager.name}
                </b>
                <span className="border border-hq-border-strong px-1.5 py-0.5 text-[10.5px] whitespace-nowrap text-hq-moss group-hover/row:border-hq-border-bright">
                    {manager.players.length} jug.{' '}
                    <span aria-hidden="true" className="text-hq-moss-dim">
                        {expanded ? '▴' : '▾'}
                    </span>
                </span>
                {manager.points !== null && (
                    <HqLed
                        className={cn(
                            'text-right text-[19px]',
                            pointsToneClass(manager.points),
                        )}
                    >
                        {formatSignedPoints(manager.points)}
                    </HqLed>
                )}
            </button>
            {expanded && (
                <ul className="mb-[7px] ml-3.5 grid gap-[3px] border-l border-(--mc) pl-2.5">
                    {manager.players.map((player) => (
                        <li
                            key={player.id}
                            className="grid grid-cols-[22px_auto_minmax(0,1fr)_auto] items-center gap-2 font-mono text-[11.5px] text-hq-paper"
                        >
                            <EntityImage
                                src={player.image}
                                alt=""
                                fallback={User}
                                shape="square"
                                className="h-[22px] w-[22px] rounded-none bg-hq-panel object-cover object-top text-hq-moss-dim"
                            />
                            <HqPositionTag position={player.position} />
                            <span className="truncate">{player.nickname}</span>
                            {player.points === null ? (
                                <span />
                            ) : (
                                <HqLed
                                    className={cn(
                                        'min-w-9 text-right text-[15px]',
                                        pointsToneClass(player.points),
                                    )}
                                >
                                    {formatSignedPoints(player.points)}
                                </HqLed>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * The "Ahora" block's matches as mini scoreboards (full-bleed inside their
 * tile) and, under each one, a line per manager whose jornada lineup plays
 * in it — expandable to those players and their points in the match.
 */
export function JornadaMatchesStrip({ matches }: { matches: JornadaMatch[] }) {
    return (
        <div
            style={{ '--cols': matches.length } as CSSProperties}
            className="-mx-3 -mb-[13px] grid grid-cols-1 gap-px border-t border-hq-border bg-hq-border md:-mx-4 md:-mb-[15px] min-[73.75rem]:grid-cols-[repeat(var(--cols),minmax(0,1fr))]"
        >
            {matches.map((match) => (
                <div
                    key={match.id}
                    className="min-w-0 bg-hq-well px-3 pt-2.5 pb-3 md:px-4 md:pb-[13px]"
                >
                    <MatchHeader match={match} />
                    {match.managers?.length === 0 && (
                        <p className="m-0 font-mono text-[11px] text-hq-moss-dim">
                            Nadie de la liga alinea jugadores aquí
                        </p>
                    )}
                    {match.managers?.map((manager) => (
                        <ManagerRow key={manager.id} manager={manager} />
                    ))}
                </div>
            ))}
        </div>
    );
}
