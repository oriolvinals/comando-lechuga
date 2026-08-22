import { Head } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Shield, User } from 'lucide-react';
import type { ReactElement } from 'react';
import { Fragment, useState } from 'react';
import { ActivityEntry } from '@/components/activity-entry';
import { BuyoutStatusBadge } from '@/components/buyout-status-badge';
import { EntityImage } from '@/components/entity-image';
import { LineupPitch } from '@/components/lineup-pitch';
import { PlayerStatsModal } from '@/components/player-stats-modal';
import { PositionBadge } from '@/components/position-badge';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    SeasonActivity,
    SeasonTeam,
    SeasonTeamLineup,
    SeasonTeamLineupPlayerEntry,
    SeasonTeamPlayer,
} from '@/types/models';

interface SeasonTeamShowProps {
    seasonTeam: SeasonTeam;
    roster: SeasonTeamPlayer[];
    lineupHistory: SeasonTeamLineup[];
    activity: SeasonActivity[];
    [key: string]: unknown;
}

type Tab = 'roster' | 'history';

export default function SeasonTeamShow({
    seasonTeam,
    roster,
    lineupHistory,
    activity,
}: SeasonTeamShowProps) {
    const [tab, setTab] = useState<Tab>('roster');
    const [expandedWeekId, setExpandedWeekId] = useState<number | null>(null);
    const [selectedPlayer, setSelectedPlayer] =
        useState<SeasonTeamLineupPlayerEntry | null>(null);

    return (
        <>
            <Head title={seasonTeam.name} />

            <div className="flex items-center gap-4">
                <EntityImage
                    src={seasonTeam.logo}
                    alt={seasonTeam.name}
                    fallback={Shield}
                    className="h-14 w-14"
                />
                <div>
                    <h1 className="text-xl font-semibold">{seasonTeam.name}</h1>
                    <p className="text-sm text-neutral-500">
                        Posición {seasonTeam.position} ·{' '}
                        {seasonTeam.total_points} puntos (
                        {seasonTeam.live_points} en vivo) ·{' '}
                        {formatCurrency(seasonTeam.value)}
                    </p>
                </div>
            </div>

            <div className="mt-8 flex gap-1 border-b border-neutral-200">
                <button
                    type="button"
                    onClick={() => setTab('roster')}
                    className={cn(
                        'border-b-2 px-3 py-2 text-sm font-medium',
                        tab === 'roster'
                            ? 'border-neutral-900 text-neutral-900'
                            : 'border-transparent text-neutral-500 hover:text-neutral-700',
                    )}
                >
                    Plantilla actual
                </button>
                <button
                    type="button"
                    onClick={() => setTab('history')}
                    className={cn(
                        'border-b-2 px-3 py-2 text-sm font-medium',
                        tab === 'history'
                            ? 'border-neutral-900 text-neutral-900'
                            : 'border-transparent text-neutral-500 hover:text-neutral-700',
                    )}
                >
                    Puntuaciones de cada jornada
                </button>
            </div>

            {tab === 'roster' ? (
                roster.length === 0 ? (
                    <p className="mt-6 text-neutral-500">
                        Este equipo no tiene jugadores en plantilla.
                    </p>
                ) : (
                    <table className="mt-6 w-full text-sm">
                        <thead>
                            <tr className="text-left text-neutral-500">
                                <th
                                    scope="col"
                                    className="py-2 pr-4 font-medium"
                                >
                                    Jugador
                                </th>
                                <th scope="col" className="px-4 font-medium">
                                    Posición
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 text-right font-medium"
                                >
                                    Cláusula
                                </th>
                                <th scope="col" className="pl-4 font-medium">
                                    Estado
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-200">
                            {roster.map((entry) => (
                                <tr key={entry.id}>
                                    <td className="py-2 pr-4">
                                        <div className="flex items-center gap-2">
                                            <EntityImage
                                                src={entry.player.image}
                                                alt={entry.player.nickname}
                                                fallback={User}
                                                className="h-8 w-8"
                                            />
                                            <span className="font-medium">
                                                {entry.player.nickname}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-4">
                                        <PositionBadge
                                            position={entry.player.position}
                                        />
                                    </td>
                                    <td className="px-4 text-right">
                                        {formatCurrency(entry.buyout_clause)}
                                    </td>
                                    <td className="pl-4">
                                        <BuyoutStatusBadge
                                            shielded={entry.shielded}
                                            buyoutClauseLockedUntil={
                                                entry.buyout_clause_locked_until
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )
            ) : lineupHistory.length === 0 ? (
                <p className="mt-6 text-neutral-500">
                    Todavía no hay jornadas jugadas.
                </p>
            ) : (
                <table className="mt-6 w-full text-sm">
                    <thead>
                        <tr className="text-left text-neutral-500">
                            <th scope="col" className="py-2 pr-4 font-medium">
                                Jornada
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
                        {lineupHistory.map((lineup) => {
                            const isExpanded = expandedWeekId === lineup.id;

                            return (
                                <Fragment key={lineup.id}>
                                    <tr
                                        className="cursor-pointer hover:bg-neutral-50"
                                        onClick={() =>
                                            setExpandedWeekId(
                                                isExpanded ? null : lineup.id,
                                            )
                                        }
                                    >
                                        <td className="py-2 pr-4 font-medium">
                                            Jornada {lineup.week_number}
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
                                                colSpan={3}
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

            <section className="mt-10" aria-labelledby="team-activity-heading">
                <h2
                    id="team-activity-heading"
                    className="text-lg font-semibold"
                >
                    Actividad
                </h2>
                {activity.length === 0 ? (
                    <p className="mt-4 text-neutral-500">
                        Todavía no hay actividad de este equipo.
                    </p>
                ) : (
                    <ul className="mt-4 divide-y divide-neutral-200">
                        {activity.map((entry) => (
                            <ActivityEntry key={entry.id} activity={entry} />
                        ))}
                    </ul>
                )}
            </section>

            <PlayerStatsModal
                entry={selectedPlayer}
                onClose={() => setSelectedPlayer(null)}
            />
        </>
    );
}

SeasonTeamShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
