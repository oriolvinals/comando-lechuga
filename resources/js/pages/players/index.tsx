import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Shield, User, UserX } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { MultiSelect } from '@/components/multi-select';
import { PositionBadge } from '@/components/position-badge';
import { StatusBadge } from '@/components/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/format';
import { POSITION_LABELS, STATUS_LABELS } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import { index as playersIndex, show as playersShow } from '@/routes/players';
import type {
    Paginated,
    Player,
    PlayerPosition,
    PlayerStatus,
} from '@/types/models';

interface TeamOption {
    id: number;
    name: string;
}

type PlayerSort = 'points' | 'value' | 'difference';
type SortDirection = 'asc' | 'desc';

interface PlayersIndexProps {
    players: Paginated<Player>;
    teams: TeamOption[];
    filters: {
        position: PlayerPosition[];
        team: number[];
        status: PlayerStatus[];
        search: string | null;
        sort: PlayerSort;
        direction: SortDirection;
    };
    [key: string]: unknown;
}

interface FilterOverrides {
    position: PlayerPosition[];
    team: number[];
    status: PlayerStatus[];
    search: string;
    sort: PlayerSort;
    direction: SortDirection;
}

export default function PlayersIndex({
    players,
    teams,
    filters,
}: PlayersIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<FilterOverrides>) => {
        const position = overrides.position ?? filters.position;
        const team = overrides.team ?? filters.team;
        const status = overrides.status ?? filters.status;
        const nextSearch = overrides.search ?? filters.search ?? '';
        const sort = overrides.sort ?? filters.sort;
        const direction = overrides.direction ?? filters.direction;

        router.get(
            playersIndex().url,
            {
                position: position.join(',') || undefined,
                team: team.join(',') || undefined,
                status: status.join(',') || undefined,
                search: nextSearch || undefined,
                sort,
                direction,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const teamOptions = teams.map((team) => ({
        value: String(team.id),
        label: team.name,
    }));
    const positionOptions = (
        Object.entries(POSITION_LABELS) as [PlayerPosition, string][]
    ).map(([value, label]) => ({ value, label }));
    const statusOptions = (
        Object.entries(STATUS_LABELS) as [PlayerStatus, string][]
    ).map(([value, label]) => ({ value, label }));

    return (
        <div className="py-10">
            <Head title="Jugadores" />

            <div className="flex flex-wrap items-center gap-3">
                <input
                    type="text"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            applyFilters({ search });
                        }
                    }}
                    placeholder="Buscar jugador…"
                    className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm"
                />

                <MultiSelect
                    label="Posición"
                    options={positionOptions}
                    selected={filters.position}
                    onChange={(next) =>
                        applyFilters({ position: next as PlayerPosition[] })
                    }
                />

                <MultiSelect
                    label="Equipo"
                    options={teamOptions}
                    selected={filters.team.map(String)}
                    onChange={(next) =>
                        applyFilters({ team: next.map(Number) })
                    }
                />

                <MultiSelect
                    label="Estado"
                    options={statusOptions}
                    selected={filters.status}
                    onChange={(next) =>
                        applyFilters({ status: next as PlayerStatus[] })
                    }
                />

                <select
                    value={filters.sort}
                    onChange={(event) =>
                        applyFilters({ sort: event.target.value as PlayerSort })
                    }
                    className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm"
                >
                    <option value="points">Ordenar por puntos</option>
                    <option value="value">Ordenar por valor</option>
                    <option value="difference">
                        Ordenar por diferencia de valor
                    </option>
                </select>

                <button
                    type="button"
                    onClick={() =>
                        applyFilters({
                            direction:
                                filters.direction === 'asc' ? 'desc' : 'asc',
                        })
                    }
                    title={
                        filters.direction === 'asc'
                            ? 'Ascendente'
                            : 'Descendente'
                    }
                    className="flex items-center gap-1 rounded-md border border-neutral-300 px-3 py-1.5 text-sm text-neutral-600 hover:bg-neutral-50"
                >
                    {filters.direction === 'asc' ? (
                        <ArrowUp className="h-4 w-4" />
                    ) : (
                        <ArrowDown className="h-4 w-4" />
                    )}
                </button>
            </div>

            {players.data.length === 0 ? (
                <p className="mt-8 text-neutral-500">
                    No hay jugadores que coincidan con estos filtros.
                </p>
            ) : (
                <table className="mt-8 w-full text-sm">
                    <thead>
                        <tr className="text-left text-neutral-500">
                            <th scope="col" className="py-2 pr-4 font-medium">
                                Jugador
                            </th>
                            <th scope="col" className="px-4 font-medium">
                                Posición
                            </th>
                            <th scope="col" className="px-4 font-medium">
                                Estado
                            </th>
                            <th
                                scope="col"
                                className="px-4 text-right font-medium"
                            >
                                Valor
                            </th>
                            <th
                                scope="col"
                                className="px-4 text-right font-medium"
                            >
                                Puntos
                            </th>
                            <th scope="col" className="pl-4 font-medium">
                                Pertenece a
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-neutral-200">
                        {players.data.map((player) => (
                            <tr key={player.id}>
                                <td className="py-2 pr-4">
                                    <Link
                                        href={playersShow(player.id).url}
                                        className="flex items-center gap-2 hover:underline"
                                    >
                                        <EntityImage
                                            src={player.image}
                                            alt={player.nickname}
                                            fallback={User}
                                            className="h-8 w-8"
                                        />
                                        <div>
                                            <p className="font-medium">
                                                {player.nickname}
                                            </p>
                                            <div className="flex items-center gap-1 text-xs text-neutral-500">
                                                <EntityImage
                                                    src={player.team.logo}
                                                    alt={player.team.name}
                                                    fallback={Shield}
                                                    className="h-4 w-4"
                                                />
                                                <span>
                                                    {player.team.short_name}
                                                </span>
                                            </div>
                                        </div>
                                    </Link>
                                </td>
                                <td className="px-4">
                                    <PositionBadge position={player.position} />
                                </td>
                                <td className="px-4">
                                    <StatusBadge status={player.status} />
                                </td>
                                <td className="px-4 text-right">
                                    <p className="font-medium">
                                        {formatCurrency(player.market_value)}
                                    </p>
                                    {player.market_value_difference !== 0 && (
                                        <p
                                            className={cn(
                                                'text-xs font-medium',
                                                player.market_value_difference >
                                                    0
                                                    ? 'text-emerald-600'
                                                    : 'text-rose-600',
                                            )}
                                        >
                                            {player.market_value_difference > 0
                                                ? '+'
                                                : ''}
                                            {formatCurrency(
                                                player.market_value_difference,
                                            )}
                                        </p>
                                    )}
                                </td>
                                <td className="px-4 text-right">
                                    {player.points}
                                </td>
                                <td className="pl-4 text-neutral-500">
                                    {player.owner_team ? (
                                        <div className="flex items-center gap-1.5">
                                            <EntityImage
                                                src={player.owner_team.logo}
                                                alt={player.owner_team.name}
                                                fallback={Shield}
                                                className="h-5 w-5"
                                            />
                                            <span>
                                                {player.owner_team.name}
                                            </span>
                                        </div>
                                    ) : (
                                        <div className="flex items-center gap-1.5 text-neutral-400">
                                            <UserX className="h-4 w-4" />
                                            <span>Libre</span>
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {players.last_page > 1 && (
                <nav
                    aria-label="Paginación"
                    className="mt-8 flex flex-wrap gap-1"
                >
                    {players.links.map((link, index) => (
                        <Link
                            key={index}
                            href={link.url ?? '#'}
                            preserveScroll
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm',
                                link.active
                                    ? 'bg-neutral-900 text-white'
                                    : 'text-neutral-600 hover:bg-neutral-100',
                                !link.url && 'pointer-events-none opacity-40',
                            )}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </nav>
            )}
        </div>
    );
}

PlayersIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
