import { Link, router } from '@inertiajs/react';
import { Lock, LockOpen, Shield, ShieldCheck, User } from 'lucide-react';
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { ClauseDifference } from '@/components/hq-player-property-card';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { HqTooltip } from '@/components/hq-tooltip';
import { resolveClauseStatus } from '@/lib/clause-status';
import { formatCurrency, formatFullDateTime } from '@/lib/format';
import { POSITION_GROUP_LABELS } from '@/lib/player-labels';
import { useLockCountdown } from '@/lib/use-lock-countdown';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import { show as teamsShow } from '@/routes/teams';
import type { PlayerPosition, ManagerPlayer } from '@/types/models';

const GROUP_ORDER: PlayerPosition[] = [
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
    'coach',
];

/**
 * Desktop columns (mock `.rhead`/`.rrow`): photo · player · clause · next
 * fixtures + difficulty · last 3 · value + today · points. Below `lg` the row
 * folds into the phone layout (see RosterRow).
 */
const ROW_GRID =
    'lg:grid-cols-[46px_minmax(130px,1.1fr)_minmax(170px,1.25fr)_96px_104px_minmax(118px,0.9fr)_50px] lg:gap-3';

const CLAUSE_LINE_CLASS =
    'flex items-center gap-[5px] font-mono text-[10.5px] leading-[1.2] font-bold tracking-[0.04em] whitespace-nowrap uppercase';

function ClauseCountdown({
    until,
    countdown,
    className,
}: {
    until: string | null;
    countdown: string;
    className: string;
}) {
    const text = (
        <span className={cn('normal-case', className)}>· {countdown}</span>
    );

    return until !== null ? (
        <HqTooltip label={formatFullDateTime(until)} focusable>
            {text}
        </HqTooltip>
    ) : (
        text
    );
}

/** Clause state (mock `.cl`): shielded, locked with countdown, or open — then the clause and its difference vs. value. */
function RosterClauseStatus({
    entry,
    now,
}: {
    entry: ManagerPlayer;
    now: number;
}) {
    const status = resolveClauseStatus(
        entry.shielded,
        entry.buyout_clause_locked_until,
        now,
    );
    const shieldCountdown = useLockCountdown(entry.shielded_until, now);
    const lockCountdown = useLockCountdown(
        entry.buyout_clause_locked_until,
        now,
    );
    const clauseLine = (valueColorClass?: string) => (
        <ClauseDifference
            clause={entry.buyout_clause}
            marketValue={entry.player.market_value}
            valueColorClass={valueColorClass}
            className="mt-1 text-[11.5px] leading-[1.2]"
        />
    );

    if (status === 'shielded') {
        return (
            <div className="min-w-0">
                <div className={cn(CLAUSE_LINE_CLASS, 'text-hq-def')}>
                    <ShieldCheck
                        aria-hidden="true"
                        className="h-[13px] w-[13px]"
                    />
                    Blindado
                    <ClauseCountdown
                        until={entry.shielded_until}
                        countdown={shieldCountdown}
                        className="text-hq-paper"
                    />
                </div>
                {clauseLine()}
            </div>
        );
    }

    if (status === 'locked') {
        return (
            <div className="min-w-0">
                <div className={cn(CLAUSE_LINE_CLASS, 'text-hq-moss')}>
                    <Lock aria-hidden="true" className="h-[13px] w-[13px]" />
                    Bloqueado
                    <ClauseCountdown
                        until={entry.buyout_clause_locked_until}
                        countdown={lockCountdown}
                        className="text-hq-gold"
                    />
                </div>
                {clauseLine()}
            </div>
        );
    }

    return (
        <div className="min-w-0">
            <div className={cn(CLAUSE_LINE_CLASS, 'text-hq-lime')}>
                <LockOpen
                    aria-hidden="true"
                    className="h-[13px] w-[13px] rotate-12"
                />
                Cláusula abierta
            </div>
            {clauseLine('text-hq-lime')}
        </div>
    );
}

/** Phone-only caption above a folded cell ("Próximos", "Últimas 3"). */
function MobileCaption({ children }: { children: ReactNode }) {
    return <span className="hq-label lg:hidden">{children}</span>;
}

/**
 * One roster player (mock `.rrow`). The whole row opens the player ficha
 * (the name is the real link, for keyboard and middle-click); the club is
 * its own link. On phones: photo · identity · points, then the clause on a
 * dashed rule, next fixtures | last 3, and value + today on a last rule.
 */
function RosterRow({ entry, now }: { entry: ManagerPlayer; now: number }) {
    const playerUrl = playersShow(entry.player.id).url;

    return (
        <div
            onClick={() => router.visit(playerUrl)}
            className={cn(
                'grid cursor-pointer grid-cols-[40px_minmax(0,1fr)_auto] items-center gap-x-2.5 gap-y-2 border-b border-hq-border px-3.5 py-3 transition-colors hover:bg-hq-panel lg:px-4 lg:py-2.5',
                ROW_GRID,
            )}
        >
            <EntityImage
                src={entry.player.image}
                alt=""
                fallback={User}
                shape="square"
                className="h-10 w-10 rounded-none border border-hq-border-strong bg-hq-panel-alt object-cover object-top lg:h-[46px] lg:w-[46px]"
            />

            <div className="min-w-0">
                <Link
                    href={playerUrl}
                    onClick={(event) => event.stopPropagation()}
                    className="block truncate text-sm leading-[1.15] font-extrabold text-hq-paper hover:underline"
                >
                    {entry.player.nickname}
                </Link>
                <div className="mt-1 flex flex-wrap items-center gap-1.5 font-mono text-[11px] leading-none text-hq-moss-dim">
                    <Link
                        href={teamsShow(entry.player.team.id).url}
                        onClick={(event) => event.stopPropagation()}
                        className="inline-flex items-center gap-[5px] hover:text-hq-paper"
                    >
                        <EntityImage
                            src={entry.player.team.logo}
                            alt=""
                            fallback={Shield}
                            shape="square"
                            className="h-3.5 w-3.5 rounded-none bg-transparent"
                        />
                        {entry.player.team.short_name}
                    </Link>
                    <HqStatusBadge status={entry.player.status} />
                </div>
            </div>

            <div className="col-span-full border-t border-dashed border-hq-border pt-2 lg:col-span-1 lg:border-0 lg:pt-0">
                <RosterClauseStatus entry={entry} now={now} />
            </div>

            <div className="col-span-2 flex flex-col gap-[5px] lg:col-span-1">
                <MobileCaption>Próximos</MobileCaption>
                <HqNextFixtures
                    fixtures={entry.player.next_fixtures}
                    size="sm"
                />
            </div>

            <div className="flex flex-col items-end gap-[5px] lg:items-start">
                <MobileCaption>Últimas 3</MobileCaption>
                <HqRecentScores
                    scores={entry.player.recent_scores}
                    finished={entry.player.recent_scores_finished}
                    used={entry.player.recent_scores_used}
                    size="xs"
                    focusable
                    className="gap-[3px] pb-2"
                />
            </div>

            <div className="col-span-full flex items-center justify-between gap-2 border-t border-dashed border-hq-border pt-2 lg:col-span-1 lg:flex-col lg:items-end lg:justify-center lg:gap-1 lg:border-0 lg:pt-0">
                <span className="font-mono text-[13px] font-bold text-hq-paper tabular-nums">
                    {formatCurrency(entry.player.market_value)}
                </span>
                <HqMarketValueDifference
                    difference={entry.player.market_value_difference}
                    trend={entry.player.market_trend}
                    className="text-xs"
                />
            </div>

            <div className="col-start-3 row-start-1 flex flex-col items-center gap-0.5 lg:col-start-auto lg:row-start-auto lg:justify-self-end">
                <HqLed tone="lime" className="text-[26px]">
                    {entry.player.points}
                </HqLed>
                <span className="font-mono text-[10px] font-semibold tracking-[0.07em] text-hq-moss-dim uppercase">
                    pts
                </span>
            </div>
        </div>
    );
}

interface RosterListProps {
    roster: ManagerPlayer[];
}

export function RosterList({ roster }: RosterListProps) {
    const now = useNow();

    if (roster.length === 0) {
        return (
            <p className="p-4 text-sm text-hq-moss">
                Este manager no tiene jugadores en plantilla.
            </p>
        );
    }

    const groups = GROUP_ORDER.map((position) => ({
        position,
        entries: roster.filter((entry) => entry.player.position === position),
    })).filter((group) => group.entries.length > 0);

    return (
        <div>
            <div
                aria-hidden="true"
                className={cn(
                    'hidden border-b border-hq-border-strong px-4 py-[9px] font-mono text-[10.5px] leading-[1.2] tracking-[0.07em] text-hq-moss-dim uppercase lg:grid',
                    ROW_GRID,
                )}
            >
                <span />
                <span>Jugador</span>
                <span>Cláusula</span>
                <span>Próximos · dificultad</span>
                <span>Últimas 3</span>
                <span className="justify-self-end">Valor · hoy</span>
                <span className="justify-self-end">Pts</span>
            </div>
            {groups.map((group) => (
                <section
                    key={group.position}
                    aria-label={POSITION_GROUP_LABELS[group.position]}
                >
                    <div className="flex items-center gap-2 border-b border-hq-border-strong px-3.5 pt-3.5 pb-2 font-mono text-[10.5px] leading-none font-bold tracking-[0.1em] text-hq-moss uppercase sm:px-4">
                        <HqPositionTag position={group.position} />
                        <span>{POSITION_GROUP_LABELS[group.position]}</span>
                        <span className="text-hq-moss-dim">
                            {group.entries.length}
                        </span>
                    </div>
                    {group.entries.map((entry) => (
                        <RosterRow key={entry.id} entry={entry} now={now} />
                    ))}
                </section>
            ))}
        </div>
    );
}
