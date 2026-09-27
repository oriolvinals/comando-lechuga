import { Link, router } from '@inertiajs/react';
import { RefreshCw, Shield, User } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqLed } from '@/components/hq-led';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqSection } from '@/components/hq-section';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { HqTooltip } from '@/components/hq-tooltip';
import { formatCurrency } from '@/lib/format';
import { useCountdown } from '@/lib/use-countdown';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { MarketPlayer, PlayerPosition } from '@/types/models';

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

function MarketRow({ listing }: { listing: MarketPlayer }) {
    const player = listing.player;

    return (
        <Link
            href={playersShow(player.id).url}
            style={
                {
                    '--pc': POSITION_COLOR_VARS[player.position],
                } as CSSProperties
            }
            className="flex gap-3 border-b border-hq-border py-[11px] pr-3.5 pl-3 shadow-[inset_3px_0_0_var(--pc)] transition-colors hover:bg-hq-panel md:pr-4 md:pl-[13px]"
        >
            <EntityImage
                src={player.image}
                alt={player.nickname}
                fallback={User}
                shape="square"
                className="h-[46px] w-[46px] shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt object-cover object-top text-hq-moss-dim"
            />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="truncate text-sm leading-[1.1] font-extrabold text-hq-paper">
                        {player.nickname}
                    </span>
                    <span className="ml-auto shrink-0 border border-hq-border-strong bg-hq-panel-alt px-[5px] py-[3px] font-mono text-[10.5px] leading-none font-bold whitespace-nowrap text-hq-moss">
                        {player.points} PTS
                    </span>
                </div>

                <div className="mt-[5px] flex flex-wrap items-center gap-1.5 font-mono text-[11px] leading-none text-hq-moss">
                    <EntityImage
                        src={player.team.logo}
                        alt={player.team.main_name}
                        fallback={Shield}
                        shape="square"
                        className="h-3.5 w-3.5 rounded-none"
                    />
                    <span>{player.team.short_name}</span>
                    <HqPositionTag position={player.position} />
                    <HqStatusBadge status={player.status} />
                </div>

                <div className="mt-2 flex flex-wrap items-center gap-2">
                    <span className="font-mono text-[13px] leading-[1.1] font-semibold whitespace-nowrap text-hq-paper tabular-nums">
                        {formatCurrency(listing.value)}
                    </span>
                    <HqMarketValueDifference
                        difference={player.market_value_difference}
                        trend={player.market_trend}
                        className="text-xs"
                    />
                    <span className="ml-auto flex items-center gap-2">
                        <HqRecentScores
                            scores={player.recent_scores}
                            finished={player.recent_scores_finished}
                            size="sm"
                        />
                        {listing.bids > 0 && (
                            <span className="inline-flex shrink-0 items-center border border-hq-ember bg-hq-ember/10 px-[5px] py-[3px] font-mono text-[10.5px] leading-none font-bold tracking-[0.04em] text-hq-ember">
                                {listing.bids}{' '}
                                {listing.bids === 1 ? 'PUJA' : 'PUJAS'}
                            </span>
                        )}
                    </span>
                </div>
            </div>
        </Link>
    );
}

function MarketCountdown({ market }: { market: MarketPlayer[] }) {
    // Every listing normally expires at the same time, so one shared
    // countdown replaces a per-row timer instead of repeating it on
    // every row.
    const countdown = useCountdown(
        market[0]?.expires_at ?? new Date().toISOString(),
    );

    if (market.length === 0) {
        return null;
    }

    return (
        <HqTooltip label="Todas las ofertas vencen a la vez">
            <HqLed tone="gold" className="text-xl">
                {countdown}
            </HqLed>
        </HqTooltip>
    );
}

export function MarketPanel({ market }: MarketPanelProps) {
    return (
        <HqSection
            code="CH·02"
            title="Mercado"
            action={
                <>
                    <MarketCountdown market={market} />
                    <RefreshMarketButton market={market} />
                </>
            }
            flush
            className="border-b-0"
        >
            {market.length === 0 ? (
                <HqEmptyState title="Sin movimiento en el mercado">
                    Vuelve más tarde para ver nuevos fichajes disponibles
                </HqEmptyState>
            ) : (
                <div className="min-[73.75rem]:max-h-[760px] min-[73.75rem]:overflow-y-auto">
                    {market.map((listing) => (
                        <MarketRow key={listing.id} listing={listing} />
                    ))}
                </div>
            )}
        </HqSection>
    );
}
