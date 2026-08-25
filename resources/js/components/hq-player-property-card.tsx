import { Link } from '@inertiajs/react';
import { Lock, Shield, ShieldCheck, UserX } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { resolveClauseStatus } from '@/lib/clause-status';
import { formatCurrency } from '@/lib/format';
import { cardTintStyle } from '@/lib/season-team-colors';
import { useCountdown } from '@/lib/use-countdown';
import { useLockCountdown } from '@/lib/use-lock-countdown';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as seasonTeamsShow } from '@/routes/season-teams';
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
}: {
    clause: number;
    marketValue: number;
    valueColorClass?: string;
}) {
    if (clause === marketValue) {
        return (
            <p className="mt-0.5 font-mono text-[10px] whitespace-nowrap text-hq-moss-dim">
                {formatCurrency(clause)} (=)
            </p>
        );
    }

    const diff = clause - marketValue;

    return (
        <p
            className={cn(
                'mt-0.5 font-mono text-[10px] whitespace-nowrap',
                valueColorClass,
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
        <div className="hq-card-cut p-4 text-center">
            <UserX className="mx-auto mb-1.5 h-5 w-5 text-hq-moss-dim" />
            <p className="font-mono text-[11px] font-bold tracking-wide text-hq-moss uppercase">
                Libre
            </p>
            <p className="mt-1 font-mono text-[10px] text-hq-moss-dim">
                sin equipo fantasy
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

    return (
        <div
            className="hq-card-cut p-4"
            style={
                cardTintStyle(owner.season_team.primary_color) as CSSProperties
            }
        >
            <p className="mb-2 font-mono text-[10px] tracking-wide text-hq-moss uppercase">
                Propiedad
            </p>
            <Link
                href={seasonTeamsShow(owner.season_team.id).url}
                className="mb-2.5 inline-flex items-center gap-2 hover:opacity-80"
            >
                <EntityImage
                    src={owner.season_team.logo}
                    alt={owner.season_team.name}
                    fallback={Shield}
                    shape="square"
                    className="h-7 w-7"
                />
                <span className="text-sm font-bold text-hq-paper">
                    {owner.season_team.name}
                </span>
            </Link>

            {status === 'shielded' ? (
                <LockStatus
                    icon={<ShieldCheck className="h-[13px] w-[13px]" />}
                    label="Blindado"
                    colorClass="text-hq-def"
                    borderClass="border-hq-def"
                    bgClass="bg-hq-def/10"
                    targetIso={owner.buyout_clause_locked_until}
                    now={now}
                >
                    <ClauseDifference
                        clause={owner.buyout_clause}
                        marketValue={marketValue}
                    />
                </LockStatus>
            ) : status === 'locked' ? (
                <LockStatus
                    icon={<Lock className="h-[13px] w-[13px]" />}
                    label="Cláusula bloqueada"
                    colorClass="text-hq-moss"
                    borderClass="border-hq-border-strong"
                    bgClass="bg-hq-moss/10"
                    countdownColorClass="text-hq-gold"
                    targetIso={owner.buyout_clause_locked_until}
                    now={now}
                >
                    <ClauseDifference
                        clause={owner.buyout_clause}
                        marketValue={marketValue}
                    />
                </LockStatus>
            ) : (
                <div className="border border-hq-lime bg-hq-lime/10 px-2.5 py-2">
                    <div className="flex items-center gap-1.5 font-mono text-[10px] font-bold text-hq-lime uppercase">
                        <Lock className="h-[13px] w-[13px] rotate-45" />
                        Cláusula abierta
                    </div>
                    <p className="mt-0.5 font-mono text-xs font-bold whitespace-nowrap text-hq-paper">
                        {formatCurrency(owner.buyout_clause)}{' '}
                        {owner.buyout_clause !== marketValue && (
                            <span className="text-[10px] font-bold text-hq-khaki">
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
        <div className="hq-card-cut relative p-4 text-center">
            {marketListing.bids > 0 && (
                <span className="absolute top-2.5 right-2.5 border border-hq-ember bg-hq-ember/10 px-1.5 py-0.5 font-mono text-[10px] font-bold text-hq-ember">
                    {marketListing.bids}{' '}
                    {marketListing.bids === 1 ? 'PUJA' : 'PUJAS'}
                </span>
            )}
            <p className="mb-2 font-mono text-[10px] font-bold tracking-wide text-hq-moss uppercase">
                En el mercado
            </p>
            <p className="my-1 font-mono text-2xl font-bold text-hq-gold">
                {countdown}
            </p>
            <span className="hq-tag-cut inline-block bg-hq-khaki px-3 py-1.5 font-mono text-sm font-bold text-hq-ink">
                {formatCurrency(marketListing.sale_price)}
            </span>
        </div>
    );
}

function LockStatus({
    icon,
    label,
    colorClass,
    borderClass,
    bgClass,
    countdownColorClass = 'text-hq-paper',
    targetIso,
    now,
    children,
}: {
    icon: ReactNode;
    label: string;
    colorClass: string;
    borderClass: string;
    bgClass: string;
    countdownColorClass?: string;
    targetIso: string;
    now: number;
    children: ReactNode;
}) {
    const countdown = useLockCountdown(targetIso, now);

    return (
        <div className={cn('border px-2.5 py-2', borderClass, bgClass)}>
            <div
                className={cn(
                    'flex items-center gap-1.5 font-mono text-[10px] font-bold uppercase',
                    colorClass,
                )}
            >
                {icon}
                {label}
            </div>
            <p className={cn('mt-0.5 font-mono text-xs', countdownColorClass)}>
                {countdown}
            </p>
            {children}
        </div>
    );
}
