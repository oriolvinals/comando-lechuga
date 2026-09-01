import { Head } from '@inertiajs/react';
import type { CSSProperties, ReactElement } from 'react';
import { useState } from 'react';
import { HqActivityTimelineEntry } from '@/components/hq-activity-timeline-entry';
import { HqLineupPitch } from '@/components/hq-lineup-pitch';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import { HqTeamPointsChart } from '@/components/hq-team-points-chart';
import { HqWeekScrollPicker } from '@/components/hq-week-scroll-picker';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/format';
import { cn } from '@/lib/utils';
import { ManagerHero } from '@/pages/season-managers/manager-hero';
import { RosterList } from '@/pages/season-managers/roster-list';
import type {
    Season,
    Activity,
    SeasonManager,
    ManagerLineup,
    ManagerLineupPlayerEntry,
    ManagerPlayer,
    WeekProgressMap,
} from '@/types/models';

/** La Liga Fantasy's fixed squad cap — not enforced server-side, so there's no backend value to read it from. */
const MAX_ROSTER_SIZE = 24;

interface SeasonManagerShowProps {
    season: Season;
    seasonManager: SeasonManager;
    roster: ManagerPlayer[];
    lineupHistory: ManagerLineup[];
    startedWeeks: number[];
    weekProgress: WeekProgressMap;
    wonWeeks: number[];
    lostWeeks: number[];
    activity: Activity[];
    [key: string]: unknown;
}

export default function SeasonManagerShow({
    season,
    seasonManager,
    roster,
    lineupHistory,
    startedWeeks,
    weekProgress,
    wonWeeks,
    lostWeeks,
    activity,
}: SeasonManagerShowProps) {
    const [selectedWeek, setSelectedWeek] = useState(season.current_week);
    const [selectedPlayer, setSelectedPlayer] =
        useState<ManagerLineupPlayerEntry | null>(null);

    const lineupForWeek = lineupHistory.find(
        (lineup) => lineup.week_number === selectedWeek,
    );
    const weekPoints = lineupHistory.reduce<Record<number, number>>(
        (acc, lineup) => {
            acc[lineup.week_number] = lineup.points;

            return acc;
        },
        {},
    );
    const rosterValueDifference = roster.reduce(
        (sum, entry) => sum + entry.player.market_value_difference,
        0,
    );

    return (
        <div
            className="hq-texture hq-bleed relative flex-1 border-y border-hq-border"
            style={
                {
                    '--pc': seasonManager.primary_color ?? 'transparent',
                    '--sc': seasonManager.secondary_color ?? 'transparent',
                } as CSSProperties
            }
        >
            <Head title={seasonManager.name} />

            <div
                className="pointer-events-none absolute inset-0 opacity-25"
                style={{
                    background:
                        'linear-gradient(100deg, var(--pc) 0%, var(--sc) 100%)',
                    maskImage:
                        'linear-gradient(to bottom, black 0%, black 12%, transparent 55%)',
                    WebkitMaskImage:
                        'linear-gradient(to bottom, black 0%, black 12%, transparent 55%)',
                }}
            />

            <div className="relative mx-auto max-w-7xl px-6 pb-9">
                <ManagerHero
                    seasonManager={seasonManager}
                    season={season}
                    wonWeeks={wonWeeks}
                    lostWeeks={lostWeeks}
                />

                <div className="mt-6">
                    <h2 className="mb-3 font-display text-lg text-hq-paper uppercase">
                        Evolución de puntos
                    </h2>
                    <HqTeamPointsChart
                        lineupHistory={lineupHistory}
                        startedWeeks={startedWeeks}
                    />

                    <div className="mt-8 flex flex-col gap-5 lg:flex-row lg:items-start">
                        <section
                            aria-labelledby="roster-heading"
                            className="min-w-0 flex-1"
                        >
                            <div className="mb-3 flex items-center gap-2.5">
                                <h2
                                    id="roster-heading"
                                    className="font-display text-lg text-hq-paper uppercase"
                                >
                                    Plantilla actual
                                </h2>
                                <span className="border border-hq-border-strong bg-hq-panel px-1.5 py-0.5 font-mono text-xs font-bold tracking-wider text-hq-moss">
                                    {roster.length}/{MAX_ROSTER_SIZE}
                                </span>
                                {rosterValueDifference !== 0 && (
                                    <span
                                        className={cn(
                                            'font-mono text-xs font-bold whitespace-nowrap',
                                            rosterValueDifference > 0
                                                ? 'text-hq-lime'
                                                : 'text-hq-live',
                                        )}
                                    >
                                        {rosterValueDifference > 0 ? '▲' : '▼'}{' '}
                                        {formatCurrency(
                                            Math.abs(rosterValueDifference),
                                        )}
                                    </span>
                                )}
                            </div>
                            <RosterList roster={roster} />
                        </section>

                        <section
                            aria-labelledby="activity-heading"
                            className="w-full shrink-0 lg:w-[400px]"
                        >
                            <div className="mb-4 flex flex-col gap-2.5">
                                <h2 className="font-display text-lg text-hq-paper uppercase">
                                    Alineación de la jornada
                                </h2>
                                <div className="min-w-0">
                                    <HqWeekScrollPicker
                                        week={selectedWeek}
                                        maxWeek={season.total_weeks}
                                        playedThroughWeek={season.current_week}
                                        weekProgress={weekProgress}
                                        weekPoints={weekPoints}
                                        onChange={setSelectedWeek}
                                    />
                                </div>
                            </div>

                            <div className="mx-auto max-w-[360px]">
                                {lineupForWeek ? (
                                    <HqLineupPitch
                                        players={lineupForWeek.players}
                                        tacticalFormation={
                                            lineupForWeek.tactical_formation
                                        }
                                        onSelectPlayer={setSelectedPlayer}
                                    />
                                ) : (
                                    <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                                        <p className="font-mono text-[11px] text-hq-moss-dim">
                                            Sin alineación registrada esa
                                            jornada.
                                        </p>
                                    </div>
                                )}
                            </div>

                            <h2
                                id="activity-heading"
                                className="mt-8 mb-3 font-display text-lg text-hq-paper uppercase"
                            >
                                Actividad
                            </h2>
                            {activity.length === 0 ? (
                                <p className="font-mono text-[11px] text-hq-moss-dim">
                                    Todavía no hay actividad de este manager.
                                </p>
                            ) : (
                                <div className="hq-card-cut px-4 py-1">
                                    {activity.map((entry) => (
                                        <HqActivityTimelineEntry
                                            key={entry.id}
                                            activity={entry}
                                        />
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>
                </div>
            </div>

            <HqPlayerStatsModal
                entry={
                    selectedPlayer
                        ? {
                              player: selectedPlayer.player,
                              team: selectedPlayer.player.team,
                              points: selectedPlayer.points ?? 0,
                              daznPoints:
                                  selectedPlayer.stats?.mins_played !==
                                  undefined
                                      ? selectedPlayer.stats.marca_points?.[1]
                                      : undefined,
                              stats: selectedPlayer.stats ?? {},
                          }
                        : null
                }
                onClose={() => setSelectedPlayer(null)}
            />
        </div>
    );
}

SeasonManagerShow.layout = (page: ReactElement) => (
    <AppLayout>{page}</AppLayout>
);
