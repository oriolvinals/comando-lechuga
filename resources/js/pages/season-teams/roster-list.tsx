import { Lock, Shield, ShieldCheck, User } from 'lucide-react';
import { ClauseDifference } from '@/components/hq-player-property-card';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { EntityImage } from '@/components/entity-image';
import { resolveClauseStatus } from '@/lib/clause-status';
import { useLockCountdown } from '@/lib/use-lock-countdown';
import { useNow } from '@/lib/use-now';
import { POSITION_GROUP_LABELS } from '@/lib/player-labels';
import type { PlayerPosition, SeasonTeamPlayer } from '@/types/models';

const GROUP_ORDER: PlayerPosition[] = [
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
    'coach',
];

function RosterClauseStatus({ entry }: { entry: SeasonTeamPlayer }) {
    const now = useNow();
    const status = resolveClauseStatus(
        entry.shielded,
        entry.buyout_clause_locked_until,
        now,
    );
    const countdown = useLockCountdown(entry.buyout_clause_locked_until);

    if (status === 'shielded') {
        return (
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-1 font-mono text-[10px] font-bold text-hq-def uppercase">
                    <ShieldCheck className="h-[13px] w-[13px]" />
                    Blindado
                    <span className="text-hq-paper normal-case">
                        · {countdown}
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
                    Bloqueada
                    <span className="text-hq-gold normal-case">
                        · {countdown}
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
            />
        </div>
    );
}

function RosterRow({ entry }: { entry: SeasonTeamPlayer }) {
    return (
        <div className="hq-card-cut mb-1.5 flex items-center gap-3 px-3.5 py-2.5">
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
            </div>

            <RosterClauseStatus entry={entry} />

            <div className="flex shrink-0 items-center gap-4">
                <div className="flex flex-col items-center gap-1">
                    <HqRecentScores
                        scores={entry.player.recent_scores}
                        size="sm"
                    />
                    <span className="font-mono text-[8px] font-bold tracking-wide text-hq-moss-dim uppercase">
                        Forma
                    </span>
                </div>
                <div className="h-8 w-px bg-hq-border" />
                <div className="flex flex-col items-center gap-1">
                    <span className="font-display text-lg text-hq-lime">
                        {entry.player.points}
                    </span>
                    <span className="font-mono text-[8px] font-bold tracking-wide text-hq-moss-dim uppercase">
                        Pts
                    </span>
                </div>
            </div>
        </div>
    );
}

interface RosterListProps {
    roster: SeasonTeamPlayer[];
}

export function RosterList({ roster }: RosterListProps) {
    if (roster.length === 0) {
        return (
            <p className="font-mono text-[11px] text-hq-moss-dim">
                Este equipo no tiene jugadores en plantilla.
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
                <div key={group.position}>
                    <div className="mt-5 mb-2 flex items-center gap-2 first:mt-0">
                        <HqPositionTag position={group.position} />
                        <span className="font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                            {POSITION_GROUP_LABELS[group.position]}
                        </span>
                    </div>
                    {group.entries.map((entry) => (
                        <RosterRow key={entry.id} entry={entry} />
                    ))}
                </div>
            ))}
        </div>
    );
}
