import { Link } from '@inertiajs/react';
import { Shield, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import {
    HqPositionTag,
    POSITION_ACCENT_BORDER_CLASSES,
} from '@/components/hq-position-tag';
import { HqSection } from '@/components/hq-section';
import { formatCurrency } from '@/lib/format';
import { useCountdown } from '@/lib/use-countdown';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { MarketPlayer } from '@/types/models';

interface MarketPanelProps {
    market: MarketPlayer[];
}

function MarketCard({ listing }: { listing: MarketPlayer }) {
    const countdown = useCountdown(listing.expires_at);
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

            <div className="mt-1 flex items-center justify-between">
                <span className="font-mono text-[11px] font-bold text-hq-gold">
                    {countdown}
                </span>
                {listing.bids > 0 && (
                    <span className="border border-hq-ember bg-hq-ember/10 px-1.5 py-0.5 font-mono text-[10px] font-bold text-hq-ember">
                        {listing.bids} {listing.bids === 1 ? 'PUJA' : 'PUJAS'}
                    </span>
                )}
            </div>
        </Link>
    );
}

export function MarketPanel({ market }: MarketPanelProps) {
    return (
        <HqSection title="Mercado">
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
