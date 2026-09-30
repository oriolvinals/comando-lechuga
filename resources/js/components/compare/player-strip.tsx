import { Link } from '@inertiajs/react';
import {
    Lock,
    LockOpen,
    Plus,
    Repeat2,
    Shield,
    ShieldCheck,
    Tag,
    User,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useCompare } from '@/components/compare/compare-context';
import { COMPARE_GRID } from '@/components/compare/compare-grid';
import type { AcquireKind } from '@/components/compare/derive';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { COMPARE_MAX, COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { formatMillions } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';

/** The cue beside the value: how he could be had today. */
const ACQUIRE_CUES: Record<
    AcquireKind,
    { label: string; icon: LucideIcon | null; className: string }
> = {
    market: { label: 'en mercado', icon: Tag, className: 'text-hq-lime' },
    clause: {
        label: 'cláusula abierta',
        icon: LockOpen,
        className: 'text-hq-lime',
    },
    locked: {
        label: 'cláusula bloqueada',
        icon: Lock,
        className: 'text-hq-gold',
    },
    shielded: {
        label: 'blindado',
        icon: ShieldCheck,
        className: 'text-hq-azure',
    },
    owned: { label: 'con dueño', icon: null, className: 'text-hq-moss' },
    free: { label: 'libre', icon: null, className: 'text-hq-moss-dim' },
};

/** Whether the sentinel above the strip has scrolled under the shell header. */
function useStuck() {
    const sentinelRef = useRef<HTMLDivElement>(null);
    const [stuck, setStuck] = useState(false);

    useEffect(() => {
        const sentinel = sentinelRef.current;

        if (!sentinel) {
            return;
        }

        const offset =
            parseFloat(
                getComputedStyle(document.documentElement).getPropertyValue(
                    '--hq-header-h',
                ),
            ) || 52;
        const observer = new IntersectionObserver(
            ([entry]) =>
                setStuck(
                    !entry.isIntersecting &&
                        entry.boundingClientRect.top < offset + 1,
                ),
            { rootMargin: `-${offset + 1}px 0px 0px 0px` },
        );

        observer.observe(sentinel);

        return () => observer.disconnect();
    }, []);

    return { sentinelRef, stuck };
}

/**
 * The compared players, one card per slot column (strip variant 1): slot
 * colour on top, photo, name, position, team, status and value with how he
 * could be had, plus replace / remove and a dashed "Añadir" slot. It sticks
 * under the shell header and compacts once stuck; hovering a card lights
 * that player on the whole page.
 */
export function ComparePlayerStrip() {
    const { players, derived, remove, openPicker, bindSlot } = useCompare();
    const { sentinelRef, stuck } = useStuck();
    const hasRoom = players.length < COMPARE_MAX;

    return (
        <>
            <div ref={sentinelRef} aria-hidden="true" className="h-px" />
            <div
                data-stuck={stuck || undefined}
                className={cn(
                    COMPARE_GRID,
                    'group sticky top-(--hq-header-h) z-20 -mt-px border-b border-hq-border-strong bg-hq-ink/96 backdrop-blur-[6px]',
                )}
            >
                <div className="hidden flex-col justify-end gap-1 px-4 py-3 sm:flex">
                    <span className="hq-label">
                        {players.length} de {COMPARE_MAX} jugadores
                    </span>
                    {hasRoom && (
                        <span className="hq-label tracking-normal text-hq-led-off normal-case group-data-stuck:hidden">
                            pulsa / para añadir
                        </span>
                    )}
                </div>
                {players.map((player, index) => {
                    const cue = ACQUIRE_CUES[derived[index].acquire.kind];

                    return (
                        <div
                            key={player.id}
                            data-slot={index}
                            {...bindSlot(index)}
                            className="relative flex min-w-0 flex-col gap-2 border-t-[3px] border-l border-l-hq-border p-2.5 transition-[padding] group-data-stuck:py-2 motion-reduce:transition-none max-sm:nth-2:border-l-0 sm:px-4 sm:py-3"
                            style={{
                                borderTopColor: COMPARE_SLOT_COLORS[index],
                            }}
                        >
                            <div className="absolute top-0.5 right-0.5 flex sm:top-1 sm:right-1">
                                <button
                                    type="button"
                                    data-replace={index}
                                    onClick={() => openPicker(index)}
                                    aria-label={`Cambiar a ${player.name}`}
                                    className="flex size-9 cursor-pointer items-center justify-center text-hq-moss-dim transition-colors hover:bg-hq-panel-alt hover:text-hq-paper max-sm:group-data-stuck:hidden sm:size-8"
                                >
                                    <Repeat2
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => remove(index)}
                                    aria-label={`Quitar a ${player.name}`}
                                    className="flex size-9 cursor-pointer items-center justify-center text-hq-moss-dim transition-colors hover:bg-hq-panel-alt hover:text-hq-live sm:size-8"
                                >
                                    <X
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                </button>
                            </div>
                            <div className="flex min-w-0 flex-col items-start gap-2 pt-7 max-sm:group-data-stuck:flex-row max-sm:group-data-stuck:items-center max-sm:group-data-stuck:pt-0 max-sm:group-data-stuck:pr-10 sm:flex-row sm:items-center sm:gap-3 sm:pt-0 sm:pr-16">
                                <EntityImage
                                    src={player.image}
                                    alt=""
                                    fallback={User}
                                    shape="square"
                                    className="size-10 shrink-0 rounded-none border border-hq-border-bright bg-hq-panel-alt object-cover object-top transition-[width,height] group-data-stuck:size-9 motion-reduce:transition-none max-sm:group-data-stuck:hidden sm:size-14"
                                />
                                <div className="min-w-0">
                                    <h2 className="line-clamp-2 text-[13px] leading-[1.05] font-black [overflow-wrap:anywhere] text-hq-paper uppercase max-sm:group-data-stuck:line-clamp-1 max-sm:group-data-stuck:text-xs sm:text-lg sm:group-data-stuck:text-base lg:text-xl">
                                        <Link
                                            href={playersShow(player.id).url}
                                            className="cursor-pointer hover:text-hq-lime"
                                        >
                                            {player.name}
                                        </Link>
                                    </h2>
                                    <div className="mt-1.5 flex flex-wrap items-center gap-1.5 font-mono text-[11px] text-hq-moss group-data-stuck:hidden">
                                        <HqPositionTag
                                            position={player.position}
                                        />
                                        <EntityImage
                                            src={player.team.logo}
                                            alt=""
                                            fallback={Shield}
                                            shape="square"
                                            className="size-3.5 rounded-none bg-transparent object-contain"
                                        />
                                        <span className="max-sm:sr-only">
                                            {player.team.short_name}
                                        </span>
                                        <HqStatusBadge status={player.status} />
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1 font-mono text-[11px] text-hq-moss-dim group-data-stuck:hidden">
                                <b className="text-[12.5px] text-hq-paper tabular-nums">
                                    {formatMillions(player.value)}
                                </b>
                                <span
                                    className={cn(
                                        'inline-flex items-center gap-1 max-sm:hidden',
                                        cue.className,
                                    )}
                                >
                                    {cue.icon && (
                                        <cue.icon
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                    )}
                                    {cue.label}
                                </span>
                            </div>
                        </div>
                    );
                })}
                {hasRoom && (
                    <div className="flex border-l border-hq-border p-2">
                        <button
                            type="button"
                            data-add=""
                            onClick={() => openPicker(null)}
                            className="flex flex-1 cursor-pointer flex-col items-center justify-center gap-1 border border-dashed border-hq-border-strong p-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase transition-colors group-data-stuck:flex-row group-data-stuck:p-2 hover:border-hq-lime hover:text-hq-lime"
                        >
                            <Plus aria-hidden="true" className="size-4" />
                            <span>
                                Añadir
                                <span className="max-sm:sr-only"> jugador</span>
                            </span>
                            <small className="font-normal tracking-normal text-hq-moss-dim normal-case group-data-stuck:hidden">
                                busca o pulsa /
                            </small>
                        </button>
                    </div>
                )}
            </div>
        </>
    );
}
