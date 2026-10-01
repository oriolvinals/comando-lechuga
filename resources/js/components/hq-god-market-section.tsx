import { router } from '@inertiajs/react';
import { Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { HqDifficultyBars } from '@/components/hq-difficulty-bars';
import { HqLed } from '@/components/hq-led';
import type { HqLedTone } from '@/components/hq-led';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqTooltip } from '@/components/hq-tooltip';
import { STATUS_LABELS } from '@/lib/player-labels';
import { difficultyFromEase } from '@/lib/rival-difficulty';
import { cn } from '@/lib/utils';
import type {
    MaxBidEstimate,
    MaxBidRival,
    PlayerFichaScore,
    PlayerStatus,
    ValueForecast,
    ValueForecastDirection,
    ValueForecastReason,
    ValueForecastReasonKind,
} from '@/types/models';

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

function ProjectionChart({
    estimate,
    forecast,
}: {
    estimate: MaxBidEstimate;
    forecast: ValueForecast | null;
}) {
    const projection = estimate.projection ?? [];
    // The forecast's 80 % range, drawn on day 1 only when the projection was
    // re-anchored to that same forecast.
    const range =
        forecast !== null && estimate.day_one_forecast !== null
            ? { low: forecast.low, high: forecast.high }
            : null;
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
    const domainValues = [
        ...projection,
        ...(estimate.bid !== null ? [estimate.bid] : []),
        ...(range !== null ? [range.low, range.high] : []),
    ];
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
    // The day-7 label is centred, so each half of it must clear its
    // neighbour within its own half of the axis.
    const fitsAround = (day7: string, day14: string) =>
        hoyWidth + gap + textWidth(day7) / 2 <= available / 2 &&
        textWidth(day7) / 2 + gap + textWidth(day14) <= available / 2;
    const fitsFull = fitsAround(day7Full, day14Full);
    const fitsShort = fitsAround(day7Short, day14Short);
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
                {range !== null && (
                    <g stroke="var(--color-hq-lime)" opacity={0.85}>
                        <line
                            x1={x(1)}
                            x2={x(1)}
                            y1={y(range.high)}
                            y2={y(range.low)}
                            strokeWidth={2}
                        />
                        <line
                            x1={x(1) - 4}
                            x2={x(1) + 4}
                            y1={y(range.high)}
                            y2={y(range.high)}
                        />
                        <line
                            x1={x(1) - 4}
                            x2={x(1) + 4}
                            y1={y(range.low)}
                            y2={y(range.low)}
                        />
                    </g>
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
                        {tooltip.day === 1 && range !== null && (
                            <div className="mt-1 text-hq-lime">
                                Previsión · rango {formatMillions(range.low)} –{' '}
                                {formatMillions(range.high)}
                            </div>
                        )}
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

const BREAKDOWN_ROW_CLASS =
    'flex items-center justify-between gap-2.5 border-t border-hq-border py-[7px] font-mono text-[11.5px] leading-tight text-hq-moss';

function BreakdownRow({
    label,
    value,
    valueClass,
    onSelect,
    selectLabel,
}: {
    label: ReactNode;
    value: string;
    valueClass: string;
    /** Makes the whole row a button (a jornada score opens its sheet). */
    onSelect?: () => void;
    selectLabel?: string;
}) {
    const content = (
        <>
            <span
                className={cn(
                    'min-w-0',
                    onSelect &&
                        'underline decoration-hq-moss-dim decoration-dotted underline-offset-[3px] transition-colors group-hover:text-hq-paper group-hover:decoration-hq-paper',
                )}
            >
                {label}
            </span>
            <b
                className={cn(
                    'shrink-0 text-right font-bold whitespace-nowrap tabular-nums',
                    valueClass,
                )}
            >
                {value}
            </b>
        </>
    );

    if (onSelect) {
        return (
            <button
                type="button"
                onClick={onSelect}
                aria-label={selectLabel}
                className={cn(
                    BREAKDOWN_ROW_CLASS,
                    'group w-full cursor-pointer text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hq-lime',
                )}
            >
                {content}
            </button>
        );
    }

    return <div className={BREAKDOWN_ROW_CLASS}>{content}</div>;
}

/** Which calculation uses a factor: the forecast (MAÑ), the bid (PUJA) or none, shown as context (INFO). */
type FactorUse = 'forecast' | 'bid' | 'info';

const FACTOR_USE_LABELS: Record<FactorUse, string> = {
    forecast: 'MAÑ',
    bid: 'PUJA',
    info: 'INFO',
};

const FACTOR_USE_CLASSES: Record<FactorUse, string> = {
    forecast: 'border-hq-lime/50 text-hq-lime',
    bid: 'border-hq-gold/50 text-hq-gold',
    info: 'border-hq-border-bright text-hq-moss',
};

function FactorUses({ uses }: { uses: FactorUse[] }) {
    return (
        <span className="ml-1.5 inline-flex gap-1 align-middle">
            {uses.map((use) => (
                <span
                    key={use}
                    className={cn(
                        'border px-1 font-mono text-[11px] leading-[15px] font-bold',
                        FACTOR_USE_CLASSES[use],
                    )}
                >
                    {FACTOR_USE_LABELS[use]}
                </span>
            ))}
        </span>
    );
}

/** Forecast reasons shown in the Mercado column; the rest go to Deportivo. */
const MARKET_REASON_KINDS: ValueForecastReasonKind[] = [
    'inertia',
    'streak',
    'market',
    'baseline',
    'floor',
];

/** How many days before the forecast's reference date each match reason's match was played. */
const MATCH_REASON_DAYS_BACK: Partial<Record<ValueForecastReasonKind, number>> =
    {
        match_today: 0,
        match_yesterday: 1,
        match_before: 2,
    };

function formatImpact(impactPct: number): string {
    return `${impactPct.toLocaleString('es-ES', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        signDisplay: 'always',
    })} pp`;
}

/** `2026-09-29` minus `days` days, as Y-m-d — UTC arithmetic, so no timezone can shift the day. */
function shiftIsoDate(isoDate: string, days: number): string {
    const date = new Date(`${isoDate}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() - days);

    return date.toISOString().slice(0, 10);
}

/**
 * The ficha score of a played match reason («Partido del 28/09 · 12 pts»),
 * matched on the fixture's UTC date like the forecast's own features; null
 * for other reasons, unplayed matches or a score the ficha doesn't hold.
 */
function reasonScore(
    reason: ValueForecastReason,
    referenceDate: string,
    scores: PlayerFichaScore[],
): PlayerFichaScore | null {
    const daysBack = MATCH_REASON_DAYS_BACK[reason.kind];

    if (daysBack === undefined) {
        return null;
    }

    const matchDate = shiftIsoDate(referenceDate, daysBack);

    return (
        scores.find(
            (score) =>
                score.points !== null &&
                score.fixture.date.slice(0, 10) === matchDate,
        ) ?? null
    );
}

function ReasonRows({
    reasons,
    referenceDate,
    scores,
    onScoreSelect,
}: {
    reasons: ValueForecastReason[];
    referenceDate: string;
    scores: PlayerFichaScore[];
    onScoreSelect?: (score: PlayerFichaScore) => void;
}) {
    return reasons.map((reason) => {
        const score = onScoreSelect
            ? reasonScore(reason, referenceDate, scores)
            : null;

        return (
            <BreakdownRow
                key={reason.kind}
                label={
                    <>
                        {reason.label}
                        <FactorUses uses={['forecast']} />
                    </>
                }
                value={formatImpact(reason.impact_pct)}
                valueClass={toneClass(reason.impact_pct)}
                onSelect={
                    score !== null && onScoreSelect
                        ? () => onScoreSelect(score)
                        : undefined
                }
                selectLabel={
                    score !== null
                        ? `Ver ficha de la jornada J${score.fixture.week_number} · ${score.points} pts`
                        : undefined
                }
            />
        );
    });
}

function RivalRow({ rival }: { rival: MaxBidRival }) {
    return (
        <div className="flex items-center justify-between gap-2.5 border-t border-hq-border py-[7px] font-mono text-[11.5px] leading-tight text-hq-moss">
            <span className="flex min-w-0 items-center gap-1.5">
                <img
                    src={rival.team.logo}
                    alt=""
                    className="size-4 shrink-0 object-contain"
                />
                <span className="truncate">
                    {rival.team.short_name} ({rival.position}º) · en{' '}
                    {rival.days_until} días
                </span>
            </span>
            <HqDifficultyBars
                difficulty={difficultyFromEase(rival.difficulty)}
                layout="gauge"
                className="shrink-0"
            />
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
                    className="flex size-11 cursor-pointer items-center justify-center text-hq-moss transition-colors hover:text-hq-paper focus-visible:text-hq-paper sm:size-6"
                >
                    <Info className="size-3.5" />
                </button>
            </HqTooltip>
        </div>
    );
}

/** An amount as a dot-matrix readout, its thousands dots set in mono so they stay legible. */
function LedAmount({ amount, tone }: { amount: number; tone: HqLedTone }) {
    const groups = Math.round(amount).toLocaleString('es-ES').split('.');

    return (
        <HqLed
            tone={tone}
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

const FORECAST_TONES: Record<ValueForecastDirection, HqLedTone> = {
    up: 'lime',
    stable: 'paper',
    down: 'live',
};

function formatSignedPercent(value: number): string {
    return `${value.toLocaleString('es-ES', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        signDisplay: 'always',
    })} %`;
}

function ForecastReadout({ forecast }: { forecast: ValueForecast }) {
    const likelyUp = forecast.up_probability >= 0.5;

    return (
        <div>
            <div className="flex items-center justify-between gap-2">
                <p className="hq-label">
                    Mañana · {formatReferenceDate(forecast.target_date)}
                </p>
                <span
                    className={cn(
                        'border px-1.5 py-0.5 font-mono text-[11px] leading-none font-bold whitespace-nowrap tabular-nums',
                        likelyUp
                            ? 'border-hq-lime/50 text-hq-lime'
                            : 'border-hq-neg/50 text-hq-neg',
                    )}
                >
                    P(sube) {formatPercent(forecast.up_probability)}
                </span>
            </div>
            <LedAmount
                amount={forecast.predicted_value}
                tone={FORECAST_TONES[forecast.direction]}
            />
            <p className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 font-mono text-xs text-hq-moss">
                <HqMarketValueDifference
                    difference={forecast.change}
                    trend={forecast.trend}
                />
                <b
                    className={cn(
                        'font-bold tabular-nums',
                        toneClass(forecast.change_pct),
                    )}
                >
                    {formatSignedPercent(forecast.change_pct)}
                </b>
            </p>
            <p className="mt-1 font-mono text-xs whitespace-nowrap text-hq-moss">
                rango {formatMillions(forecast.low)} –{' '}
                {formatMillions(forecast.high)}
            </p>
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
    const label = <p className="hq-label">Puja máxima rentable</p>;

    if (estimate.status === 'profitable' && estimate.bid !== null) {
        return (
            <>
                {label}
                <LedAmount amount={estimate.bid} tone="amber" />
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

interface HqGodMarketSectionProps {
    estimate: MaxBidEstimate;
    forecast: ValueForecast | null;
    playerStatus: PlayerStatus;
    /** The ficha's jornada scores — a played match reason opens its sheet. */
    scores: PlayerFichaScore[];
    onScoreSelect?: (score: PlayerFichaScore) => void;
}

/**
 * God mode «Mercado» section of the ficha (mock _prevision-valor.html, variant
 * A), fenced by the `hq-god-frame` tape: tomorrow's value forecast and the
 * max profitable bid side by side, the bid's 14-day projection (day 1 = the
 * forecast, with its 80 % range) and the factors behind both, each tagged
 * with the calculation that uses it (MAÑ / PUJA / INFO).
 */
export function HqGodMarketSection({
    estimate,
    forecast,
    playerStatus,
    scores,
    onScoreSelect,
}: HqGodMarketSectionProps) {
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
                only: ['maxBid', 'valueForecast'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        }, CONFIDENCE_DEBOUNCE_MS);
    }

    return (
        <section
            aria-label="Mercado"
            className={cn(
                'hq-god-frame',
                profitable
                    ? 'bg-linear-to-b from-hq-amber/5 to-transparent to-60%'
                    : 'bg-linear-to-b from-hq-live/5 to-transparent to-60%',
            )}
        >
            <h2 className="px-3.5 pt-3 font-mono text-[11px] leading-none font-bold tracking-[0.07em] text-hq-amber uppercase sm:px-5">
                Mercado
            </h2>
            <div
                className={cn(
                    'grid grid-cols-1 gap-3.5 p-3.5 sm:px-5 sm:py-[18px]',
                    hasProjection &&
                        'min-[68.75rem]:grid-cols-[270px_minmax(0,1fr)] min-[68.75rem]:items-center min-[68.75rem]:gap-7',
                )}
            >
                <div className="space-y-5 sm:max-w-[270px]">
                    {forecast !== null && (
                        <ForecastReadout forecast={forecast} />
                    )}
                    <div>
                        <Headline
                            estimate={estimate}
                            playerStatus={playerStatus}
                        />
                        {hasProjection && (
                            <>
                                <ConfidenceStepper
                                    percent={confidencePercent}
                                    onChange={handleConfidenceChange}
                                />
                                <ConfidenceLabel
                                    lockDays={estimate.lock_days}
                                />
                            </>
                        )}
                    </div>
                </div>

                {hasProjection && (
                    <ProjectionChart estimate={estimate} forecast={forecast} />
                )}
            </div>

            {(hasProjection || forecast !== null) && (
                <div
                    className={cn(
                        'grid grid-cols-1 border-t border-hq-amber/25',
                        hasProjection
                            ? 'min-[68.75rem]:grid-cols-3'
                            : 'min-[68.75rem]:grid-cols-2',
                    )}
                >
                    <div className="border-b border-hq-border px-3.5 py-3 sm:px-4 min-[68.75rem]:border-r min-[68.75rem]:border-b-0">
                        <ColumnHeading>Mercado</ColumnHeading>
                        {forecast !== null && (
                            <ReasonRows
                                reasons={forecast.reasons.filter((reason) =>
                                    MARKET_REASON_KINDS.includes(reason.kind),
                                )}
                                referenceDate={forecast.reference_date}
                                scores={scores}
                                onScoreSelect={onScoreSelect}
                            />
                        )}
                        {hasProjection && (
                            <>
                                <BreakdownRow
                                    label={
                                        <>
                                            Momentum (3 días)
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatDaily(
                                        estimate.momentum_increment ?? 0,
                                    )}
                                    valueClass={toneClass(
                                        estimate.momentum_increment ?? 0,
                                    )}
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            Mercado general
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatDaily(
                                        estimate.market_adjustment ?? 0,
                                    )}
                                    valueClass={toneClass(
                                        estimate.market_adjustment ?? 0,
                                    )}
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            Deportivo
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatDaily(
                                        estimate.sport_adjustment ?? 0,
                                    )}
                                    valueClass={toneClass(
                                        estimate.sport_adjustment ?? 0,
                                    )}
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            Proyección día {estimate.lock_days}
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatMillions(
                                        estimate.projected_day14 ?? 0,
                                    )}
                                    valueClass="text-hq-paper"
                                />
                            </>
                        )}
                    </div>

                    <div
                        className={cn(
                            'px-3.5 py-3 sm:px-4',
                            hasProjection &&
                                'border-b border-hq-border min-[68.75rem]:border-r min-[68.75rem]:border-b-0',
                        )}
                    >
                        <ColumnHeading>
                            Deportivo
                            {hasProjection &&
                                ` · S ${formatSigned(estimate.sport_score ?? 0)}`}
                        </ColumnHeading>
                        {forecast !== null && (
                            <ReasonRows
                                reasons={forecast.reasons.filter(
                                    (reason) =>
                                        !MARKET_REASON_KINDS.includes(
                                            reason.kind,
                                        ),
                                )}
                                referenceDate={forecast.reference_date}
                                scores={scores}
                                onScoreSelect={onScoreSelect}
                            />
                        )}
                        <BreakdownRow
                            label={
                                <>
                                    Estado
                                    <FactorUses
                                        uses={
                                            hasProjection
                                                ? ['bid', 'info']
                                                : ['info']
                                        }
                                    />
                                </>
                            }
                            value={STATUS_LABELS[playerStatus]}
                            valueClass={
                                playerStatus === 'ok'
                                    ? 'text-hq-lime'
                                    : 'text-hq-neg'
                            }
                        />
                        {hasProjection && (
                            <>
                                <BreakdownRow
                                    label={
                                        <>
                                            Forma
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatSigned(estimate.form ?? 0)}
                                    valueClass={toneClass(estimate.form ?? 0)}
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            {`Participación reciente ${estimate.recent_participation
                                                .map(
                                                    (match) =>
                                                        `${match.minutes}'`,
                                                )
                                                .join(' · ')}`}
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatDecimal(
                                        estimate.recent_participation_share ??
                                            0,
                                    )}
                                    valueClass="text-hq-paper"
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            Titularidad prevista
                                            <FactorUses
                                                uses={['bid', 'info']}
                                            />
                                        </>
                                    }
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
                                            <FactorUses uses={['bid']} />
                                            <span className="block text-[11px] text-hq-moss/70">
                                                {participationMixHint(estimate)}
                                            </span>
                                        </>
                                    }
                                    value={formatDecimal(
                                        estimate.participation ?? 0,
                                    )}
                                    valueClass="text-hq-paper"
                                />
                                {firstRival !== null && (
                                    <BreakdownRow
                                        label={
                                            <>
                                                Próximo partido en{' '}
                                                {firstRival.days_until} días
                                                <FactorUses uses={['bid']} />
                                            </>
                                        }
                                        value={`peso ${formatDecimal(firstRival.weight)}`}
                                        valueClass="text-hq-paper"
                                    />
                                )}
                            </>
                        )}
                    </div>

                    {hasProjection && (
                        <div className="px-3.5 py-3 sm:px-4">
                            <ColumnHeading>
                                Próximos rivales
                                <FactorUses uses={['bid', 'info']} />
                            </ColumnHeading>
                            {estimate.upcoming_rivals.map((rival) => (
                                <RivalRow
                                    key={`${rival.team.id}-${rival.days_until}`}
                                    rival={rival}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
