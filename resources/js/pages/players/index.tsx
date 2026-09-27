import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Search, X } from 'lucide-react';
import type { ReactElement } from 'react';
import { useEffect, useRef, useState } from 'react';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqMultiSelect } from '@/components/hq-multi-select';
import { HqPageHeader } from '@/components/hq-page-header';
import { PlayerRow, PlayerRowHeader } from '@/components/hq-player-row';
import { HqTooltip } from '@/components/hq-tooltip';
import AppLayout from '@/layouts/app-layout';
import { POSITION_LABELS, STATUS_LABELS } from '@/lib/player-labels';
import { PLAYER_SEARCH_INPUT_ID } from '@/lib/player-search';
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

interface ActiveFilterChip {
    key: string;
    label: string;
    remove: Partial<FilterOverrides>;
}

const SORT_LABELS: Record<PlayerSort, string> = {
    points: 'Puntos',
    value: 'Valor',
    difference: 'Diferencia',
};

/** Half the filter bar on phones (two per row), natural width from `sm` up. */
const FILTER_ITEM_CLASS = 'w-[calc(50%-3px)] sm:w-auto';

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

    const activeChips: ActiveFilterChip[] = [
        ...filters.position.map((position) => ({
            key: `position-${position}`,
            label: POSITION_LABELS[position],
            remove: {
                position: filters.position.filter((item) => item !== position),
            },
        })),
        ...filters.team.map((teamId) => ({
            key: `team-${teamId}`,
            label:
                teams.find((team) => team.id === teamId)?.main_name ??
                String(teamId),
            remove: { team: filters.team.filter((item) => item !== teamId) },
        })),
        ...filters.seasonManager.map((managerId) => ({
            key: `manager-${managerId}`,
            label:
                seasonManagers.find((manager) => manager.id === managerId)
                    ?.name ?? String(managerId),
            remove: {
                seasonManager: filters.seasonManager.filter(
                    (item) => item !== managerId,
                ),
            },
        })),
        ...filters.status.map((status) => ({
            key: `status-${status}`,
            label: STATUS_LABELS[status],
            remove: {
                status: filters.status.filter((item) => item !== status),
            },
        })),
    ];

    const directionLabel =
        filters.direction === 'asc' ? 'Ascendente' : 'Descendente';

    return (
        <div className="flex-1">
            <Head title="Jugadores" />

            <HqPageHeader
                code="CH·J · BASE DE DATOS"
                title="Jugadores"
                meta={[
                    {
                        label: 'Resultados',
                        value: players.total.toLocaleString('es-ES'),
                    },
                ]}
            />

            <div className="flex flex-wrap items-center gap-1.5 border-b border-hq-border bg-hq-panel px-3.5 py-2.5 sm:gap-2 sm:px-4 sm:py-3">
                <label className="flex h-11 w-full items-center gap-2 border border-hq-border-strong bg-hq-ink px-2.5 text-hq-moss focus-within:border-hq-lime sm:h-[34px] sm:w-auto sm:min-w-[230px]">
                    <Search aria-hidden="true" className="size-4 shrink-0" />
                    <input
                        id={PLAYER_SEARCH_INPUT_ID}
                        type="search"
                        aria-label="Buscar jugador"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Buscar jugador…"
                        autoComplete="off"
                        className="min-w-0 flex-1 bg-transparent font-mono text-[13px] text-hq-paper placeholder-hq-moss-dim outline-none"
                    />
                    <kbd className="hidden border border-hq-border-strong px-1.5 py-0.5 font-mono text-[10px] leading-none text-hq-moss-dim sm:inline-block">
                        /
                    </kbd>
                </label>

                <HqMultiSelect
                    label="Posición"
                    options={positionOptions}
                    selected={filters.position}
                    onChange={(next) =>
                        applyFilters({ position: next as PlayerPosition[] })
                    }
                    className={FILTER_ITEM_CLASS}
                />

                <HqMultiSelect
                    label="Equipo"
                    options={teamOptions}
                    selected={filters.team.map(String)}
                    onChange={(next) =>
                        applyFilters({ team: next.map(Number) })
                    }
                    className={FILTER_ITEM_CLASS}
                />

                <HqMultiSelect
                    label="Manager"
                    options={seasonManagerOptions}
                    selected={filters.seasonManager.map(String)}
                    onChange={(next) =>
                        applyFilters({ seasonManager: next.map(Number) })
                    }
                    className={FILTER_ITEM_CLASS}
                />

                <HqMultiSelect
                    label="Estado"
                    options={statusOptions}
                    selected={filters.status}
                    onChange={(next) =>
                        applyFilters({ status: next as PlayerStatus[] })
                    }
                    className={FILTER_ITEM_CLASS}
                />

                <div className="flex w-full gap-1.5 sm:w-auto sm:gap-2">
                    <select
                        value={filters.sort}
                        aria-label="Ordenar"
                        onChange={(event) =>
                            applyFilters({
                                sort: event.target.value as PlayerSort,
                            })
                        }
                        className="h-11 min-w-0 flex-1 cursor-pointer border border-hq-border-strong bg-hq-ink px-2 font-mono text-[11.5px] font-bold tracking-[0.05em] text-hq-moss uppercase focus:border-hq-lime focus:outline-none sm:h-[34px] sm:flex-none"
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

                    <HqTooltip label={directionLabel}>
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
                            aria-label={`Cambiar dirección (ahora ${directionLabel.toLowerCase()})`}
                            className="flex size-11 cursor-pointer items-center justify-center border border-hq-border-strong bg-hq-ink text-hq-moss transition-colors hover:border-hq-lime hover:text-hq-lime sm:h-[34px] sm:w-8"
                        >
                            {filters.direction === 'asc' ? (
                                <ArrowUp className="size-3.5" />
                            ) : (
                                <ArrowDown className="size-3.5" />
                            )}
                        </button>
                    </HqTooltip>
                </div>

                {activeChips.length > 0 && (
                    <div className="flex w-full flex-wrap gap-1.5">
                        {activeChips.map((chip) => (
                            <button
                                key={chip.key}
                                type="button"
                                onClick={() => applyFilters(chip.remove)}
                                aria-label={`Quitar filtro ${chip.label}`}
                                className="inline-flex min-h-9 cursor-pointer items-center gap-1.5 border border-hq-border-strong bg-hq-ink px-2 font-mono text-[11px] font-semibold text-hq-moss transition-colors hover:border-hq-live hover:text-hq-live sm:min-h-0 sm:py-1"
                            >
                                {chip.label}
                                <X aria-hidden="true" className="size-3" />
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {players.data.length === 0 ? (
                <HqEmptyState glyph="?" title="Sin resultados">
                    No hay jugadores que coincidan con estos filtros.
                </HqEmptyState>
            ) : (
                <>
                    <p className="border-b border-hq-border px-3.5 py-2.5 hq-label sm:px-4">
                        {players.total.toLocaleString('es-ES')} jugadores ·
                        página {players.current_page} de {players.last_page}
                    </p>
                    <PlayerRowHeader />
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
                    className="flex flex-wrap items-center gap-1 px-3.5 py-3.5 sm:px-4"
                >
                    {players.links.map((link, index) => (
                        <Link
                            key={index}
                            href={link.url ?? '#'}
                            preserveScroll
                            aria-current={link.active ? 'page' : undefined}
                            className={cn(
                                'inline-flex h-11 min-w-11 items-center justify-center border px-2 font-mono text-xs font-bold sm:h-8 sm:min-w-[34px]',
                                link.active
                                    ? 'border-hq-lime bg-hq-lime text-hq-ink'
                                    : 'border-hq-border-strong text-hq-moss hover:border-hq-border-bright hover:text-hq-paper',
                                !link.url && 'pointer-events-none opacity-35',
                            )}
                            dangerouslySetInnerHTML={{
                                __html: link.label,
                            }}
                        />
                    ))}
                    <span className="ml-auto font-mono text-xs text-hq-moss-dim">
                        {players.per_page} por página
                    </span>
                </nav>
            )}
        </div>
    );
}

PlayersIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
