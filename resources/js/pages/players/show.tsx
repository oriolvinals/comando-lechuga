import { Head, Link } from '@inertiajs/react';
import { Info, Shield, User } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import {
    describeMarketTrend,
    HqMarketValueDifference,
} from '@/components/hq-market-trend-icon';
import { HqMaxBidCard } from '@/components/hq-max-bid-card';
import { HqPlayerMatchTimeline } from '@/components/hq-player-match-timeline';
import { HqPlayerPropertyCard } from '@/components/hq-player-property-card';
import {
    HqPlayerValueChart,
    HqValueChartRangeToggle,
} from '@/components/hq-player-value-chart';
import type { ValueChartRange } from '@/components/hq-player-value-chart';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqSection } from '@/components/hq-section';
import { HqStartOutcomeChip } from '@/components/hq-start-probability';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { HqTooltip } from '@/components/hq-tooltip';
import AppLayout from '@/layouts/app-layout';
import {
    formatAverage,
    formatCurrency,
    formatDecimal,
    formatMatchDateTime,
    formatMillions,
    formatNumber,
} from '@/lib/format';
import { buildOwnershipTimeline } from '@/lib/ownership-timeline';
import { didNotPlayMatch, POSITION_LABELS } from '@/lib/player-labels';
import { daznPointsBadgeClass, matchPointsBadgeClass } from '@/lib/points';
import {
    formatDataAge,
    START_TONE_TEXT_CLASSES,
    startTone,
} from '@/lib/start-probability';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { CapitalGain } from '@/pages/players/capital-gain';
import { NextRivalsList } from '@/pages/players/next-rivals-list';
import { OwnershipHistory } from '@/pages/players/ownership-history';
import { show as teamsShow } from '@/routes/teams';
import type {
    Fixture,
    MaxBidEstimate,
    OwnershipActivity,
    Player,
    PlayerFichaMarketListing,
    PlayerFichaScore,
    PlayerMarketPoint,
    PlayerMissedFixture,
    PlayerNextStart,
    PlayerOwnership,
    PlayerCapitalGain,
    PlayerPointsPerMillion,
    PlayerStatus,
    PlayerValueTrend,
} from '@/types/models';

interface PlayerShowProps {
    player: Player;
    currentWeek: number;
    owner: PlayerOwnership | null;
    marketListing: PlayerFichaMarketListing | null;
    marketHistory: PlayerMarketPoint[];
    scores: PlayerFichaScore[];
    ownershipActivity: OwnershipActivity[];
    teamJoinedAt: Record<string, string>;
    teamFixtures: Fixture[];
    missedFixtures: PlayerMissedFixture[];
    valueTrend: PlayerValueTrend | null;
    pointsPerMillion: PlayerPointsPerMillion | null;
    capitalGain: PlayerCapitalGain | null;
    maxBid: MaxBidEstimate | null;
    [key: string]: unknown;
}

/** Per-cell rules of the 2×2 (phones) / 1×4 (md+) KPI strip — 4 cells, no Titularidad data for this player. */
const KPI_CELL_BORDERS = [
    'border-r border-b md:border-b-0',
    'border-b md:border-r md:border-b-0',
    'border-r',
    '',
];

/** Per-cell rules with a 5th "Titularidad" cell — 2×2+1 (phones, full-width last row) / 1×5 (md+). */
const KPI_CELL_BORDERS_WITH_START = [
    'border-r border-b md:border-r md:border-b-0',
    'border-b md:border-r md:border-b-0',
    'border-r border-b md:border-r md:border-b-0',
    'border-b md:border-r md:border-b-0',
    'col-span-2 md:col-span-1',
];

function Kpi({
    label,
    border,
    hot = false,
    children,
    sub,
    extra,
}: {
    label: string;
    border: string;
    hot?: boolean;
    children: ReactNode;
    sub?: ReactNode;
    /** A second, wrapping mono line under `sub` (the 30-day and pts/M€ figures). */
    extra?: ReactNode;
}) {
    return (
        <div
            className={cn(
                'min-w-0 border-hq-border px-3.5 py-3.5 sm:px-4',
                border,
                hot && 'bg-linear-to-b from-hq-lime/6 to-transparent',
            )}
        >
            <p className="hq-label">{label}</p>
            <div className="mt-2 flex min-h-[30px] items-center">
                {children}
            </div>
            {sub && (
                <div className="mt-1.5 truncate font-mono text-[11.5px] leading-snug text-hq-moss">
                    {sub}
                </div>
            )}
            {extra && (
                <div className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-[3px] font-mono text-[11.5px] leading-[1.35] whitespace-nowrap text-hq-moss">
                    {extra}
                </div>
            )}
        </div>
    );
}

/**
 * The Titularidad KPI cell's headline: the big dot-matrix % (mock `HqLed`),
 * coloured by {@link startTone} — muted while the data is stale — or, once
 * the lineup is confirmed, the shared Titular/Suplente outcome chip in
 * place of the %.
 */
function PlayerStartHeadline({
    start,
    status,
}: {
    start: PlayerNextStart;
    status: PlayerStatus;
}) {
    if (start.confirmed_starter !== null) {
        return (
            <HqStartOutcomeChip
                facts={start}
                className="h-[22px] px-[7px] text-[11px]"
            />
        );
    }

    const tone = startTone(start.probability, status);

    return (
        <span
            title="Probabilidades: FútbolFantasy"
            className={cn(
                'flex items-baseline gap-[3px]',
                start.is_stale && 'opacity-60 saturate-[.15]',
            )}
        >
            <HqLed className={cn('text-[26px]', START_TONE_TEXT_CLASSES[tone])}>
                {start.probability === null ? '—' : start.probability}
            </HqLed>
            {start.probability !== null && (
                <span
                    className={cn(
                        'font-mono text-xs font-bold',
                        START_TONE_TEXT_CLASSES[tone],
                    )}
                >
                    %
                </span>
            )}
        </span>
    );
}

export default function PlayerShow({
    player,
    currentWeek,
    owner,
    marketListing,
    marketHistory,
    scores,
    ownershipActivity,
    teamJoinedAt,
    teamFixtures,
    missedFixtures,
    valueTrend,
    pointsPerMillion,
    capitalGain,
    maxBid,
}: PlayerShowProps) {
    const [chartRange, setChartRange] = useState<ValueChartRange>(30);
    const ownershipSegments = buildOwnershipTimeline(
        ownershipActivity,
        owner?.season_manager ?? null,
        teamJoinedAt,
    );

    const daznScores = scores.filter(
        (score) =>
            score.stats != null &&
            !didNotPlayMatch(score.stats, score.fixture.state) &&
            score.stats.marca_points,
    );
    const daznAverage =
        daznScores.length > 0
            ? daznScores.reduce(
                  (sum, score) => sum + (score.stats?.marca_points?.[1] ?? 0),
                  0,
              ) / daznScores.length
            : null;
    const scoredMatches = scores.filter(
        (score) => score.points !== null,
    ).length;
    const averagePoints = Number(player.average_points);
    const trend =
        player.market_trend !== null
            ? describeMarketTrend(player.market_trend)
            : null;
    const difference = player.market_value_difference;
    const valueTrendClass =
        valueTrend && valueTrend.multiple >= 1 ? 'text-hq-lime' : 'text-hq-neg';
    const nextStart = player.next_start ?? null;
    const kpiBorders =
        nextStart !== null ? KPI_CELL_BORDERS_WITH_START : KPI_CELL_BORDERS;
    const now = useNow(60_000);

    return (
        <div className="flex-1">
            <Head title={player.nickname} />

            <div className="flex items-start gap-3.5 border-b border-hq-border bg-[radial-gradient(ellipse_at_0%_0%,rgba(196,255,61,0.07),transparent_55%)] px-3.5 pt-4 pb-3.5 sm:items-center sm:gap-5 sm:px-5 sm:pt-[22px] sm:pb-[18px]">
                <EntityImage
                    src={player.image}
                    alt={player.nickname}
                    fallback={User}
                    shape="square"
                    className="size-[72px] shrink-0 rounded-none border border-hq-border-bright bg-hq-panel-alt object-cover object-top sm:size-24"
                />
                <div className="min-w-0">
                    <span className="block font-mono text-[11px] leading-none font-semibold tracking-[0.14em] text-hq-lime">
                        FICHA DE JUGADOR
                    </span>
                    <h1 className="mt-[5px] mb-2.5 font-display text-[30px] leading-[0.92] break-words text-hq-paper uppercase sm:text-[44px]">
                        {player.nickname}
                    </h1>
                    <div className="flex flex-wrap items-center gap-2.5">
                        <HqPositionTag position={player.position} />
                        <span className="font-mono text-xs text-hq-moss">
                            {POSITION_LABELS[player.position]}
                        </span>
                        <Link
                            href={teamsShow(player.team.id).url}
                            className="inline-flex min-w-0 items-center gap-2 hover:opacity-80"
                        >
                            <EntityImage
                                src={player.team.logo}
                                alt=""
                                fallback={Shield}
                                shape="square"
                                className="size-[22px] shrink-0 rounded-none bg-transparent"
                            />
                            <span className="truncate text-[13px] font-bold text-hq-paper">
                                {player.team.main_name}
                            </span>
                        </Link>
                        <HqStatusBadge status={player.status} variant="long" />
                    </div>
                </div>
            </div>

            <div
                className={cn(
                    'grid grid-cols-2 border-b border-hq-border',
                    nextStart !== null ? 'md:grid-cols-5' : 'md:grid-cols-4',
                )}
            >
                <Kpi
                    label="Valor"
                    border={kpiBorders[0]}
                    hot
                    sub={
                        trend !== null ? (
                            <span
                                className={
                                    trend.rising
                                        ? 'text-hq-lime'
                                        : 'text-hq-neg'
                                }
                            >
                                {trend.label}
                            </span>
                        ) : undefined
                    }
                    extra={
                        valueTrend && (
                            <>
                                <span
                                    className={cn(
                                        'text-[11px] font-bold tracking-[0.04em]',
                                        valueTrendClass,
                                    )}
                                >
                                    30 D
                                </span>
                                <b className={cn('font-bold', valueTrendClass)}>
                                    ×{formatDecimal(valueTrend.multiple)}
                                </b>
                                <span className="text-hq-moss-dim">
                                    desde {formatMillions(valueTrend.value)}
                                </span>
                            </>
                        )
                    }
                >
                    <div className="min-w-0">
                        <p className="font-mono text-lg leading-[1.1] font-semibold tracking-[-0.02em] whitespace-nowrap text-hq-paper tabular-nums sm:text-[22px]">
                            {formatCurrency(player.market_value)}
                        </p>
                        {(difference !== 0 || player.market_trend !== null) && (
                            <p className="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <span
                                    className={cn(
                                        'font-mono text-[11px] leading-none font-bold',
                                        difference < 0
                                            ? 'text-hq-neg'
                                            : 'text-hq-lime',
                                    )}
                                >
                                    HOY
                                </span>
                                <HqMarketValueDifference
                                    difference={difference}
                                    trend={player.market_trend}
                                />
                            </p>
                        )}
                    </div>
                </Kpi>

                <Kpi
                    label="Puntos"
                    border={kpiBorders[1]}
                    sub={`${scoredMatches} ${scoredMatches === 1 ? 'partido puntuado' : 'partidos puntuados'}`}
                    extra={
                        <>
                            <b className="font-bold">
                                {pointsPerMillion
                                    ? formatDecimal(pointsPerMillion.value)
                                    : '—'}
                            </b>
                            <span>pts/M€</span>
                            {pointsPerMillion?.rank != null && (
                                <HqTooltip
                                    label={`${pointsPerMillion.rank}º de ${formatNumber(pointsPerMillion.ranked)} jugadores con puntos`}
                                    focusable
                                    className="text-hq-moss-dim"
                                >
                                    · {pointsPerMillion.rank}º liga
                                </HqTooltip>
                            )}
                        </>
                    }
                >
                    <HqLed tone="lime" glow className="text-[34px]">
                        {player.points}
                    </HqLed>
                </Kpi>

                <Kpi label="Media" border={kpiBorders[2]} sub="por partido">
                    <span
                        className={cn(
                            'inline-flex h-7 items-center px-2 font-mono text-lg font-bold tabular-nums',
                            matchPointsBadgeClass(averagePoints),
                        )}
                    >
                        {formatAverage(averagePoints)}
                    </span>
                </Kpi>

                <Kpi label="Media DAZN" border={kpiBorders[3]}>
                    {daznAverage !== null ? (
                        <span
                            className={cn(
                                'inline-flex h-7 items-center px-2 font-mono text-lg font-bold tabular-nums',
                                daznPointsBadgeClass(daznAverage),
                            )}
                        >
                            {formatAverage(daznAverage)}
                        </span>
                    ) : (
                        <span className="font-mono text-lg text-hq-moss-dim">
                            —
                        </span>
                    )}
                </Kpi>

                {nextStart !== null && (
                    <Kpi
                        label="Titularidad"
                        border={kpiBorders[4]}
                        sub={
                            <>
                                {nextStart.is_home ? 'vs ' : '@ '}
                                {nextStart.opponent.short_name}
                                {' · '}
                                {formatMatchDateTime(nextStart.date)}
                            </>
                        }
                        extra={
                            nextStart.confirmed_starter === null &&
                            nextStart.fetched_at ? (
                                <span
                                    className={cn(
                                        nextStart.is_stale &&
                                            'font-bold text-hq-gold',
                                    )}
                                >
                                    {nextStart.is_stale && '▲ datos de '}
                                    {formatDataAge(nextStart.fetched_at, now)}
                                </span>
                            ) : undefined
                        }
                    >
                        <PlayerStartHeadline
                            start={nextStart}
                            status={player.status}
                        />
                    </Kpi>
                )}
            </div>

            {maxBid !== null && (
                <HqMaxBidCard estimate={maxBid} playerStatus={player.status} />
            )}

            <div className="grid grid-cols-1 min-[73.75rem]:grid-cols-[minmax(0,1fr)_400px]">
                <div className="min-w-0">
                    <HqSection
                        title="Evolución"
                        action={
                            <HqValueChartRangeToggle
                                range={chartRange}
                                onChange={setChartRange}
                            />
                        }
                        bodyClassName="pb-3"
                    >
                        <HqPlayerValueChart
                            range={chartRange}
                            marketHistory={marketHistory}
                            scores={scores}
                            missedFixtures={missedFixtures}
                            ownershipSegments={ownershipSegments}
                        />
                    </HqSection>

                    <HqSection
                        title="Partidos"
                        action={
                            <span className="hidden normal-case sm:inline">
                                toca una fila para ver todas las estadísticas
                            </span>
                        }
                        flush
                    >
                        <HqPlayerMatchTimeline
                            scores={scores}
                            teamFixtures={teamFixtures}
                            currentWeek={currentWeek}
                            playerPosition={player.position}
                            teamId={player.team.id}
                        />
                    </HqSection>
                </div>

                <aside className="min-w-0 border-hq-border min-[73.75rem]:border-l">
                    <HqSection title="Propiedad">
                        <HqPlayerPropertyCard
                            owner={owner}
                            marketListing={marketListing}
                            marketValue={player.market_value}
                        />
                        {owner !== null && <CapitalGain gain={capitalGain} />}
                    </HqSection>

                    <HqSection title="Traspasos" flush>
                        <OwnershipHistory segments={ownershipSegments} />
                    </HqSection>

                    <HqSection
                        title="Próximos rivales"
                        action={
                            <HqTooltip
                                label="Dificultad = posición del rival en la tabla, de −1 (líder) a +1 (último), misma fórmula que la puja máxima"
                                wrap
                                focusable
                                className="items-center gap-1.5"
                            >
                                dificultad
                                <Info aria-hidden="true" className="size-3.5" />
                            </HqTooltip>
                        }
                        flush
                    >
                        <NextRivalsList fixtures={player.next_fixtures} />
                    </HqSection>
                </aside>
            </div>
        </div>
    );
}

PlayerShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
