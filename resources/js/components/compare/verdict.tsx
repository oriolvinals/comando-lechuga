import { User } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useState } from 'react';
import { useCompare } from '@/components/compare/compare-context';
import type {
    VerdictEvidenceRow,
    VerdictLens,
    VerdictReason,
} from '@/components/compare/derive';
import { verdict, winner } from '@/components/compare/derive';
import { EntityImage } from '@/components/entity-image';
import { COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { cn } from '@/lib/utils';

const LENSES: { key: VerdictLens; label: (week: number) => string }[] = [
    { key: 'buy', label: () => 'Fichar' },
    { key: 'sell', label: () => 'Vender' },
    { key: 'start', label: (week) => `Alinear J${week}` },
];

const EVIDENCE_GRID =
    'grid min-w-[440px] grid-cols-[minmax(110px,160px)_repeat(var(--cols),minmax(0,1fr))] border-b border-hq-border';

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
            {reason.lead && <b className="text-hq-paper">{reason.lead}</b>}
            {reason.lead && reason.rest && ' · '}
            {reason.rest}
        </>
    );
}

/** God mode only: which of the compared players to buy, sell or field, with 4–5 rows of evidence (spec §5b). */
export function CompareVerdict() {
    const { players, derived, currentWeek, now } = useCompare();
    const [lens, setLens] = useState<VerdictLens>('buy');
    const result = verdict(lens, players, derived, currentWeek, now);
    const top = result.recommended;
    const columns = { '--cols': players.length } as CSSProperties;

    return (
        <section
            aria-labelledby="cmp-verdict-title"
            className="border-b border-hq-border-strong bg-hq-panel"
        >
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-hq-border px-3.5 py-2.5 sm:px-4">
                <h2 id="cmp-verdict-title" className="hq-label text-hq-lime">
                    Veredicto
                </h2>
                <div
                    role="tablist"
                    aria-label="Decisión"
                    className="inline-flex border border-hq-border-strong"
                >
                    {LENSES.map((item) => (
                        <button
                            key={item.key}
                            role="tab"
                            type="button"
                            aria-selected={lens === item.key}
                            onClick={() => setLens(item.key)}
                            className={cn(
                                'h-11 cursor-pointer px-3 font-mono text-[11px] font-bold tracking-[0.06em] uppercase transition-colors sm:h-8',
                                lens === item.key
                                    ? 'bg-hq-lime text-hq-ink'
                                    : 'text-hq-moss hover:text-hq-paper',
                            )}
                        >
                            {item.label(currentWeek)}
                        </button>
                    ))}
                </div>
            </div>

            <div className="grid gap-3.5 px-3.5 py-3.5 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] sm:px-4">
                <div
                    className="flex items-start gap-3 border-l-2 pl-3"
                    style={{
                        borderLeftColor:
                            top === null
                                ? 'var(--color-hq-border-strong)'
                                : COMPARE_SLOT_COLORS[top],
                    }}
                >
                    {top !== null && (
                        <EntityImage
                            src={players[top].image}
                            alt={players[top].name}
                            fallback={User}
                            shape="square"
                            className="size-12 shrink-0 rounded-none object-cover object-top"
                        />
                    )}
                    <div className="min-w-0">
                        <span className="hq-label">{result.title}</span>
                        <p className="m-0 mt-1 font-display text-2xl leading-none text-hq-paper uppercase">
                            {top === null ? 'Ninguno' : players[top].name}
                        </p>
                        {top !== null && (
                            <p className="m-0 mt-1.5 font-mono text-xs text-hq-moss">
                                <Reason reason={result.reasons[top]} />
                            </p>
                        )}
                    </div>
                </div>
                <ol className="m-0 flex list-none flex-col gap-2 p-0">
                    {result.order
                        .filter((index) => index !== top)
                        .map((index, position) => (
                            <li
                                key={players[index].id}
                                className={cn(
                                    'flex items-start gap-2.5 font-mono text-xs',
                                    result.out[index] && 'opacity-60',
                                )}
                                style={
                                    {
                                        '--slot': COMPARE_SLOT_COLORS[index],
                                    } as CSSProperties
                                }
                            >
                                <span className="w-6 shrink-0 text-hq-moss-dim">
                                    {top === null ? '–' : `${position + 2}.º`}
                                </span>
                                <span className="min-w-0">
                                    <b className="border-l-2 border-(--slot) pl-1.5 text-hq-paper uppercase">
                                        {players[index].name}
                                    </b>
                                    <span className="mt-0.5 block text-hq-moss">
                                        <Reason
                                            reason={result.reasons[index]}
                                        />
                                    </span>
                                </span>
                            </li>
                        ))}
                </ol>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-y border-hq-border px-3.5 py-2 sm:px-4">
                <h3 className="hq-label">Por qué</h3>
                <span className="font-mono text-[11px] text-hq-moss-dim">
                    {lens === 'sell'
                        ? 'en rojo, la peor señal de cada fila'
                        : 'subrayado, el mejor de cada fila'}
                </span>
            </div>
            <div
                role="table"
                aria-label={`Por qué · ${result.title}`}
                className="overflow-x-auto"
            >
                <div role="row" className={EVIDENCE_GRID} style={columns}>
                    <div role="columnheader" className="px-3.5 py-2 sm:px-4">
                        <span className="sr-only">Dato</span>
                    </div>
                    {players.map((player, index) => (
                        <div
                            key={player.id}
                            role="columnheader"
                            className="truncate px-2.5 py-2 font-mono text-[11px] font-bold text-hq-paper uppercase"
                            style={{
                                boxShadow: `inset 0 2px 0 ${COMPARE_SLOT_COLORS[index]}`,
                            }}
                        >
                            {player.name}
                        </div>
                    ))}
                </div>
                {result.rows.map((row) => {
                    const marked = markedIndex(row);

                    return (
                        <div
                            key={row.label}
                            role="row"
                            className={EVIDENCE_GRID}
                            style={columns}
                        >
                            <div
                                role="rowheader"
                                className="px-3.5 py-2 sm:px-4"
                            >
                                <b className="block text-[11px] font-extrabold text-hq-paper uppercase">
                                    {row.label}
                                </b>
                                {row.hint && (
                                    <small className="font-mono text-[10.5px] text-hq-moss-dim">
                                        {row.hint}
                                    </small>
                                )}
                            </div>
                            {row.texts.map((text, index) => (
                                <div
                                    key={players[index].id}
                                    role="cell"
                                    className={cn(
                                        'self-center px-2.5 py-2 font-mono text-xs text-hq-paper tabular-nums',
                                        index === marked &&
                                            (row.mark === 'worst'
                                                ? 'text-hq-live shadow-[inset_0_-2px_0_var(--color-hq-live)]'
                                                : 'shadow-[inset_0_-2px_0_var(--color-hq-lime)]'),
                                    )}
                                >
                                    {text}
                                </div>
                            ))}
                        </div>
                    );
                })}
            </div>
            <p className="m-0 px-3.5 py-2.5 font-mono text-[11px] text-hq-moss-dim sm:px-4">
                Lectura rápida con los datos de las fichas, no una
                recomendación. DAZN oficial · Titularidad FútbolFantasy ·
                Dificultad 0–10.
            </p>
        </section>
    );
}
