import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { Fragment, useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { LineupPitch } from '@/components/lineup-pitch';
import { PlayerStatsModal } from '@/components/player-stats-modal';
import { WeekSelector } from '@/components/week-selector';
import AppLayout from '@/layouts/app-layout';
import {
    index as seasonTeamsIndex,
    show as seasonTeamsShow,
} from '@/routes/season-teams';
import type {
    Season,
    SeasonTeamLineup,
    SeasonTeamLineupPlayerEntry,
} from '@/types/models';

interface SeasonTeamsIndexProps {
    season: Season;
    filters: { week: number };
    lineups: SeasonTeamLineup[];
    [key: string]: unknown;
}

export default function SeasonTeamsIndex({
    season,
    filters,
    lineups,
}: SeasonTeamsIndexProps) {
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [selectedPlayer, setSelectedPlayer] =
        useState<SeasonTeamLineupPlayerEntry | null>(null);

    const goToWeek = (nextWeek: number) => {
        router.get(
            seasonTeamsIndex().url,
            { week: nextWeek },
            { preserveScroll: true },
        );
    };

    return (
        <div className="py-10">
            <Head title="Equipos" />

            <WeekSelector
                week={filters.week}
                maxWeek={season.current_week}
                onChange={goToWeek}
                label={`Jornada ${filters.week}`}
            />

            {lineups.length === 0 ? (
                <p className="mt-8 text-neutral-500">
                    Nadie tenía alineación registrada esta jornada.
                </p>
            ) : (
                <table className="mt-8 w-full text-sm">
                    <thead>
                        <tr className="text-left text-neutral-500">
                            <th scope="col" className="py-2 pr-4 font-medium">
                                #
                            </th>
                            <th scope="col" className="px-4 font-medium">
                                Equipo
                            </th>
                            <th
                                scope="col"
                                className="px-4 text-right font-medium"
                            >
                                Puntos
                            </th>
                            <th scope="col" className="w-8 pl-4" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-neutral-200">
                        {lineups.map((lineup, index) => {
                            const isExpanded = expandedId === lineup.id;

                            return (
                                <Fragment key={lineup.id}>
                                    <tr
                                        className="cursor-pointer hover:bg-neutral-50"
                                        onClick={() =>
                                            setExpandedId(
                                                isExpanded ? null : lineup.id,
                                            )
                                        }
                                    >
                                        <td className="py-2 pr-4 text-neutral-500">
                                            {index + 1}
                                        </td>
                                        <td className="px-4">
                                            <div className="flex items-center gap-2">
                                                <EntityImage
                                                    src={
                                                        lineup.season_team.logo
                                                    }
                                                    alt={
                                                        lineup.season_team.name
                                                    }
                                                    fallback={Shield}
                                                    className="h-6 w-6"
                                                />
                                                <Link
                                                    href={
                                                        seasonTeamsShow(
                                                            lineup.season_team
                                                                .id,
                                                        ).url
                                                    }
                                                    onClick={(event) =>
                                                        event.stopPropagation()
                                                    }
                                                    className="font-medium hover:underline"
                                                >
                                                    {lineup.season_team.name}
                                                </Link>
                                            </div>
                                        </td>
                                        <td className="px-4 text-right font-semibold">
                                            {lineup.points}
                                        </td>
                                        <td className="pl-4 text-neutral-400">
                                            {isExpanded ? (
                                                <ChevronUp className="h-4 w-4" />
                                            ) : (
                                                <ChevronDown className="h-4 w-4" />
                                            )}
                                        </td>
                                    </tr>
                                    {isExpanded && (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="bg-neutral-50 px-4 py-3"
                                            >
                                                <LineupPitch
                                                    players={lineup.players}
                                                    tacticalFormation={
                                                        lineup.tactical_formation
                                                    }
                                                    onSelectPlayer={
                                                        setSelectedPlayer
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            );
                        })}
                    </tbody>
                </table>
            )}

            <PlayerStatsModal
                entry={selectedPlayer}
                onClose={() => setSelectedPlayer(null)}
            />
        </div>
    );
}

SeasonTeamsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
