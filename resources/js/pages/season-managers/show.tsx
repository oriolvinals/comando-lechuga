import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { HqActivityTimelineEntry } from '@/components/hq-activity-timeline-entry';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqLineupPitch } from '@/components/hq-lineup-pitch';
import {
    HqPlayerStatsModal,
    lineupPlayerStatsEntry,
} from '@/components/hq-player-stats-modal';
import { HqChannelHeader, HqSection } from '@/components/hq-section';
import { HqTeamPointsChart } from '@/components/hq-team-points-chart';
import { HqTooltip } from '@/components/hq-tooltip';
import { HqWeekPickerBand } from '@/components/hq-week-scroll-picker';
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
    ManagerWeekRankMap,
    ManagerWeeklySummary,
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
    weekRanks: ManagerWeekRankMap;
    weeklySummary: ManagerWeeklySummary;
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
    weekRanks,
    weeklySummary,
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
        <>
            <Head title={seasonManager.name} />

            <ManagerHero
                seasonManager={seasonManager}
                season={season}
                wonWeeks={wonWeeks}
                lostWeeks={lostWeeks}
                weeklySummary={weeklySummary}
            />

            <HqSection
                title="Evolución de puntos"
                action={
                    lineupHistory.length > 0 &&
                    'nº bajo la J = puesto en la jornada'
                }
            >
                <HqTeamPointsChart
                    lineupHistory={lineupHistory}
                    startedWeeks={startedWeeks}
                    wonWeeks={wonWeeks}
                    lostWeeks={lostWeeks}
                    weekRanks={weekRanks}
                />
            </HqSection>

            <div className="grid grid-cols-1 min-[80rem]:grid-cols-[minmax(0,1fr)_400px]">
                <section
                    aria-labelledby="roster-heading"
                    className="min-w-0 border-b border-hq-border min-[80rem]:border-r min-[80rem]:border-b-0"
                >
                    <HqChannelHeader
                        title={
                            <span id="roster-heading">Plantilla actual</span>
                        }
                        action={
                            <>
                                <span className="border border-hq-border-strong bg-hq-panel px-1.5 py-[3px] font-mono text-xs leading-none font-bold text-hq-moss">
                                    {roster.length}/{MAX_ROSTER_SIZE}
                                </span>
                                {rosterValueDifference !== 0 && (
                                    <HqTooltip
                                        label="Variación de valor de la plantilla hoy"
                                        tone={
                                            rosterValueDifference > 0
                                                ? 'lime'
                                                : 'neg'
                                        }
                                        focusable
                                        className={cn(
                                            'font-mono font-bold whitespace-nowrap',
                                            rosterValueDifference > 0
                                                ? 'text-hq-lime'
                                                : 'text-hq-neg',
                                        )}
                                    >
                                        {rosterValueDifference > 0 ? '▲' : '▼'}{' '}
                                        {formatCurrency(
                                            Math.abs(rosterValueDifference),
                                        )}
                                    </HqTooltip>
                                )}
                            </>
                        }
                    />
                    <RosterList roster={roster} />
                </section>

                <aside className="min-w-0">
                    <HqChannelHeader title="Alineación de la jornada" />
                    <HqWeekPickerBand
                        week={selectedWeek}
                        maxWeek={season.total_weeks}
                        playedThroughWeek={season.current_week}
                        weekProgress={weekProgress}
                        weekPoints={weekPoints}
                        onChange={setSelectedWeek}
                    />
                    <div className="border-b border-hq-border p-3.5 sm:p-4">
                        {lineupForWeek ? (
                            <div className="mx-auto max-w-[360px]">
                                <HqLineupPitch
                                    players={lineupForWeek.players}
                                    tacticalFormation={
                                        lineupForWeek.tactical_formation
                                    }
                                    onSelectPlayer={setSelectedPlayer}
                                />
                            </div>
                        ) : (
                            <HqEmptyState
                                glyph="▦"
                                title="Sin alineación"
                                className="m-0 sm:m-0"
                            >
                                Sin alineación registrada esa jornada.
                            </HqEmptyState>
                        )}
                    </div>

                    <HqChannelHeader title="Actividad" />
                    {activity.length === 0 ? (
                        <p className="p-4 text-sm text-hq-moss">
                            Todavía no hay actividad de este manager.
                        </p>
                    ) : (
                        <div>
                            {activity.map((entry) => (
                                <HqActivityTimelineEntry
                                    key={entry.id}
                                    activity={entry}
                                />
                            ))}
                        </div>
                    )}
                </aside>
            </div>

            <HqPlayerStatsModal
                entry={
                    selectedPlayer
                        ? lineupPlayerStatsEntry(selectedPlayer)
                        : null
                }
                onClose={() => setSelectedPlayer(null)}
            />
        </>
    );
}

SeasonManagerShow.layout = (page: ReactElement) => (
    <AppLayout>{page}</AppLayout>
);
