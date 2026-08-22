import { Shield, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqSection } from '@/components/hq-section';
import { formatCurrency, formatRelativeTime } from '@/lib/format';
import type { MarketPlayer } from '@/types/models';

interface MarketPanelProps {
    market: MarketPlayer[];
}

export function MarketPanel({ market }: MarketPanelProps) {
    return (
        <HqSection number="03" title="Suministros disponibles">
            {market.length === 0 ? (
                <p className="text-sm text-hq-moss">
                    No hay jugadores en el mercado ahora mismo.
                </p>
            ) : (
                <div className="flex flex-col gap-1.5">
                    {market.map((listing) => (
                        <div
                            key={listing.id}
                            className="flex items-center gap-3.5 border border-hq-border bg-hq-panel px-3.5 py-2.5"
                        >
                            <EntityImage
                                src={listing.player.image}
                                alt={listing.player.nickname}
                                fallback={User}
                                className="h-9 w-9 border border-hq-border-strong bg-hq-panel-alt"
                            />
                            <span className="text-sm font-bold text-hq-paper">
                                {listing.player.nickname}
                            </span>
                            <EntityImage
                                src={listing.player.team.logo}
                                alt={listing.player.team.name}
                                fallback={Shield}
                                shape="square"
                                className="-ml-1.5 h-5 w-5 bg-hq-panel-alt p-0.5"
                            />
                            {listing.bids > 0 && (
                                <span className="ml-2 font-mono text-[10px] text-hq-ember">
                                    {listing.bids}{' '}
                                    {listing.bids === 1 ? 'PUJA' : 'PUJAS'}{' '}
                                    ACTIVA{listing.bids === 1 ? '' : 'S'}
                                </span>
                            )}
                            <span className="ml-auto font-mono text-[10px] text-hq-moss">
                                {formatRelativeTime(listing.expires_at)}
                            </span>
                            <span className="hq-tag-cut bg-hq-khaki px-2.5 py-1 font-mono text-xs font-bold text-hq-ink">
                                {formatCurrency(listing.value)}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </HqSection>
    );
}
