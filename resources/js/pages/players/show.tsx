import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { HqPlayerMatchTimeline } from '@/components/hq-player-match-timeline';
import { HqPlayerPropertyCard } from '@/components/hq-player-property-card';
import { HqPlayerValueChart } from '@/components/hq-player-value-chart';
import { HqPositionTag } from '@/components/hq-position-tag';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/format';
import { buildOwnershipTimeline } from '@/lib/ownership-timeline';
import { STATUS_BADGE_CLASS, STATUS_LABELS } from '@/lib/player-labels';
import { matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type {
    Fixture,
    OwnershipActivity,
    Player,
    PlayerFichaMarketListing,
    PlayerFichaScore,
    PlayerMarketPoint,
    PlayerOwnership,
    Season,
} from '@/types/models';

interface PlayerShowProps {
    player: Player;
    season: Season;
    owner: PlayerOwnership | null;
    marketListing: PlayerFichaMarketListing | null;
    marketHistory: PlayerMarketPoint[];
    scores: PlayerFichaScore[];
    ownershipActivity: OwnershipActivity[];
    teamJoinedAt: Record<string, string>;
    teamFixtures: Fixture[];
    [key: string]: unknown;
}

export default function PlayerShow({
    player,
    season,
    owner,
    marketListing,
    marketHistory,
    scores,
    ownershipActivity,
    teamJoinedAt,
    teamFixtures,
}: PlayerShowProps) {
    const ownershipSegments = buildOwnershipTimeline(
        ownershipActivity,
        owner?.season_team ?? null,
        teamJoinedAt,
    );

    return (
        <>
            <Head title={player.nickname} />
            <div className="hq-texture hq-bleed min-h-[calc(100vh-95px)] border-y border-hq-border">
                <div className="mx-auto flex max-w-7xl flex-col gap-6 px-6 py-9 lg:flex-row lg:items-start">
                    <div className="w-full shrink-0 lg:w-64">
                        <div className="hq-card-cut p-4 text-center">
                            <div className="mx-auto mb-3 h-22 w-22 overflow-hidden rounded-full border-2 border-hq-border-strong bg-hq-border">
                                <img
                                    src={player.image}
                                    alt={player.nickname}
                                    className="h-full w-full scale-125 object-cover object-bottom"
                                />
                            </div>
                            <h1 className="mb-1.5 font-display text-xl text-hq-paper uppercase">
                                {player.nickname}
                            </h1>
                            <div className="mb-3 flex items-center justify-center gap-2">
                                <HqPositionTag position={player.position} />
                                <img
                                    src={player.team.logo}
                                    alt={player.team.name}
                                    className="h-[18px] w-[18px] object-contain"
                                />
                            </div>
                            {player.status !== 'ok' && (
                                <span
                                    className={cn(
                                        'mb-3 inline-block border px-2 py-0.5 font-mono text-[10px] font-bold uppercase',
                                        STATUS_BADGE_CLASS[player.status],
                                    )}
                                >
                                    {STATUS_LABELS[player.status]}
                                </span>
                            )}

                            <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                <span className="font-mono text-[11px] text-hq-moss">
                                    PUNTOS
                                </span>
                                <span className="bg-hq-border px-1.5 font-mono font-bold text-hq-paper">
                                    {player.points}
                                </span>
                            </div>
                            <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                <span className="font-mono text-[11px] text-hq-moss">
                                    MEDIA
                                </span>
                                <span
                                    className={cn(
                                        'px-1.5 font-mono font-bold',
                                        matchPointsBadgeClass(
                                            Number(player.average_points),
                                        ),
                                    )}
                                >
                                    {player.average_points}
                                </span>
                            </div>
                            <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                <span className="font-mono text-[11px] text-hq-moss">
                                    VALOR
                                </span>
                                <span className="font-mono font-bold text-hq-paper">
                                    {formatCurrency(player.market_value)}
                                </span>
                            </div>
                            {player.market_value_difference !== 0 && (
                                <div className="flex items-center justify-between border-t border-hq-border-strong pt-1.5">
                                    <span className="font-mono text-[11px] text-hq-lime">
                                        Δ HOY
                                    </span>
                                    <span
                                        className={cn(
                                            'font-mono font-bold',
                                            player.market_value_difference > 0
                                                ? 'text-hq-lime'
                                                : 'text-hq-live',
                                        )}
                                    >
                                        {player.market_value_difference > 0 ? '+' : ''}
                                        {formatCurrency(player.market_value_difference)}
                                    </span>
                                </div>
                            )}
                        </div>

                        <div className="mt-4">
                            <HqPlayerPropertyCard
                                owner={owner}
                                marketListing={marketListing}
                                marketValue={player.market_value}
                            />
                        </div>
                    </div>

                    <div className="min-w-0 flex-1 space-y-8">
                        <HqPlayerValueChart
                            marketHistory={marketHistory}
                            scores={scores}
                            ownershipSegments={ownershipSegments}
                        />
                        <HqPlayerMatchTimeline
                            scores={scores}
                            teamFixtures={teamFixtures}
                            currentWeek={season.current_week}
                            playerPosition={player.position}
                        />
                    </div>
                </div>
            </div>
        </>
    );
}

PlayerShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
