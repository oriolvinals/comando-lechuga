import { Link, router } from '@inertiajs/react';
import { Armchair, RefreshCw, Shield, User } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { HqCompareToggle } from '@/components/compare/compare-toggle';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { startBadgeTierClass } from '@/components/hq-lineup-pitch';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqSection } from '@/components/hq-section';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { HqTooltip } from '@/components/hq-tooltip';
import { useCompareSelection } from '@/lib/compare-selection';
import { formatCurrency, formatMillions } from '@/lib/format';
import { dataAgeTooltipLabel, startTone } from '@/lib/start-probability';
import { useCountdown } from '@/lib/use-countdown';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type {
    MarketPlayer,
    PlayerNextStart,
    PlayerPosition,
    PlayerStatus,
} from '@/types/models';

interface MarketPanelProps {
    market: MarketPlayer[];
}

/** The position colour as a CSS value, for the row's inset accent rule. */
const POSITION_COLOR_VARS: Record<PlayerPosition, string> = {
    goalkeeper: 'var(--color-hq-por)',
    defender: 'var(--color-hq-def)',
    midfield: 'var(--color-hq-med)',
    striker: 'var(--color-hq-del)',
    coach: 'var(--color-hq-ent)',
};

type RefreshStatus = 'idle' | 'loading' | 'no-news' | 'new-bids';

// Local reloads can resolve in a few ms, which lets React batch the
// 'loading' and result updates into one paint and skip the spin entirely.
const MIN_LOADING_MS = 450;
const RESULT_FLASH_MS = 1600;

function totalBids(market: MarketPlayer[]) {
    return market.reduce((sum, listing) => sum + listing.bids, 0);
}

function RefreshMarketButton({ market }: { market: MarketPlayer[] }) {
    const [status, setStatus] = useState<RefreshStatus>('idle');
    const pendingTimeout = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(pendingTimeout.current), []);

    const handleRefresh = () => {
        if (status === 'loading') {
            return;
        }

        const previousBids = totalBids(market);
        const startedAt = Date.now();
        setStatus('loading');

        const settle = (next: RefreshStatus) => {
            clearTimeout(pendingTimeout.current);
            const delay = Math.max(
                0,
                MIN_LOADING_MS - (Date.now() - startedAt),
            );
            pendingTimeout.current = setTimeout(() => {
                setStatus(next);

                if (next !== 'idle') {
                    pendingTimeout.current = setTimeout(
                        () => setStatus('idle'),
                        RESULT_FLASH_MS,
                    );
                }
            }, delay);
        };

        router.reload({
            only: ['market'],
            onSuccess: (page) => {
                const updatedMarket = page.props.market as MarketPlayer[];
                settle(
                    totalBids(updatedMarket) > previousBids
                        ? 'new-bids'
                        : 'no-news',
                );
            },
            onError: () => settle('idle'),
        });
    };

    return (
        <button
            type="button"
            onClick={handleRefresh}
            disabled={status === 'loading'}
            title="Actualizar mercado"
            aria-label="Actualizar mercado"
            className={cn(
                'inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center border bg-hq-ink transition-colors sm:h-[30px] sm:w-8',
                status === 'loading' &&
                    'cursor-not-allowed border-hq-border-strong text-hq-moss',
                status === 'no-news' &&
                    'border-hq-lime bg-hq-lime/10 text-hq-lime',
                status === 'new-bids' &&
                    'border-hq-ember bg-hq-ember/10 text-hq-ember',
                status === 'idle' &&
                    'border-hq-border-strong text-hq-moss hover:border-hq-lime hover:text-hq-lime',
            )}
        >
            <RefreshCw
                aria-hidden="true"
                className={cn(
                    'h-3.5 w-3.5',
                    status === 'loading' && 'animate-spin',
                )}
            />
        </button>
    );
}

/**
 * One grid cell: a player card on desktop, a bordered, snapping slide on
 * the phone's swipeable strip.
 */
const CELL_CLASS =
    'min-w-0 snap-start max-md:border max-md:border-hq-border-strong md:border-r md:border-b md:border-hq-border';

interface NextStartBadge {
    label: string;
    className: string;
    content: ReactNode;
}

/**
 * The listing photo's top-right corner badge: once the lineup is confirmed,
 * ✓ titular or a bench glyph suplente — otherwise FútbolFantasy's % on the
 * same tone scale used everywhere else (lilac ≥ 90 %, lime 70–89 %, gold
 * < 70 %, red injured/suspended). Null once the player's match has kicked
 * off or without any data — the backend (`StartProbabilities::forPlayersNextFixture`)
 * already omits `next_start` in both cases.
 */
function nextStartBadge(
    start: PlayerNextStart | null | undefined,
    status: PlayerStatus,
    now: number,
): NextStartBadge | null {
    if (!start) {
        return null;
    }

    if (start.confirmed_starter !== null) {
        return start.confirmed_starter
            ? {
                  label: 'Titular confirmado',
                  className: 'bg-hq-lime text-hq-ink',
                  content: '✓',
              }
            : {
                  label: 'Suplente confirmado',
                  className: 'bg-hq-border-strong text-hq-moss',
                  content: (
                      <Armchair aria-hidden="true" className="h-2.5 w-2.5" />
                  ),
              };
    }

    if (start.probability === null) {
        return null;
    }

    return {
        label: start.fetched_at
            ? `${start.probability} % de ser titular · ${dataAgeTooltipLabel(start.fetched_at, now)}`
            : `${start.probability} % de ser titular`,
        className: startBadgeTierClass(startTone(start.probability, status)),
        content: `${start.probability}%`,
    };
}

function MarketCard({ listing }: { listing: MarketPlayer }) {
    const player = listing.player;
    const now = useNow(60_000);
    const startBadge = nextStartBadge(player.next_start, player.status, now);
    const isCompared = useCompareSelection().some(
        (entry) => entry.id === player.id,
    );

    return (
        <div className={cn(CELL_CLASS, 'relative')}>
            <Link
                href={playersShow(player.id).url}
                style={
                    {
                        '--pc': POSITION_COLOR_VARS[player.position],
                    } as CSSProperties
                }
                className={cn(
                    'relative flex h-full flex-col bg-hq-ink px-3.5 pt-3.5 pb-[13px] shadow-[inset_0_3px_0_var(--pc)] transition-colors hover:bg-hq-panel',
                    isCompared &&
                        'bg-[linear-gradient(160deg,color-mix(in_srgb,var(--color-hq-lime)_8%,transparent),transparent_55%)] shadow-[inset_0_3px_0_var(--pc),inset_0_0_0_1px_color-mix(in_srgb,var(--color-hq-lime)_45%,transparent)]',
                )}
            >
                <div className="flex items-start gap-[11px]">
                    <span className="relative shrink-0 pb-2.5">
                        <EntityImage
                            src={player.image}
                            alt={player.nickname}
                            fallback={User}
                            shape="square"
                            className="h-[58px] w-[58px] rounded-none border border-hq-border-strong bg-hq-panel-alt object-cover object-top text-hq-moss-dim"
                        />
                        <HqPositionTag
                            position={player.position}
                            className="absolute bottom-0 left-1/2 -translate-x-1/2 bg-hq-ink px-[3px] py-0.5 text-[9px]"
                        />
                        {startBadge && (
                            <HqTooltip
                                label={startBadge.label}
                                className={cn(
                                    'absolute -top-1.5 -right-1.5 z-10 h-4 min-w-5 items-center justify-center px-[3px] font-mono text-[10.5px] leading-none font-extrabold tabular-nums',
                                    startBadge.className,
                                )}
                            >
                                {startBadge.content}
                            </HqTooltip>
                        )}
                    </span>
                    <div className="min-w-0 flex-1">
                        <span className="block truncate text-[15px] leading-[1.1] font-extrabold text-hq-paper">
                            {player.nickname}
                        </span>
                        <div className="mt-1.5 flex flex-wrap items-center gap-[5px] font-mono text-[10.5px] leading-none text-hq-moss">
                            <EntityImage
                                src={player.team.logo}
                                alt={player.team.main_name}
                                fallback={Shield}
                                shape="square"
                                className="h-[13px] w-[13px] rounded-none"
                            />
                            <span>{player.team.short_name}</span>
                        </div>
                        {player.status !== 'ok' && (
                            <div className="mt-1.5 flex">
                                <HqStatusBadge status={player.status} />
                            </div>
                        )}
                    </div>
                    <div className="shrink-0 pt-[30px] text-right">
                        <HqLed
                            tone={player.points < 0 ? 'live' : 'lime'}
                            className="block text-[24px]"
                        >
                            {player.points}
                        </HqLed>
                        <span className="mt-1 block font-mono text-[9.5px] leading-none font-bold tracking-[0.12em] text-hq-moss-dim">
                            PTS
                        </span>
                    </div>
                </div>

                <div className="mt-3.5 font-mono text-[15px] leading-none font-semibold text-hq-paper tabular-nums">
                    {formatCurrency(listing.value)}
                </div>
                <div className="mt-1.5 min-h-3.5">
                    <HqMarketValueDifference
                        difference={player.market_value_difference}
                        trend={player.market_trend}
                        className="text-xs"
                    />
                </div>

                <div className="mt-auto flex items-center gap-1.5 pt-3">
                    <HqRecentScores
                        scores={player.recent_scores}
                        finished={player.recent_scores_finished}
                        size="sm"
                    />
                    {listing.bids > 0 && (
                        <span className="ml-auto inline-flex shrink-0 items-center border border-hq-ember bg-hq-ember/10 px-[5px] py-[3px] font-mono text-[10.5px] leading-none font-bold tracking-[0.04em] text-hq-ember">
                            {listing.bids}{' '}
                            {listing.bids === 1 ? 'PUJA' : 'PUJAS'}
                        </span>
                    )}
                </div>
            </Link>
            <div className="absolute top-[9px] right-[9px] z-10 flex">
                <HqCompareToggle
                    variant="card"
                    player={{
                        id: player.id,
                        name: player.nickname,
                        image: player.image,
                    }}
                />
            </div>
        </div>
    );
}

function SummaryRow({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-baseline justify-between border-t border-dashed border-hq-border-strong pt-[7px] font-mono text-[11.5px] text-hq-moss">
            <span>{label}</span>
            {children}
        </div>
    );
}

function MarketSummaryCard({ market }: { market: MarketPlayer[] }) {
    // Every listing normally expires at the same time, so this one shared
    // countdown stands in for a per-card timer.
    const countdown = useCountdown(market[0].expires_at);
    const bids = totalBids(market);
    const listingsWithBids = market.filter(
        (listing) => listing.bids > 0,
    ).length;
    const totalValue = market.reduce((sum, listing) => sum + listing.value, 0);

    return (
        <div
            className={cn(
                CELL_CLASS,
                'flex flex-col justify-between gap-3 bg-hq-well p-3.5',
            )}
        >
            <div>
                <div className="font-mono text-[10px] font-bold tracking-[0.12em] text-hq-moss-dim uppercase">
                    Cierran todas en
                </div>
                <div className="mt-2">
                    <HqTooltip label="Todas las ofertas vencen a la vez">
                        <HqLed tone="gold" className="text-[34px]">
                            {countdown}
                        </HqLed>
                    </HqTooltip>
                </div>
            </div>
            <div className="flex flex-col gap-1.5">
                <SummaryRow label="En venta">
                    <b className="text-hq-paper">{market.length}</b>
                </SummaryRow>
                <SummaryRow label="Pujas">
                    <b className={bids > 0 ? 'text-hq-ember' : 'text-hq-paper'}>
                        {bids} en {listingsWithBids}
                    </b>
                </SummaryRow>
                <SummaryRow label="Valor total">
                    <b className="text-hq-paper">
                        {formatMillions(totalValue)}
                    </b>
                </SummaryRow>
            </div>
        </div>
    );
}

export function MarketPanel({ market }: MarketPanelProps) {
    return (
        <HqSection
            title="Mercado"
            action={<RefreshMarketButton market={market} />}
            flush
        >
            {market.length === 0 ? (
                <div className="m-3.5 flex flex-wrap items-center gap-x-3.5 gap-y-1.5 border border-dashed border-hq-border-bright px-4 py-3 font-mono text-[12.5px] text-hq-moss sm:mx-4">
                    <span
                        aria-hidden="true"
                        className="font-dot text-2xl leading-none font-black text-hq-border-bright"
                    >
                        ∅
                    </span>
                    <h3 className="font-sans text-sm font-extrabold tracking-[0.02em] text-hq-paper uppercase">
                        Sin movimiento en el mercado
                    </h3>
                    <span>
                        Vuelve más tarde para ver nuevos fichajes disponibles
                    </span>
                </div>
            ) : (
                <div className="max-md:pt-3.5">
                    <div className="grid snap-x snap-mandatory scroll-px-3.5 [scrollbar-width:thin] auto-cols-[76%] grid-flow-col gap-2.5 overflow-x-auto px-3.5 pb-3.5 md:-mb-px md:snap-none md:auto-cols-auto md:grid-flow-row md:grid-cols-4 md:gap-0 md:overflow-visible md:p-0 min-[73.75rem]:grid-cols-6 md:[&>*:nth-child(4n)]:border-r-0 min-[73.75rem]:[&>*:nth-child(4n):not(:nth-child(6n))]:border-r min-[73.75rem]:[&>*:nth-child(6n)]:border-r-0">
                        {market.map((listing) => (
                            <MarketCard key={listing.id} listing={listing} />
                        ))}
                        <MarketSummaryCard market={market} />
                    </div>
                </div>
            )}
        </HqSection>
    );
}
