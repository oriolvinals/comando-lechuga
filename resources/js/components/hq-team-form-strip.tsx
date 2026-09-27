import { Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqTooltip } from '@/components/hq-tooltip';
import { formatMatchDateShort } from '@/lib/format';
import {
    RESULT_BADGE_CLASSES,
    RESULT_BORDER_CLASSES,
    RESULT_LABEL,
} from '@/lib/team-fixture-result';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type { StandingsRow, Team } from '@/types/models';

/** The two teams a hovered match belongs to — both their rows get highlighted. */
export type HoveredMatch = [number, number] | null;

/**
 * One result square (mock `.res`): 22px from `lg`, a 32px square with a 44px
 * tall hit area on phones. Literal class strings so Tailwind can see them.
 */
const SQUARE_CLASSES =
    'relative flex size-8 shrink-0 items-center justify-center font-mono text-xs leading-none font-bold transition-[filter] after:absolute after:-inset-y-1.5 after:inset-x-0 hover:brightness-125 lg:size-[22px] lg:text-[11px] lg:after:hidden';

/** "5-0 (BAR - ELC)", own team first, plus the match date on its own line. */
export function HqTeamMatchTooltip({
    prefix,
    prefixTone = 'live',
    own,
    opponent,
    score,
    date,
}: {
    prefix?: string;
    prefixTone?: 'live' | 'moss';
    own: Team;
    opponent: Team;
    score?: string;
    date: string;
}) {
    return (
        <div className="text-center">
            {prefix && (
                <div
                    className={cn(
                        'font-bold',
                        prefixTone === 'live' ? 'text-hq-live' : 'text-hq-moss',
                    )}
                >
                    {prefix}
                </div>
            )}
            <div>
                {score && <span className="font-bold">{score}</span>}{' '}
                <span className="text-hq-moss">
                    ({own.short_name} - {opponent.short_name})
                </span>
            </div>
            <div className="mt-0.5 text-[10.5px] text-hq-moss-dim">
                {formatMatchDateShort(date)}
            </div>
        </div>
    );
}

interface MatchSquareProps {
    fixtureId: number;
    team: Team;
    opponent: Team;
    tooltip: ReactNode;
    borderClassName?: string;
    className?: string;
    onHover?: (match: HoveredMatch) => void;
    children: ReactNode;
}

function MatchSquare({
    fixtureId,
    team,
    opponent,
    tooltip,
    borderClassName,
    className,
    onHover,
    children,
}: MatchSquareProps) {
    return (
        <HqTooltip label={tooltip} borderClassName={borderClassName}>
            <Link
                href={fixturesShow(fixtureId).url}
                onClick={(event) => event.stopPropagation()}
                onMouseEnter={() => onHover?.([team.id, opponent.id])}
                onMouseLeave={() => onHover?.(null)}
                onFocus={() => onHover?.([team.id, opponent.id])}
                onBlur={() => onHover?.(null)}
                className={cn(SQUARE_CLASSES, className)}
            >
                {children}
            </Link>
        </HqTooltip>
    );
}

/**
 * The live score chip next to a team's name (mock: `.res` widened to fit the
 * score), linking to the match — tooltip "EN DIRECTO · 1-0 (ALA - VAL)".
 */
export function HqTeamLiveScore({
    row,
    onHover,
    className,
}: {
    row: StandingsRow;
    onHover?: (match: HoveredMatch) => void;
    className?: string;
}) {
    const live = row.live;

    if (live === null) {
        return null;
    }

    return (
        <MatchSquare
            fixtureId={live.fixture_id}
            team={row.team}
            opponent={live.opponent}
            onHover={onHover}
            borderClassName={RESULT_BORDER_CLASSES[live.result]}
            tooltip={
                <HqTeamMatchTooltip
                    prefix="EN DIRECTO"
                    own={row.team}
                    opponent={live.opponent}
                    score={live.score}
                    date={live.date}
                />
            }
            className={cn(
                'w-auto px-1.5 lg:w-auto',
                RESULT_BADGE_CLASSES[live.result],
                className,
            )}
        >
            {live.score}
        </MatchSquare>
    );
}

/**
 * The standings "Forma · próximo" strip (mock `.forma`): this team's live
 * match (red frame + pulsing dot) or else its next opponent's crest, then up
 * to 4 finished results as V/E/D squares, newest first. Every square links
 * to its match, carries a rich tooltip and reports the pair of teams it
 * involves through `onHover`, so the table can highlight both rows.
 */
export function HqTeamFormStrip({
    row,
    onHover,
    className,
}: {
    row: StandingsRow;
    onHover?: (match: HoveredMatch) => void;
    className?: string;
}) {
    if (
        row.live === null &&
        row.next === null &&
        row.recent_form.length === 0
    ) {
        return <span className="font-mono text-hq-moss-dim">–</span>;
    }

    return (
        <span className={cn('inline-flex items-center gap-1', className)}>
            {row.live && (
                <MatchSquare
                    fixtureId={row.live.fixture_id}
                    team={row.team}
                    opponent={row.live.opponent}
                    onHover={onHover}
                    borderClassName={RESULT_BORDER_CLASSES[row.live.result]}
                    tooltip={
                        <HqTeamMatchTooltip
                            prefix="EN DIRECTO"
                            own={row.team}
                            opponent={row.live.opponent}
                            score={row.live.score}
                            date={row.live.date}
                        />
                    }
                    className={cn(
                        'border border-hq-live',
                        RESULT_BADGE_CLASSES[row.live.result],
                    )}
                >
                    {RESULT_LABEL[row.live.result]}
                    <span className="absolute -top-[3px] -right-[3px] size-1.5 animate-hq-pulse rounded-full bg-hq-live shadow-[0_0_0_2px_var(--color-hq-ink)]" />
                </MatchSquare>
            )}
            {row.live === null && row.next && (
                <MatchSquare
                    fixtureId={row.next.fixture_id}
                    team={row.team}
                    opponent={row.next.opponent}
                    onHover={onHover}
                    tooltip={
                        <HqTeamMatchTooltip
                            prefix="PRÓXIMO"
                            prefixTone="moss"
                            own={row.team}
                            opponent={row.next.opponent}
                            date={row.next.date}
                        />
                    }
                    className="border border-hq-border-strong bg-hq-panel-alt p-[3px] lg:p-0.5"
                >
                    <EntityImage
                        src={row.next.opponent.logo}
                        alt={row.next.opponent.main_name}
                        fallback={Shield}
                        shape="square"
                        className="h-full w-full rounded-none bg-transparent object-contain"
                    />
                </MatchSquare>
            )}
            {row.recent_form.map((entry) => (
                <MatchSquare
                    key={entry.fixture_id}
                    fixtureId={entry.fixture_id}
                    team={row.team}
                    opponent={entry.opponent}
                    onHover={onHover}
                    borderClassName={RESULT_BORDER_CLASSES[entry.result]}
                    tooltip={
                        <HqTeamMatchTooltip
                            own={row.team}
                            opponent={entry.opponent}
                            score={entry.score}
                            date={entry.date}
                        />
                    }
                    className={RESULT_BADGE_CLASSES[entry.result]}
                >
                    {RESULT_LABEL[entry.result]}
                </MatchSquare>
            ))}
        </span>
    );
}
