import { Shield, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqSection } from '@/components/hq-section';
import { formatCurrency } from '@/lib/format';
import { POSITION_ABBREVIATIONS } from '@/lib/player-labels';
import { useCountdown } from '@/lib/use-countdown';
import type { MarketPlayer } from '@/types/models';

interface MarketPanelProps {
    market: MarketPlayer[];
}

function MarketCard({ listing }: { listing: MarketPlayer }) {
    const countdown = useCountdown(listing.expires_at);

    return (
        <div className="relative rounded-md border border-hq-border bg-hq-panel px-4 py-4 text-center">
            {listing.bids > 0 && (
                <span className="absolute top-2 right-2 rounded bg-hq-ember/10 px-1.5 py-0.5 font-mono text-[9px] font-bold text-hq-ember">
                    {listing.bids} {listing.bids === 1 ? 'PUJA' : 'PUJAS'}
                </span>
            )}
            <EntityImage
                src={listing.player.image}
                alt={listing.player.nickname}
                fallback={User}
                className="mx-auto mb-2 h-14 w-14 border border-hq-border-strong bg-hq-panel-alt"
            />
            <p className="text-sm font-extrabold text-hq-paper">
                {listing.player.nickname}
            </p>
            <div className="mt-1.5 flex items-center justify-center gap-1.5">
                <EntityImage
                    src={listing.player.team.logo}
                    alt={listing.player.team.name}
                    fallback={Shield}
                    shape="square"
                    className="h-4 w-4 bg-hq-panel-alt p-0.5"
                />
                <span className="border border-hq-border-strong px-1.5 py-0.5 font-mono text-[9px] font-bold text-hq-khaki">
                    {POSITION_ABBREVIATIONS[listing.player.position]}
                </span>
            </div>
            <p className="mt-2 font-mono text-[10px] text-hq-moss">
                <span className="block font-display text-base text-hq-paper">
                    {listing.player.points}
                </span>
                PTS
            </p>
            <p className="mt-2.5 font-mono text-sm font-bold text-hq-lime">
                {countdown}
            </p>
            <span className="hq-tag-cut mt-2 inline-block bg-hq-khaki px-2.5 py-1 font-mono text-xs font-bold text-hq-ink">
                {formatCurrency(listing.value)}
            </span>
        </div>
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
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    {market.map((listing) => (
                        <MarketCard key={listing.id} listing={listing} />
                    ))}
                </div>
            )}
        </HqSection>
    );
}
