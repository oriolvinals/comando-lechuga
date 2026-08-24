import type { SeasonTeamLineup } from '@/types/models';

interface HqTeamPointsChartProps {
    lineupHistory: SeasonTeamLineup[];
}

/** Weekly points as a simple bar chart, oldest week first — no interactivity, this is a trend-at-a-glance, not a drill-down (that's what the lineup-of-the-week section below it is for). */
export function HqTeamPointsChart({ lineupHistory }: HqTeamPointsChartProps) {
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
                {weeks.map((week) => (
                    <div
                        key={week.id}
                        className="flex h-full flex-1 flex-col justify-end"
                    >
                        <span className="mb-1 text-center font-display text-[11px] text-hq-lime">
                            {week.points}
                        </span>
                        <div
                            className="mx-auto w-3/5 bg-hq-lime/40"
                            style={{
                                height: `${Math.max(4, (week.points / maxPoints) * 100)}%`,
                            }}
                        />
                    </div>
                ))}
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
