import { HqEmptyState } from '@/components/hq-empty-state';
import { HqTooltip } from '@/components/hq-tooltip';
import { teamFormTextClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { ManagerLineup } from '@/types/models';

interface HqTeamPointsChartProps {
    lineupHistory: ManagerLineup[];
    startedWeeks: number[];
    /** Jornadas this manager won — noted in that bar's tooltip. */
    wonWeeks?: number[];
    /** Jornadas this manager finished last (farolillo rojo) — noted in that bar's tooltip. */
    lostWeeks?: number[];
}

/** Solid bar fill per team-form tier — same breakpoints as `teamFormTextClass`. */
function teamFormSolidBarClass(points: number): string {
    if (points < 0) {
        return 'bg-hq-live';
    }

    if (points < 31) {
        return 'bg-hq-gold';
    }

    if (points < 56) {
        return 'bg-hq-lime';
    }

    if (points < 91) {
        return 'bg-hq-azure';
    }

    return 'bg-hq-violet';
}

/**
 * Weekly points as a bar chart in a dark well (mock `.pchart`), oldest week
 * first: a dot-matrix value over each bar, bars coloured by the team-form
 * tier, a dashed stub for jornadas that haven't started, and J labels. Each
 * column's tooltip repeats the value and flags won / last-place jornadas.
 */
export function HqTeamPointsChart({
    lineupHistory,
    startedWeeks,
    wonWeeks = [],
    lostWeeks = [],
}: HqTeamPointsChartProps) {
    if (lineupHistory.length === 0) {
        return (
            <HqEmptyState glyph="▁" title="Sin jornadas" className="m-0 sm:m-0">
                Todavía no hay jornadas jugadas.
            </HqEmptyState>
        );
    }

    const weeks = [...lineupHistory].sort(
        (a, b) => a.week_number - b.week_number,
    );
    const maxPoints = Math.max(...weeks.map((week) => week.points), 1);

    return (
        <div className="hq-hud flex h-[170px] items-end gap-[5px] border border-hq-border bg-hq-well px-2 pt-2.5 md:h-[190px] md:gap-2.5 md:px-3.5 md:pt-3.5">
            {weeks.map((week) => {
                const started = startedWeeks.includes(week.week_number);
                const won = wonWeeks.includes(week.week_number);
                const lost = lostWeeks.includes(week.week_number);
                const label = started ? (
                    <>
                        <b className="font-bold">
                            J{week.week_number} · {week.points} pts
                        </b>
                        {won && (
                            <>
                                <br />
                                Ganó la jornada
                            </>
                        )}
                        {lost && (
                            <>
                                <br />
                                Farolillo rojo
                            </>
                        )}
                    </>
                ) : (
                    `J${week.week_number} · sin empezar`
                );

                return (
                    <HqTooltip
                        key={week.id}
                        label={label}
                        focusable
                        className="h-full min-w-0 flex-1 flex-col items-center justify-end"
                    >
                        <span
                            className={cn(
                                'mb-1.5 font-dot text-[13px] leading-none font-black tabular-nums md:text-base',
                                started
                                    ? teamFormTextClass(week.points)
                                    : 'text-hq-moss-dim',
                            )}
                        >
                            {started ? week.points : '–'}
                        </span>
                        {started ? (
                            <span
                                className={cn(
                                    'block w-[62%] max-w-14 opacity-75',
                                    teamFormSolidBarClass(week.points),
                                )}
                                style={{
                                    height: `${Math.max(4, (Math.max(week.points, 0) / maxPoints) * 100)}%`,
                                }}
                            />
                        ) : (
                            <span className="block h-[3px] w-[62%] max-w-14 border-t border-dashed border-hq-border-bright" />
                        )}
                        <span
                            className={cn(
                                'mt-[7px] mb-2 font-mono text-[10.5px] leading-none font-semibold',
                                won
                                    ? 'text-hq-gold'
                                    : lost
                                      ? 'text-hq-live'
                                      : 'text-hq-moss',
                            )}
                        >
                            J{week.week_number}
                        </span>
                    </HqTooltip>
                );
            })}
        </div>
    );
}
