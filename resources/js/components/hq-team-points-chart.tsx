import { teamFormBarClass, teamFormTextClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { SeasonManagerLineup } from '@/types/models';

interface HqTeamPointsChartProps {
    lineupHistory: SeasonManagerLineup[];
    startedWeeks: number[];
}

/** Weekly points as a simple bar chart, oldest week first — no interactivity, this is a trend-at-a-glance, not a drill-down (that's what the lineup-of-the-week section below it is for). */
export function HqTeamPointsChart({
    lineupHistory,
    startedWeeks,
}: HqTeamPointsChartProps) {
    if (lineupHistory.length === 0) {
        return (
            <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                <p className="font-mono text-[11px] text-hq-moss-dim">
                    Todavía no hay jornadas jugadas.
                </p>
            </div>
        );
    }

    const weeks = [...lineupHistory].sort(
        (a, b) => a.week_number - b.week_number,
    );
    const maxPoints = Math.max(...weeks.map((week) => week.points), 1);

    return (
        <div className="hq-card-cut p-4">
            <div className="flex h-24 items-end gap-1.5">
                {weeks.map((week) => {
                    const started = startedWeeks.includes(week.week_number);

                    return (
                        <div
                            key={week.id}
                            className="flex h-full flex-1 flex-col justify-end"
                        >
                            <span
                                className={cn(
                                    'mb-1 text-center font-display text-[11px]',
                                    started
                                        ? teamFormTextClass(week.points)
                                        : 'text-hq-moss-dim',
                                )}
                            >
                                {started ? week.points : '–'}
                            </span>
                            {started ? (
                                <div
                                    className={cn(
                                        'mx-auto w-3/5',
                                        teamFormBarClass(week.points),
                                    )}
                                    style={{
                                        height: `${Math.max(4, (week.points / maxPoints) * 100)}%`,
                                    }}
                                />
                            ) : (
                                <div className="mx-auto h-1 w-3/5 border-t border-dashed border-hq-border-strong" />
                            )}
                        </div>
                    );
                })}
            </div>
            <div className="mt-1.5 flex gap-1.5">
                {weeks.map((week) => (
                    <span
                        key={week.id}
                        className="flex-1 text-center font-mono text-[8px] text-hq-moss-dim"
                    >
                        J{week.week_number}
                    </span>
                ))}
            </div>
        </div>
    );
}
