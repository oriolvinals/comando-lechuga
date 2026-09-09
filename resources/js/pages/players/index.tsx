import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import type { ReactElement } from 'react';
import { useEffect, useRef, useState } from 'react';
import { HqMultiSelect } from '@/components/hq-multi-select';
import { PlayerRow } from '@/components/hq-player-row';
import AppLayout from '@/layouts/app-layout';
import { POSITION_LABELS, STATUS_LABELS } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import { index as playersIndex } from '@/routes/players';
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

interface RealTeamOption {
    id: number;
    main_name: string;
}

type PlayerSort = 'points' | 'value' | 'difference';
type SortDirection = 'asc' | 'desc';

interface PlayersIndexProps {
    players: Paginated<Player>;
    teams: RealTeamOption[];
    seasonManagers: TeamOption[];
    filters: {
        position: PlayerPosition[];
        team: number[];
        seasonManager: number[];
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
    seasonManager: number[];
    status: PlayerStatus[];
    search: string;
    sort: PlayerSort;
    direction: SortDirection;
}

const SORT_LABELS: Record<PlayerSort, string> = {
    points: 'Puntos',
    value: 'Valor',
    difference: 'Diferencia',
};

export default function PlayersIndex({
    players,
    teams,
    seasonManagers,
    filters,
}: PlayersIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');

    const applyFilters = (overrides: Partial<FilterOverrides>) => {
        const position = overrides.position ?? filters.position;
        const team = overrides.team ?? filters.team;
        const seasonManager = overrides.seasonManager ?? filters.seasonManager;
        const status = overrides.status ?? filters.status;
        const nextSearch = overrides.search ?? filters.search ?? '';
        const sort = overrides.sort ?? filters.sort;
        const direction = overrides.direction ?? filters.direction;

        router.get(
            playersIndex().url,
            {
                position: position.join(',') || undefined,
                team: team.join(',') || undefined,
                season_manager: seasonManager.join(',') || undefined,
                status: status.join(',') || undefined,
                search: nextSearch || undefined,
                sort,
                direction,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    // Keep a ref to the latest applyFilters so the debounce effect below
    // doesn't need it in its dependency array (it's a new closure every
    // render, which would otherwise reset the pending timer each render).
    const applyFiltersRef = useRef(applyFilters);
    useEffect(() => {
        applyFiltersRef.current = applyFilters;
    });

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timeout = setTimeout(() => {
            applyFiltersRef.current({ search });
        }, 350);

        return () => clearTimeout(timeout);
    }, [search, filters.search]);

    const teamOptions = teams.map((team) => ({
        value: String(team.id),
        label: team.main_name,
    }));
    const seasonManagerOptions = seasonManagers.map((seasonManager) => ({
        value: String(seasonManager.id),
        label: seasonManager.name,
    }));
    const positionOptions = (
        Object.entries(POSITION_LABELS) as [PlayerPosition, string][]
    ).map(([value, label]) => ({ value, label }));
    const statusOptions = (
        Object.entries(STATUS_LABELS) as [PlayerStatus, string][]
    )
        .filter(([status]) => status !== 'out_of_league')
        .map(([value, label]) => ({ value, label }));

    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto max-w-7xl px-6 py-9">
                <Head title="Jugadores" />

                <h1 className="mb-6 font-display text-3xl text-hq-paper uppercase">
                    Jugadores
                </h1>

                <div className="mb-5 flex flex-wrap gap-2.5">
                    <input
                        type="text"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Buscar jugador…"
                        className="border border-hq-border bg-hq-panel px-3 py-2 font-mono text-[11px] text-hq-paper placeholder-hq-moss-dim focus:border-hq-lime focus:outline-none"
                    />

                    <HqMultiSelect
                        label="Posición"
                        options={positionOptions}
                        selected={filters.position}
                        onChange={(next) =>
                            applyFilters({ position: next as PlayerPosition[] })
                        }
                    />

                    <HqMultiSelect
                        label="Equipo"
                        options={teamOptions}
                        selected={filters.team.map(String)}
                        onChange={(next) =>
                            applyFilters({ team: next.map(Number) })
                        }
                    />

                    <HqMultiSelect
                        label="Manager"
                        options={seasonManagerOptions}
                        selected={filters.seasonManager.map(String)}
                        onChange={(next) =>
                            applyFilters({ seasonManager: next.map(Number) })
                        }
                    />

                    <HqMultiSelect
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
                            applyFilters({
                                sort: event.target.value as PlayerSort,
                            })
                        }
                        className="border border-hq-border bg-hq-panel px-3 py-2 font-mono text-[11px] font-bold tracking-wide text-hq-moss uppercase focus:border-hq-lime focus:outline-none"
                    >
                        {(
                            Object.entries(SORT_LABELS) as [
                                PlayerSort,
                                string,
                            ][]
                        ).map(([value, label]) => (
                            <option key={value} value={value}>
                                Ordenar: {label}
                            </option>
                        ))}
                    </select>

                    <button
                        type="button"
                        onClick={() =>
                            applyFilters({
                                direction:
                                    filters.direction === 'asc'
                                        ? 'desc'
                                        : 'asc',
                            })
                        }
                        title={
                            filters.direction === 'asc'
                                ? 'Ascendente'
                                : 'Descendente'
                        }
                        className="flex items-center border border-hq-border bg-hq-panel px-2.5 py-2 text-hq-moss hover:border-hq-border-strong"
                    >
                        {filters.direction === 'asc' ? (
                            <ArrowUp className="h-3.5 w-3.5" />
                        ) : (
                            <ArrowDown className="h-3.5 w-3.5" />
                        )}
                    </button>
                </div>

                {players.data.length === 0 ? (
                    <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                        <p className="mb-2 text-3xl">🔍</p>
                        <p className="font-display text-lg text-hq-paper uppercase">
                            Sin resultados
                        </p>
                        <p className="mt-1.5 font-mono text-[11px] text-hq-moss-dim">
                            No hay jugadores que coincidan con estos filtros.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="mb-2 hidden items-center justify-between px-3.5 font-mono text-[10px] text-hq-moss-dim uppercase xl:flex">
                            <div className="flex items-center gap-3">
                                <span className="w-11 shrink-0" />
                                <span className="w-[190px] shrink-0">
                                    Jugador
                                </span>
                                <span className="w-11 shrink-0 text-center">
                                    Pos.
                                </span>
                                <span className="w-16 shrink-0">Estado</span>
                                <span className="w-[150px] shrink-0">
                                    Pertenece a
                                </span>
                            </div>
                            <div className="flex items-center gap-6">
                                <span className="shrink-0">
                                    Siguientes partidos
                                </span>
                                <span className="w-[130px] shrink-0">
                                    Últimas 3 jornadas
                                </span>
                                <span className="w-[130px] shrink-0 text-right">
                                    Valor
                                </span>
                                <span className="w-[52px] shrink-0 text-center">
                                    Pts
                                </span>
                            </div>
                        </div>

                        <div>
                            {players.data.map((player) => (
                                <PlayerRow key={player.id} player={player} />
                            ))}
                        </div>
                    </>
                )}

                {players.last_page > 1 && (
                    <nav
                        aria-label="Paginación"
                        className="mt-6 flex flex-wrap gap-1.5"
                    >
                        {players.links.map((link, index) => (
                            <Link
                                key={index}
                                href={link.url ?? '#'}
                                preserveScroll
                                className={cn(
                                    'border px-3 py-1.5 font-mono text-[11px] font-bold',
                                    link.active
                                        ? 'border-hq-lime bg-hq-lime text-hq-ink'
                                        : 'border-hq-border text-hq-moss hover:border-hq-border-strong',
                                    !link.url &&
                                        'pointer-events-none opacity-40',
                                )}
                                dangerouslySetInnerHTML={{
                                    __html: link.label,
                                }}
                            />
                        ))}
                    </nav>
                )}
            </div>
        </div>
    );
}

PlayersIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
