import { Link, router } from '@inertiajs/react';
import { RefreshCw, Shield, User } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import {
    HqPositionTag,
    POSITION_ACCENT_BORDER_CLASSES,
} from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqSection } from '@/components/hq-section';
import { formatCurrency } from '@/lib/format';
import { useCountdown } from '@/lib/use-countdown';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { MarketPlayer } from '@/types/models';

interface MarketPanelProps {
    market: MarketPlayer[];
}

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
            const delay = Math.max(0, MIN_LOADING_MS - (Date.now() - startedAt));
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
                'hq-tag-cut h-9 w-9 shrink-0 cursor-pointer p-px transition-colors',
                status === 'loading' &&
                    'cursor-not-allowed bg-hq-border-strong',
                status === 'no-news' && 'bg-hq-lime',
                status === 'new-bids' && 'bg-hq-ember',
                status === 'idle' && 'bg-hq-border-strong hover:bg-hq-lime',
            )}
        >
            <span
                className={cn(
                    'hq-tag-cut flex h-full w-full items-center justify-center',
                    status === 'loading' && 'bg-hq-panel-alt text-hq-moss',
                    status === 'no-news' && 'bg-hq-lime/10 text-hq-lime',
                    status === 'new-bids' && 'bg-hq-ember/10 text-hq-ember',
                    status === 'idle' && 'bg-hq-panel-alt text-hq-lime',
                )}
            >
                <RefreshCw
                    className={cn(
                        'h-4 w-4',
                        status === 'loading' && 'animate-spin',
                    )}
                />
            </span>
        </button>
    );
}

function MarketCard({ listing }: { listing: MarketPlayer }) {
    const player = listing.player;

    return (
        <Link
            href={playersShow(player.id).url}
            className={cn(
                'hq-card-cut block border-l-[3px] px-3 py-2.5 transition-[filter] hover:brightness-125',
                POSITION_ACCENT_BORDER_CLASSES[player.position],
            )}
        >
            <div className="flex items-center gap-2">
                <EntityImage
                    src={player.image}
                    alt={player.nickname}
                    fallback={User}
                    className="h-[30px] w-[30px] shrink-0 border border-hq-border-strong bg-hq-panel-alt"
                />
                <span className="flex-1 truncate text-[13px] font-extrabold text-hq-paper">
                    {player.nickname}
                </span>
                <span className="shrink-0 bg-hq-border px-1.5 py-0.5 font-mono text-[10px] font-bold text-hq-moss">
                    {player.points} PTS
                </span>
            </div>

            <div className="mt-1.5 flex items-center gap-1.5">
                <EntityImage
                    src={player.team.logo}
                    alt={player.team.name}
                    fallback={Shield}
                    shape="square"
                    className="h-[11px] w-[11px]"
                />
                <span className="font-mono text-[10px] text-hq-moss">
                    {player.team.short_name}
                </span>
                <HqPositionTag position={player.position} />
            </div>

            <p className="mt-1.5 font-mono text-[12px] font-bold text-hq-paper">
                {formatCurrency(listing.value)}
                {player.market_value_difference !== 0 && (
                    <span
                        className={cn(
                            'ml-2 text-[10px]',
                            player.market_value_difference > 0
                                ? 'text-hq-lime'
                                : 'text-hq-live',
                        )}
                    >
                        {player.market_value_difference > 0 ? '▲' : '▼'}{' '}
                        {formatCurrency(Math.abs(player.market_value_difference))}
                    </span>
                )}
            </p>

            <div className="mt-1.5 flex items-center justify-between gap-2">
                <HqRecentScores
                    scores={player.recent_scores}
                    finished={player.recent_scores_finished}
                    size="sm"
                />
                {listing.bids > 0 && (
                    <span className="shrink-0 border border-hq-ember bg-hq-ember/10 px-1.5 py-0.5 font-mono text-[10px] font-bold text-hq-ember">
                        {listing.bids} {listing.bids === 1 ? 'PUJA' : 'PUJAS'}
                    </span>
                )}
            </div>
        </Link>
    );
}

function MarketCountdown({ market }: { market: MarketPlayer[] }) {
    // Every listing normally expires at the same time, so one shared
    // countdown replaces a per-card timer instead of repeating it on
    // every card.
    const countdown = useCountdown(
        market[0]?.expires_at ?? new Date().toISOString(),
    );

    if (market.length === 0) {
        return null;
    }

    return (
        <span className="font-display text-xl text-hq-gold">
            {countdown}
        </span>
    );
}

export function MarketPanel({ market }: MarketPanelProps) {
    return (
        <HqSection
            title="Mercado"
            action={
                <div className="flex items-center gap-3">
                    <MarketCountdown market={market} />
                    <RefreshMarketButton market={market} />
                </div>
            }
        >
            {market.length === 0 ? (
                <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                    <p className="mb-2 text-3xl">🗃️</p>
                    <p className="font-display text-lg text-hq-paper uppercase">
                        Sin movimiento en el mercado
                    </p>
                    <p className="mt-1.5 font-mono text-[11px] text-hq-moss-dim">
                        Vuelve más tarde para ver nuevos fichajes disponibles
                    </p>
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    {market.map((listing) => (
                        <MarketCard key={listing.id} listing={listing} />
                    ))}
                </div>
            )}
        </HqSection>
    );
}
