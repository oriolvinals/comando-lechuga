import { router } from '@inertiajs/react';
import { Tag, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { formatAverage, formatMillions } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    ManagerSquare,
    OverValue,
    PayerSquares,
} from '@/pages/god/radar-helpers';
import { ReloadingCountdown } from '@/pages/god/radar-unlocks';
import { show as playersShow } from '@/routes/players';
import type { RadarManager, RadarMarketListing } from '@/types/models';

const HEAD_CLASS = 'px-2 font-medium';

/** "−3,2 M€" in red when the price doesn't fit. */
function Remaining({ amount }: { amount: number }) {
    return (
        <b
            className={cn(
                'font-mono text-[13px] whitespace-nowrap tabular-nums',
                amount < 0 && 'text-hq-neg',
            )}
        >
            {formatMillions(amount)}
        </b>
    );
}

interface RadarMarketProps {
    listings: RadarMarketListing[];
    byId: Map<number, RadarManager>;
    connectedManagerId: number | null;
    /** The «Paga» filter's manager: the payers column becomes what they'd have left. */
    payer: RadarManager | null;
    payerLow: number;
    /** The connected account's real cash, when known. */
    myCash: number | null;
}

/** The «Dueño: Mercado» view of the radar table: one row per live market listing. */
export function RadarMarket({
    listings,
    byId,
    connectedManagerId,
    payer,
    payerLow,
    myCash,
}: RadarMarketProps) {
    if (listings.length === 0) {
        return <HqEmptyState title="Nadie en el mercado con estos filtros." />;
    }

    return (
        <table className="w-full border-collapse max-[860px]:block">
            <thead className="max-[860px]:hidden">
                <tr className="border-y border-hq-border font-mono text-[11px] tracking-[0.07em] text-hq-moss-dim uppercase">
                    <th
                        scope="col"
                        className="py-1.5 pr-2 pl-3.5 text-left font-medium"
                    >
                        Jugador
                    </th>
                    <th scope="col" className={cn(HEAD_CLASS, 'text-left')}>
                        Vende
                    </th>
                    <th scope="col" className={cn(HEAD_CLASS, 'text-right')}>
                        Precio
                    </th>
                    <th scope="col" className={cn(HEAD_CLASS, 'text-right')}>
                        Valor
                    </th>
                    <th scope="col" className={cn(HEAD_CLASS, 'text-right')}>
                        Media
                    </th>
                    <th scope="col" className={cn(HEAD_CLASS, 'text-right')}>
                        Cierra
                    </th>
                    <th scope="col" className={cn(HEAD_CLASS, 'text-left')}>
                        {payer ? 'Le queda' : 'Pueden pagar'}
                    </th>
                    {myCash !== null && (
                        <th
                            scope="col"
                            className="py-1.5 pr-3.5 pl-2 text-right font-medium"
                        >
                            Te queda
                        </th>
                    )}
                </tr>
            </thead>
            <tbody className="max-[860px]:block">
                {listings.map((listing) => {
                    const seller =
                        listing.seller_id !== null
                            ? byId.get(listing.seller_id)
                            : undefined;
                    const rivals = listing.payers.filter(
                        (entry) => entry.manager_id !== connectedManagerId,
                    );
                    const fichaUrl = playersShow(listing.player.id).url;

                    return (
                        <tr
                            key={listing.listing_id}
                            tabIndex={0}
                            onClick={() => router.visit(fichaUrl)}
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'Enter' &&
                                    event.target === event.currentTarget
                                ) {
                                    router.visit(fichaUrl);
                                }
                            }}
                            title={`Abrir ficha de ${listing.player.nickname}`}
                            className="cursor-pointer border-b border-hq-border hover:bg-hq-panel max-[860px]:grid max-[860px]:grid-cols-[minmax(0,1fr)_auto] max-[860px]:gap-x-3 max-[860px]:gap-y-2 max-[860px]:px-3.5 max-[860px]:py-2.5"
                        >
                            <td className="py-1.5 pr-2 pl-3.5 max-[860px]:p-0">
                                <div className="flex min-w-0 items-center gap-2">
                                    <EntityImage
                                        src={listing.player.image}
                                        alt={listing.player.nickname}
                                        fallback={User}
                                        shape="square"
                                        className="size-9 shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt object-cover object-top text-hq-moss-dim"
                                    />
                                    <div className="min-w-0">
                                        <b className="block truncate text-[13.5px] font-extrabold">
                                            {listing.player.nickname}
                                        </b>
                                        <span className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 font-mono text-[11px] text-hq-moss-dim">
                                            <HqPositionTag
                                                position={
                                                    listing.player.position
                                                }
                                            />
                                            <span>
                                                {listing.player.team_short_name}{' '}
                                                · {listing.player.points} pts
                                                <span className="min-[861px]:hidden">
                                                    {' '}
                                                    · media{' '}
                                                    {formatAverage(
                                                        listing.player
                                                            .average_points,
                                                    )}
                                                </span>
                                            </span>
                                            <HqStatusBadge
                                                status={listing.player.status}
                                            />
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td className="px-2 max-[860px]:col-span-2 max-[860px]:p-0">
                                {seller ? (
                                    <span className="inline-flex max-w-full items-center gap-1.5 text-[12.5px] font-semibold">
                                        <ManagerSquare
                                            manager={seller}
                                            size="md"
                                        />
                                        <span className="truncate">
                                            {seller.name}
                                        </span>
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center gap-1.5 font-mono text-[11.5px] font-semibold text-hq-moss">
                                        <Tag
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        Mercado
                                    </span>
                                )}
                            </td>
                            <td className="px-2 text-right max-[860px]:p-0 max-[860px]:text-left">
                                <span className="flex flex-col items-end gap-0.5 max-[860px]:items-start">
                                    <span className="inline-flex items-center gap-1.5">
                                        <b className="font-mono text-[13px] whitespace-nowrap tabular-nums">
                                            {formatMillions(listing.price)}
                                        </b>
                                        {listing.bids > 0 && (
                                            <span className="inline-flex shrink-0 items-center border border-hq-ember bg-hq-ember/10 px-[5px] py-[2px] font-mono text-[11px] leading-none font-bold tracking-[0.04em] whitespace-nowrap text-hq-ember">
                                                {listing.bids}{' '}
                                                {listing.bids === 1
                                                    ? 'PUJA'
                                                    : 'PUJAS'}
                                            </span>
                                        )}
                                    </span>
                                    <OverValue
                                        overValue={
                                            listing.price - listing.value
                                        }
                                    />
                                </span>
                            </td>
                            <td className="px-2 text-right max-[860px]:p-0">
                                <span className="flex flex-col items-end gap-0.5">
                                    <span className="font-mono text-xs whitespace-nowrap text-hq-moss tabular-nums">
                                        {formatMillions(listing.value)}
                                    </span>
                                    <HqMarketValueDifference
                                        difference={
                                            listing.player
                                                .market_value_difference
                                        }
                                        trend={listing.player.market_trend}
                                    />
                                </span>
                            </td>
                            <td className="px-2 text-right font-mono text-[13px] font-bold tabular-nums max-[860px]:hidden">
                                {formatAverage(listing.player.average_points)}
                            </td>
                            <td className="px-2 text-right text-[12.5px] text-hq-gold max-[860px]:col-start-2 max-[860px]:row-start-1 max-[860px]:p-0">
                                <ReloadingCountdown
                                    key={listing.expires_at}
                                    target={listing.expires_at}
                                />
                            </td>
                            <td className="px-2 max-[860px]:p-0">
                                {payer ? (
                                    <Remaining
                                        amount={payerLow - listing.price}
                                    />
                                ) : (
                                    <PayerSquares payers={rivals} byId={byId} />
                                )}
                            </td>
                            {myCash !== null && (
                                <td className="py-1.5 pr-3.5 pl-2 text-right max-[860px]:p-0">
                                    <span className="font-mono text-[11px] text-hq-moss-dim min-[861px]:hidden">
                                        Te queda{' '}
                                    </span>
                                    <Remaining
                                        amount={myCash - listing.price}
                                    />
                                </td>
                            )}
                        </tr>
                    );
                })}
            </tbody>
        </table>
    );
}
