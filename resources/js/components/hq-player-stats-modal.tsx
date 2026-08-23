import { Link } from '@inertiajs/react';
import { ArrowUpRight, Shield, User, X } from 'lucide-react';
import { useEffect } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { JORNADA_STAT_LABELS, JORNADA_STAT_ORDER } from '@/lib/player-labels';
import { pointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { JornadaStats, Player, Team } from '@/types/models';

export interface HqPlayerStatsEntry {
    player: Player;
    team: Team;
    points: number;
    daznPoints?: number;
    stats: JornadaStats;
}

interface HqPlayerStatsModalProps {
    entry: HqPlayerStatsEntry | null;
    onClose: () => void;
}

const BODY_STAT_ORDER = JORNADA_STAT_ORDER.filter(
    (key) => key !== 'marca_points',
);

export function HqPlayerStatsModal({
    entry,
    onClose,
}: HqPlayerStatsModalProps) {
    useEffect(() => {
        if (!entry) {
            return;
        }

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [entry, onClose]);

    if (!entry) {
        return null;
    }

    const { player, team, points, daznPoints, stats } = entry;

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            onClick={onClose}
        >
            <div
                className="w-full max-w-sm border border-hq-border-strong bg-hq-ink"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex justify-end p-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="text-hq-moss-dim hover:text-hq-paper"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div className="flex flex-col items-center gap-1.5 px-5 pt-1 pb-4 text-center">
                    <EntityImage
                        src={player.image}
                        alt={player.nickname}
                        fallback={User}
                        className="h-16 w-16 border-2 border-hq-border-strong bg-hq-border"
                    />
                    <h2 className="font-display text-lg text-hq-paper uppercase">
                        {player.nickname}
                    </h2>
                    <div className="flex items-center gap-1.5 font-mono text-[11px] text-hq-moss">
                        <EntityImage
                            src={team.logo}
                            alt={team.name}
                            fallback={Shield}
                            shape="square"
                            className="h-4 w-4 bg-transparent"
                        />
                        {team.name}
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                        <HqPositionTag position={player.position} />
                        <span
                            className={cn(
                                'rounded-sm px-3 py-0.5 font-display text-xl',
                                pointsBadgeClass(points),
                            )}
                        >
                            {points}
                        </span>
                        {daznPoints !== undefined && (
                            <span className="flex items-center gap-1 font-mono text-[11px] text-hq-moss-dim">
                                <img
                                    src="/images/dazn-logo.png"
                                    alt="DAZN"
                                    className="h-4 w-4"
                                />
                                {daznPoints}
                            </span>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-2">
                    {BODY_STAT_ORDER.map((key, index) => {
                        const [value, delta] = stats[key] ?? [0, 0];
                        const isZero = value === 0;

                        return (
                            <div
                                key={key}
                                className={cn(
                                    'flex flex-col gap-0.5 border-t border-hq-border px-4 py-1.5',
                                    index % 2 === 1 &&
                                        'border-l border-hq-border',
                                )}
                            >
                                <span
                                    className={cn(
                                        'font-mono text-[10px] tracking-wide text-hq-moss uppercase',
                                        isZero && 'opacity-40',
                                    )}
                                >
                                    {JORNADA_STAT_LABELS[key] ?? key}
                                </span>
                                <span
                                    className={cn(
                                        'flex items-center gap-1.5 font-mono',
                                        isZero && 'opacity-40',
                                    )}
                                >
                                    <span className="font-bold text-hq-paper">
                                        {value}
                                    </span>
                                    {delta !== 0 && (
                                        <span
                                            className={cn(
                                                'text-[10px] font-bold',
                                                delta > 0
                                                    ? 'text-hq-lime'
                                                    : 'text-hq-live',
                                            )}
                                        >
                                            {delta > 0 ? '+' : ''}
                                            {delta}
                                        </span>
                                    )}
                                </span>
                            </div>
                        );
                    })}
                </div>

                <Link
                    href={playersShow(player.id).url}
                    className="flex items-center justify-center gap-1.5 border-t border-hq-border py-2.5 font-mono text-[11px] font-bold text-hq-lime hover:bg-hq-panel-alt"
                >
                    VER FICHA COMPLETA
                    <ArrowUpRight className="h-3.5 w-3.5" />
                </Link>
            </div>
        </div>
    );
}
