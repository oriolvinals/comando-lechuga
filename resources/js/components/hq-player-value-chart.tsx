import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { TYPE_LABELS } from '@/components/activity-helpers';
import { formatCurrency } from '@/lib/format';
import type { OwnershipSegment } from '@/lib/ownership-timeline';
import {
    isSegmentStart,
    ownerAtDate,
    segmentAtDate,
} from '@/lib/ownership-timeline';
import { matchPointsColor } from '@/lib/points';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import type { PlayerFichaScore, PlayerMarketPoint } from '@/types/models';

const DEFAULT_WIDTH = 900;
const VALUE_TOP = 26;
const VALUE_BOTTOM = 150;
const POINTS_CAPTION_Y = 176;
const POINTS_TOP = 184;
const POINTS_BOTTOM = 238;
const BAR_LABEL_SPACE = 16;
const JORNADA_LABEL_Y = 254;
const BAND_Y = 262;
const BAND_HEIGHT = 14;
const DATE_Y = 294;
const VIEW_HEIGHT = 302;
const HIT_HEIGHT = 246;
const DAY_MS = 24 * 60 * 60 * 1000;
const SNAP_RADIUS = 12;

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
    ownerId: number | null;
    action: string | null;
    jornada: {
        week: number;
        points: number;
        managerId: number | null;
        managerName: string;
        managerColor: string;
    } | null;
}

export function HqPlayerValueChart({
    marketHistory,
    scores,
    ownershipSegments,
}: HqPlayerValueChartProps) {
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

    const visibleHistory = useMemo(() => {
        if (range === 'all') {
            return marketHistory;
        }

        return marketHistory.slice(Math.max(0, marketHistory.length - range));
    }, [marketHistory, range]);

    const geometry = useMemo(() => {
        const n = visibleHistory.length;

        if (n === 0) {
            return null;
        }

        const values = visibleHistory.map((point) => point.value);
        const min = Math.min(...values);
        const max = Math.max(...values);
        const xAt = (index: number) => (n === 1 ? width / 2 : (index / (n - 1)) * width);
        const yAt = (value: number) =>
            max === min
                ? (VALUE_TOP + VALUE_BOTTOM) / 2
                : VALUE_BOTTOM - ((value - min) / (max - min)) * (VALUE_BOTTOM - VALUE_TOP);

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
                    color: segmentOwner === null ? 'var(--color-hq-moss-dim)' : managerColor(segmentOwner.primary_color),
                });
                boundaries.push(xAt(index));
                segmentStartX = xAt(index);
                segmentOwner = owner;
            }
        }

        bandSegments.push({
            x: segmentStartX,
            width: width - segmentStartX,
            color: segmentOwner === null ? 'var(--color-hq-moss-dim)' : managerColor(segmentOwner.primary_color),
        });

        // Each jornada's bar sits on the market-history day closest to its
        // fixture date, so both panels share one time axis. Jornadas outside
        // the visible range are dropped.
        const times = visibleHistory.map((point) => new Date(point.date).getTime());
        const placed = scores.flatMap((score) => {
            const time = new Date(score.fixture.date).getTime();

            if (time < times[0] - DAY_MS || time > times[n - 1] + DAY_MS) {
                return [];
            }

            let index = 0;
            let closest = Infinity;

            times.forEach((candidate, candidateIndex) => {
                const distance = Math.abs(candidate - time);

                if (distance < closest) {
                    closest = distance;
                    index = candidateIndex;
                }
            });

            return [{ score, index }];
        });

        const spacing = n > 1 ? width / (n - 1) : width;
        const barWidth = Math.min(26, Math.max(8, spacing * 1.6));
        const pointValues = placed.map(({ score }) => score.points ?? 0);
        const maxPoints = Math.max(...pointValues, 12);
        const minPoints = Math.min(...pointValues, 0);
        // Only carve out label space below the baseline when there's a
        // negative bar to label.
        const plotTop = POINTS_TOP + BAR_LABEL_SPACE;
        const plotBottom = POINTS_BOTTOM - (minPoints < 0 ? BAR_LABEL_SPACE : 0);
        const pointsToY = (points: number) =>
            plotBottom - ((points - minPoints) / (maxPoints - minPoints)) * (plotBottom - plotTop);
        const zeroY = pointsToY(0);

        const bars = placed.map(({ score, index }) => {
            const points = score.points ?? 0;
            const valueY = pointsToY(points);
            const isNegative = points < 0;
            const manager = score.lineup_manager;

            return {
                key: score.id,
                index,
                cx: xAt(index),
                y: isNegative ? zeroY : valueY,
                height: Math.max(1.5, Math.abs(zeroY - valueY)),
                valueY,
                week: score.fixture.week_number,
                points,
                isNegative,
                managerId: manager?.id ?? null,
                managerName: manager?.name ?? 'No alineado',
                managerColor: manager
                    ? managerColor(manager.primary_color)
                    : 'var(--color-hq-moss-dim)',
            };
        });

        return { xAt, yAt, lineSegments, bandSegments, boundaries, bars, barWidth, zeroY };
    }, [visibleHistory, scores, ownershipSegments, width]);

    const legend = useMemo(() => {
        const seen = new Map<string, { label: string; color: string }>();

        for (const segment of ownershipSegments) {
            const key = segment.seasonManager ? `team-${segment.seasonManager.id}` : 'libre';

            if (!seen.has(key)) {
                seen.set(key, {
                    label: segment.seasonManager?.name ?? 'Libre',
                    color: segment.seasonManager
                        ? managerColor(segment.seasonManager.primary_color)
                        : 'var(--color-hq-moss-dim)',
                });
            }
        }

        for (const bar of geometry?.bars ?? []) {
            const key = bar.managerId === null ? 'no-alineado' : `team-${bar.managerId}`;

            if (!seen.has(key)) {
                seen.set(key, { label: bar.managerName, color: bar.managerColor });
            }
        }

        return [...seen.values()];
    }, [ownershipSegments, geometry]);

    function handleMove(clientX: number) {
        const svg = svgRef.current;

        if (!svg || !geometry) {
            return;
        }

        const rect = svg.getBoundingClientRect();
        const relX = ((clientX - rect.left) / rect.width) * width;
        const pxRatio = rect.width / width;
        const n = visibleHistory.length;
        // Hovering near a jornada's bar snaps to that day, so its points and
        // the manager they belonged to are easy to hit.
        const snapped = geometry.bars.find(
            (bar) => Math.abs(bar.cx - relX) <= Math.max(SNAP_RADIUS, geometry.barWidth / 2),
        );
        const index = snapped
            ? snapped.index
            : Math.max(0, Math.min(n - 1, Math.round((relX / width) * (n - 1))));
        const point = visibleHistory[index];
        const previous = index > 0 ? visibleHistory[index - 1] : null;
        const segment = segmentAtDate(ownershipSegments, point.date);
        const x = geometry.xAt(index);
        const y = geometry.yAt(point.value);

        setHoverPoint({ x, y });
        setTooltip({
            x: rect.left + x * pxRatio,
            y: rect.top + y * pxRatio,
            date: formatDateLabel(point.date),
            value: formatCurrency(point.value),
            diff: previous ? point.value - previous.value : null,
            ownerName: segment?.seasonManager?.name ?? 'Libre',
            ownerColor: segment?.seasonManager
                ? managerColor(segment.seasonManager.primary_color)
                : 'var(--color-hq-moss-dim)',
            ownerId: segment?.seasonManager?.id ?? null,
            action: describeOrigin(segment, point.date),
            jornada: snapped
                ? {
                      week: snapped.week,
                      points: snapped.points,
                      managerId: snapped.managerId,
                      managerName: snapped.managerName,
                      managerColor: snapped.managerColor,
                  }
                : null,
        });
    }

    function clearHover() {
        setTooltip(null);
        setHoverPoint(null);
    }

    // On a jornada day the tooltip leads with who the points belonged to; the
    // day's owner row is only kept when it adds something (a different owner,
    // or the deal that started their ownership).
    const showOwnerRow =
        tooltip !== null &&
        (tooltip.jornada === null ||
            tooltip.jornada.managerId !== tooltip.ownerId ||
            tooltip.action !== null);

    return (
        <div>
            <div className="mb-3">
                <div className="flex items-center justify-between gap-2">
                    <h2 className="font-display text-lg tracking-wide text-hq-paper uppercase">
                        Evolución
                    </h2>
                    <div className="inline-flex shrink-0 border border-hq-border-strong">
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
            </div>

            <div ref={containerRef} className="hq-card-cut p-4">
                {geometry === null ? (
                    <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                        <p className="font-mono text-[11px] text-hq-moss-dim">
                            Todavía no hay histórico de valor.
                        </p>
                    </div>
                ) : (
                    <svg
                        ref={svgRef}
                        viewBox={`0 0 ${width} ${VIEW_HEIGHT}`}
                        className="w-full cursor-crosshair overflow-visible touch-none"
                        onMouseLeave={clearHover}
                    >
                        <text
                            x={4}
                            y={12}
                            className="font-mono"
                            fontSize={9}
                            letterSpacing={1.5}
                            fill="var(--color-hq-moss-dim)"
                        >
                            VALOR
                        </text>
                        <text
                            x={4}
                            y={POINTS_CAPTION_Y}
                            className="font-mono"
                            fontSize={9}
                            letterSpacing={1.5}
                            fill="var(--color-hq-moss-dim)"
                        >
                            PUNTOS
                        </text>

                        {geometry.lineSegments.map((segment, index) => (
                            <path
                                key={index}
                                d={segment.d}
                                stroke={segment.color}
                                strokeWidth={2.5}
                                fill="none"
                                strokeLinecap="round"
                            />
                        ))}

                        <line
                            x1={0}
                            y1={geometry.zeroY}
                            x2={width}
                            y2={geometry.zeroY}
                            stroke="var(--color-hq-border-strong)"
                            strokeWidth={1}
                        />
                        {geometry.bars.map((bar) => (
                            <g key={bar.key}>
                                <rect
                                    x={bar.cx - geometry.barWidth / 2}
                                    y={bar.y}
                                    width={geometry.barWidth}
                                    height={bar.height}
                                    fill={matchPointsColor(bar.points)}
                                    opacity={0.55}
                                />
                                <text
                                    x={bar.cx}
                                    y={bar.isNegative ? bar.y + bar.height + 14 : bar.y - 5}
                                    textAnchor="middle"
                                    className="font-display"
                                    fontSize={13}
                                    fill={matchPointsColor(bar.points)}
                                    stroke="var(--color-hq-panel)"
                                    strokeWidth={3}
                                    paintOrder="stroke"
                                >
                                    {bar.points}
                                </text>
                                <text
                                    x={bar.cx}
                                    y={JORNADA_LABEL_Y}
                                    textAnchor="middle"
                                    className="font-mono"
                                    fontSize={9}
                                    fill="var(--color-hq-moss)"
                                >
                                    J{bar.week}
                                </text>
                            </g>
                        ))}
                        {geometry.bars.length === 0 && (
                            <text
                                x={width / 2}
                                y={(POINTS_TOP + POINTS_BOTTOM) / 2}
                                textAnchor="middle"
                                className="font-mono"
                                fontSize={10}
                                fill="var(--color-hq-moss-dim)"
                            >
                                Sin jornadas jugadas en este rango
                            </text>
                        )}

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
                                y1={16}
                                x2={x}
                                y2={POINTS_BOTTOM}
                                stroke="var(--color-hq-ember)"
                                strokeWidth={1}
                                strokeDasharray="3,3"
                            />
                        ))}

                        {hoverPoint && (
                            <g pointerEvents="none">
                                <line
                                    x1={hoverPoint.x}
                                    y1={VALUE_TOP - 8}
                                    x2={hoverPoint.x}
                                    y2={POINTS_BOTTOM}
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

                        <text
                            x={4}
                            y={DATE_Y}
                            className="font-mono"
                            fontSize={10}
                            fill="var(--color-hq-moss)"
                        >
                            {formatDateLabel(visibleHistory[0].date)}
                        </text>
                        <text
                            x={width - 4}
                            y={DATE_Y}
                            textAnchor="end"
                            className="font-mono"
                            fontSize={10}
                            fill="var(--color-hq-moss)"
                        >
                            {formatDateLabel(
                                visibleHistory[visibleHistory.length - 1].date,
                            )}
                        </text>

                        <rect
                            x={0}
                            y={0}
                            width={width}
                            height={HIT_HEIGHT}
                            fill="transparent"
                            onMouseMove={(event) => handleMove(event.clientX)}
                            onTouchStart={(event) => handleMove(event.touches[0].clientX)}
                            onTouchMove={(event) => handleMove(event.touches[0].clientX)}
                            onTouchEnd={clearHover}
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
                        {tooltip.jornada && (
                            <div className="mt-1.5 border-t border-hq-border-strong pt-1.5">
                                <div
                                    className="font-bold tracking-wide"
                                    style={{
                                        color: matchPointsColor(tooltip.jornada.points),
                                    }}
                                >
                                    J{tooltip.jornada.week} · {tooltip.jornada.points} PTS
                                </div>
                                <div className="mt-1 flex items-center gap-1.5 text-hq-khaki">
                                    <span
                                        className="h-2 w-2 shrink-0 rounded-[1px]"
                                        style={{
                                            backgroundColor: tooltip.jornada.managerColor,
                                        }}
                                    />
                                    {tooltip.jornada.managerName}
                                </div>
                            </div>
                        )}
                        {showOwnerRow && (
                            <div className="mt-1 flex items-center gap-1.5 text-hq-khaki">
                                <span
                                    className="h-2 w-2 shrink-0 rounded-[1px]"
                                    style={{ backgroundColor: tooltip.ownerColor }}
                                />
                                {tooltip.jornada
                                    ? `Dueño · ${tooltip.ownerName}`
                                    : tooltip.ownerName}
                            </div>
                        )}
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
