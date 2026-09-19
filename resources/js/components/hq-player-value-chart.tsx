import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { TYPE_COLORS, TYPE_LABELS } from '@/components/activity-helpers';
import { formatCurrency } from '@/lib/format';
import type { OwnershipSegment } from '@/lib/ownership-timeline';
import {
    isSegmentStart,
    localDateKey,
    ownerAtDate,
    segmentAtDate,
} from '@/lib/ownership-timeline';
import { matchPointsColor } from '@/lib/points';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import type {
    PlayerFichaScore,
    PlayerMarketPoint,
    PlayerMissedFixture,
    SeasonActivityType,
    SeasonManager,
} from '@/types/models';

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
const TOOLTIP_VIEWPORT_MARGIN = 8;
const TOOLTIP_BELOW_OFFSET = 14;

type Range = 10 | 30 | 'all';

interface HqPlayerValueChartProps {
    marketHistory: PlayerMarketPoint[];
    scores: PlayerFichaScore[];
    missedFixtures: PlayerMissedFixture[];
    ownershipSegments: OwnershipSegment[];
}

interface TooltipParty {
    id: number | null;
    name: string;
    color: string;
}

interface TooltipDeal {
    type: SeasonActivityType;
    label: string;
    seller: TooltipParty;
    buyer: TooltipParty;
    amount: number | null;
    /** The deal's amount minus the player's value that day; `null` when the deal has no amount. */
    difference: number | null;
}

function describeParty(manager: SeasonManager | null): TooltipParty {
    return manager === null
        ? { id: null, name: 'Libre', color: 'var(--color-hq-moss-dim)' }
        : {
              id: manager.id,
              name: manager.name,
              color: managerColor(manager.primary_color),
          };
}

/**
 * The signing/sale/buyout that started the segment, when the hovered day is
 * the day it happened — who gave the player up, who got them, and how the
 * amount compares to the player's value that day.
 */
function describeDeal(
    segment: OwnershipSegment | null,
    dateIso: string,
    dayValue: number,
): TooltipDeal | null {
    if (!segment?.startedBy || !isSegmentStart(segment, dateIso)) {
        return null;
    }

    const { type, amount, seller } = segment.startedBy;

    return {
        type,
        label: type === 'joined_league' ? 'Se unió a la liga' : TYPE_LABELS[type],
        seller: describeParty(seller),
        buyer: describeParty(segment.seasonManager),
        amount,
        difference: amount === null ? null : amount - dayValue,
    };
}

function TooltipPartyLabel({ party }: { party: TooltipParty }) {
    return (
        <span className="flex items-center gap-1.5">
            <span
                className="h-2 w-2 shrink-0 rounded-[1px]"
                style={{ backgroundColor: party.color }}
            />
            {party.name}
        </span>
    );
}

function formatDateLabel(dateIso: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        day: 'numeric',
        month: 'short',
        timeZone: 'Europe/Madrid',
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
    owner: TooltipParty;
    deal: TooltipDeal | null;
    jornada: {
        week: number;
        points: number | null;
        managerId: number | null;
        managerName: string | null;
        managerColor: string;
    } | null;
}

export function HqPlayerValueChart({
    marketHistory,
    scores,
    missedFixtures,
    ownershipSegments,
}: HqPlayerValueChartProps) {
    const [range, setRange] = useState<Range>(30);
    const [tooltip, setTooltip] = useState<TooltipState | null>(null);
    const [hoverPoint, setHoverPoint] = useState<{ x: number; y: number } | null>(null);
    const svgRef = useRef<SVGSVGElement>(null);
    const containerRef = useRef<HTMLDivElement>(null);
    const tooltipRef = useRef<HTMLDivElement>(null);
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

    // The tooltip sits above the hovered point; when it wouldn't fit there
    // (chart near the top of the viewport) it flips below instead. Its height
    // is only known once rendered, so the placement is applied to the element
    // before paint rather than through state.
    useLayoutEffect(() => {
        const el = tooltipRef.current;

        if (!el || !tooltip) {
            return;
        }

        const fitsAbove =
            tooltip.y - el.offsetHeight * 1.15 >= TOOLTIP_VIEWPORT_MARGIN;

        el.style.transform = fitsAbove
            ? 'translate(-50%, -115%)'
            : `translate(-50%, ${TOOLTIP_BELOW_OFFSET}px)`;
    }, [tooltip]);

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

        // Each jornada's bar sits on the market-history day its fixture was
        // played, so both panels share one time axis. Days are compared as
        // league-local calendar days: the history's dates are local midnights,
        // so matching the nearest instant would push an evening kick-off onto
        // the next day. Jornadas outside the visible range are dropped.
        // A jornada the player has no points for — no lineup row for the
        // finished fixture, or a row without points — is marked "-" instead of
        // a bar, so it stays distinct from a real 0.
        const days = visibleHistory.map((point) => Date.parse(localDateKey(point.date)));
        const jornadas = [
            ...scores.map((score) => ({
                key: `score-${score.id}`,
                fixture: score.fixture,
                points: score.points,
                manager: score.lineup_manager,
            })),
            ...missedFixtures.map((missed) => ({
                key: `missed-${missed.fixture.id}`,
                fixture: missed.fixture,
                points: null,
                manager: missed.lineup_manager,
            })),
        ];
        const placed = jornadas.flatMap((jornada) => {
            const day = Date.parse(localDateKey(jornada.fixture.date));

            if (day < days[0] - DAY_MS || day > days[n - 1] + DAY_MS) {
                return [];
            }

            let index = 0;
            let closest = Infinity;

            days.forEach((candidate, candidateIndex) => {
                const distance = Math.abs(candidate - day);

                if (distance < closest) {
                    closest = distance;
                    index = candidateIndex;
                }
            });

            return [{ jornada, index }];
        });

        const spacing = n > 1 ? width / (n - 1) : width;
        const barWidth = Math.min(26, Math.max(8, spacing * 1.6));
        const pointValues = placed.map(({ jornada }) => jornada.points ?? 0);
        const maxPoints = Math.max(...pointValues, 12);
        const minPoints = Math.min(...pointValues, 0);
        // Only carve out label space below the baseline when there's a
        // negative bar to label.
        const plotTop = POINTS_TOP + BAR_LABEL_SPACE;
        const plotBottom = POINTS_BOTTOM - (minPoints < 0 ? BAR_LABEL_SPACE : 0);
        const pointsToY = (points: number) =>
            plotBottom - ((points - minPoints) / (maxPoints - minPoints)) * (plotBottom - plotTop);
        const zeroY = pointsToY(0);

        const marks = placed.map(({ jornada, index }) => {
            const points = jornada.points;
            const valueY = pointsToY(points ?? 0);
            const isNegative = points !== null && points < 0;
            const manager = jornada.manager;

            return {
                key: jornada.key,
                index,
                cx: xAt(index),
                y: isNegative ? zeroY : valueY,
                height: Math.max(1.5, Math.abs(zeroY - valueY)),
                week: jornada.fixture.week_number,
                points,
                isNegative,
                managerId: manager?.id ?? null,
                managerName: manager?.name ?? null,
                managerColor: manager
                    ? managerColor(manager.primary_color)
                    : 'var(--color-hq-moss-dim)',
            };
        });

        return { xAt, yAt, lineSegments, bandSegments, boundaries, marks, barWidth, zeroY };
    }, [visibleHistory, scores, missedFixtures, ownershipSegments, width]);

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

        for (const mark of geometry?.marks ?? []) {
            if (mark.managerName === null) {
                continue;
            }

            const key = `team-${mark.managerId}`;

            if (!seen.has(key)) {
                seen.set(key, { label: mark.managerName, color: mark.managerColor });
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
        // Hovering near a jornada's bar (or its "-") snaps to that day, so its
        // points and the manager they belonged to are easy to hit.
        const snapped = geometry.marks.find(
            (mark) => Math.abs(mark.cx - relX) <= Math.max(SNAP_RADIUS, geometry.barWidth / 2),
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
            owner: describeParty(segment?.seasonManager ?? null),
            deal: describeDeal(segment, point.date, point.value),
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

    // The day's owner rides along the date, except on a deal day where the
    // seller → buyer row already says who ends up with the player. The manager
    // a jornada's points belonged to always gets its own row under the points.
    const showJornadaManager = tooltip?.jornada?.managerName != null;

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
                        {geometry.marks.map((mark) => (
                            <g key={mark.key}>
                                {mark.points === null ? (
                                    <text
                                        x={mark.cx}
                                        y={mark.y - 5}
                                        textAnchor="middle"
                                        className="font-display"
                                        fontSize={13}
                                        fill="var(--color-hq-moss-dim)"
                                    >
                                        -
                                    </text>
                                ) : (
                                    <>
                                        <rect
                                            x={mark.cx - geometry.barWidth / 2}
                                            y={mark.y}
                                            width={geometry.barWidth}
                                            height={mark.height}
                                            fill={matchPointsColor(mark.points)}
                                            opacity={0.55}
                                        />
                                        <text
                                            x={mark.cx}
                                            y={mark.isNegative ? mark.y + mark.height + 14 : mark.y - 5}
                                            textAnchor="middle"
                                            className="font-display"
                                            fontSize={13}
                                            fill={matchPointsColor(mark.points)}
                                            stroke="var(--color-hq-panel)"
                                            strokeWidth={3}
                                            paintOrder="stroke"
                                        >
                                            {mark.points}
                                        </text>
                                    </>
                                )}
                                <text
                                    x={mark.cx}
                                    y={JORNADA_LABEL_Y}
                                    textAnchor="middle"
                                    className="font-mono"
                                    fontSize={9}
                                    fill="var(--color-hq-moss)"
                                >
                                    J{mark.week}
                                </text>
                            </g>
                        ))}
                        {geometry.marks.length === 0 && (
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
                        ref={tooltipRef}
                        className="pointer-events-none fixed z-[999] min-w-[210px] border border-hq-lime bg-hq-panel-alt px-3 py-2 font-mono text-xs whitespace-nowrap"
                        style={{ left: tooltip.x, top: tooltip.y }}
                    >
                        <div className="flex items-center justify-between gap-4 text-[10px] tracking-wide text-hq-moss uppercase">
                            <span>{tooltip.date}</span>
                            {tooltip.deal === null && (
                                <span className="text-hq-khaki normal-case">
                                    <TooltipPartyLabel party={tooltip.owner} />
                                </span>
                            )}
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
                            <div className="mt-2 border-t border-hq-border-strong pt-2">
                                <div
                                    className="font-bold tracking-wide"
                                    style={{
                                        color:
                                            tooltip.jornada.points === null
                                                ? 'var(--color-hq-moss)'
                                                : matchPointsColor(tooltip.jornada.points),
                                    }}
                                >
                                    {tooltip.jornada.points === null
                                        ? `J${tooltip.jornada.week} · -`
                                        : `J${tooltip.jornada.week} · ${tooltip.jornada.points} PTS`}
                                </div>
                                {showJornadaManager && (
                                    <div className="mt-1 flex items-center gap-1.5 text-hq-khaki">
                                        <span
                                            className="h-2 w-2 shrink-0 rounded-[1px]"
                                            style={{
                                                backgroundColor: tooltip.jornada.managerColor,
                                            }}
                                        />
                                        {tooltip.jornada.managerName}
                                    </div>
                                )}
                            </div>
                        )}
                        {tooltip.deal && (
                            <div className="mt-2 border-t border-hq-border-strong pt-2">
                                <div
                                    className={cn(
                                        'text-[10px] font-bold tracking-wide uppercase',
                                        TYPE_COLORS[tooltip.deal.type],
                                    )}
                                >
                                    {tooltip.deal.label}
                                </div>
                                <div className="mt-1 flex items-center gap-1.5 text-hq-khaki">
                                    {tooltip.deal.type !== 'joined_league' && (
                                        <>
                                            <TooltipPartyLabel party={tooltip.deal.seller} />
                                            <span className="text-hq-moss-dim">→</span>
                                        </>
                                    )}
                                    <TooltipPartyLabel party={tooltip.deal.buyer} />
                                </div>
                                {tooltip.deal.amount !== null && (
                                    <div className="mt-1 text-sm font-bold text-hq-paper">
                                        {formatCurrency(tooltip.deal.amount)}
                                    </div>
                                )}
                                {tooltip.deal.difference !== null &&
                                    tooltip.deal.difference !== 0 && (
                                        <div
                                            className={cn(
                                                'font-bold',
                                                tooltip.deal.difference > 0
                                                    ? 'text-hq-lime'
                                                    : 'text-hq-live',
                                            )}
                                        >
                                            {tooltip.deal.difference > 0 ? '▲' : '▼'}{' '}
                                            {formatCurrency(Math.abs(tooltip.deal.difference))}{' '}
                                            <span className="font-normal text-hq-moss">
                                                {tooltip.deal.difference > 0
                                                    ? 'sobre su valor'
                                                    : 'bajo su valor'}
                                            </span>
                                        </div>
                                    )}
                            </div>
                        )}
                    </div>,
                    document.body,
                )}
        </div>
    );
}
