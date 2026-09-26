import { router } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { formatCurrency } from '@/lib/format';
import { STATUS_LABELS } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import type { MaxBidEstimate, MaxBidRival, PlayerStatus } from '@/types/models';

const OFFER_SPREAD = 0.1;
/** Rough JetBrains Mono advance width at 11px — used only to keep the chart's
 * three axis labels from overlapping at narrow widths, never to size text. */
const MONO_CHAR_PX = 6.6;
const CHART_HEIGHT = 80;
const CHART_PAD = 4;
const TOOLTIP_VIEWPORT_MARGIN = 8;
const CONFIDENCE_MIN = 50;
const CONFIDENCE_MAX = 95;
const CONFIDENCE_STEP = 5;
const CONFIDENCE_DEBOUNCE_MS = 300;

function formatMillions(amount: number): string {
    return `${(amount / 1_000_000).toLocaleString('es-ES', { maximumFractionDigits: 2 })} M€`;
}

function formatDaily(amount: number): string {
    const sign = amount >= 0 ? '+' : '−';
    const absolute = Math.abs(amount);
    const text =
        absolute >= 1_000_000
            ? `${(absolute / 1_000_000).toLocaleString('es-ES', { maximumFractionDigits: 2 })} M`
            : `${Math.round(absolute / 1_000).toLocaleString('es-ES')} k`;

    return `${sign}${text}/día`;
}

function formatSigned(value: number): string {
    return value.toLocaleString('es-ES', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        signDisplay: 'always',
    });
}

function toneClass(value: number): string {
    if (value > 0) {
        return 'text-hq-lime';
    }

    return value < 0 ? 'text-hq-live' : 'text-hq-paper';
}

function textWidth(text: string): number {
    return text.length * MONO_CHAR_PX;
}

interface ChartTooltipState {
    x: number;
    y: number;
    day: number;
}

function ProjectionChart({ estimate }: { estimate: MaxBidEstimate }) {
    const projection = estimate.projection ?? [];
    const containerRef = useRef<HTMLDivElement>(null);
    const svgRef = useRef<SVGSVGElement>(null);
    const tooltipRef = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(600);
    const [tooltip, setTooltip] = useState<ChartTooltipState | null>(null);

    // The viewBox width tracks the container's real pixel width (like
    // HqPlayerValueChart) so text renders at its true size and the axis
    // labels can be measured against the chart's actual available room.
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

    useLayoutEffect(() => {
        const el = tooltipRef.current;

        if (!el || !tooltip) {
            return;
        }

        const fitsAbove =
            tooltip.y - el.offsetHeight * 1.15 >= TOOLTIP_VIEWPORT_MARGIN;
        const verticalTransform = fitsAbove ? '-115%' : '14px';

        // The tooltip is positioned at `left: tooltip.x` and normally
        // centered with `translateX(-50%)`. Near day 0 or day 14 that
        // centering can push it past the viewport edge, so nudge it inward
        // by exactly enough to keep it fully on screen, without moving its
        // anchor point (the hovered day) off the -50% baseline unless needed.
        const halfWidth = el.offsetWidth / 2;
        const minCenter = TOOLTIP_VIEWPORT_MARGIN + halfWidth;
        const maxCenter =
            window.innerWidth - TOOLTIP_VIEWPORT_MARGIN - halfWidth;
        const clampedX = Math.min(maxCenter, Math.max(minCenter, tooltip.x));
        const horizontalNudge = clampedX - tooltip.x;

        el.style.transform = `translate(calc(-50% + ${horizontalNudge}px), ${verticalTransform})`;
    }, [tooltip]);

    const profitable = estimate.status === 'profitable';
    const low = Math.min(...projection) * (1 - OFFER_SPREAD);
    const high =
        Math.max(...projection, estimate.bid ?? 0) * (1 + OFFER_SPREAD);
    const x = (day: number) => CHART_PAD + (day / 14) * (width - 2 * CHART_PAD);
    const y = (amount: number) =>
        CHART_HEIGHT -
        CHART_PAD -
        ((amount - low) / (high - low)) * (CHART_HEIGHT - 2 * CHART_PAD);
    const line = projection.map((v, day) => `${x(day)},${y(v)}`).join(' ');
    const band = [
        ...projection.map((v, day) => `${x(day)},${y(v * (1 + OFFER_SPREAD))}`),
        ...projection
            .map((v, day) => `${x(day)},${y(v * (1 - OFFER_SPREAD))}`)
            .reverse(),
    ].join(' ');
    const stroke = profitable ? 'var(--color-hq-lime)' : 'var(--color-hq-live)';

    // "hoy" / "día 7 · x" / "día 14 · x" never overlap regardless of the
    // card's real width: fall back to shorter labels, and drop the middle
    // one entirely, before letting any two labels collide.
    const day7Full = `día 7 · ${formatMillions(projection[7])}`;
    const day7Short = `d7 · ${formatMillions(projection[7])}`;
    const day14Full = `día 14 · ${formatMillions(projection[14])}`;
    const day14Short = `d14 · ${formatMillions(projection[14])}`;
    const gap = 10;
    const available = width - 2 * CHART_PAD;
    const hoyWidth = textWidth('hoy');
    const fitsFull =
        hoyWidth + gap + textWidth(day7Full) + gap + textWidth(day14Full) <=
        available;
    const fitsShort =
        hoyWidth + gap + textWidth(day7Short) + gap + textWidth(day14Short) <=
        available;
    const day14Label = fitsFull ? day14Full : day14Short;
    const day7Label = fitsFull ? day7Full : fitsShort ? day7Short : null;

    function handleMove(clientX: number) {
        const svg = svgRef.current;

        if (!svg) {
            return;
        }

        const rect = svg.getBoundingClientRect();
        const relX = ((clientX - rect.left) / rect.width) * width;
        const pxRatio = rect.width / width;
        const day = Math.max(0, Math.min(14, Math.round((relX / width) * 14)));

        setTooltip({
            x: rect.left + x(day) * pxRatio,
            y: rect.top + y(projection[day]) * pxRatio,
            day,
        });
    }

    function clearHover() {
        setTooltip(null);
    }

    const hoveredValue = tooltip !== null ? projection[tooltip.day] : null;

    return (
        <div ref={containerRef} className="w-full">
            <svg
                ref={svgRef}
                viewBox={`0 0 ${width} ${CHART_HEIGHT + 16}`}
                className="block w-full cursor-crosshair touch-none"
                role="img"
                aria-label={`Proyección: ${formatMillions(projection[14])} en 14 días`}
                onMouseMove={(event) => handleMove(event.clientX)}
                onMouseLeave={clearHover}
                onTouchStart={(event) => handleMove(event.touches[0].clientX)}
                onTouchMove={(event) => handleMove(event.touches[0].clientX)}
                onTouchEnd={clearHover}
            >
                <polygon points={band} fill={stroke} opacity={0.08} />
                <line
                    x1={CHART_PAD}
                    x2={width - CHART_PAD}
                    y1={y(estimate.value)}
                    y2={y(estimate.value)}
                    stroke="var(--color-hq-border-strong)"
                />
                {estimate.bid !== null && (
                    <>
                        <line
                            x1={CHART_PAD}
                            x2={width - CHART_PAD}
                            y1={y(estimate.bid)}
                            y2={y(estimate.bid)}
                            stroke="var(--color-hq-gold)"
                            strokeDasharray="4 3"
                        />
                        <text
                            x={width - CHART_PAD}
                            y={y(estimate.bid) - 3}
                            textAnchor="end"
                            className="fill-hq-gold font-mono text-[11px]"
                        >
                            puja
                        </text>
                    </>
                )}
                <polyline
                    points={line}
                    fill="none"
                    stroke={stroke}
                    strokeWidth={1.8}
                />
                <circle
                    cx={x(0)}
                    cy={y(estimate.value)}
                    r={2.5}
                    className="fill-hq-paper"
                />
                {tooltip !== null && (
                    <g pointerEvents="none">
                        <line
                            x1={x(tooltip.day)}
                            x2={x(tooltip.day)}
                            y1={CHART_PAD}
                            y2={CHART_HEIGHT - CHART_PAD}
                            stroke="var(--color-hq-paper)"
                            strokeWidth={1}
                            opacity={0.35}
                        />
                        <circle
                            cx={x(tooltip.day)}
                            cy={y(hoveredValue ?? estimate.value)}
                            r={3}
                            fill="var(--color-hq-lime)"
                            stroke="var(--color-hq-ink)"
                            strokeWidth={1.2}
                        />
                    </g>
                )}
                <text
                    x={CHART_PAD}
                    y={CHART_HEIGHT + 13}
                    className="fill-hq-moss-dim font-mono text-[11px]"
                >
                    hoy
                </text>
                {day7Label !== null && (
                    <text
                        x={width / 2}
                        y={CHART_HEIGHT + 13}
                        textAnchor="middle"
                        className="fill-hq-moss-dim font-mono text-[11px]"
                    >
                        {day7Label}
                    </text>
                )}
                <text
                    x={width - CHART_PAD}
                    y={CHART_HEIGHT + 13}
                    textAnchor="end"
                    className="fill-hq-moss-dim font-mono text-[11px]"
                >
                    {day14Label}
                </text>
            </svg>

            {tooltip !== null &&
                hoveredValue !== null &&
                createPortal(
                    <div
                        ref={tooltipRef}
                        className="pointer-events-none fixed z-[999] min-w-[160px] border border-hq-gold bg-hq-panel-alt px-3 py-2 font-mono text-[11px] whitespace-nowrap text-hq-paper"
                        style={{ left: tooltip.x, top: tooltip.y }}
                    >
                        <div className="text-[11px] tracking-wide text-hq-moss uppercase">
                            {tooltip.day === 0
                                ? 'Día 0 · hoy'
                                : `Día ${tooltip.day}`}
                        </div>
                        <div className="mt-0.5 font-bold">
                            {formatMillions(hoveredValue)}
                        </div>
                        <div className="mt-1 text-hq-moss">
                            Oferta:{' '}
                            {formatMillions(hoveredValue * (1 - OFFER_SPREAD))}
                            {' – '}
                            {formatMillions(hoveredValue * (1 + OFFER_SPREAD))}
                        </div>
                        {estimate.bid !== null && (
                            <div className="mt-1 font-bold text-hq-gold">
                                puja {formatMillions(estimate.bid)}
                            </div>
                        )}
                    </div>,
                    document.body,
                )}
        </div>
    );
}

function ColumnHeading({ children }: { children: ReactNode }) {
    return (
        <div className="mb-1 font-mono text-[11px] tracking-wide text-hq-moss uppercase">
            {children}
        </div>
    );
}

function BreakdownRow({
    label,
    value,
    valueClass,
}: {
    label: string;
    value: string;
    valueClass: string;
}) {
    return (
        <div className="flex items-center justify-between gap-2 border-t border-hq-border py-1.5 font-mono text-[11px]">
            <span className="text-hq-moss">{label}</span>
            <span className={cn('text-right font-bold', valueClass)}>
                {value}
            </span>
        </div>
    );
}

function RivalRow({ rival }: { rival: MaxBidRival }) {
    return (
        <div className="flex items-center justify-between gap-2 border-t border-hq-border py-1.5 font-mono text-[11px]">
            <span className="flex items-center gap-1.5 text-hq-moss">
                <img
                    src={rival.team.logo}
                    alt=""
                    className="h-4 w-4 shrink-0 object-contain"
                />
                <span>
                    {rival.team.short_name} ({rival.position}º) · en{' '}
                    {rival.days_until} días
                </span>
            </span>
            <span
                className={cn(
                    'shrink-0 font-bold',
                    toneClass(rival.difficulty),
                )}
            >
                {formatSigned(rival.difficulty)} × {rival.weight.toFixed(2)}
            </span>
        </div>
    );
}

function ConfidenceStepper({
    percent,
    onChange,
}: {
    percent: number;
    onChange: (next: number) => void;
}) {
    return (
        <div className="mt-2 inline-flex items-center border border-hq-border-strong font-mono text-[11px] font-bold text-hq-paper">
            <button
                type="button"
                onClick={() =>
                    onChange(
                        Math.max(CONFIDENCE_MIN, percent - CONFIDENCE_STEP),
                    )
                }
                disabled={percent <= CONFIDENCE_MIN}
                aria-label="Bajar confianza"
                className="px-2 py-1 text-hq-moss hover:text-hq-paper disabled:cursor-not-allowed disabled:text-hq-moss-dim disabled:opacity-50"
            >
                −
            </button>
            <span className="border-x border-hq-border-strong px-2 py-1 tabular-nums">
                {percent} %
            </span>
            <button
                type="button"
                onClick={() =>
                    onChange(
                        Math.min(CONFIDENCE_MAX, percent + CONFIDENCE_STEP),
                    )
                }
                disabled={percent >= CONFIDENCE_MAX}
                aria-label="Subir confianza"
                className="px-2 py-1 text-hq-moss hover:text-hq-paper disabled:cursor-not-allowed disabled:text-hq-moss-dim disabled:opacity-50"
            >
                +
            </button>
        </div>
    );
}

function Headline({
    estimate,
    playerStatus,
}: {
    estimate: MaxBidEstimate;
    playerStatus: PlayerStatus;
}) {
    if (estimate.status === 'profitable' && estimate.bid !== null) {
        return (
            <>
                <p className="mt-2 font-mono text-2xl font-bold text-hq-lime">
                    {formatCurrency(estimate.bid)}
                </p>
                <p className="font-mono text-[11px] text-hq-moss">
                    <span className="font-bold text-hq-lime">
                        {((estimate.bid_premium ?? 0) * 100).toLocaleString(
                            'es-ES',
                            { maximumFractionDigits: 1, signDisplay: 'always' },
                        )}{' '}
                        %
                    </span>{' '}
                    sobre su valor ({formatMillions(estimate.value)})
                </p>
            </>
        );
    }

    if (estimate.status === 'no_data') {
        return (
            <p className="mt-2 font-mono text-sm font-bold text-hq-moss uppercase">
                Sin datos suficientes
            </p>
        );
    }

    const reason =
        estimate.status === 'unavailable'
            ? STATUS_LABELS[playerStatus]
            : `Proyección a la baja: ${formatMillions((estimate.projected_day14 ?? estimate.value) - estimate.value)} en ${estimate.lock_days} días`;

    return (
        <>
            <p className="mt-2 font-mono text-lg font-bold text-hq-live uppercase">
                Sin rentabilidad
            </p>
            <p className="font-mono text-[11px] text-hq-moss">{reason}</p>
        </>
    );
}

interface HqMaxBidCardProps {
    estimate: MaxBidEstimate;
    playerStatus: PlayerStatus;
}

/**
 * Hidden "puja máxima rentable" card: the bid the best daily league offer
 * beats with the chosen confidence probability (default 75 %, adjustable via
 * the stepper) during the clause lock, the projected value, and the factors
 * behind it. Full-width, above "Evolución" (whose section title already
 * labels this card, so no inner label is repeated here). In no_data/
 * unavailable states only the headline renders — no chart, no breakdown,
 * since there is nothing to project.
 */
export function HqMaxBidCard({ estimate, playerStatus }: HqMaxBidCardProps) {
    const hasProjection = estimate.projection !== null;
    const profitable = estimate.status === 'profitable';
    const firstRival = estimate.upcoming_rivals[0] ?? null;
    const [confidencePercent, setConfidencePercent] = useState(() =>
        Math.round(estimate.confidence * 100),
    );
    // Stay in sync when the estimate prop changes for a reason other than our
    // own stepper (e.g. browser back/forward restoring a different
    // ?confianza) — adjusted during render rather than in an effect, per
    // https://react.dev/learn/you-might-not-need-an-effect.
    const [lastSyncedConfidence, setLastSyncedConfidence] = useState(
        estimate.confidence,
    );

    if (estimate.confidence !== lastSyncedConfidence) {
        setLastSyncedConfidence(estimate.confidence);
        setConfidencePercent(Math.round(estimate.confidence * 100));
    }

    const confidenceTimeout = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(confidenceTimeout.current), []);

    function handleConfidenceChange(nextPercent: number) {
        setConfidencePercent(nextPercent);
        clearTimeout(confidenceTimeout.current);

        confidenceTimeout.current = setTimeout(() => {
            const params = new URLSearchParams(window.location.search);
            params.set('confianza', String(nextPercent));

            router.get(window.location.pathname, Object.fromEntries(params), {
                only: ['maxBid'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        }, CONFIDENCE_DEBOUNCE_MS);
    }

    return (
        <div
            className="hq-card-cut p-4 md:p-5"
            style={
                {
                    '--hq-card-tint': profitable
                        ? 'rgb(196 255 61 / 0.05)'
                        : 'rgb(255 61 90 / 0.05)',
                } as CSSProperties
            }
        >
            <div
                className={cn(
                    'flex flex-col gap-6',
                    hasProjection &&
                        'min-[1100px]:grid min-[1100px]:grid-cols-[240px_minmax(0,1fr)] min-[1100px]:items-center min-[1100px]:gap-7',
                )}
            >
                <div>
                    <Headline estimate={estimate} playerStatus={playerStatus} />
                    {hasProjection && (
                        <ConfidenceStepper
                            percent={confidencePercent}
                            onChange={handleConfidenceChange}
                        />
                    )}
                </div>

                {hasProjection && <ProjectionChart estimate={estimate} />}
            </div>

            {hasProjection && (
                <div className="mt-4 grid grid-cols-1 gap-6 border-t border-hq-border-strong pt-4 min-[1100px]:grid-cols-3">
                    <div>
                        <ColumnHeading>Mercado</ColumnHeading>
                        <BreakdownRow
                            label="Momentum (3 días)"
                            value={formatDaily(
                                estimate.momentum_increment ?? 0,
                            )}
                            valueClass={toneClass(
                                estimate.momentum_increment ?? 0,
                            )}
                        />
                        <BreakdownRow
                            label="Mercado general"
                            value={formatDaily(estimate.market_adjustment ?? 0)}
                            valueClass={toneClass(
                                estimate.market_adjustment ?? 0,
                            )}
                        />
                        <BreakdownRow
                            label="Deportivo"
                            value={formatDaily(estimate.sport_adjustment ?? 0)}
                            valueClass={toneClass(
                                estimate.sport_adjustment ?? 0,
                            )}
                        />
                        <BreakdownRow
                            label={`Proyección día ${estimate.lock_days}`}
                            value={formatMillions(
                                estimate.projected_day14 ?? 0,
                            )}
                            valueClass="text-hq-paper"
                        />
                    </div>

                    <div>
                        <ColumnHeading>
                            Deportivo · S{' '}
                            {formatSigned(estimate.sport_score ?? 0)}
                        </ColumnHeading>
                        <BreakdownRow
                            label="Forma"
                            value={formatSigned(estimate.form ?? 0)}
                            valueClass={toneClass(estimate.form ?? 0)}
                        />
                        <BreakdownRow
                            label={`Participación ${estimate.recent_participation
                                .map((match) => `${match.minutes}'`)
                                .join(' · ')}`}
                            value={(estimate.participation ?? 0).toFixed(2)}
                            valueClass="text-hq-paper"
                        />
                        {firstRival !== null && (
                            <BreakdownRow
                                label={`Próximo partido en ${firstRival.days_until} días`}
                                value={`peso ${firstRival.weight.toFixed(2)}`}
                                valueClass="text-hq-paper"
                            />
                        )}
                    </div>

                    <div>
                        <ColumnHeading>
                            Rivales ·{' '}
                            {formatSigned(estimate.rivals_effect ?? 0)}
                        </ColumnHeading>
                        {estimate.upcoming_rivals.map((rival) => (
                            <RivalRow
                                key={`${rival.team.id}-${rival.days_until}`}
                                rival={rival}
                            />
                        ))}
                    </div>
                </div>
            )}

            <p className="mt-4 border-t border-hq-border pt-2 font-mono text-[11px] leading-snug text-hq-moss-dim">
                Mejor oferta esperada durante los {estimate.lock_days} días de
                blindaje · {Math.round(estimate.confidence * 100)} % de
                confianza
            </p>
        </div>
    );
}
