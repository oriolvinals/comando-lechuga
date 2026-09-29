import { router } from '@inertiajs/react';
import { Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { HqLed } from '@/components/hq-led';
import { HqTooltip } from '@/components/hq-tooltip';
import { STATUS_LABELS } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import type { MaxBidEstimate, MaxBidRival, PlayerStatus } from '@/types/models';

const OFFER_SPREAD = 0.1;
/** Rough Chivo Mono advance width at 11px — used only to keep the chart's
 * three axis labels from overlapping at narrow widths, never to size text. */
const MONO_CHAR_PX = 7.2;
/** Matches the card's own `min-[1100px]:` breakpoint for the two-tier layout,
 * so the chart's plot height tracks the same desktop/mobile split. */
const DESKTOP_QUERY = '(min-width: 68.75rem)';
const CHART_HEIGHT_DESKTOP = 160;
const CHART_HEIGHT_MOBILE = 110;
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

/** `2026-09-26` → `26/09`, straight from the string so no timezone can shift the day. */
function formatReferenceDate(isoDate: string): string {
    const [, month, day] = isoDate.split('-');

    return `${day}/${month}`;
}

/** Two decimals with a comma, the Spanish way (`0,28`). */
function formatDecimal(value: number): string {
    return value.toLocaleString('es-ES', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function formatPercent(value: number): string {
    return `${Math.round(value * 100)} %`;
}

/** How `participation` was built, e.g. "50 % reciente + 50 % prevista". */
function participationMixHint(estimate: MaxBidEstimate): string {
    if (estimate.next_start_probability === null) {
        return '100 % reciente (sin dato previsto)';
    }

    const weight = estimate.start_probability_weight ?? 0;

    return `${formatPercent(1 - weight)} reciente + ${formatPercent(weight)} prevista`;
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

    return value < 0 ? 'text-hq-neg' : 'text-hq-paper';
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
    const [isDesktop, setIsDesktop] = useState(
        () =>
            typeof window !== 'undefined' &&
            window.matchMedia(DESKTOP_QUERY).matches,
    );
    const clipId = `max-bid-chart-clip-${useId().replace(/[^a-zA-Z0-9-]/g, '')}`;
    const chartHeight = isDesktop ? CHART_HEIGHT_DESKTOP : CHART_HEIGHT_MOBILE;

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

    // Mirrors the card's own `min-[1100px]:` breakpoint so the plot shrinks
    // sensibly under it instead of staying at the tall desktop height.
    useEffect(() => {
        const mql = window.matchMedia(DESKTOP_QUERY);
        const handleChange = () => setIsDesktop(mql.matches);

        handleChange();
        mql.addEventListener('change', handleChange);

        return () => mql.removeEventListener('change', handleChange);
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
    // The y-domain fits the projection line and the bid line (today's value
    // is already projection[0]), with 5 % padding — not the ±10 % offer band,
    // which would otherwise squash the curve into a thin sliver. The band is
    // still drawn at its real width, just clipped to the plot area below.
    const domainValues =
        estimate.bid !== null ? [...projection, estimate.bid] : projection;
    const rawMin = Math.min(...domainValues);
    const rawMax = Math.max(...domainValues);
    let low: number;
    let high: number;

    if (rawMax > rawMin) {
        const padding = (rawMax - rawMin) * 0.05;
        low = rawMin - padding;
        high = rawMax + padding;
    } else {
        // A flat projection (and no bid, or a bid equal to it) has zero span
        // — fall back to a small window around the value so the line isn't a
        // degenerate point.
        const fallbackSpan = Math.max(Math.abs(rawMin), 1) * 0.05;
        low = rawMin - fallbackSpan;
        high = rawMax + fallbackSpan;
    }

    const x = (day: number) => CHART_PAD + (day / 14) * (width - 2 * CHART_PAD);
    const y = (amount: number) =>
        chartHeight -
        CHART_PAD -
        ((amount - low) / (high - low)) * (chartHeight - 2 * CHART_PAD);
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
    // That day's best possible offer (1,1 × its projected value) against the
    // one fixed bid for the whole lock — whether it could ever reach it,
    // rather than repeating the same "puja" figure on every single day.
    const maxOfferAtHover =
        hoveredValue !== null ? hoveredValue * (1 + OFFER_SPREAD) : null;
    const offerReachesBid =
        maxOfferAtHover !== null &&
        estimate.bid !== null &&
        maxOfferAtHover >= estimate.bid;

    return (
        <div ref={containerRef} className="w-full">
            <svg
                ref={svgRef}
                viewBox={`0 0 ${width} ${chartHeight + 16}`}
                className="block w-full cursor-crosshair touch-pan-y"
                role="img"
                aria-label={`Proyección: ${formatMillions(projection[14])} en 14 días`}
                onMouseMove={(event) => handleMove(event.clientX)}
                onMouseLeave={clearHover}
                onTouchStart={(event) => handleMove(event.touches[0].clientX)}
                onTouchMove={(event) => handleMove(event.touches[0].clientX)}
                onTouchEnd={clearHover}
            >
                <defs>
                    <clipPath id={clipId}>
                        <rect
                            x={CHART_PAD}
                            y={CHART_PAD}
                            width={width - 2 * CHART_PAD}
                            height={chartHeight - 2 * CHART_PAD}
                        />
                    </clipPath>
                </defs>
                {Array.from({ length: 15 }, (_, day) => (
                    <line
                        key={day}
                        x1={x(day)}
                        x2={x(day)}
                        y1={CHART_PAD}
                        y2={chartHeight - CHART_PAD}
                        stroke="var(--color-hq-border)"
                        opacity={0.6}
                    />
                ))}
                <polygon
                    points={band}
                    fill={stroke}
                    opacity={0.09}
                    clipPath={`url(#${clipId})`}
                />
                <line
                    x1={CHART_PAD}
                    x2={width - CHART_PAD}
                    y1={y(estimate.value)}
                    y2={y(estimate.value)}
                    stroke="var(--color-hq-border-bright)"
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
                    r={2.8}
                    className="fill-hq-paper"
                />
                {tooltip !== null && (
                    <g pointerEvents="none">
                        <line
                            x1={x(tooltip.day)}
                            x2={x(tooltip.day)}
                            y1={CHART_PAD}
                            y2={chartHeight - CHART_PAD}
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
                    y={chartHeight + 13}
                    className="fill-hq-moss-dim font-mono text-[11px]"
                >
                    hoy
                </text>
                {day7Label !== null && (
                    <text
                        x={width / 2}
                        y={chartHeight + 13}
                        textAnchor="middle"
                        className="fill-hq-moss-dim font-mono text-[11px]"
                    >
                        {day7Label}
                    </text>
                )}
                <text
                    x={width - CHART_PAD}
                    y={chartHeight + 13}
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
                        className="pointer-events-none fixed z-[999] min-w-[160px] border border-hq-gold bg-hq-panel-alt px-3 py-2 font-mono text-[11px] whitespace-nowrap text-hq-paper shadow-[0_10px_28px_rgba(0,0,0,0.55)]"
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
                        {estimate.bid !== null && maxOfferAtHover !== null && (
                            <div
                                className={cn(
                                    'mt-1 font-bold',
                                    offerReachesBid
                                        ? 'text-hq-lime'
                                        : 'text-hq-moss-dim',
                                )}
                            >
                                Oferta máx. {formatMillions(maxOfferAtHover)} ·{' '}
                                {offerReachesBid
                                    ? 'puede superar la puja'
                                    : 'no llega a la puja'}
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
        <div className="mb-1.5 font-mono text-[11px] leading-none font-bold tracking-[0.07em] text-hq-moss uppercase">
            {children}
        </div>
    );
}

function BreakdownRow({
    label,
    value,
    valueClass,
}: {
    label: ReactNode;
    value: string;
    valueClass: string;
}) {
    return (
        <div className="flex items-center justify-between gap-2.5 border-t border-hq-border py-[7px] font-mono text-[11.5px] leading-tight text-hq-moss">
            <span className="min-w-0">{label}</span>
            <b
                className={cn(
                    'shrink-0 text-right font-bold whitespace-nowrap tabular-nums',
                    valueClass,
                )}
            >
                {value}
            </b>
        </div>
    );
}

function RivalRow({ rival }: { rival: MaxBidRival }) {
    return (
        <BreakdownRow
            label={
                <span className="flex items-center gap-1.5">
                    <img
                        src={rival.team.logo}
                        alt=""
                        className="size-4 shrink-0 object-contain"
                    />
                    <span>
                        {rival.team.short_name} ({rival.position}º) · en{' '}
                        {rival.days_until} días
                    </span>
                </span>
            }
            value={`${formatSigned(rival.difficulty)} × ${formatDecimal(rival.weight)}`}
            valueClass={toneClass(rival.difficulty)}
        />
    );
}

function ConfidenceStepper({
    percent,
    onChange,
}: {
    percent: number;
    onChange: (next: number) => void;
}) {
    const buttonClass =
        'flex h-11 w-11 cursor-pointer items-center justify-center font-mono text-base leading-none font-bold text-hq-moss hover:bg-hq-panel-alt hover:text-hq-paper disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:bg-transparent sm:h-8 sm:w-[34px]';

    return (
        <div className="mt-3.5 inline-flex items-center border border-hq-border-bright font-mono text-[13px] font-bold text-hq-paper">
            <button
                type="button"
                onClick={() =>
                    onChange(
                        Math.max(CONFIDENCE_MIN, percent - CONFIDENCE_STEP),
                    )
                }
                disabled={percent <= CONFIDENCE_MIN}
                aria-label="Bajar confianza"
                className={buttonClass}
            >
                −
            </button>
            <span
                aria-live="polite"
                className="flex h-11 min-w-[62px] items-center justify-center border-x border-hq-border-bright tabular-nums sm:h-8"
            >
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
                className={buttonClass}
            >
                +
            </button>
        </div>
    );
}

function ConfidenceExplanation({ lockDays }: { lockDays: number }) {
    return (
        <>
            <p>
                <span className="font-bold">Confianza</span>: probabilidad de
                que, durante los {lockDays} días de blindaje, te llegue una
                oferta que supere lo que pagas. Cuanto más alta, más baja la
                puja.
            </p>
            <ul className="mt-1.5 space-y-1">
                <li>
                    <span className="font-bold">50–65 %</span>: agresiva. Si
                    necesitas al jugador sí o sí o te sobra dinero; es más fácil
                    pasarte.
                </li>
                <li>
                    <span className="font-bold">75 %</span> (por defecto):
                    equilibrio entre conseguirlo y no perder dinero.
                </li>
                <li>
                    <span className="font-bold">80–95 %</span>: prudente. Si vas
                    justo de dinero o solo lo quieres si es buen negocio.
                </li>
            </ul>
        </>
    );
}

function ConfidenceLabel({ lockDays }: { lockDays: number }) {
    return (
        <div className="mt-[7px] flex items-center gap-1.5">
            <span className="hq-label">Confianza</span>
            <HqTooltip
                label={<ConfidenceExplanation lockDays={lockDays} />}
                tone="gold"
                wrap
            >
                <button
                    type="button"
                    aria-label="Qué significa la confianza"
                    className="flex size-11 cursor-help items-center justify-center text-hq-moss transition-colors hover:text-hq-paper focus-visible:text-hq-paper sm:size-6"
                >
                    <Info className="size-3.5" />
                </button>
            </HqTooltip>
        </div>
    );
}

/** The bid as a dot-matrix readout, its thousands dots set in mono so they stay legible. */
function BidReadout({ bid }: { bid: number }) {
    const groups = Math.round(bid).toLocaleString('es-ES').split('.');

    return (
        <HqLed
            tone="lime"
            glow
            className="mt-2.5 block text-[34px] whitespace-nowrap sm:text-[40px]"
        >
            {groups.map((group, index) => (
                <span key={index}>
                    {index > 0 && (
                        <i className="mx-px font-mono text-[0.55em] font-bold not-italic">
                            .
                        </i>
                    )}
                    {group}
                </span>
            ))}
            <span className="text-[24px]"> €</span>
        </HqLed>
    );
}

function Headline({
    estimate,
    playerStatus,
}: {
    estimate: MaxBidEstimate;
    playerStatus: PlayerStatus;
}) {
    const label = <p className="hq-label">Puja máxima rentable</p>;

    if (estimate.status === 'profitable' && estimate.bid !== null) {
        return (
            <>
                {label}
                <BidReadout bid={estimate.bid} />
                <p className="mt-1.5 font-mono text-xs text-hq-moss">
                    <b className="font-bold text-hq-lime">
                        {((estimate.bid_premium ?? 0) * 100).toLocaleString(
                            'es-ES',
                            { maximumFractionDigits: 1, signDisplay: 'always' },
                        )}{' '}
                        %
                    </b>{' '}
                    sobre su valor ({formatMillions(estimate.value)})
                </p>
            </>
        );
    }

    if (estimate.status === 'no_data') {
        return (
            <>
                {label}
                <p className="mt-2.5 font-mono text-base leading-tight font-bold text-hq-moss uppercase">
                    Sin datos suficientes
                </p>
            </>
        );
    }

    const reason =
        estimate.status === 'unavailable'
            ? STATUS_LABELS[playerStatus]
            : `Proyección a la baja: ${formatMillions((estimate.projected_day14 ?? estimate.value) - estimate.value)} en ${estimate.lock_days} días`;

    return (
        <>
            {label}
            <p className="mt-2.5 text-[22px] leading-none font-extrabold text-hq-live uppercase">
                Sin rentabilidad
            </p>
            <p className="mt-1.5 font-mono text-xs text-hq-moss">{reason}</p>
        </>
    );
}

interface HqMaxBidCardProps {
    estimate: MaxBidEstimate;
    playerStatus: PlayerStatus;
}

/**
 * Hidden "puja máxima rentable" panel, fenced by the god-mode amber hazard
 * strip (mock `.god`): the bid the best daily league offer beats with the
 * chosen confidence probability (default 75 %, adjustable via the stepper)
 * during the clause lock, the 14-day projection, and the factors behind it
 * in three columns. In no_data/unavailable states only the headline renders
 * — no chart, no breakdown, since there is nothing to project.
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
        <section
            aria-label="God mode: puja máxima rentable"
            className={cn(
                'border-b border-hq-border',
                profitable
                    ? 'bg-linear-to-b from-hq-amber/5 to-transparent to-60%'
                    : 'bg-linear-to-b from-hq-live/5 to-transparent to-60%',
            )}
        >
            <div className="flex min-h-[38px] items-center gap-3 border-b border-hq-amber/35 px-3.5 font-mono text-xs leading-none font-bold tracking-[0.09em] text-hq-amber uppercase hq-hazard sm:px-4">
                <span className="bg-hq-amber px-1.5 py-1 tracking-[0.14em] text-[#1a1405]">
                    God mode
                </span>
            </div>

            <div
                className={cn(
                    'grid grid-cols-1 gap-3.5 p-3.5 sm:px-5 sm:py-[18px]',
                    hasProjection &&
                        'min-[68.75rem]:grid-cols-[270px_minmax(0,1fr)] min-[68.75rem]:items-center min-[68.75rem]:gap-7',
                )}
            >
                <div>
                    <Headline estimate={estimate} playerStatus={playerStatus} />
                    {hasProjection && (
                        <>
                            <ConfidenceStepper
                                percent={confidencePercent}
                                onChange={handleConfidenceChange}
                            />
                            <ConfidenceLabel lockDays={estimate.lock_days} />
                        </>
                    )}
                </div>

                {hasProjection && <ProjectionChart estimate={estimate} />}
            </div>

            {hasProjection && (
                <div className="grid grid-cols-1 border-t border-hq-amber/25 min-[68.75rem]:grid-cols-3">
                    <div className="border-b border-hq-border px-3.5 py-3 sm:px-4 min-[68.75rem]:border-r min-[68.75rem]:border-b-0">
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

                    <div className="border-b border-hq-border px-3.5 py-3 sm:px-4 min-[68.75rem]:border-r min-[68.75rem]:border-b-0">
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
                            label={`Participación reciente ${estimate.recent_participation
                                .map((match) => `${match.minutes}'`)
                                .join(' · ')}`}
                            value={formatDecimal(
                                estimate.recent_participation_share ?? 0,
                            )}
                            valueClass="text-hq-paper"
                        />
                        <BreakdownRow
                            label="Titularidad prevista (FútbolFantasy)"
                            value={
                                estimate.next_start_probability === null
                                    ? '—'
                                    : formatPercent(
                                          estimate.next_start_probability,
                                      )
                            }
                            valueClass="text-hq-paper"
                        />
                        <BreakdownRow
                            label={
                                <>
                                    Participación usada
                                    <span className="block text-[10.5px] text-hq-moss/70">
                                        {participationMixHint(estimate)}
                                    </span>
                                </>
                            }
                            value={formatDecimal(estimate.participation ?? 0)}
                            valueClass="text-hq-paper"
                        />
                        {firstRival !== null && (
                            <BreakdownRow
                                label={`Próximo partido en ${firstRival.days_until} días`}
                                value={`peso ${formatDecimal(firstRival.weight)}`}
                                valueClass="text-hq-paper"
                            />
                        )}
                    </div>

                    <div className="px-3.5 py-3 sm:px-4">
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

            <p className="border-t border-hq-border px-3.5 py-2.5 font-mono text-[11.5px] leading-snug text-hq-moss-dim sm:px-4">
                Mejor oferta esperada durante los {estimate.lock_days} días de
                blindaje · {Math.round(estimate.confidence * 100)} % de
                confianza
                {estimate.reference_date !== null &&
                    ` · Datos del ${formatReferenceDate(estimate.reference_date)}`}
            </p>
        </section>
    );
}
