import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Shield, User } from 'lucide-react';
import type { MouseEvent as ReactMouseEvent, ReactElement } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqMultiSelect } from '@/components/hq-multi-select';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/format';
import {
    POSITION_LABELS,
    STATUS_BADGE_CLASS,
    STATUS_LABELS,
    STATUS_SHORT_LABELS,
} from '@/lib/player-labels';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import { index as playersIndex, show as playersShow } from '@/routes/players';
import { show as seasonManagersShow } from '@/routes/season-managers';
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

function PlayerRow({ player }: { player: Player }) {
    const ownerManager = player.owner_manager;
    const goToOwnerManager = (event: ReactMouseEvent) => {
        if (!ownerManager) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        router.visit(seasonManagersShow(ownerManager.id).url);
    };

    return (
        <Link href={playersShow(player.id).url} className="block">
            {/* Desktop / tablet row */}
            <div className="hq-card-cut mb-1.5 hidden items-center justify-between px-3.5 py-2.5 transition-[filter] hover:brightness-125 md:flex">
                <div className="flex min-w-0 items-center gap-3">
                    <EntityImage
                        src={player.image}
                        alt={player.nickname}
                        fallback={User}
                        className="h-11 w-11 shrink-0 bg-hq-border"
                    />
                    <div className="w-[190px] shrink-0">
                        <p className="truncate text-sm font-extrabold text-hq-paper">
                            {player.nickname}
                        </p>
                        <div className="mt-0.5 flex items-center gap-1.5">
                            <EntityImage
                                src={player.team.logo}
                                alt={player.team.name}
                                fallback={Shield}
                                shape="square"
                                className="h-3.5 w-3.5"
                            />
                            <span className="font-mono text-[10px] text-hq-moss-dim">
                                {player.team.short_name}
                            </span>
                        </div>
                    </div>
                    <div className="w-11 shrink-0 text-center">
                        <HqPositionTag position={player.position} />
                    </div>
                    <div className="w-16 shrink-0">
                        {player.status !== 'ok' && (
                            <span
                                className={cn(
                                    'border px-1.5 py-0.5 font-mono text-[9px] font-bold uppercase',
                                    STATUS_BADGE_CLASS[player.status],
                                )}
                            >
                                {STATUS_SHORT_LABELS[player.status]}
                            </span>
                        )}
                    </div>
                    <div className="flex w-[150px] shrink-0 items-center gap-1.5 font-mono text-[11px] text-hq-moss">
                        {ownerManager ? (
                            <span
                                role="link"
                                tabIndex={0}
                                onClick={goToOwnerManager}
                                className="flex min-w-0 cursor-pointer items-center gap-1.5 hover:text-hq-paper"
                            >
                                <span
                                    className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                                    style={{
                                        backgroundColor: managerColor(
                                            ownerManager.primary_color,
                                        ),
                                    }}
                                />
                                <span className="truncate">
                                    {ownerManager.name}
                                </span>
                            </span>
                        ) : (
                            <span className="text-hq-moss-dim">Libre</span>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-6">
                    <HqRecentScores
                        scores={player.recent_scores}
                        finished={player.recent_scores_finished}
                        className="w-[130px]"
                    />
                    <div className="w-[130px] shrink-0 text-right">
                        <p className="font-mono text-[13px] font-bold text-hq-paper">
                            {formatCurrency(player.market_value)}
                        </p>
                        {player.market_value_difference !== 0 && (
                            <p
                                className={cn(
                                    'font-mono text-[10px] font-bold',
                                    player.market_value_difference > 0
                                        ? 'text-hq-lime'
                                        : 'text-hq-live',
                                )}
                            >
                                {player.market_value_difference > 0
                                    ? '▲'
                                    : '▼'}{' '}
                                {formatCurrency(
                                    Math.abs(player.market_value_difference),
                                )}
                            </p>
                        )}
                    </div>
                    <div className="w-[52px] shrink-0 text-center font-display text-xl text-hq-lime">
                        {player.points}
                    </div>
                </div>
            </div>

            {/* Mobile row */}
            <div className="hq-card-cut mb-2 px-3 py-2.5 transition-[filter] hover:brightness-125 md:hidden">
                <div className="flex items-center gap-2.5">
                    <EntityImage
                        src={player.image}
                        alt={player.nickname}
                        fallback={User}
                        className="h-9 w-9 shrink-0 bg-hq-border"
                    />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-extrabold text-hq-paper">
                            {player.nickname}
                        </p>
                        <div className="mt-0.5 flex items-center gap-1.5">
                            <EntityImage
                                src={player.team.logo}
                                alt={player.team.name}
                                fallback={Shield}
                                shape="square"
                                className="h-[10px] w-[10px]"
                            />
                            <span className="font-mono text-[9px] text-hq-moss-dim">
                                {player.team.short_name}
                            </span>
                            <HqPositionTag position={player.position} />
                            {player.status !== 'ok' && (
                                <span
                                    className={cn(
                                        'border px-1 py-0.5 font-mono text-[8px] font-bold uppercase',
                                        STATUS_BADGE_CLASS[player.status],
                                    )}
                                >
                                    {STATUS_SHORT_LABELS[player.status]}
                                </span>
                            )}
                        </div>
                    </div>
                    <span className="shrink-0 font-display text-lg text-hq-lime">
                        {player.points}
                    </span>
                </div>
                <div className="mt-2 flex items-center justify-between border-t border-hq-ink pt-2">
                    <p className="font-mono text-[11px] font-bold text-hq-paper">
                        {formatCurrency(player.market_value)}
                        {player.market_value_difference !== 0 && (
                            <span
                                className={cn(
                                    'ml-2 text-[10px]',
                                    player.market_value_difference > 0
                                        ? 'text-hq-lime'
                                        : 'text-hq-live',
                                )}
                            >
                                {player.market_value_difference > 0
                                    ? '▲'
                                    : '▼'}{' '}
                                {formatCurrency(
                                    Math.abs(player.market_value_difference),
                                )}
                            </span>
                        )}
                    </p>
                    <div className="flex items-center gap-1.5 font-mono text-[10px] text-hq-moss">
                        {ownerManager ? (
                            <span
                                role="link"
                                tabIndex={0}
                                onClick={goToOwnerManager}
                                className="flex min-w-0 cursor-pointer items-center gap-1.5 hover:text-hq-paper"
                            >
                                <span
                                    className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                                    style={{
                                        backgroundColor: managerColor(
                                            ownerManager.primary_color,
                                        ),
                                    }}
                                />
                                <span className="max-w-[110px] truncate">
                                    {ownerManager.name}
                                </span>
                            </span>
                        ) : (
                            <span className="text-hq-moss-dim">Libre</span>
                        )}
                    </div>
                </div>
                <div className="mt-2 border-t border-hq-ink pt-2">
                    <HqRecentScores
                        scores={player.recent_scores}
                        finished={player.recent_scores_finished}
                    />
                </div>
            </div>
        </Link>
    );
}

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

    const teamOptions = teams.map((team) => ({
        value: String(team.id),
        label: team.name,
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
        <div className="hq-texture hq-bleed min-h-[calc(100vh-95px)] border-y border-hq-border">
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
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                applyFilters({ search });
                            }
                        }}
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
                        <div className="mb-2 hidden items-center justify-between px-3.5 font-mono text-[10px] text-hq-moss-dim uppercase md:flex">
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
