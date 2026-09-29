import { HqTooltip } from '@/components/hq-tooltip';
import { daznPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { DaznFields } from '@/types/models';

type HqDaznBadgeSize = 'xs' | 'sm' | 'md' | 'row';

const LOGO_SIZE_CLASSES: Record<HqDaznBadgeSize, string> = {
    xs: 'h-3 w-3',
    sm: 'h-3.5 w-3.5',
    md: 'h-4 w-4',
    row: 'h-3.5 w-3.5',
};

interface HqDaznBadgeProps {
    entry: DaznFields;
    /**
     * `row` is the player ficha's match-log cell, which already sits inside a
     * toggle button — so it stays out of the tab order there.
     */
    size?: HqDaznBadgeSize;
    /** Sits the badge on a dark plate — for the pitch, where it overlays the grass. */
    plate?: boolean;
}

/**
 * A player's DAZN rating for one match: the official one (with our frozen
 * estimate on hover), or our provisional estimate — pulsing, amber tooltip
 * with the reasons — until LaLiga Fantasy publishes it. Renders nothing when
 * there is neither.
 */
export function HqDaznBadge({
    entry,
    size = 'sm',
    plate = false,
}: HqDaznBadgeProps) {
    const official = entry.dazn_points;
    const estimate = entry.dazn_estimate;

    if (official !== null) {
        return (
            <HqTooltip
                wrap
                focusable={size !== 'row'}
                tone="lime"
                label={
                    <OfficialTooltip official={official} estimate={estimate} />
                }
            >
                {size === 'row' ? (
                    <span
                        className={cn(
                            'inline-flex h-[22px] min-w-[30px] items-center justify-center px-[5px] font-mono text-xs leading-none font-bold tabular-nums',
                            daznPointsBadgeClass(official),
                        )}
                    >
                        {official}
                    </span>
                ) : (
                    <BadgeContent value={official} size={size} plate={plate} />
                )}
            </HqTooltip>
        );
    }

    if (estimate === null) {
        return null;
    }

    return (
        <HqTooltip
            wrap
            focusable={size !== 'row'}
            tone="amber"
            label={<ProvisionalTooltip entry={entry} />}
        >
            <BadgeContent
                value={estimate}
                size={size}
                plate={plate}
                provisional
            />
        </HqTooltip>
    );
}

function BadgeContent({
    value,
    size,
    plate,
    provisional = false,
}: {
    value: number;
    size: HqDaznBadgeSize;
    plate: boolean;
    provisional?: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 font-mono leading-none font-semibold tabular-nums',
                size === 'md' ? 'text-[13px]' : 'text-[11px]',
                plate
                    ? 'bg-hq-well/75 py-0.5 pr-[5px] pl-[3px] text-hq-paper'
                    : 'text-hq-moss',
                provisional &&
                    'animate-hq-est motion-reduce:animate-none motion-reduce:opacity-60',
            )}
        >
            <img
                src="/images/dazn-logo.png"
                alt="DAZN"
                className={LOGO_SIZE_CLASSES[size]}
            />
            {value}
        </span>
    );
}

function ProvisionalTooltip({ entry }: { entry: DaznFields }) {
    return (
        <span className="flex flex-col gap-1.5 text-left">
            <span className="font-mono text-[10px] font-bold tracking-wider text-hq-amber">
                DAZN PROVISIONAL · ESTIMACIÓN
            </span>
            <span>
                Aún no es la nota oficial. La calculamos con lo que lleva de
                partido:
            </span>
            {entry.dazn_estimate_reasons.length > 0 && (
                <ul className="flex flex-col gap-0.5">
                    {entry.dazn_estimate_reasons.map((reason) => (
                        <li key={reason}>
                            <span className="mr-1 text-hq-amber">·</span>
                            {reason}
                        </li>
                    ))}
                </ul>
            )}
            <small className="text-[10.5px] text-hq-moss">
                {entry.dazn_estimate_source === 'worldcup26'
                    ? 'Estimación con datos parciales: menos fiable (~6 de cada 10).'
                    : 'Acierta la nota exacta ~7 de cada 10 veces y casi siempre queda a ±1. LaLiga Fantasy publica la oficial al acabar el partido.'}
            </small>
        </span>
    );
}

function OfficialTooltip({
    official,
    estimate,
}: {
    official: number;
    estimate: number | null;
}) {
    return (
        <span className="flex flex-col gap-1.5 text-left">
            <span className="font-mono text-[10px] font-bold tracking-wider text-hq-lime">
                DAZN OFICIAL
            </span>
            <span>LaLiga Fantasy: {official}</span>
            {estimate !== null && (
                <>
                    <span>
                        Comando Lechuga estimó: {estimate}{' '}
                        {estimateDifferenceLabel(estimate - official)}
                    </span>
                    <small className="text-[10.5px] text-hq-moss">
                        Nuestra estimación se congeló al publicarse la nota
                        oficial.
                    </small>
                </>
            )}
        </span>
    );
}

/** "✓" when the estimate matched, otherwise the signed gap, e.g. "(+1)" or "(−2)". */
function estimateDifferenceLabel(difference: number): string {
    if (difference === 0) {
        return '✓';
    }

    return `(${difference > 0 ? '+' : '−'}${Math.abs(difference)})`;
}
