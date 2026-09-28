import { Link } from '@inertiajs/react';
import { Lock, LockOpen, Shield, ShieldCheck, UserX } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqTooltip } from '@/components/hq-tooltip';
import { resolveClauseStatus } from '@/lib/clause-status';
import { formatCurrency, formatFullDateTime } from '@/lib/format';
import { managerColor } from '@/lib/season-manager-colors';
import { useCountdown } from '@/lib/use-countdown';
import { useLockCountdown } from '@/lib/use-lock-countdown';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { PlayerFichaMarketListing, PlayerOwnership } from '@/types/models';

interface HqPlayerPropertyCardProps {
    owner: PlayerOwnership | null;
    marketListing: PlayerFichaMarketListing | null;
    marketValue: number;
}

export function ClauseDifference({
    clause,
    marketValue,
    valueColorClass = 'text-hq-khaki',
    className,
}: {
    clause: number;
    marketValue: number;
    valueColorClass?: string;
    /** Overrides the default 10px mono line (e.g. the roster's larger clause column). */
    className?: string;
}) {
    if (clause === marketValue) {
        return (
            <p
                className={cn(
                    'mt-0.5 truncate font-mono text-[10px] text-hq-moss-dim',
                    className,
                )}
            >
                {formatCurrency(clause)} (=)
            </p>
        );
    }

    const diff = clause - marketValue;

    return (
        <p
            className={cn(
                'mt-0.5 truncate font-mono text-[10px]',
                valueColorClass,
                className,
            )}
        >
            {formatCurrency(clause)}{' '}
            <span className="text-hq-live">
                ({diff >= 0 ? '+' : ''}
                {formatCurrency(diff)})
            </span>
        </p>
    );
}

const LABEL_CLASS =
    'font-mono text-[11px] leading-tight font-medium tracking-[0.07em] uppercase';

/**
 * Who holds the player (mock `.prop`): the owning manager — washed in their
 * colour — with the clause state (shielded / locked with countdown, or open)
 * and the clause against the value; or the market listing (countdown, sale
 * price, bids); or "Libre".
 */
export function HqPlayerPropertyCard({
    owner,
    marketListing,
    marketValue,
}: HqPlayerPropertyCardProps) {
    if (owner !== null) {
        return <OwnedStatus owner={owner} marketValue={marketValue} />;
    }

    if (marketListing !== null) {
        return <MarketListingStatus marketListing={marketListing} />;
    }

    return (
        <div className="border border-hq-border-strong px-3.5 py-[22px] text-center">
            <UserX
                aria-hidden="true"
                className="mx-auto size-[22px] text-hq-moss-dim"
            />
            <p className={cn(LABEL_CLASS, 'mt-1.5 text-hq-moss')}>Libre</p>
            <p className="mt-1 font-mono text-[11.5px] text-hq-moss-dim">
                sin manager fantasy
            </p>
        </div>
    );
}

function OwnedStatus({
    owner,
    marketValue,
}: {
    owner: PlayerOwnership;
    marketValue: number;
}) {
    const now = useNow();
    const status = resolveClauseStatus(
        owner.shielded,
        owner.buyout_clause_locked_until,
        now,
    );
    const tint = owner.season_manager.primary_color
        ? managerColor(owner.season_manager.primary_color)
        : 'transparent';

    return (
        <div
            className="border border-hq-border-strong bg-[linear-gradient(135deg,color-mix(in_srgb,var(--prop-tint)_18%,transparent),transparent_70%)] p-3.5"
            style={{ '--prop-tint': tint } as CSSProperties}
        >
            <p className={cn(LABEL_CLASS, 'text-hq-moss-dim')}>Propiedad</p>
            <Link
                href={seasonManagersShow(owner.season_manager.id).url}
                className="mt-2.5 mb-3 flex min-h-11 min-w-0 items-center gap-2.5 hover:opacity-80 sm:min-h-0"
            >
                <EntityImage
                    src={owner.season_manager.logo}
                    alt=""
                    fallback={Shield}
                    shape="square"
                    className="size-[34px] rounded-none"
                />
                <span className="truncate text-[15px] leading-tight font-bold text-hq-paper">
                    {owner.season_manager.name}
                </span>
            </Link>

            {status === 'shielded' ? (
                <LockStatus
                    icon={<ShieldCheck className="size-3.5" />}
                    label="Blindado"
                    frameClass="border-hq-def bg-hq-def/10"
                    labelClass="text-hq-def"
                    countdownClass="text-hq-paper"
                    targetIso={owner.shielded_until}
                    now={now}
                >
                    <ClauseDifference
                        clause={owner.buyout_clause}
                        marketValue={marketValue}
                        className="mt-1.5 text-[11.5px] leading-tight"
                    />
                </LockStatus>
            ) : status === 'locked' ? (
                <LockStatus
                    icon={<Lock className="size-3.5" />}
                    label="Cláusula bloqueada"
                    frameClass="border-hq-border-bright bg-hq-moss/10"
                    labelClass="text-hq-moss"
                    countdownClass="text-hq-gold"
                    targetIso={owner.buyout_clause_locked_until}
                    now={now}
                >
                    <ClauseDifference
                        clause={owner.buyout_clause}
                        marketValue={marketValue}
                        className="mt-1.5 text-[11.5px] leading-tight"
                    />
                </LockStatus>
            ) : (
                <div className="border border-hq-lime bg-hq-lime/10 px-[11px] py-[9px]">
                    <div className="flex items-center gap-1.5 font-mono text-[10.5px] leading-none font-bold tracking-[0.05em] text-hq-lime uppercase">
                        <LockOpen
                            aria-hidden="true"
                            className="size-3.5 rotate-12"
                        />
                        Cláusula abierta
                    </div>
                    <p className="mt-[7px] font-mono text-sm leading-none font-bold whitespace-nowrap text-hq-paper tabular-nums">
                        {formatCurrency(owner.buyout_clause)}{' '}
                        {owner.buyout_clause !== marketValue && (
                            <span className="text-[11px] text-hq-khaki">
                                (+
                                {formatCurrency(
                                    owner.buyout_clause - marketValue,
                                )}
                                )
                            </span>
                        )}
                    </p>
                </div>
            )}
        </div>
    );
}

function MarketListingStatus({
    marketListing,
}: {
    marketListing: PlayerFichaMarketListing;
}) {
    const countdown = useCountdown(marketListing.expires_at);

    return (
        <div className="border border-hq-border-strong p-3.5 text-center">
            <div className="flex items-center justify-between gap-2">
                <p className={cn(LABEL_CLASS, 'text-hq-moss-dim')}>
                    En el mercado
                </p>
                {marketListing.bids > 0 && (
                    <span className="border border-hq-ember bg-hq-ember/10 px-1.5 py-[3px] font-mono text-[10px] leading-none font-bold text-hq-ember">
                        {marketListing.bids}{' '}
                        {marketListing.bids === 1 ? 'PUJA' : 'PUJAS'}
                    </span>
                )}
            </div>
            <HqTooltip
                label={`Cierra ${formatFullDateTime(marketListing.expires_at)}`}
                tone="gold"
                focusable
                className="mt-3 mb-2.5 flex w-full justify-center"
            >
                <HqLed tone="gold" className="text-[30px]">
                    {countdown}
                </HqLed>
            </HqTooltip>
            <div>
                <span className="inline-block bg-hq-khaki px-[9px] py-1.5 font-mono text-sm leading-none font-bold whitespace-nowrap text-[#16140c]">
                    {formatCurrency(marketListing.sale_price)}
                </span>
            </div>
            <p className={cn(LABEL_CLASS, 'mt-2.5 text-hq-moss-dim')}>
                Precio de salida · valor {formatCurrency(marketListing.value)}
            </p>
        </div>
    );
}

function LockStatus({
    icon,
    label,
    frameClass,
    labelClass,
    countdownClass,
    targetIso,
    now,
    children,
}: {
    icon: ReactNode;
    label: string;
    frameClass: string;
    labelClass: string;
    countdownClass: string;
    targetIso: string | null;
    now: number;
    children: ReactNode;
}) {
    const countdown = useLockCountdown(targetIso, now);

    return (
        <div className={cn('border px-[11px] py-[9px]', frameClass)}>
            <div
                className={cn(
                    'flex items-center gap-1.5 font-mono text-[10.5px] leading-none font-bold tracking-[0.05em] uppercase',
                    labelClass,
                )}
            >
                {icon}
                {label}
            </div>
            <p
                className={cn(
                    'mt-[7px] font-mono text-sm leading-none font-semibold tabular-nums',
                    countdownClass,
                )}
            >
                {targetIso !== null ? (
                    <HqTooltip label={formatFullDateTime(targetIso)} focusable>
                        {countdown}
                    </HqTooltip>
                ) : (
                    countdown
                )}
            </p>
            {children}
        </div>
    );
}
