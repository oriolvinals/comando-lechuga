import { HqTooltip } from '@/components/hq-tooltip';
import { formatCurrency } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { MarketTrend } from '@/types/models';

type TrendGlyph =
    | 'doubleUp'
    | 'up'
    | 'steady'
    | 'down'
    | 'doubleDown'
    | 'inflectionUp'
    | 'inflectionDown';

interface TrendDisplay {
    glyph: TrendGlyph;
    label: string;
    rising: boolean;
}

/** 14×14 strokes: double/single chevrons for sharp/normal, an arrow for steady, a V/Λ kink for inflections. */
const GLYPH_PATHS: Record<TrendGlyph, string[]> = {
    doubleUp: ['3,7.5 7,3.5 11,7.5', '3,12 7,8 11,12'],
    up: ['3,9.5 7,5.5 11,9.5'],
    steady: ['2,7 11.5,7', '8.2,3.6 11.6,7 8.2,10.4'],
    down: ['3,4.5 7,8.5 11,4.5'],
    doubleDown: ['3,2 7,6 11,2', '3,6.5 7,10.5 11,6.5'],
    inflectionUp: ['1.5,4.5 5.2,10.5 12.3,3.2', '8.4,3 12.4,3 12.4,7'],
    inflectionDown: ['1.5,9.5 5.2,3.5 12.3,10.8', '8.4,11 12.4,11 12.4,7'],
};

/**
 * The glyph always reads "up = improving, down = worsening" (arrow = same
 * pace): a fall that slows down points up, a fall that speeds up points down.
 * The colour tells whether the value is rising (lime) or falling (red); an
 * inflection gets a kink glyph and a thick left border.
 */
const TREND_DISPLAY: Record<MarketTrend, TrendDisplay> = {
    positive_inflection: {
        glyph: 'inflectionUp',
        label: 'Inflexión positiva: pasó de bajar a subir',
        rising: true,
    },
    rise_accelerating_sharply: {
        glyph: 'doubleUp',
        label: 'La subida se acelera mucho',
        rising: true,
    },
    rise_accelerating: {
        glyph: 'up',
        label: 'La subida se acelera',
        rising: true,
    },
    rise_steady: {
        glyph: 'steady',
        label: 'Sube a ritmo constante',
        rising: true,
    },
    rise_decelerating: {
        glyph: 'down',
        label: 'La subida se desacelera',
        rising: true,
    },
    rise_decelerating_sharply: {
        glyph: 'doubleDown',
        label: 'La subida se desacelera mucho',
        rising: true,
    },
    negative_inflection: {
        glyph: 'inflectionDown',
        label: 'Inflexión negativa: pasó de subir a bajar',
        rising: false,
    },
    fall_decelerating_sharply: {
        glyph: 'doubleUp',
        label: 'La bajada se desacelera mucho',
        rising: false,
    },
    fall_decelerating: {
        glyph: 'up',
        label: 'La bajada se desacelera',
        rising: false,
    },
    fall_steady: {
        glyph: 'steady',
        label: 'Baja a ritmo constante',
        rising: false,
    },
    fall_accelerating: {
        glyph: 'down',
        label: 'La bajada se acelera',
        rising: false,
    },
    fall_accelerating_sharply: {
        glyph: 'doubleDown',
        label: 'La bajada se acelera mucho',
        rising: false,
    },
};

interface HqMarketTrendIconProps {
    trend: MarketTrend | null;
    className?: string;
}

/**
 * A player's market trend (all 12 states) as a small framed glyph, with the
 * trend spelled out in Spanish on hover or keyboard focus. Renders nothing
 * without a trend.
 */
export function HqMarketTrendIcon({
    trend,
    className,
}: HqMarketTrendIconProps) {
    if (trend === null) {
        return null;
    }

    const { glyph, label, rising } = TREND_DISPLAY[trend];
    const isInflection = glyph === 'inflectionUp' || glyph === 'inflectionDown';

    return (
        <HqTooltip
            label={label}
            tone={rising ? 'lime' : 'neg'}
            className="shrink-0 align-middle"
            focusable
        >
            <span
                role="img"
                aria-label={label}
                className={cn(
                    'inline-flex size-[17px] shrink-0 items-center justify-center border border-current',
                    rising
                        ? 'bg-hq-lime/12 text-hq-lime'
                        : 'bg-hq-neg/12 text-hq-neg',
                    isInflection && 'border-l-[3px]',
                    className,
                )}
            >
                <svg
                    viewBox="0 0 14 14"
                    aria-hidden="true"
                    className="size-[13px] fill-none stroke-current stroke-[2.2] [stroke-linecap:square] [stroke-linejoin:miter]"
                >
                    {GLYPH_PATHS[glyph].map((points) => (
                        <polyline key={points} points={points} />
                    ))}
                </svg>
            </span>
        </HqTooltip>
    );
}

interface HqMarketValueDifferenceProps {
    /** Yesterday-to-today change in market value. */
    difference: number;
    trend: MarketTrend | null;
    className?: string;
}

/**
 * The market trend glyph next to yesterday's signed value change. Both share
 * a colour: the backend turns a last day that moved against the trend into
 * an inflection in that day's direction.
 */
export function HqMarketValueDifference({
    difference,
    trend,
    className,
}: HqMarketValueDifferenceProps) {
    if (difference === 0 && trend === null) {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex items-center gap-[5px] font-mono text-xs leading-none font-semibold whitespace-nowrap tabular-nums',
                className,
            )}
        >
            <HqMarketTrendIcon trend={trend} />
            {difference !== 0 && (
                <span
                    className={difference > 0 ? 'text-hq-lime' : 'text-hq-neg'}
                >
                    {difference > 0 ? '+' : '−'}
                    {formatCurrency(Math.abs(difference))}
                </span>
            )}
        </span>
    );
}
