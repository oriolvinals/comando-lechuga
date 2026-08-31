import { JORNADA_STAT_LABELS, JORNADA_STAT_ORDER } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import type { JornadaStats } from '@/types/models';

const BODY_STAT_ORDER = JORNADA_STAT_ORDER.filter(
    (key) => key !== 'marca_points',
);

interface HqJornadaStatsGridProps {
    stats: JornadaStats | null;
    /** 3 needs real width to breathe (the ficha panel) — narrower contexts like the pitch-token modal should stick with 2, or labels start truncating. */
    columns?: 2 | 3;
}

/**
 * The 19-stat breakdown for a single jornada, split into stats with a real
 * value or delta this jornada vs. the ones that stayed at zero — otherwise
 * the handful of numbers that actually matter get lost among a wall of
 * identical-looking zeros.
 */
export function HqJornadaStatsGrid({
    stats,
    columns = 2,
}: HqJornadaStatsGridProps) {
    const statsWithData = BODY_STAT_ORDER.filter((key) => {
        const [value, delta] = stats?.[key] ?? [0, 0];

        return value !== 0 || delta !== 0;
    });
    const statsWithoutData = BODY_STAT_ORDER.filter(
        (key) => !statsWithData.includes(key),
    );
    const statCell = (key: (typeof BODY_STAT_ORDER)[number]) => {
        const [value, delta] = stats?.[key] ?? [0, 0];
        const isZero = value === 0 && delta === 0;

        return (
            <div key={key} className="flex flex-col gap-0.5 px-3 py-1.5">
                <span
                    className={cn(
                        'truncate font-mono text-[9px] tracking-wide text-hq-moss uppercase',
                        isZero && 'opacity-40',
                    )}
                >
                    {JORNADA_STAT_LABELS[key] ?? key}
                </span>
                <span
                    className={cn(
                        'flex items-center gap-1 font-mono text-[13px]',
                        isZero && 'opacity-30',
                    )}
                >
                    <span className="font-bold text-hq-paper">{value}</span>
                    {delta !== 0 && (
                        <span
                            className={cn(
                                'text-[9px] font-bold',
                                delta > 0 ? 'text-hq-lime' : 'text-hq-live',
                            )}
                        >
                            {delta > 0 ? '+' : ''}
                            {delta}
                        </span>
                    )}
                </span>
            </div>
        );
    };

    const gridClass = cn('grid grid-cols-2', columns === 3 && 'sm:grid-cols-3');

    return (
        <div className="p-1.5">
            {statsWithData.length > 0 && (
                <>
                    <p className="px-3 pt-2 pb-1 font-mono text-[9px] tracking-wide text-hq-moss-dim uppercase">
                        Esta jornada
                    </p>
                    <div className={gridClass}>
                        {statsWithData.map(statCell)}
                    </div>
                </>
            )}
            {statsWithoutData.length > 0 && (
                <>
                    <p className="px-3 pt-2 pb-1 font-mono text-[9px] tracking-wide text-hq-moss-dim uppercase">
                        Sin registro esta jornada
                    </p>
                    <div className={gridClass}>
                        {statsWithoutData.map(statCell)}
                    </div>
                </>
            )}
        </div>
    );
}
