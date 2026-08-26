import { Head, Link, router } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLineupPitch } from '@/components/hq-lineup-pitch';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import { HqWeekScrollPicker } from '@/components/hq-week-scroll-picker';
import AppLayout from '@/layouts/app-layout';
import { crestTintStyle } from '@/lib/season-team-colors';
import { cn } from '@/lib/utils';
import {
    index as seasonTeamsIndex,
    show as seasonTeamsShow,
} from '@/routes/season-teams';
import type {
    Season,
    SeasonTeamLineup,
    SeasonTeamLineupPlayerEntry,
    WeekProgressMap,
} from '@/types/models';

const MEDAL_BORDERS = [
    'border-l-hq-gold',
    'border-l-hq-silver',
    'border-l-hq-bronze',
];

interface SeasonTeamsIndexProps {
    season: Season;
    filters: { week: number };
    lineups: SeasonTeamLineup[];
    weekProgress: WeekProgressMap;
    [key: string]: unknown;
}

export default function SeasonTeamsIndex({
    season,
    filters,
    lineups,
    weekProgress,
}: SeasonTeamsIndexProps) {
    const [selectedPlayer, setSelectedPlayer] =
        useState<SeasonTeamLineupPlayerEntry | null>(null);

    const goToWeek = (nextWeek: number) => {
        router.get(
            seasonTeamsIndex().url,
            { week: nextWeek },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <>
            <div className="hq-texture hq-bleed min-h-[calc(100vh-95px)] border-y border-hq-border">
                <div className="mx-auto max-w-7xl px-6 py-9">
                    <Head title="Equipos" />

                    <h1 className="mb-6 font-display text-3xl text-hq-paper uppercase">
                        Equipos
                    </h1>

                    <div className="mb-6">
                        <HqWeekScrollPicker
                            week={filters.week}
                            maxWeek={season.total_weeks}
                            playedThroughWeek={season.current_week}
                            weekProgress={weekProgress}
                            onChange={goToWeek}
                        />
                    </div>

                    {lineups.length === 0 ? (
                        <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                            <p className="mb-2 text-3xl">📋</p>
                            <p className="font-display text-lg text-hq-paper uppercase">
                                Sin alineaciones
                            </p>
                            <p className="mt-1.5 font-mono text-[11px] text-hq-moss-dim">
                                Nadie tenía alineación registrada esta
                                jornada.
                            </p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {lineups.map((lineup, index) => (
                                <div
                                    key={lineup.id}
                                    className={cn(
                                        'border border-l-4 border-hq-border bg-hq-panel p-4',
                                        index < 3 && MEDAL_BORDERS[index],
                                        index === 0 && 'bg-hq-panel-alt',
                                    )}
                                >
                                    <div className="mb-3 flex items-center gap-2.5">
                                        <Link
                                            href={
                                                seasonTeamsShow(
                                                    lineup.season_team.id,
                                                ).url
                                            }
                                            className="flex min-w-0 flex-1 items-center gap-2.5 hover:opacity-80"
                                        >
                                            <EntityImage
                                                src={lineup.season_team.logo}
                                                alt={lineup.season_team.name}
                                                fallback={Shield}
                                                shape="square"
                                                style={crestTintStyle(
                                                    lineup.season_team
                                                        .primary_color,
                                                )}
                                                className="hq-crest-cut h-16 w-16 shrink-0 bg-hq-border p-2 text-hq-khaki"
                                            />
                                            <span className="flex-1 truncate text-sm font-extrabold text-hq-paper">
                                                {lineup.season_team.name}
                                            </span>
                                        </Link>
                                        <span className="shrink-0 font-display text-2xl text-hq-lime">
                                            {lineup.points}
                                        </span>
                                    </div>

                                    <HqLineupPitch
                                        players={lineup.players}
                                        onSelectPlayer={setSelectedPlayer}
                                    />
                                </div>
                            ))}
                        </div>
                    )}
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
        </>
    );
}

SeasonTeamsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
