import { Shield, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency, formatRelativeTime } from '@/lib/format';
import type { MarketPlayer } from '@/types/models';

interface MarketPanelProps {
    market: MarketPlayer[];
}

export function MarketPanel({ market }: MarketPanelProps) {
    return (
        <section aria-labelledby="market-heading">
            <h2 id="market-heading" className="text-lg font-semibold">
                Mercado
            </h2>

            {market.length === 0 ? (
                <p className="mt-4 text-neutral-500">
                    No hay jugadores en el mercado ahora mismo.
                </p>
            ) : (
                <ul className="mt-4 divide-y divide-neutral-200">
                    {market.map((listing) => (
                        <li
                            key={listing.id}
                            className="flex items-center justify-between gap-4 py-3"
                        >
                            <div className="flex items-center gap-3">
                                <EntityImage
                                    src={listing.player.image}
                                    alt={listing.player.nickname}
                                    fallback={User}
                                    className="h-10 w-10"
                                />
                                <div>
                                    <p className="font-medium">
                                        {listing.player.nickname}
                                    </p>
                                    <div className="flex items-center gap-1 text-sm text-neutral-500">
                                        <EntityImage
                                            src={listing.player.team.logo}
                                            alt={listing.player.team.name}
                                            fallback={Shield}
                                            className="h-4 w-4"
                                        />
                                        <span>
                                            {listing.player.team.short_name}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div className="text-right text-sm">
                                <p className="font-semibold">
                                    {formatCurrency(listing.value)}
                                </p>
                                <p className="text-neutral-500">
                                    {listing.bids}{' '}
                                    {listing.bids === 1 ? 'puja' : 'pujas'} ·
                                    expira{' '}
                                    {formatRelativeTime(listing.expires_at)}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
