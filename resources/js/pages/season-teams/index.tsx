import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Shield, User } from 'lucide-react';
import type { ReactElement } from 'react';
import { Fragment, useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { PositionBadge } from '@/components/position-badge';
import { WeekSelector } from '@/components/week-selector';
import AppLayout from '@/layouts/app-layout';
import {
    index as seasonTeamsIndex,
    show as seasonTeamsShow,
} from '@/routes/season-teams';
import type { Season, SeasonTeamLineup } from '@/types/models';

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

    const goToWeek = (nextWeek: number) => {
        router.get(
            seasonTeamsIndex().url,
            { week: nextWeek },
            { preserveScroll: true },
        );
    };

    return (
        <>
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
                                                <ul className="grid grid-cols-1 gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                                                    {lineup.players.map(
                                                        (entry) => (
                                                            <li
                                                                key={entry.id}
                                                                className="flex items-center justify-between gap-2 py-1 text-sm"
                                                            >
                                                                <div className="flex items-center gap-2">
                                                                    <EntityImage
                                                                        src={
                                                                            entry
                                                                                .player
                                                                                .image
                                                                        }
                                                                        alt={
                                                                            entry
                                                                                .player
                                                                                .nickname
                                                                        }
                                                                        fallback={
                                                                            User
                                                                        }
                                                                        className="h-6 w-6"
                                                                    />
                                                                    <span>
                                                                        {
                                                                            entry
                                                                                .player
                                                                                .nickname
                                                                        }
                                                                    </span>
                                                                    <PositionBadge
                                                                        position={
                                                                            entry.position
                                                                        }
                                                                    />
                                                                </div>
                                                                <span className="font-medium">
                                                                    {
                                                                        entry.points
                                                                    }
                                                                </span>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            );
                        })}
                    </tbody>
                </table>
            )}
        </>
    );
}

SeasonTeamsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
