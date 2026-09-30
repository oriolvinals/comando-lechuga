import { Link } from '@inertiajs/react';
import { Lock, LockOpen, Shield, ShieldCheck, Tag, UserX } from 'lucide-react';
import type { CSSProperties } from 'react';
import type { DerivedPlayer } from '@/components/compare/derive';
import { formatCountdown, formatLockLeft } from '@/components/compare/derive';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency, formatMillions } from '@/lib/format';
import { managerColor } from '@/lib/season-manager-colors';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { ComparedPlayer } from '@/types/models';

const CLAUSE_DISPLAY = {
    open: {
        icon: LockOpen,
        label: 'Cláusula abierta',
        short: 'Abierta',
        className: 'text-hq-lime border-hq-lime/40',
    },
    locked: {
        icon: Lock,
        label: 'Bloqueada',
        short: 'Bloqueada',
        className: 'text-hq-gold border-hq-gold/40',
    },
    shielded: {
        icon: ShieldCheck,
        label: 'Blindado',
        short: 'Blindado',
        className: 'text-hq-azure border-hq-azure/40',
    },
} as const;

export function CompareProperty({
    player,
    derived,
}: {
    player: ComparedPlayer;
    derived: DerivedPlayer;
}) {
    const now = useNow(1000);

    if (player.listing) {
        return (
            <div className="@container flex flex-col gap-1.5">
                <span className="hq-label">
                    En el mercado
                    {player.listing.bids > 0 && (
                        <b className="text-hq-ember">
                            {' '}
                            · {player.listing.bids}{' '}
                            {player.listing.bids === 1 ? 'puja' : 'pujas'}
                        </b>
                    )}
                </span>
                <span className="font-mono text-sm font-bold text-hq-gold tabular-nums">
                    {formatCountdown(player.listing.expires_at, now)}
                </span>
                <b className="font-mono text-sm text-hq-paper tabular-nums">
                    <span className="@max-[220px]:hidden">
                        {formatCurrency(player.listing.sale_price)}
                    </span>
                    <span className="hidden @max-[220px]:inline">
                        {formatMillions(player.listing.sale_price)}
                    </span>
                </b>
            </div>
        );
    }

    if (player.owner) {
        const display = derived.clauseState
            ? CLAUSE_DISPLAY[derived.clauseState]
            : null;
        const time = derived.acquire.until
            ? formatLockLeft(derived.acquire.until, now)
            : null;

        return (
            <div
                className="@container flex flex-col gap-2"
                style={
                    {
                        '--tint': managerColor(player.owner.color),
                    } as CSSProperties
                }
            >
                <Link
                    href={seasonManagersShow(player.owner.id).url}
                    className="inline-flex min-w-0 cursor-pointer items-center gap-2 border-l-2 border-(--tint) pl-2 font-mono text-xs font-bold text-hq-khaki hover:text-hq-paper"
                >
                    <EntityImage
                        src={player.owner.logo}
                        alt=""
                        fallback={Shield}
                        shape="square"
                        className="size-5 rounded-none"
                    />
                    <span className="truncate">{player.owner.name}</span>
                </Link>
                {display && player.clause && (
                    <div
                        className={cn(
                            'flex flex-col gap-1 border px-2 py-1.5',
                            display.className,
                        )}
                    >
                        <span className="flex items-center gap-1.5 font-mono text-[10.5px] font-bold tracking-[0.04em] uppercase">
                            <display.icon
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {display.label}
                            {time && (
                                <span className="ml-auto whitespace-nowrap">
                                    {time}
                                </span>
                            )}
                        </span>
                        <b className="font-mono text-sm text-hq-paper tabular-nums">
                            <span className="@max-[220px]:hidden">
                                {formatCurrency(player.clause.amount)}
                            </span>
                            <span className="hidden @max-[220px]:inline">
                                {formatMillions(player.clause.amount)}
                            </span>
                        </b>
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="flex items-center gap-2 font-mono text-xs text-hq-moss">
            <UserX aria-hidden="true" className="size-4" />
            <span>Libre</span>
            <small className="text-hq-moss-dim">sin manager fantasy</small>
        </div>
    );
}

export function ComparePropertyLine({
    player,
    derived,
}: {
    player: ComparedPlayer;
    derived: DerivedPlayer;
}) {
    const now = useNow(1000);
    const row =
        'grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-2.5 font-mono text-xs';

    if (player.listing) {
        return (
            <div className={row}>
                <span className="flex min-w-0 items-center gap-1.5 truncate text-hq-paper">
                    <Tag aria-hidden="true" className="size-3.5" />
                    Mercado
                    {player.listing.bids > 0 &&
                        ` · ${player.listing.bids} ${player.listing.bids === 1 ? 'puja' : 'pujas'}`}
                </span>
                <span className="whitespace-nowrap text-hq-gold tabular-nums">
                    {formatCountdown(player.listing.expires_at, now)}
                </span>
                <b className="text-hq-paper tabular-nums">
                    {formatMillions(player.listing.sale_price)}
                </b>
            </div>
        );
    }

    if (player.owner) {
        const display = derived.clauseState
            ? CLAUSE_DISPLAY[derived.clauseState]
            : null;
        const time = derived.acquire.until
            ? formatLockLeft(derived.acquire.until, now)
            : null;

        return (
            <div
                className={row}
                style={
                    {
                        '--tint': managerColor(player.owner.color),
                    } as CSSProperties
                }
            >
                <span className="flex min-w-0 items-center gap-1.5 border-l-2 border-(--tint) pl-1.5 text-hq-paper">
                    <EntityImage
                        src={player.owner.logo}
                        alt=""
                        fallback={Shield}
                        shape="square"
                        className="size-4 rounded-none"
                    />
                    <span className="truncate">{player.owner.name}</span>
                </span>
                {display && player.clause ? (
                    <>
                        <span
                            className={cn(
                                'flex items-center gap-1 whitespace-nowrap',
                                display.className,
                            )}
                            title={display.label}
                        >
                            <display.icon
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {time ?? display.short}
                        </span>
                        <b className="text-hq-paper tabular-nums">
                            {formatMillions(player.clause.amount)}
                        </b>
                    </>
                ) : (
                    <>
                        <span />
                        <span />
                    </>
                )}
            </div>
        );
    }

    return (
        <div className={cn(row, 'text-hq-moss-dim')}>
            <span className="flex items-center gap-1.5 text-hq-moss">
                <UserX aria-hidden="true" className="size-3.5" />
                Libre
            </span>
            <span>sin manager</span>
            <b>—</b>
        </div>
    );
}
