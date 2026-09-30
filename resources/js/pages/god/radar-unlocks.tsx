import { Link, router } from '@inertiajs/react';
import { Lock, LockOpen, Shield, ShieldCheck, Tag, Timer } from 'lucide-react';
import { useCallback, useState } from 'react';
import { HqCountdown } from '@/components/hq-countdown';
import { HqPositionTag } from '@/components/hq-position-tag';
import { formatMillions } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ManagerSquare } from '@/pages/god/radar-helpers';
import { show as playersShow } from '@/routes/players';
import type { RadarClause, RadarManager } from '@/types/models';

const RELOAD_COOLDOWN_MS = 5_000;
const reloadedMoments = new Set<string>();
let lastReloadAt = 0;
let trailingReload: ReturnType<typeof setTimeout> | null = null;

function reloadRadarProps(): void {
    lastReloadAt = Date.now();
    router.reload({ only: ['clauses', 'market', 'managers', 'now'] });
}

/**
 * Reload the radar once per elapsed moment: countdowns of the same moment
 * (the table and the sidebar, or clauses that open together) share one
 * reload. A different moment inside the cooldown schedules one trailing
 * reload instead of being swallowed; it is dropped if the page changed.
 */
function reloadRadarFor(moment: string): void {
    if (reloadedMoments.has(moment)) {
        return;
    }

    reloadedMoments.add(moment);
    const sinceLastReload = Date.now() - lastReloadAt;

    if (sinceLastReload >= RELOAD_COOLDOWN_MS) {
        reloadRadarProps();

        return;
    }

    if (trailingReload !== null) {
        return;
    }

    const pathname = window.location.pathname;

    trailingReload = setTimeout(() => {
        trailingReload = null;

        if (window.location.pathname === pathname) {
            reloadRadarProps();
        }
    }, RELOAD_COOLDOWN_MS - sinceLastReload);
}

/**
 * A live countdown that reloads the radar props when it reaches zero — but
 * only when the moment was still ahead on mount. A target already past (the
 * browser clock ahead of the server's) would otherwise fire on every reload
 * and loop. Key it by `target` so a new moment gets a fresh check.
 */
export function ReloadingCountdown({ target }: { target: string }) {
    const [isAheadOnMount] = useState(
        () => new Date(target).getTime() > Date.now(),
    );
    const reload = useCallback(() => reloadRadarFor(target), [target]);

    return (
        <HqCountdown
            target={target}
            onElapsed={isAheadOnMount ? reload : undefined}
        />
    );
}

const BADGE_CLASS =
    'inline-flex h-[22px] items-center gap-1.5 border px-1.5 font-mono text-[11px] leading-none font-bold whitespace-nowrap';

/** Clause state as in the comparator: LockOpen lime, Lock gold + countdown, ShieldCheck azure + countdown, Tag lime. */
export function ClauseStateBadge({ clause }: { clause: RadarClause }) {
    if (clause.state === 'open') {
        return (
            <span
                className={cn(
                    BADGE_CLASS,
                    'border-hq-lime/40 bg-hq-lime/[0.07] text-hq-lime',
                )}
            >
                <LockOpen aria-hidden="true" className="size-3" />
                Abierta
            </span>
        );
    }

    if (clause.state === 'listed') {
        return (
            <span
                className={cn(BADGE_CLASS, 'border-hq-lime/40 text-hq-lime')}
                title="En venta: no se puede pagar la cláusula"
            >
                <Tag aria-hidden="true" className="size-3" />
                En venta
            </span>
        );
    }

    if (clause.state === 'shielded' && clause.shielded_until) {
        return (
            <span
                className={cn(BADGE_CLASS, 'border-hq-azure/40 text-hq-azure')}
                title="Blindado"
            >
                <ShieldCheck aria-hidden="true" className="size-3" />
                <ReloadingCountdown
                    key={clause.shielded_until}
                    target={clause.shielded_until}
                />
            </span>
        );
    }

    return (
        <span
            className={cn(BADGE_CLASS, 'border-hq-gold/40 text-hq-gold')}
            title="Bloqueada"
        >
            <Lock aria-hidden="true" className="size-3" />
            <ReloadingCountdown
                key={clause.locked_until}
                target={clause.locked_until}
            />
        </span>
    );
}

const ROW_CLASS =
    'grid cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 border-t border-hq-border px-3.5 py-1.5 hover:bg-hq-panel';

/** Sidebar: the next 10 locked clauses to open, and the shielded ones, with live countdowns. */
export function RadarUnlocks({
    clauses,
    managers,
}: {
    clauses: RadarClause[];
    managers: RadarManager[];
}) {
    const byId = new Map(managers.map((manager) => [manager.id, manager]));
    const locked = clauses
        .filter((clause) => clause.state === 'locked')
        .sort((a, b) => a.locked_until.localeCompare(b.locked_until))
        .slice(0, 10);
    const shielded = clauses.filter((clause) => clause.state === 'shielded');

    return (
        <aside
            aria-labelledby="radar-unlocks"
            className="border-t border-hq-border-strong xl:border-t-0 xl:border-l"
        >
            <h2
                id="radar-unlocks"
                className="flex items-center gap-1.5 px-3.5 pt-3 pb-2 text-[15px] font-black uppercase"
            >
                <Timer aria-hidden="true" className="size-4 text-hq-moss" />
                Se abren
            </h2>
            {locked.length === 0 && (
                <p className="px-3.5 pb-3.5 text-[13px] text-hq-moss">
                    Ninguna bloqueada.
                </p>
            )}
            {locked.map((clause) => {
                const owner = byId.get(clause.owner_id);

                return (
                    <Link
                        key={clause.player.id}
                        href={playersShow(clause.player.id)}
                        title={`Abrir ficha de ${clause.player.nickname}`}
                        className={ROW_CLASS}
                    >
                        <HqPositionTag position={clause.player.position} />
                        <span className="min-w-0">
                            <b className="block truncate text-[13px] font-extrabold">
                                {clause.player.nickname}
                            </b>
                            <small className="flex items-center gap-1.5 font-mono text-[11px] text-hq-moss-dim">
                                {owner && <ManagerSquare manager={owner} />}
                                <span className="truncate">
                                    {owner?.name} ·{' '}
                                    {formatMillions(clause.amount)}
                                </span>
                            </small>
                        </span>
                        <ClauseStateBadge clause={clause} />
                    </Link>
                );
            })}
            <h2 className="mt-1.5 flex items-center gap-1.5 px-3.5 pt-3 pb-2 text-[15px] font-black uppercase">
                <ShieldCheck
                    aria-hidden="true"
                    className="size-4 text-hq-moss"
                />
                Blindados
            </h2>
            {shielded.length === 0 ? (
                <p className="flex items-center gap-1.5 px-3.5 pb-3.5 text-[13px] text-hq-moss">
                    <Shield
                        aria-hidden="true"
                        className="size-3.5 text-hq-moss-dim"
                    />
                    Ninguno ahora.
                </p>
            ) : (
                shielded.map((clause) => (
                    <Link
                        key={clause.player.id}
                        href={playersShow(clause.player.id)}
                        title={`Abrir ficha de ${clause.player.nickname}`}
                        className={ROW_CLASS}
                    >
                        <HqPositionTag position={clause.player.position} />
                        <b className="truncate text-[13px] font-extrabold">
                            {clause.player.nickname}
                        </b>
                        <ClauseStateBadge clause={clause} />
                    </Link>
                ))
            )}
        </aside>
    );
}
