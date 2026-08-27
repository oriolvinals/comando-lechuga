import { Head } from '@inertiajs/react';
import type { CSSProperties, ReactElement } from 'react';
import { useState } from 'react';
import { HqActivityTimelineEntry } from '@/components/hq-activity-timeline-entry';
import { HqLineupPitch } from '@/components/hq-lineup-pitch';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import { HqTeamPointsChart } from '@/components/hq-team-points-chart';
import { HqWeekScrollPicker } from '@/components/hq-week-scroll-picker';
import AppLayout from '@/layouts/app-layout';
import { ManagerHero } from '@/pages/season-managers/manager-hero';
import { RosterList } from '@/pages/season-managers/roster-list';
import type {
    Season,
    SeasonActivity,
    SeasonManager,
    SeasonManagerLineup,
    SeasonManagerLineupPlayerEntry,
    SeasonManagerPlayer,
    WeekProgressMap,
} from '@/types/models';

interface SeasonManagerShowProps {
    season: Season;
    seasonManager: SeasonManager;
    roster: SeasonManagerPlayer[];
    lineupHistory: SeasonManagerLineup[];
    startedWeeks: number[];
    weekProgress: WeekProgressMap;
    wonWeeks: number[];
    activity: SeasonActivity[];
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
    activity,
}: SeasonManagerShowProps) {
    const [selectedWeek, setSelectedWeek] = useState(season.current_week);
    const [selectedPlayer, setSelectedPlayer] =
        useState<SeasonManagerLineupPlayerEntry | null>(null);

    const lineupForWeek = lineupHistory.find(
        (lineup) => lineup.week_number === selectedWeek,
    );

    return (
        <div
            className="hq-texture hq-bleed relative min-h-[calc(100vh-95px)] border-y border-hq-border"
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
                            <h2
                                id="roster-heading"
                                className="mb-3 font-display text-lg text-hq-paper uppercase"
                            >
                                Plantilla actual
                            </h2>
                            <RosterList roster={roster} />
                        </section>

                        <section
                            aria-labelledby="activity-heading"
                            className="w-full shrink-0 lg:w-[340px]"
                        >
                            <h2
                                id="activity-heading"
                                className="mb-3 font-display text-lg text-hq-paper uppercase"
                            >
                                Actividad
                            </h2>
                            {activity.length === 0 ? (
                                <p className="font-mono text-[11px] text-hq-moss-dim">
                                    Todavía no hay actividad de este equipo.
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

                    <div className="mt-10 border-t border-dashed border-hq-border pt-6">
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
                                    onChange={setSelectedWeek}
                                />
                            </div>
                        </div>

                        <div className="mx-auto max-w-[360px]">
                            {lineupForWeek ? (
                                <HqLineupPitch
                                    players={lineupForWeek.players}
                                    onSelectPlayer={setSelectedPlayer}
                                />
                            ) : (
                                <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                                    <p className="font-mono text-[11px] text-hq-moss-dim">
                                        Sin alineación registrada esa jornada.
                                    </p>
                                </div>
                            )}
                        </div>
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
