import type { LucideIcon } from 'lucide-react';
import {
    ChevronDown,
    ChevronsDown,
    ChevronsUp,
    ChevronUp,
    ChevronRight,
    TriangleAlert,
} from 'lucide-react';
import { HqTooltip } from '@/components/hq-tooltip';
import { formatCurrency } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { MarketTrend } from '@/types/models';

interface TrendDisplay {
    icon: LucideIcon;
    label: string;
    rising: boolean;
}

/**
 * The icon always reads "up = improving, down = worsening" (right = same
 * pace): a fall that slows down points up, a fall that speeds up points down.
 * The colour tells whether the value is rising (lime) or falling (red); an
 * inflection is a warning triangle.
 */
const TREND_DISPLAY: Record<MarketTrend, TrendDisplay> = {
    positive_inflection: {
        icon: TriangleAlert,
        label: 'Inflexión positiva: pasó de bajar a subir',
        rising: true,
    },
    rise_accelerating_sharply: {
        icon: ChevronsUp,
        label: 'La subida se acelera mucho',
        rising: true,
    },
    rise_accelerating: {
        icon: ChevronUp,
        label: 'La subida se acelera',
        rising: true,
    },
    rise_steady: {
        icon: ChevronRight,
        label: 'Sube a ritmo constante',
        rising: true,
    },
    rise_decelerating: {
        icon: ChevronDown,
        label: 'La subida se desacelera',
        rising: true,
    },
    rise_decelerating_sharply: {
        icon: ChevronsDown,
        label: 'La subida se desacelera mucho',
        rising: true,
    },
    negative_inflection: {
        icon: TriangleAlert,
        label: 'Inflexión negativa: pasó de subir a bajar',
        rising: false,
    },
    fall_decelerating_sharply: {
        icon: ChevronsUp,
        label: 'La bajada se desacelera mucho',
        rising: false,
    },
    fall_decelerating: {
        icon: ChevronUp,
        label: 'La bajada se desacelera',
        rising: false,
    },
    fall_steady: {
        icon: ChevronRight,
        label: 'Baja a ritmo constante',
        rising: false,
    },
    fall_accelerating: {
        icon: ChevronDown,
        label: 'La bajada se acelera',
        rising: false,
    },
    fall_accelerating_sharply: {
        icon: ChevronsDown,
        label: 'La bajada se acelera mucho',
        rising: false,
    },
};

/** The trend spelled out in Spanish ("Sube a ritmo constante"), and whether the value is rising. */
export function describeMarketTrend(trend: MarketTrend): {
    label: string;
    rising: boolean;
} {
    const { label, rising } = TREND_DISPLAY[trend];

    return { label, rising };
}

interface HqMarketTrendIconProps {
    trend: MarketTrend | null;
    className?: string;
}

/**
 * A player's market trend (all 12 states) as a coloured Lucide icon, with the
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

    const { icon: Icon, label, rising } = TREND_DISPLAY[trend];

    return (
        <HqTooltip
            label={label}
            tone={rising ? 'lime' : 'neg'}
            className="shrink-0 align-middle"
            focusable
        >
            <Icon
                aria-label={label}
                strokeWidth={2.75}
                className={cn(
                    'size-3.5 shrink-0',
                    rising ? 'text-hq-lime' : 'text-hq-neg',
                    className,
                )}
            />
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
 * The market trend icon next to yesterday's signed value change. Both share
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
