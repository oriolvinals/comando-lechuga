import { X } from 'lucide-react';
import { useEffect, useId, useRef } from 'react';
import { useJornadaSheet } from '@/components/hq-jornada-sheet';
import { HqLed } from '@/components/hq-led';
import { ManagerCrest } from '@/components/prizes/prize-crest';
import { You } from '@/components/prizes/prize-leader';
import { PrizePassport } from '@/components/prizes/prize-passport';
import { millions, restValue, shortManagerName } from '@/lib/prize-format';
import { cn } from '@/lib/utils';
import type {
    PrizeManager,
    PrizePlayer,
    PrizeRowData,
    PrizeStanding,
} from '@/types/prizes';

function getFocusableElements(container: HTMLElement): HTMLElement[] {
    return Array.from(
        container.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ).filter((element) => element.offsetParent !== null);
}

function jornadaList(weeks: number[] | undefined): string {
    return (weeks ?? []).map((week) => `J${week}`).join(' · ');
}

/** Everyone's own context line in the detail (the rows only show the leader's). */
function detailLine(
    prize: PrizeStanding,
    row: PrizeRowData,
    managers: Map<number, PrizeManager>,
    players: Record<string, PrizePlayer>,
): string {
    const context = row.context;
    const name = (id: number | undefined) =>
        id ? shortManagerName(managers.get(id)?.name ?? '') : '';

    switch (prize.key) {
        case 'best_night':
        case 'worst_night':
            return context.week_number ? `en la J${context.week_number}` : '';
        case 'sunday_king':
        case 'worst_weeks':
            return jornadaList(context.weeks);
        case 'most_buyouts_made':
            return context.favourite
                ? `Más a ${name(context.favourite.season_manager_id)} (×${context.favourite.count})`
                : '';
        case 'most_buyouts_suffered':
            return context.nemesis
                ? `Su verdugo: ${name(context.nemesis.season_manager_id)} (×${context.nemesis.count})`
                : '';
        case 'most_overpaid':
            return context.worst
                ? `El peor: ${players[context.worst.player_id]?.nickname ?? ''}, +${millions(context.worst.overpaid)} M€`
                : '';
        case 'longest_partnership':
            return context.player_id
                ? `${players[context.player_id]?.nickname ?? ''} · J${context.from_week}–J${context.to_week} · ${context.alive ? 'sigue' : 'racha rota'}`
                : '';
        case 'most_owned_player':
            // Which player the time is with, only when players are tied; «no lo tuvo» is already the value.
            return prize.candidates.length > 1 && context.player_id
                ? (players[context.player_id]?.nickname ?? '')
                : '';
        default:
            return '';
    }
}

/**
 * A prize's full ranking, always centred (also on phones). The Fichaje del
 * Pueblo adds one passport per tied player on top and each player's owner
 * order below; the Banquillo's «el que más dejó» opens that match's jornada
 * sheet. Esc (unless the jornada sheet is on top), the backdrop and the «×»
 * close it; focus is trapped inside and returned to the row that opened it.
 */
export function PrizeDetailDialog({
    prize,
    managers,
    players,
    viewer,
    onClose,
}: {
    prize: PrizeStanding | null;
    managers: Map<number, PrizeManager>;
    players: Record<string, PrizePlayer>;
    viewer: number | null;
    onClose: () => void;
}) {
    const titleId = useId();
    const dialogRef = useRef<HTMLDivElement>(null);
    const onCloseRef = useRef(onClose);
    const sheet = useJornadaSheet();
    const isOpen = prize !== null;

    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const opener =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        const dialog = dialogRef.current;
        (dialog ? getFocusableElements(dialog)[0] : undefined)?.focus();

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        const handleKeyDown = (event: KeyboardEvent) => {
            const target = event.target;

            // The jornada sheet opened from here sits on top and handles its own keys.
            if (
                dialog &&
                target instanceof Element &&
                !dialog.contains(target) &&
                target.closest('[role="dialog"]')
            ) {
                return;
            }

            if (event.key === 'Escape') {
                onCloseRef.current();

                return;
            }

            if (event.key !== 'Tab' || !dialog) {
                return;
            }

            const focusable = getFocusableElements(dialog);

            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (!dialog.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => {
            window.removeEventListener('keydown', handleKeyDown);
            document.body.style.overflow = previousOverflow;
            opener?.focus();
        };
    }, [isOpen]);

    if (!prize) {
        return null;
    }

    const isMostOwned = prize.key === 'most_owned_player';
    const ownerOrders = isMostOwned
        ? prize.candidates.map((candidate) => {
              const order = candidate.chain
                  .map((id) => shortManagerName(managers.get(id)?.name ?? ''))
                  .join(' › ');

              return {
                  playerId: candidate.player_id,
                  text: `Orden de ${players[candidate.player_id]?.nickname ?? ''}: ${order}${candidate.on_market ? ' › mercado' : ''}.`,
              };
          })
        : [];

    return (
        <div
            className="fixed inset-0 z-[150] grid cursor-pointer place-items-center bg-black/65 p-3"
            onClick={onClose}
        >
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                tabIndex={-1}
                onClick={(event) => event.stopPropagation()}
                className="max-h-[calc(100dvh-40px)] w-[min(520px,calc(100vw-24px))] cursor-default overflow-y-auto border border-hq-border-bright bg-hq-panel text-hq-paper shadow-[0_30px_80px_rgba(0,0,0,0.6)] outline-none"
            >
                <div className="flex items-start gap-3 border-b border-hq-border-strong px-4 py-3.5">
                    <div className="min-w-0">
                        <span
                            className={cn(
                                'font-mono text-[11.5px] font-bold',
                                prize.amount === 10
                                    ? 'text-hq-gold'
                                    : 'text-hq-khaki',
                            )}
                        >
                            {prize.amount} €
                        </span>
                        <h3
                            id={titleId}
                            className="mt-1.5 font-display text-[22px] leading-none uppercase"
                        >
                            {prize.name}
                        </h3>
                        <p className="mt-1.5 text-[12.5px] leading-snug text-hq-moss">
                            {prize.rule}
                        </p>
                    </div>
                    <button
                        type="button"
                        aria-label="Cerrar"
                        onClick={onClose}
                        className="ml-auto grid size-10 shrink-0 cursor-pointer place-items-center border border-hq-border-strong text-hq-moss transition-colors hover:border-hq-paper hover:text-hq-paper"
                    >
                        <X className="size-[18px]" aria-hidden="true" />
                    </button>
                </div>

                {isMostOwned && prize.candidates.length > 0 && (
                    <div
                        className={cn(
                            'grid gap-px border-b border-hq-border bg-hq-border',
                            prize.candidates.length > 1 && 'sm:grid-cols-2',
                        )}
                    >
                        {prize.candidates.map((candidate) => (
                            <PrizePassport
                                key={candidate.player_id}
                                candidate={candidate}
                                player={players[candidate.player_id]}
                                managers={managers}
                            />
                        ))}
                    </div>
                )}

                <ol className="py-1.5">
                    {prize.rows.map((row) => {
                        const manager = managers.get(row.season_manager_id);

                        if (!manager) {
                            return null;
                        }

                        const isLeader = prize.leaders.includes(
                            row.season_manager_id,
                        );
                        const line = detailLine(prize, row, managers, players);
                        const miss = row.context.top_miss;
                        const missPlayer = miss
                            ? players[miss.player_id]
                            : undefined;
                        const value = restValue(prize, row);

                        return (
                            <li
                                key={row.season_manager_id}
                                className={cn(
                                    'grid min-h-10 grid-cols-[18px_22px_minmax(0,1fr)_auto] items-center gap-[9px] px-4 py-[5px] [&+&]:border-t [&+&]:border-hq-border',
                                    isLeader && 'bg-hq-lime/[0.07]',
                                    row.season_manager_id === viewer &&
                                        'shadow-[inset_2px_0_0_var(--color-hq-paper)]',
                                )}
                            >
                                <HqLed
                                    tone={isLeader ? 'lime' : 'off'}
                                    className="text-right text-[15px]"
                                >
                                    {row.place ?? '–'}
                                </HqLed>
                                <ManagerCrest
                                    manager={manager}
                                    className={cn(
                                        'size-[22px]',
                                        row.value === null && 'opacity-45',
                                    )}
                                />
                                <span className="min-w-0 font-mono text-[12.5px] font-semibold">
                                    <span className="flex min-w-0 items-center">
                                        <span className="min-w-0 truncate">
                                            {manager.name}
                                        </span>
                                        {row.season_manager_id === viewer && (
                                            <You />
                                        )}
                                    </span>
                                    {line && (
                                        <small className="mt-[3px] block text-[11px] font-normal text-hq-moss">
                                            {line}
                                        </small>
                                    )}
                                    {miss && missPlayer && (
                                        <button
                                            type="button"
                                            aria-busy={
                                                sheet?.isLoading(
                                                    miss.player_id,
                                                    miss.fixture_id,
                                                ) || undefined
                                            }
                                            disabled={sheet === null}
                                            onClick={() =>
                                                sheet?.openMatch(
                                                    miss.player_id,
                                                    miss.fixture_id,
                                                )
                                            }
                                            className="mt-[3px] block cursor-pointer text-left text-[11px] font-normal text-hq-moss underline decoration-hq-border-bright underline-offset-2 transition-colors hover:text-hq-lime aria-busy:animate-hq-pulse"
                                        >
                                            El que más dejó:{' '}
                                            {missPlayer.nickname} · J
                                            {miss.week_number} · {miss.points}{' '}
                                            pts
                                        </button>
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'font-mono text-[13px] font-semibold whitespace-nowrap tabular-nums',
                                        isLeader && 'text-hq-lime',
                                        value.muted && 'text-hq-led-off',
                                    )}
                                >
                                    {value.text}
                                </span>
                            </li>
                        );
                    })}
                </ol>

                {ownerOrders.length > 0 && (
                    <div className="border-t border-hq-border px-4 pt-2.5 pb-3.5 font-mono text-[11.5px] leading-relaxed text-hq-moss">
                        {ownerOrders.map((order) => (
                            <p key={order.playerId}>{order.text}</p>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
