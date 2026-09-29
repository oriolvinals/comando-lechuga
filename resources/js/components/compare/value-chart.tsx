import { useRef, useState } from 'react';
import type { PointerEvent } from 'react';
import { TipRow, useChartTooltip } from '@/components/compare/chart-tooltip';
import { useCompare } from '@/components/compare/compare-context';
import {
    formatPercentChange,
    percentSeries,
} from '@/components/compare/derive';
import { COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { formatMillions } from '@/lib/format';

export interface ValueChartSeries {
    slot: number;
    name: string;
    history: [string, number][];
}

interface CompareValueChartProps {
    series: ValueChartSeries[];
    width: number;
    height: number;
    padRight?: number;
    label: string;
    /** View A: the series nearest the pointer highlights its player in the whole view. */
    onHighlight?: (slot: number | null) => void;
    showAxis?: boolean;
}

const PAD_LEFT = 4;
const PAD_TOP = 10;
const PAD_BOTTOM = 6;
/** How close (in CSS px) the pointer must be to a line to highlight its player. */
const HOT_DISTANCE = 14;

function dayMonth(date: string): string {
    const [, month, day] = date.split('-');

    return `${day}/${month}`;
}

/**
 * Value evolution as % over each series' first snapshot, on one scale,
 * scrubbable: crosshair and a dot per series, the tooltip with each value
 * and change; one tab stop, ←/→ a day, AvPág/RePág 7 days, Inicio/Fin, and
 * each day announced.
 */
export function CompareValueChart({
    series,
    width,
    height,
    padRight = 8,
    label,
    onHighlight,
    showAxis = true,
}: CompareValueChartProps) {
    const { announce } = useCompare();
    const { show, hide } = useChartTooltip();
    const wrapRef = useRef<HTMLDivElement>(null);
    const svgRef = useRef<SVGSVGElement>(null);
    const [cursor, setCursor] = useState<number | null>(null);

    // A player without two snapshots has no line; the rest still draw.
    const charted = series.filter((item) => item.history.length >= 2);
    // Series may have different lengths: align on the common tail (the same last days for everyone).
    const length =
        charted.length === 0
            ? 0
            : Math.min(...charted.map((item) => item.history.length));

    if (length < 2) {
        return (
            <p className="m-0 py-2 font-mono text-xs text-hq-moss-dim">
                Sin histórico suficiente
            </p>
        );
    }

    const aligned = charted.map((item) => ({
        ...item,
        history: item.history.slice(-length),
    }));
    const values = aligned.map((item) => percentSeries(item.history));
    const flat = values.flat();
    const min = Math.min(0, ...flat);
    const span = Math.max(0, ...flat) - min || 1;
    const plotWidth = width - PAD_LEFT - padRight;
    const plotHeight = height - PAD_TOP - PAD_BOTTOM;
    const x = (index: number) => PAD_LEFT + (index * plotWidth) / (length - 1);
    const y = (value: number) =>
        PAD_TOP + (1 - (value - min) / span) * plotHeight;
    const dates = aligned[0].history.map(([date]) => date);
    const clamp = (index: number) => Math.max(0, Math.min(length - 1, index));

    const tipFor = (index: number, hot: number | null) => {
        const ago = length - 1 - index;
        const head = `${dayMonth(dates[index])} · ${ago === 0 ? 'hoy' : `hace ${ago} ${ago === 1 ? 'día' : 'días'}`}`;
        const order = aligned
            .map((_, position) => position)
            .sort((a, b) => values[b][index] - values[a][index]);

        return {
            content: (
                <>
                    <b className="font-sans text-[12.5px] leading-[1.15] font-black uppercase">
                        {head}
                    </b>
                    <span className="mt-[3px] text-hq-moss-dim">
                        valor · cambio desde el {dayMonth(dates[0])}
                    </span>
                    <span className="mt-2 flex flex-col gap-1 border-t border-hq-border pt-[7px]">
                        {order.map((position) => (
                            <TipRow
                                key={aligned[position].slot}
                                slotColor={
                                    COMPARE_SLOT_COLORS[aligned[position].slot]
                                }
                                name={aligned[position].name}
                                value={formatMillions(
                                    aligned[position].history[index][1],
                                )}
                                extra={formatPercentChange(
                                    values[position][index],
                                )}
                                me={position === hot || aligned.length === 1}
                            />
                        ))}
                    </span>
                </>
            ),
            text: `${head}: ${order
                .map(
                    (position) =>
                        `${aligned[position].name} ${formatMillions(aligned[position].history[index][1])}, ${formatPercentChange(values[position][index])}`,
                )
                .join('; ')}`,
        };
    };

    const select = (index: number, hot: number | null, speak: boolean) => {
        const wrap = wrapRef.current;
        const svg = svgRef.current;

        if (!wrap || !svg) {
            return;
        }

        setCursor(index);
        onHighlight?.(hot === null ? null : aligned[hot].slot);
        const { content, text } = tipFor(index, hot);
        const topY = Math.min(...values.map((value) => y(value[index])));

        show(
            content,
            () => {
                if (!svg.isConnected) {
                    return null;
                }

                const rect = svg.getBoundingClientRect();
                const left = rect.left + (x(index) / width) * rect.width;
                const top = rect.top + (topY / height) * rect.height;

                return { left, right: left, top: top - 4, bottom: rect.bottom };
            },
            wrap,
        );

        if (speak) {
            announce(text);
        }
    };

    const release = () => {
        setCursor(null);
        onHighlight?.(null);

        if (wrapRef.current) {
            hide(wrapRef.current);
        }
    };

    const fromPointer = (event: PointerEvent<HTMLDivElement>) => {
        const rect = svgRef.current?.getBoundingClientRect();

        if (!rect || rect.width === 0) {
            return;
        }

        const pointerX = ((event.clientX - rect.left) / rect.width) * width;
        const pointerY = ((event.clientY - rect.top) / rect.height) * height;
        const index = clamp(
            Math.round(((pointerX - PAD_LEFT) / plotWidth) * (length - 1)),
        );
        let hot: number | null = null;
        let distance = Infinity;

        for (let position = 0; position < values.length; position++) {
            const gap = Math.abs(y(values[position][index]) - pointerY);

            if (gap < distance) {
                distance = gap;
                hot = position;
            }
        }

        select(
            index,
            (distance * rect.height) / height > HOT_DISTANCE ? null : hot,
            false,
        );
    };

    const moves: Record<string, (current: number) => number> = {
        ArrowLeft: (current) => current - 1,
        ArrowRight: (current) => current + 1,
        PageUp: (current) => current - 7,
        PageDown: (current) => current + 7,
        Home: () => 0,
        End: () => length - 1,
    };

    return (
        <div
            ref={wrapRef}
            data-cmp-chart=""
            role="group"
            tabIndex={0}
            aria-label={`${label}. Flechas izquierda y derecha para recorrer los días.`}
            className="relative cursor-crosshair touch-pan-y outline-hq-lime focus-visible:outline-2 focus-visible:outline-offset-4"
            onPointerMove={fromPointer}
            onPointerDown={fromPointer}
            onPointerLeave={(event) => {
                if (
                    event.pointerType !== 'touch' &&
                    document.activeElement !== wrapRef.current
                ) {
                    release();
                }
            }}
            onFocus={(event) => {
                if (event.currentTarget.matches(':focus-visible')) {
                    select(cursor ?? length - 1, null, true);
                }
            }}
            onBlur={release}
            onKeyDown={(event) => {
                const move = moves[event.key];

                if (move) {
                    event.preventDefault();
                    select(clamp(move(cursor ?? length - 1)), null, true);
                }
            }}
        >
            <div className="relative">
                <svg
                    ref={svgRef}
                    viewBox={`0 0 ${width} ${height}`}
                    preserveAspectRatio="none"
                    className="block h-auto w-full overflow-visible"
                    aria-hidden="true"
                >
                    {[0, 0.5, 1].map((fraction) => {
                        const lineY = PAD_TOP + fraction * plotHeight;

                        return (
                            <line
                                key={fraction}
                                x1={PAD_LEFT}
                                x2={width - padRight}
                                y1={lineY}
                                y2={lineY}
                                stroke="var(--color-hq-border)"
                                vectorEffect="non-scaling-stroke"
                            />
                        );
                    })}
                    <line
                        x1={PAD_LEFT}
                        x2={width - padRight}
                        y1={y(0)}
                        y2={y(0)}
                        stroke="var(--color-hq-border-strong)"
                        strokeDasharray="3 3"
                        vectorEffect="non-scaling-stroke"
                    />
                    {values.map((value, position) => (
                        <path
                            key={aligned[position].slot}
                            data-slot={aligned[position].slot}
                            d={value
                                .map(
                                    (point, index) =>
                                        `${index ? 'L' : 'M'}${x(index).toFixed(1)} ${y(point).toFixed(1)}`,
                                )
                                .join(' ')}
                            fill="none"
                            stroke={COMPARE_SLOT_COLORS[aligned[position].slot]}
                            strokeWidth={2}
                            strokeLinejoin="round"
                            vectorEffect="non-scaling-stroke"
                        />
                    ))}
                </svg>
                {cursor !== null && (
                    <>
                        <span
                            className="pointer-events-none absolute w-px bg-hq-moss-dim"
                            style={{
                                left: `${(x(cursor) / width) * 100}%`,
                                top: `${(PAD_TOP / height) * 100}%`,
                                height: `${(plotHeight / height) * 100}%`,
                            }}
                        />
                        {values.map((value, position) => (
                            <i
                                key={aligned[position].slot}
                                data-slot={aligned[position].slot}
                                className="pointer-events-none absolute -mt-[4.5px] -ml-[4.5px] size-[9px] rounded-full shadow-[0_0_0_2px_var(--color-hq-ink)]"
                                style={{
                                    left: `${(x(cursor) / width) * 100}%`,
                                    top: `${(y(value[cursor]) / height) * 100}%`,
                                    background:
                                        COMPARE_SLOT_COLORS[
                                            aligned[position].slot
                                        ],
                                }}
                            />
                        ))}
                    </>
                )}
            </div>
            {showAxis && (
                <div
                    aria-hidden="true"
                    className="mt-1 flex justify-between font-mono text-[10px] leading-none text-hq-moss-dim"
                >
                    <span>{dayMonth(dates[0])}</span>
                    <span>hoy</span>
                </div>
            )}
        </div>
    );
}
