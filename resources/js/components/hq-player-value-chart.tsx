import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { TYPE_LABELS } from '@/components/activity-card';
import { formatCurrency } from '@/lib/format';
import type { OwnershipSegment } from '@/lib/ownership-timeline';
import {
    isSegmentStart,
    ownerAtDate,
    segmentAtDate,
} from '@/lib/ownership-timeline';
import { teamColor } from '@/lib/season-team-colors';
import { cn } from '@/lib/utils';
import type { PlayerFichaScore, PlayerMarketPoint } from '@/types/models';

const DEFAULT_WIDTH = 900;
const HEIGHT = 160;
const BAND_Y = 176;
const BAND_HEIGHT = 14;
const BAR_LABEL_SPACE = 20;

type Mode = 'valor' | 'puntos';
type Range = 10 | 30 | 'all';

interface HqPlayerValueChartProps {
    marketHistory: PlayerMarketPoint[];
    scores: PlayerFichaScore[];
    ownershipSegments: OwnershipSegment[];
}

function describeOrigin(
    segment: OwnershipSegment | null,
    dateIso: string,
): string | null {
    if (!segment?.startedBy || !isSegmentStart(segment, dateIso)) {
        return null;
    }

    if (segment.startedBy.type === 'joined_league') {
        return 'Se unió a la liga';
    }

    const label = TYPE_LABELS[segment.startedBy.type];

    return segment.startedBy.amount === null
        ? label
        : `${label} · ${formatCurrency(segment.startedBy.amount)}`;
}

function formatDateLabel(dateIso: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        day: 'numeric',
        month: 'short',
    })
        .format(new Date(dateIso))
        .toUpperCase();
}

interface TooltipState {
    x: number;
    y: number;
    date: string;
    value: string;
    diff: number | null;
    ownerName: string;
    ownerColor: string;
    action: string | null;
}

export function HqPlayerValueChart({
    marketHistory,
    scores,
    ownershipSegments,
}: HqPlayerValueChartProps) {
    const [mode, setMode] = useState<Mode>('valor');
    const [range, setRange] = useState<Range>(30);
    const [tooltip, setTooltip] = useState<TooltipState | null>(null);
    const [hoverPoint, setHoverPoint] = useState<{ x: number; y: number } | null>(null);
    const svgRef = useRef<SVGSVGElement>(null);
    const containerRef = useRef<HTMLDivElement>(null);
    // The viewBox width tracks the container's real pixel width so SVG text/strokes
    // render at true size on any screen — a fixed viewBox scaled down for a narrow
    // mobile container shrinks everything (including text) proportionally, making
    // axis labels unreadably small.
    const [width, setWidth] = useState(DEFAULT_WIDTH);

    useEffect(() => {
        const el = containerRef.current;

        if (!el) {
            return;
        }

        const observer = new ResizeObserver((entries) => {
            const entry = entries[0];

            if (entry) {
                setWidth(Math.max(200, Math.round(entry.contentRect.width)));
            }
        });
        observer.observe(el);

        return () => observer.disconnect();
    }, []);

    const legend = useMemo(() => {
        const seen = new Map<string, { label: string; color: string }>();

        for (const segment of ownershipSegments) {
            const key = segment.seasonTeam ? `team-${segment.seasonTeam.id}` : 'libre';

            if (!seen.has(key)) {
                seen.set(key, {
                    label: segment.seasonTeam?.name ?? 'Libre',
                    color: segment.seasonTeam
                        ? teamColor(segment.seasonTeam.primary_color)
                        : 'var(--color-hq-moss-dim)',
                });
            }
        }

        return [...seen.values()];
    }, [ownershipSegments]);

    const visibleHistory = useMemo(() => {
        if (mode !== 'valor' || range === 'all') {
            return marketHistory;
        }

        return marketHistory.slice(Math.max(0, marketHistory.length - range));
    }, [marketHistory, mode, range]);

    const valorGeometry = useMemo(() => {
        const n = visibleHistory.length;

        if (n === 0) {
            return null;
        }

        const values = visibleHistory.map((point) => point.value);
        const min = Math.min(...values);
        const max = Math.max(...values);
        const xAt = (index: number) => (n === 1 ? width / 2 : (index / (n - 1)) * width);
        const yAt = (value: number) =>
            max === min ? HEIGHT / 2 : HEIGHT - ((value - min) / (max - min)) * HEIGHT;

        // Catmull-Rom-to-Bezier: each segment's control points lean on the
        // neighboring points (clamped at the ends), so the curve passes
        // through every value but arrives/leaves each one on a smooth
        // tangent instead of a sharp elbow.
        const lineSegments = visibleHistory.slice(1).map((point, index) => {
            const previous = visibleHistory[index];
            const before = visibleHistory[index - 1] ?? previous;
            const after = visibleHistory[index + 2] ?? point;

            const x0 = xAt(Math.max(0, index - 1));
            const y0 = yAt(before.value);
            const x1 = xAt(index);
            const y1 = yAt(previous.value);
            const x2 = xAt(index + 1);
            const y2 = yAt(point.value);
            const x3 = xAt(Math.min(n - 1, index + 2));
            const y3 = yAt(after.value);

            const cp1x = x1 + (x2 - x0) / 6;
            const cp1y = y1 + (y2 - y0) / 6;
            const cp2x = x2 - (x3 - x1) / 6;
            const cp2y = y2 - (y3 - y1) / 6;

            return {
                d: `M ${x1},${y1} C ${cp1x},${cp1y} ${cp2x},${cp2y} ${x2},${y2}`,
                color:
                    point.value >= previous.value
                        ? 'var(--color-hq-lime)'
                        : 'var(--color-hq-live)',
            };
        });

        const bandSegments: { x: number; width: number; color: string }[] = [];
        const boundaries: number[] = [];
        let segmentStartX = 0;
        let segmentOwner = ownerAtDate(ownershipSegments, visibleHistory[0].date);

        for (let index = 1; index < n; index++) {
            const owner = ownerAtDate(ownershipSegments, visibleHistory[index].date);

            if (owner?.id !== segmentOwner?.id) {
                bandSegments.push({
                    x: segmentStartX,
                    width: xAt(index) - segmentStartX,
                    color: segmentOwner === null ? 'var(--color-hq-moss-dim)' : teamColor(segmentOwner.primary_color),
                });
                boundaries.push(xAt(index));
                segmentStartX = xAt(index);
                segmentOwner = owner;
            }
        }

        bandSegments.push({
            x: segmentStartX,
            width: width - segmentStartX,
            color: segmentOwner === null ? 'var(--color-hq-moss-dim)' : teamColor(segmentOwner.primary_color),
        });

        return { xAt, yAt, lineSegments, bandSegments, boundaries };
    }, [visibleHistory, ownershipSegments, width]);

    const puntosGeometry = useMemo(() => {
        const n = scores.length;

        if (n === 0) {
            return null;
        }

        const slot = width / n;
        const barWidth = Math.min(64, slot * 0.5);
        const maxPoints = Math.max(...scores.map((score) => score.points), 12);

        const bars = scores.map((score, index) => {
            const cx = index * slot + slot / 2;
            const barHeight =
                (score.points / maxPoints) * (HEIGHT - BAR_LABEL_SPACE);

            return {
                cx,
                y: HEIGHT - barHeight,
                height: barHeight,
                week: score.fixture.week_number,
                points: score.points,
            };
        });

        const owners = scores.map((score) => ownerAtDate(ownershipSegments, score.fixture.date));
        const bandSegments = owners.map((owner, index) => ({
            x: index * slot,
            width: slot,
            color: owner === null ? 'var(--color-hq-moss-dim)' : teamColor(owner.primary_color),
        }));
        const boundaries: number[] = [];

        for (let index = 1; index < n; index++) {
            if (owners[index]?.id !== owners[index - 1]?.id) {
                boundaries.push(index * slot);
            }
        }

        return { slot, barWidth, bars, bandSegments, boundaries };
    }, [scores, ownershipSegments, width]);

    function handleMove(clientX: number) {
        const svg = svgRef.current;

        if (!svg) {
            return;
        }

        const rect = svg.getBoundingClientRect();
        const relX = ((clientX - rect.left) / rect.width) * width;
        const pxRatio = rect.width / width;

        if (mode === 'valor' && valorGeometry) {
            const n = visibleHistory.length;
            const index = Math.max(0, Math.min(n - 1, Math.round((relX / width) * (n - 1))));
            const point = visibleHistory[index];
            const previous = index > 0 ? visibleHistory[index - 1] : null;
            const segment = segmentAtDate(ownershipSegments, point.date);
            const x = valorGeometry.xAt(index);
            const y = valorGeometry.yAt(point.value);

            setHoverPoint({ x, y });
            setTooltip({
                x: rect.left + x * pxRatio,
                y: rect.top + y * pxRatio,
                date: formatDateLabel(point.date),
                value: formatCurrency(point.value),
                diff: previous ? point.value - previous.value : null,
                ownerName: segment?.seasonTeam?.name ?? 'Libre',
                ownerColor: segment?.seasonTeam
                    ? teamColor(segment.seasonTeam.primary_color)
                    : 'var(--color-hq-moss-dim)',
                action: describeOrigin(segment, point.date),
            });
        } else if (mode === 'puntos' && puntosGeometry) {
            const n = scores.length;
            const index = Math.max(0, Math.min(n - 1, Math.floor(relX / puntosGeometry.slot)));
            const score = scores[index];
            const segment = segmentAtDate(ownershipSegments, score.fixture.date);
            const bar = puntosGeometry.bars[index];

            setHoverPoint({ x: bar.cx, y: bar.y });
            setTooltip({
                x: rect.left + bar.cx * pxRatio,
                y: rect.top + bar.y * pxRatio,
                date: `Jornada ${score.fixture.week_number}`,
                value: `${score.points} puntos`,
                diff: null,
                ownerName: segment?.seasonTeam?.name ?? 'Libre',
                ownerColor: segment?.seasonTeam
                    ? teamColor(segment.seasonTeam.primary_color)
                    : 'var(--color-hq-moss-dim)',
                action: null,
            });
        }
    }

    const geometry = mode === 'valor' ? valorGeometry : puntosGeometry;

    return (
        <div>
            <div className="mb-3">
                <div className="flex items-center justify-between gap-2">
                    <h2 className="font-display text-lg tracking-wide text-hq-paper uppercase">
                        Evolución
                    </h2>
                    <div className="inline-flex shrink-0 border border-hq-border-strong">
                        <button
                            type="button"
                            onClick={() => setMode('valor')}
                            className={cn(
                                'px-3 py-1.5 font-mono text-[11px] font-bold',
                                mode === 'valor'
                                    ? 'bg-hq-lime text-hq-ink'
                                    : 'text-hq-moss',
                            )}
                        >
                            VALOR
                        </button>
                        <button
                            type="button"
                            onClick={() => setMode('puntos')}
                            className={cn(
                                'px-3 py-1.5 font-mono text-[11px] font-bold',
                                mode === 'puntos'
                                    ? 'bg-hq-lime text-hq-ink'
                                    : 'text-hq-moss',
                            )}
                        >
                            PUNTOS
                        </button>
                    </div>
                </div>
                {mode === 'valor' && (
                    <div className="mt-2 flex justify-end">
                        <div className="inline-flex border border-hq-border-strong">
                            {([10, 30, 'all'] as const).map((option) => (
                                <button
                                    key={option}
                                    type="button"
                                    onClick={() => setRange(option)}
                                    className={cn(
                                        'px-3 py-1.5 font-mono text-[11px] font-bold',
                                        range === option
                                            ? 'bg-hq-lime text-hq-ink'
                                            : 'text-hq-moss',
                                    )}
                                >
                                    {option === 'all' ? 'TODO' : `${option}D`}
                                </button>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <div ref={containerRef} className="hq-card-cut p-4">
                {geometry === null ? (
                    <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                        <p className="font-mono text-[11px] text-hq-moss-dim">
                            {mode === 'valor'
                                ? 'Todavía no hay histórico de valor.'
                                : 'Todavía no ha jugado ninguna jornada.'}
                        </p>
                    </div>
                ) : (
                    <svg
                        ref={svgRef}
                        viewBox={`0 0 ${width} 220`}
                        className="w-full cursor-crosshair overflow-visible touch-none"
                        onMouseLeave={() => {
                            setTooltip(null);
                            setHoverPoint(null);
                        }}
                    >
                        {mode === 'valor' &&
                            valorGeometry &&
                            valorGeometry.lineSegments.map((segment, index) => (
                                <path
                                    key={index}
                                    d={segment.d}
                                    stroke={segment.color}
                                    strokeWidth={2.5}
                                    fill="none"
                                    strokeLinecap="round"
                                />
                            ))}
                        {mode === 'puntos' &&
                            puntosGeometry &&
                            puntosGeometry.bars.map((bar) => (
                                <g key={bar.week}>
                                    <rect
                                        x={bar.cx - puntosGeometry.barWidth / 2}
                                        y={bar.y}
                                        width={puntosGeometry.barWidth}
                                        height={bar.height}
                                        fill="var(--color-hq-lime)"
                                        opacity={0.35}
                                    />
                                    <text
                                        x={bar.cx}
                                        y={bar.y - 8}
                                        textAnchor="middle"
                                        className="font-display"
                                        fontSize={13}
                                        fill="var(--color-hq-lime)"
                                    >
                                        {bar.points}
                                    </text>
                                    <text
                                        x={bar.cx}
                                        y={HEIGHT + 14}
                                        textAnchor="middle"
                                        className="font-mono"
                                        fontSize={9}
                                        fill="var(--color-hq-moss)"
                                    >
                                        J{bar.week}
                                    </text>
                                </g>
                            ))}

                        {geometry.bandSegments.map((segment, index) => (
                            <rect
                                key={index}
                                x={segment.x}
                                y={BAND_Y}
                                width={Math.max(0, segment.width)}
                                height={BAND_HEIGHT}
                                fill={segment.color}
                                opacity={0.75}
                            />
                        ))}

                        {geometry.boundaries.map((x, index) => (
                            <line
                                key={index}
                                x1={x}
                                y1={0}
                                x2={x}
                                y2={HEIGHT}
                                stroke="var(--color-hq-ember)"
                                strokeWidth={1}
                                strokeDasharray="3,3"
                            />
                        ))}

                        {hoverPoint && (
                            <g pointerEvents="none">
                                <line
                                    x1={hoverPoint.x}
                                    y1={0}
                                    x2={hoverPoint.x}
                                    y2={HEIGHT}
                                    stroke="var(--color-hq-paper)"
                                    strokeWidth={1}
                                    opacity={0.35}
                                />
                                <circle
                                    cx={hoverPoint.x}
                                    cy={hoverPoint.y}
                                    r={4}
                                    fill="var(--color-hq-lime)"
                                    stroke="var(--color-hq-ink)"
                                    strokeWidth={1.5}
                                />
                            </g>
                        )}

                        {mode === 'valor' && (
                            <>
                                <text
                                    x={4}
                                    y={205}
                                    className="font-mono"
                                    fontSize={10}
                                    fill="var(--color-hq-moss)"
                                >
                                    {formatDateLabel(visibleHistory[0].date)}
                                </text>
                                <text
                                    x={width - 4}
                                    y={205}
                                    textAnchor="end"
                                    className="font-mono"
                                    fontSize={10}
                                    fill="var(--color-hq-moss)"
                                >
                                    {formatDateLabel(
                                        visibleHistory[visibleHistory.length - 1].date,
                                    )}
                                </text>
                            </>
                        )}

                        <rect
                            x={0}
                            y={0}
                            width={width}
                            height={190}
                            fill="transparent"
                            onMouseMove={(event) => handleMove(event.clientX)}
                            onTouchStart={(event) => handleMove(event.touches[0].clientX)}
                            onTouchMove={(event) => handleMove(event.touches[0].clientX)}
                            onTouchEnd={() => {
                                setTooltip(null);
                                setHoverPoint(null);
                            }}
                        />
                    </svg>
                )}
            </div>

            {legend.length > 0 && (
                <div className="mt-2.5 flex flex-wrap gap-x-4 gap-y-1">
                    {legend.map((entry) => (
                        <span
                            key={entry.label}
                            className="flex items-center gap-1.5 font-mono text-[11px] text-hq-moss"
                        >
                            <span
                                className="h-2 w-2 shrink-0 rounded-[1px]"
                                style={{ backgroundColor: entry.color }}
                            />
                            {entry.label}
                        </span>
                    ))}
                </div>
            )}

            {tooltip &&
                createPortal(
                    <div
                        className="pointer-events-none fixed z-[999] -translate-x-1/2 -translate-y-[115%] border border-hq-lime bg-hq-panel-alt px-3 py-2 font-mono text-xs whitespace-nowrap"
                        style={{ left: tooltip.x, top: tooltip.y }}
                    >
                        <div className="text-[10px] tracking-wide text-hq-moss uppercase">
                            {tooltip.date}
                        </div>
                        <div className="mt-0.5 text-sm font-bold text-hq-paper">
                            {tooltip.value}
                        </div>
                        {tooltip.diff !== null && tooltip.diff !== 0 && (
                            <div
                                className={cn(
                                    'mt-0.5 font-bold',
                                    tooltip.diff > 0
                                        ? 'text-hq-lime'
                                        : 'text-hq-live',
                                )}
                            >
                                {tooltip.diff > 0 ? '▲' : '▼'}{' '}
                                {formatCurrency(Math.abs(tooltip.diff))}
                            </div>
                        )}
                        <div className="mt-1 flex items-center gap-1.5 text-hq-khaki">
                            <span
                                className="h-2 w-2 shrink-0 rounded-[1px]"
                                style={{ backgroundColor: tooltip.ownerColor }}
                            />
                            {tooltip.ownerName}
                        </div>
                        {tooltip.action && (
                            <div className="mt-0.5 text-[10px] text-hq-moss">
                                {tooltip.action}
                            </div>
                        )}
                    </div>,
                    document.body,
                )}
        </div>
    );
}
