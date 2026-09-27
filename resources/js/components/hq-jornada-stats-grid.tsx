import { HqTooltip } from '@/components/hq-tooltip';
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
    /** List the stats that stayed at zero ("Sin registro esta jornada") — the compact pitch-token modal leaves them out. */
    showEmptyStats?: boolean;
}

function SectionHeading({
    title,
    meta,
}: {
    title: string;
    meta: string | number;
}) {
    return (
        <p className="flex justify-between border-b border-hq-border bg-hq-well px-3.5 pt-[9px] pb-[7px] font-mono text-[10px] leading-none font-bold tracking-[0.12em] text-hq-moss-dim uppercase">
            <span>{title}</span>
            <span>{meta}</span>
        </p>
    );
}

/**
 * The 19-stat breakdown for a single jornada (mock `.sgrid`), split into
 * stats with a real value or delta this jornada — a ruled grid of label,
 * value and the fantasy points it adds — vs. the ones that stayed at zero,
 * folded into one compact line so the numbers that matter aren't lost
 * among a wall of identical zeros.
 */
export function HqJornadaStatsGrid({
    stats,
    columns = 2,
    showEmptyStats = true,
}: HqJornadaStatsGridProps) {
    const statsWithData = BODY_STAT_ORDER.filter((key) => {
        const [value, delta] = stats?.[key] ?? [0, 0];

        return value !== 0 || delta !== 0;
    });
    const statsWithoutData = BODY_STAT_ORDER.filter(
        (key) => !statsWithData.includes(key),
    );

    return (
        <div>
            {statsWithData.length > 0 && (
                <>
                    <SectionHeading
                        title="Esta jornada"
                        meta="valor · puntos"
                    />
                    <div
                        className={cn(
                            'grid grid-cols-2',
                            columns === 3 && 'sm:grid-cols-3',
                        )}
                    >
                        {statsWithData.map((key) => {
                            const [value, delta] = stats?.[key] ?? [0, 0];

                            return (
                                <div
                                    key={key}
                                    className="min-w-0 border-r border-b border-hq-border px-3.5 py-2"
                                >
                                    <div className="truncate font-mono text-[10px] leading-[1.2] font-semibold tracking-[0.06em] text-hq-moss uppercase">
                                        {JORNADA_STAT_LABELS[key] ?? key}
                                    </div>
                                    <div className="mt-1 flex items-baseline gap-1.5 font-mono text-[15px] leading-none font-bold text-hq-paper tabular-nums">
                                        <span>{value}</span>
                                        {delta !== 0 && (
                                            <HqTooltip label="Puntos fantasy que aporta">
                                                <span
                                                    className={cn(
                                                        'text-[11px]',
                                                        delta > 0
                                                            ? 'text-hq-lime'
                                                            : 'text-hq-neg',
                                                    )}
                                                >
                                                    {delta > 0 ? '+' : ''}
                                                    {delta}
                                                </span>
                                            </HqTooltip>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </>
            )}
            {showEmptyStats && statsWithoutData.length > 0 && (
                <>
                    <SectionHeading
                        title="Sin registro esta jornada"
                        meta={statsWithoutData.length}
                    />
                    <p className="flex flex-wrap gap-x-3 gap-y-1 border-b border-hq-border px-3.5 py-2.5 font-mono text-[11px] leading-snug text-hq-moss-dim">
                        {statsWithoutData.map((key) => (
                            <span key={key} className="whitespace-nowrap">
                                {JORNADA_STAT_LABELS[key] ?? key}{' '}
                                <b className="text-hq-moss">0</b>
                            </span>
                        ))}
                    </p>
                </>
            )}
        </div>
    );
}
