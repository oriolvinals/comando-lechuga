import type { CSSProperties } from 'react';
import { useState } from 'react';
import { useCompare } from '@/components/compare/compare-context';
import { SlotName } from '@/components/compare/compare-grid';
import type {
    VerdictEvidenceRow,
    VerdictLens,
    VerdictReason,
    VerdictTone,
} from '@/components/compare/derive';
import { verdict, winner } from '@/components/compare/derive';
import { COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { cn } from '@/lib/utils';

const LENSES: {
    key: VerdictLens;
    label: (week: number) => string;
    hint: string;
    stamp: string;
}[] = [
    {
        key: 'buy',
        label: () => 'Fichar',
        hint: 'a quién comprar',
        stamp: 'Fichar',
    },
    {
        key: 'sell',
        label: () => 'Vender',
        hint: 'de quién salir',
        stamp: 'Vender',
    },
    {
        key: 'start',
        label: (week) => `Alinear J${week}`,
        hint: 'quién juega',
        stamp: 'Alinear',
    },
];

const TONE_CLASSES: Record<VerdictTone, string> = {
    ok: 'bg-hq-lime',
    lock: 'bg-hq-amber',
    no: 'bg-hq-led-off',
    warn: 'bg-hq-amber',
};

/** Index of the cell to mark: the best of the row, or (Vender) its worst signal, the lowest value. */
function markedIndex(row: VerdictEvidenceRow): number | null {
    if (row.values === null || row.mark === null) {
        return null;
    }

    return row.mark === 'best'
        ? winner(row.values, row.lowerIsBetter)
        : winner(row.values, true);
}

function Reason({ reason }: { reason: VerdictReason }) {
    return (
        <>
            {reason.lead && (
                <b className="font-bold text-hq-paper">{reason.lead}</b>
            )}
            {reason.lead && reason.rest && ' · '}
            {reason.rest}
        </>
    );
}

/**
 * God mode only: which of the compared players to buy, sell or field
 * ("Fichas con sello"). Each card sits in its player's strip column and
 * shows only the stamp or place, the reason and the evidence; the evidence
 * labels live once, in the label column, and the cards borrow its rows
 * (subgrid) so they line up. On phones the cards stack, the pick first.
 */
export function CompareVerdict() {
    const { players, derived, currentWeek, now, bindSlot } = useCompare();
    const [lens, setLens] = useState<VerdictLens>('buy');
    const result = verdict(lens, players, derived, currentWeek, now);
    const top = result.recommended;
    const stamp = LENSES.find((item) => item.key === lens)?.stamp ?? '';
    const marks = result.rows.map(markedIndex);

    return (
        <section
            aria-labelledby="cmp-verdict-title"
            className="bg-hq-panel hq-god-frame"
        >
            <div className="flex flex-wrap items-center justify-between gap-2.5 border-b border-hq-border p-2.5 sm:px-[13px]">
                <h2 id="cmp-verdict-title" className="hq-label text-hq-lime">
                    Veredicto
                </h2>
                <div
                    role="group"
                    aria-label="Decisión"
                    className="flex border border-hq-border-strong max-sm:w-full"
                >
                    {LENSES.map((item) => {
                        const pressed = lens === item.key;

                        return (
                            <button
                                key={item.key}
                                type="button"
                                aria-pressed={pressed}
                                onClick={() => setLens(item.key)}
                                className={cn(
                                    'flex h-12 cursor-pointer flex-col items-start justify-center gap-[3px] px-2 text-left transition-colors not-last:border-r not-last:border-hq-border-strong max-sm:flex-1 sm:h-[42px] sm:px-3.5',
                                    pressed
                                        ? 'bg-hq-lime'
                                        : 'group hover:bg-hq-panel-alt',
                                )}
                            >
                                <b
                                    className={cn(
                                        'font-mono text-[11px] leading-none font-bold tracking-[0.06em] uppercase',
                                        pressed
                                            ? 'text-hq-ink'
                                            : 'text-hq-moss group-hover:text-hq-paper',
                                    )}
                                >
                                    {item.label(currentWeek)}
                                </b>
                                <small
                                    className={cn(
                                        'font-mono text-[10px] leading-none',
                                        pressed
                                            ? 'text-hq-ink'
                                            : 'text-hq-moss-dim',
                                    )}
                                >
                                    {item.hint}
                                </small>
                            </button>
                        );
                    })}
                </div>
            </div>

            <div
                className="flex flex-col gap-2.5 p-2.5 sm:grid sm:grid-cols-[minmax(125px,177px)_repeat(var(--cols),minmax(0,1fr))] sm:gap-0 sm:px-0 sm:pt-2.5 sm:pb-3"
                style={{
                    gridTemplateRows: `auto repeat(${result.rows.length}, auto)`,
                }}
            >
                <div
                    className="hidden flex-col gap-1 px-[13px] pt-3 pb-1.5 sm:flex"
                    style={{ gridColumn: 1, gridRow: 1 }}
                >
                    <span className="hq-label text-hq-paper">
                        {result.title}
                    </span>
                    <small className="font-mono text-[10.5px] leading-[1.3] text-hq-moss-dim">
                        {lens === 'sell'
                            ? 'en rojo, la peor señal de cada dato'
                            : 'mejor de cada dato en lima'}
                    </small>
                </div>
                {result.rows.map((row, rowIndex) => (
                    <div
                        key={row.label}
                        aria-hidden="true"
                        className="hidden flex-col justify-center gap-0.5 border-b border-hq-border px-[13px] py-1.5 sm:flex"
                        style={{ gridColumn: 1, gridRow: rowIndex + 2 }}
                    >
                        <b className="text-[10.5px] leading-[1.3] font-extrabold text-hq-moss uppercase">
                            {row.label}
                        </b>
                        {row.hint && (
                            <small className="font-mono text-[10.5px] leading-[1.3] text-hq-moss-dim">
                                {row.hint}
                            </small>
                        )}
                    </div>
                ))}

                {players.map((player, index) => {
                    const isPick = index === top;
                    const isOut = result.out[index];
                    const place = result.order.indexOf(index) + 1;

                    return (
                        <article
                            key={player.id}
                            data-slot={index}
                            {...bindSlot(index)}
                            aria-label={player.name}
                            className={cn(
                                'flex flex-col border border-t-2 border-hq-border border-t-(--slot) bg-hq-ink px-3 sm:row-span-full sm:mx-[5px] sm:grid sm:grid-rows-subgrid',
                                isPick &&
                                    'border-hq-lime border-t-(--slot) bg-hq-panel-alt max-sm:order-first',
                                isOut && 'opacity-60',
                            )}
                            style={
                                {
                                    gridColumn: index + 2,
                                    '--slot': COMPARE_SLOT_COLORS[index],
                                } as CSSProperties
                            }
                        >
                            <div className="flex flex-col gap-1.5 py-2.5">
                                <span className="flex items-center justify-between gap-2 sm:contents">
                                    <SlotName
                                        slot={index}
                                        name={player.name}
                                        className="text-sm text-hq-paper sm:sr-only"
                                    />
                                    {isPick ? (
                                        <span
                                            className={cn(
                                                'self-start px-1.5 py-1 font-mono text-[10px] leading-none font-bold tracking-[0.08em] whitespace-nowrap text-hq-ink uppercase',
                                                lens === 'sell'
                                                    ? 'bg-hq-live'
                                                    : 'bg-hq-lime',
                                            )}
                                        >
                                            {stamp}
                                        </span>
                                    ) : (
                                        <span className="self-start border border-hq-border-strong px-1.5 py-1 font-mono text-[11px] leading-none font-bold whitespace-nowrap text-hq-moss-dim">
                                            {isOut
                                                ? 'Descartado'
                                                : `${place}.º`}
                                        </span>
                                    )}
                                </span>
                                <p
                                    className={cn(
                                        'm-0 font-mono text-xs leading-[1.4] text-hq-moss',
                                        isPick && '[&_b]:text-[13px]',
                                    )}
                                >
                                    <Reason reason={result.reasons[index]} />
                                </p>
                            </div>
                            {result.rows.map((row, rowIndex) => {
                                const text = row.texts[index];
                                const tone = row.tones?.[index];
                                const marked = marks[rowIndex] === index;

                                return (
                                    <div
                                        key={row.label}
                                        className={cn(
                                            'flex items-center justify-between gap-2 border-b border-hq-border py-1.5 font-mono text-[12.5px] leading-[1.3] text-hq-paper tabular-nums last:border-b-0',
                                            tone &&
                                                'max-sm:flex-col max-sm:items-start max-sm:gap-1',
                                        )}
                                    >
                                        <span className="font-sans text-[10.5px] leading-[1.3] font-extrabold text-hq-moss uppercase sm:sr-only">
                                            {row.label}
                                        </span>
                                        {tone ? (
                                            <span className="inline-flex items-center gap-1.5 text-[11.5px]">
                                                <i
                                                    aria-hidden="true"
                                                    className={cn(
                                                        'size-[7px] shrink-0',
                                                        TONE_CLASSES[tone],
                                                    )}
                                                />
                                                {text}
                                            </span>
                                        ) : (
                                            <span>
                                                {text}
                                                {marked && (
                                                    <em
                                                        className={cn(
                                                            'ml-1.5 px-1 py-0.5 align-[1px] font-mono text-[9.5px] leading-none font-bold tracking-[0.06em] text-hq-ink uppercase not-italic',
                                                            row.mark === 'worst'
                                                                ? 'bg-hq-live'
                                                                : 'bg-hq-lime',
                                                        )}
                                                    >
                                                        {row.mark === 'worst'
                                                            ? 'peor'
                                                            : 'mejor'}
                                                    </em>
                                                )}
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </article>
                    );
                })}
            </div>
        </section>
    );
}
