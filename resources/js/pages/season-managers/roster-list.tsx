import { Link } from '@inertiajs/react';
import { Lock, Shield, ShieldCheck, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { ClauseDifference } from '@/components/hq-player-property-card';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { resolveClauseStatus } from '@/lib/clause-status';
import { formatCurrency } from '@/lib/format';
import {
    POSITION_GROUP_LABELS,
    STATUS_BADGE_CLASS,
    STATUS_SHORT_LABELS,
} from '@/lib/player-labels';
import { useLockCountdown } from '@/lib/use-lock-countdown';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { PlayerPosition, SeasonManagerPlayer } from '@/types/models';

const GROUP_ORDER: PlayerPosition[] = [
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
    'coach',
];

function RosterClauseStatus({
    entry,
    now,
}: {
    entry: SeasonManagerPlayer;
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

    if (status === 'shielded') {
        return (
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1 font-mono text-[10px] font-bold text-hq-def uppercase">
                    <ShieldCheck className="h-[13px] w-[13px]" />
                    Blindado
                    <span className="text-hq-paper normal-case">
                        · {shieldCountdown}
                    </span>
                </div>
                <ClauseDifference
                    clause={entry.buyout_clause}
                    marketValue={entry.player.market_value}
                />
            </div>
        );
    }

    if (status === 'locked') {
        return (
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1 font-mono text-[10px] font-bold text-hq-moss uppercase">
                    <Lock className="h-[13px] w-[13px]" />
                    Bloqueado
                    <span className="text-hq-gold normal-case">
                        · {lockCountdown}
                    </span>
                </div>
                <ClauseDifference
                    clause={entry.buyout_clause}
                    marketValue={entry.player.market_value}
                />
            </div>
        );
    }

    return (
        <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1 font-mono text-[10px] font-bold text-hq-lime uppercase">
                <Lock className="h-[13px] w-[13px] rotate-45" />
                Cláusula abierta
            </div>
            <ClauseDifference
                clause={entry.buyout_clause}
                marketValue={entry.player.market_value}
                valueColorClass="text-hq-lime"
            />
        </div>
    );
}

function MarketValueDiff({ entry }: { entry: SeasonManagerPlayer }) {
    if (entry.player.market_value_difference === 0) {
        return null;
    }

    return (
        <span
            className={cn(
                'font-mono text-xs font-bold whitespace-nowrap',
                entry.player.market_value_difference > 0
                    ? 'text-hq-lime'
                    : 'text-hq-live',
            )}
        >
            {entry.player.market_value_difference > 0 ? '▲' : '▼'}{' '}
            {formatCurrency(Math.abs(entry.player.market_value_difference))}
        </span>
    );
}

function RosterRow({ entry, now }: { entry: SeasonManagerPlayer; now: number }) {
    return (
        <Link href={playersShow(entry.player.id).url} className="block">
            {/* Desktop / tablet row */}
            <div className="hq-card-cut mb-1.5 hidden items-center gap-3 px-3.5 py-2.5 transition-[filter] hover:brightness-125 md:flex">
                <EntityImage
                    src={entry.player.image}
                    alt={entry.player.nickname}
                    fallback={User}
                    className="h-10 w-10 shrink-0 bg-hq-border"
                />
                <div className="w-40 min-w-0 shrink-0">
                    <p className="truncate text-sm font-extrabold text-hq-paper">
                        {entry.player.nickname}
                    </p>
                    <div className="mt-0.5 flex items-center gap-1.5">
                        <EntityImage
                            src={entry.player.team.logo}
                            alt={entry.player.team.name}
                            fallback={Shield}
                            shape="square"
                            className="h-3.5 w-3.5"
                        />
                        <span className="truncate font-mono text-[10px] text-hq-moss-dim">
                            {entry.player.team.short_name}
                        </span>
                    </div>
                    {entry.player.status !== 'ok' && (
                        <span
                            className={cn(
                                'mt-1 inline-block border px-1.5 py-0.5 font-mono text-[9px] font-bold uppercase',
                                STATUS_BADGE_CLASS[entry.player.status],
                            )}
                        >
                            {STATUS_SHORT_LABELS[entry.player.status]}
                        </span>
                    )}
                </div>

                <RosterClauseStatus entry={entry} now={now} />

                <div className="flex shrink-0 items-center gap-4">
                    <HqRecentScores
                        scores={entry.player.recent_scores}
                        finished={entry.player.recent_scores_finished}
                        used={entry.player.recent_scores_used}
                    />
                    <div className="h-8 w-px bg-hq-border" />
                    <div className="flex w-[76px] shrink-0 justify-center">
                        <MarketValueDiff entry={entry} />
                    </div>
                    <div className="h-8 w-px bg-hq-border" />
                    <div className="flex w-10 shrink-0 flex-col items-center gap-1">
                        <span className="font-display text-2xl text-hq-lime">
                            {entry.player.points}
                        </span>
                        <span className="font-mono text-[8px] font-bold tracking-wide text-hq-moss-dim uppercase">
                            Pts
                        </span>
                    </div>
                </div>
            </div>

            {/* Mobile row */}
            <div className="hq-card-cut mb-1.5 px-3.5 py-2.5 transition-[filter] hover:brightness-125 md:hidden">
                <div className="flex items-center gap-2.5">
                    <EntityImage
                        src={entry.player.image}
                        alt={entry.player.nickname}
                        fallback={User}
                        className="h-9 w-9 shrink-0 bg-hq-border"
                    />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-extrabold text-hq-paper">
                            {entry.player.nickname}
                        </p>
                        <div className="mt-0.5 flex items-center gap-1.5">
                            <EntityImage
                                src={entry.player.team.logo}
                                alt={entry.player.team.name}
                                fallback={Shield}
                                shape="square"
                                className="h-[10px] w-[10px]"
                            />
                            <span className="truncate font-mono text-[9px] text-hq-moss-dim">
                                {entry.player.team.short_name}
                            </span>
                        </div>
                        {entry.player.status !== 'ok' && (
                            <span
                                className={cn(
                                    'mt-1 inline-block border px-1.5 py-0.5 font-mono text-[9px] font-bold uppercase',
                                    STATUS_BADGE_CLASS[entry.player.status],
                                )}
                            >
                                {STATUS_SHORT_LABELS[entry.player.status]}
                            </span>
                        )}
                    </div>
                    <span className="shrink-0 font-display text-xl text-hq-lime">
                        {entry.player.points}
                    </span>
                </div>

                <div className="mt-2 border-t border-hq-ink pt-2">
                    <RosterClauseStatus entry={entry} now={now} />
                </div>

                <div className="mt-2 flex items-center justify-between border-t border-hq-ink pt-2">
                    <HqRecentScores
                        scores={entry.player.recent_scores}
                        finished={entry.player.recent_scores_finished}
                        used={entry.player.recent_scores_used}
                        size="sm"
                    />
                    <MarketValueDiff entry={entry} />
                </div>
            </div>
        </Link>
    );
}

interface RosterListProps {
    roster: SeasonManagerPlayer[];
}

export function RosterList({ roster }: RosterListProps) {
    const now = useNow();

    if (roster.length === 0) {
        return (
            <p className="font-mono text-[11px] text-hq-moss-dim">
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
            {groups.map((group) => (
                <div key={group.position} className="mt-9 first:mt-0">
                    <div className="mb-2 flex items-center gap-2">
                        <HqPositionTag position={group.position} />
                        <span className="font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                            {POSITION_GROUP_LABELS[group.position]}
                        </span>
                    </div>
                    {group.entries.map((entry) => (
                        <RosterRow key={entry.id} entry={entry} now={now} />
                    ))}
                </div>
            ))}
        </div>
    );
}
