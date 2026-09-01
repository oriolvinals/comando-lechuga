import { Link } from '@inertiajs/react';
import { ArrowUpRight, Shield, User, X } from 'lucide-react';
import { useEffect } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqFixtureCardContent } from '@/components/hq-fixture-card';
import { HqJornadaStatsGrid } from '@/components/hq-jornada-stats-grid';
import { HqPositionTag } from '@/components/hq-position-tag';
import { MatchEventIcons } from '@/components/match-event-icons';
import { matchPointsBadgeClass } from '@/lib/points';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import { show as playersShow } from '@/routes/players';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { Fixture, JornadaStats, Player, SeasonManager, Team } from '@/types/models';

export interface HqPlayerStatsEntry {
    player: Player;
    team: Team;
    points: number;
    daznPoints?: number;
    stats: JornadaStats;
    lineupManager?: SeasonManager | null;
    subMinute?: { minute: number; direction: 'in' | 'out' } | null;
    /** The match this jornada's stats came from — shown below the stats grid with its result. Omit (or null) when the modal is already opened from that match's own ficha, where repeating it would be redundant. */
    fixture?: Fixture | null;
}

interface HqPlayerStatsModalProps {
    entry: HqPlayerStatsEntry | null;
    onClose: () => void;
}

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

    const {
        player,
        team,
        points,
        daznPoints,
        stats,
        lineupManager,
        subMinute,
        fixture,
    } = entry;

    return (
        <div
            className="fixed inset-0 z-50 flex cursor-pointer items-center justify-center overflow-y-auto bg-black/60 p-4"
            onClick={onClose}
        >
            <div
                className="max-h-[85vh] w-full max-w-sm cursor-default overflow-y-auto border border-hq-border-strong bg-hq-ink"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="sticky top-0 z-10 flex justify-end bg-hq-ink p-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="cursor-pointer text-hq-moss-dim hover:text-hq-paper"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div className="flex flex-col items-center gap-1.5 px-5 pt-1 text-center">
                    <div className="relative">
                        <EntityImage
                            src={player.image}
                            alt={player.nickname}
                            fallback={User}
                            className="h-16 w-16 border-2 border-hq-border-strong bg-hq-border"
                        />
                        {subMinute && (
                            <span
                                className={cn(
                                    'absolute -right-2 -bottom-1 border bg-hq-ink px-1.5 py-0.5 font-mono text-[10px] font-bold whitespace-nowrap',
                                    subMinute.direction === 'out'
                                        ? 'border-hq-live text-hq-live'
                                        : 'border-hq-lime text-hq-lime',
                                )}
                            >
                                ↳{subMinute.minute}'
                            </span>
                        )}
                    </div>
                    <h2 className="font-display text-lg text-hq-paper uppercase">
                        {player.nickname}
                    </h2>
                    <div className="flex items-center gap-1.5 font-mono text-[11px] text-hq-moss">
                        <EntityImage
                            src={team.logo}
                            alt={team.main_name}
                            fallback={Shield}
                            shape="square"
                            className="h-4 w-4 bg-transparent"
                        />
                        {team.main_name}
                    </div>
                    {lineupManager && (
                        <Link
                            href={seasonManagersShow(lineupManager.id).url}
                            className="flex items-center gap-1.5 font-mono text-[11px] text-hq-moss hover:text-hq-paper"
                        >
                            <span
                                className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                                style={{
                                    backgroundColor: managerColor(
                                        lineupManager.primary_color,
                                    ),
                                }}
                            />
                            {lineupManager.name}
                        </Link>
                    )}
                    <div className="mt-1 flex items-center gap-2">
                        <HqPositionTag position={player.position} />
                        <span
                            className={cn(
                                'rounded-sm px-3 py-0.5 font-display text-xl',
                                matchPointsBadgeClass(points),
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
                    <div className="mt-1">
                        <MatchEventIcons
                            stats={stats}
                            position={player.position}
                        />
                    </div>
                </div>

                {fixture && (
                    <Link
                        href={fixturesShow(fixture.id).url}
                        className="block border-t border-b border-hq-border px-4 py-2.5 text-center hover:bg-hq-panel-alt"
                    >
                        <p className="mb-0.5 font-mono text-[10px] tracking-widest text-hq-moss uppercase">
                            Jornada {fixture.week_number}
                        </p>
                        <HqFixtureCardContent fixture={fixture} />
                    </Link>
                )}

                <HqJornadaStatsGrid stats={stats} />

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
