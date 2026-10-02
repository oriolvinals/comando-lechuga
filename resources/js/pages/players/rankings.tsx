import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ArrowUpRight,
    ChevronDown,
    Home,
    Maximize2,
    Plane,
    Shield,
    User,
    X,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqManagerChip } from '@/components/hq-manager-chip';
import { HqMultiSelect } from '@/components/hq-multi-select';
import { HqPageHeader } from '@/components/hq-page-header';
import { HqPlayersViewTabs } from '@/components/hq-players-view-tabs';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqChannelHeader } from '@/components/hq-section';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/format';
import {
    JORNADA_STAT_LABELS,
    POSITION_ABBREVIATIONS,
    POSITION_LABELS,
} from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import {
    show as playersShow,
    rankings as playersRankings,
} from '@/routes/players';
import type { PlayerPosition } from '@/types/models';

interface RankingTeam {
    id: number;
    main_name: string;
    short_name: string;
    logo: string;
}

interface RankingManager {
    id: number;
    name: string;
    primary_color: string | null;
}

interface RankingRow {
    rank: number;
    value: number;
    matches: number;
    player: {
        id: number;
        nickname: string;
        image: string;
        position: PlayerPosition;
        team: RankingTeam;
    };
    lined_up_by?: RankingManager[];
}

interface BreakdownMatch {
    fixture_id: number;
    week_number: number;
    rival: RankingTeam;
    is_home: boolean;
    own_score: number | null;
    rival_score: number | null;
    value: number;
    minutes: number;
    played: boolean;
    lined_up_by: RankingManager[];
}

interface RankingStat {
    key: string;
    /** Takes points away: listed apart and shown in red. */
    bad: boolean;
}

interface RankingsProps {
    weeksPlayed: number;
    stats: RankingStat[];
    stat: string | null;
    overview: { stat: string; count: number; rows: RankingRow[] }[] | null;
    ranking: RankingRow[] | null;
    breakdown: { player_id: number; matches: BreakdownMatch[] } | null;
    teams: { id: number; main_name: string }[];
    seasonManagers: { id: number; name: string }[];
    filters: {
        position: PlayerPosition[];
        team: number[];
        seasonManager: number | null;
        week: number | null;
    };
    [key: string]: unknown;
}

interface Query {
    stat: string | null;
    player: number | null;
    position: PlayerPosition[];
    team: number[];
    seasonManager: number | null;
    week: number | null;
}

const POSITIONS: PlayerPosition[] = [
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
];

const POSITION_CHIP_ON: Record<PlayerPosition, string> = {
    goalkeeper:
        'text-hq-por bg-hq-por/14 shadow-[inset_0_-2px_0_var(--color-hq-por)]',
    defender:
        'text-hq-def bg-hq-def/14 shadow-[inset_0_-2px_0_var(--color-hq-def)]',
    midfield:
        'text-hq-med bg-hq-med/14 shadow-[inset_0_-2px_0_var(--color-hq-med)]',
    striker:
        'text-hq-del bg-hq-del/14 shadow-[inset_0_-2px_0_var(--color-hq-del)]',
    coach: 'text-hq-ent bg-hq-ent/14 shadow-[inset_0_-2px_0_var(--color-hq-ent)]',
};

const PAGE_SIZE = 25;

const CONTROL_CLASS =
    'h-11 cursor-pointer border border-hq-border-strong bg-hq-ink px-2.5 font-mono text-[11.5px] font-bold tracking-[0.05em] text-hq-moss uppercase transition-colors hover:border-hq-border-bright hover:text-hq-paper focus:border-hq-lime focus:outline-none sm:h-[34px]';

function statLabel(key: string): string {
    return JORNADA_STAT_LABELS[key] ?? key;
}

function visit(query: Query, only?: string[]) {
    router.get(
        playersRankings().url,
        {
            stat: query.stat ?? undefined,
            player: query.player ?? undefined,
            position: query.position.join(',') || undefined,
            team: query.team.join(',') || undefined,
            season_manager: query.seasonManager ?? undefined,
            jornada: query.week ?? undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            ...(only ? { only } : {}),
        },
    );
}

function TeamBit({ team }: { team: RankingTeam }) {
    return (
        <span className="inline-flex min-w-0 items-center gap-[5px]">
            <EntityImage
                src={team.logo}
                alt=""
                fallback={Shield}
                shape="square"
                className="size-3.5 shrink-0 rounded-none bg-transparent"
            />
            <span className="truncate">{team.short_name}</span>
        </span>
    );
}

function PlayerPhoto({ src, size }: { src: string; size: 'sm' | 'md' | 'lg' }) {
    return (
        <EntityImage
            src={src}
            alt=""
            fallback={User}
            shape="square"
            className={cn(
                'shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt object-cover object-top text-hq-moss-dim',
                size === 'sm' && 'size-7',
                size === 'md' && 'size-10 md:size-[46px]',
                size === 'lg' && 'size-11',
            )}
        />
    );
}

/** The filter bar: on a ranking, «← Todos» and the action select; then positions, team and «Temporada» or one manager. */
function FilterBar({
    query,
    stats,
    teams,
    seasonManagers,
    weeksPlayed,
}: {
    query: Query;
    stats: RankingsProps['stats'];
    teams: RankingsProps['teams'];
    seasonManagers: RankingsProps['seasonManagers'];
    weeksPlayed: number;
}) {
    const togglePosition = (position: PlayerPosition) =>
        visit({
            ...query,
            player: null,
            position: query.position.includes(position)
                ? query.position.filter((item) => item !== position)
                : [...query.position, position],
        });

    return (
        <div className="flex flex-wrap items-center gap-1.5 border-b border-hq-border bg-hq-panel px-3.5 py-2.5 sm:gap-2 sm:px-4 sm:py-3">
            {query.stat !== null && (
                <>
                    <button
                        type="button"
                        onClick={() =>
                            visit({ ...query, stat: null, player: null })
                        }
                        aria-label="Todos los rankings"
                        className={cn(
                            CONTROL_CLASS,
                            'inline-flex w-11 items-center justify-center gap-1.5 px-0 hover:border-hq-lime hover:text-hq-lime sm:w-auto sm:px-2.5',
                        )}
                    >
                        <ArrowLeft aria-hidden="true" className="size-3.5" />
                        <span className="max-sm:hidden">Todos</span>
                    </button>
                    <select
                        value={query.stat}
                        aria-label="Estadística"
                        onChange={(event) =>
                            visit({
                                ...query,
                                stat: event.target.value,
                                player: null,
                            })
                        }
                        className={cn(
                            CONTROL_CLASS,
                            'min-w-0 flex-1 sm:flex-none',
                        )}
                    >
                        {[
                            { label: 'Buenas acciones', bad: false },
                            { label: 'Malas acciones', bad: true },
                        ].map((group) => (
                            <optgroup key={group.label} label={group.label}>
                                {stats
                                    .filter((item) => item.bad === group.bad)
                                    .map((item) => (
                                        <option key={item.key} value={item.key}>
                                            {statLabel(item.key)}
                                        </option>
                                    ))}
                            </optgroup>
                        ))}
                    </select>
                </>
            )}

            <div
                role="group"
                aria-label="Posición"
                className="flex w-full border border-hq-border-strong sm:inline-flex sm:w-auto"
            >
                {POSITIONS.map((position, index) => {
                    const on = query.position.includes(position);

                    return (
                        <button
                            key={position}
                            type="button"
                            aria-pressed={on}
                            title={POSITION_LABELS[position]}
                            onClick={() => togglePosition(position)}
                            className={cn(
                                'inline-flex h-11 min-w-12 flex-1 cursor-pointer items-center justify-center px-2.5 font-mono text-[11.5px] leading-none font-bold tracking-[0.05em] transition-colors sm:h-8 sm:flex-none',
                                index > 0 && 'border-l border-hq-border-strong',
                                on
                                    ? POSITION_CHIP_ON[position]
                                    : 'bg-hq-ink text-hq-moss hover:bg-hq-panel-alt hover:text-hq-paper',
                            )}
                        >
                            {POSITION_ABBREVIATIONS[position]}
                        </button>
                    );
                })}
            </div>

            <HqMultiSelect
                label="Equipo"
                options={teams.map((team) => ({
                    value: String(team.id),
                    label: team.main_name,
                }))}
                selected={query.team.map(String)}
                onChange={(next) =>
                    visit({ ...query, player: null, team: next.map(Number) })
                }
                className="w-[calc(50%-3px)] sm:w-auto"
            />

            <select
                value={query.seasonManager ?? ''}
                aria-label="General o manager"
                onChange={(event) =>
                    visit({
                        ...query,
                        player: null,
                        seasonManager:
                            event.target.value === ''
                                ? null
                                : Number(event.target.value),
                    })
                }
                className={cn(
                    CONTROL_CLASS,
                    'w-[calc(50%-3px)] sm:w-auto',
                    query.seasonManager !== null &&
                        'border-hq-lime text-hq-lime',
                )}
            >
                <option value="">General</option>
                {seasonManagers.map((manager) => (
                    <option key={manager.id} value={manager.id}>
                        Alineado por {manager.name}
                    </option>
                ))}
            </select>

            <select
                value={query.week ?? ''}
                aria-label="Jornada"
                onChange={(event) =>
                    visit({
                        ...query,
                        player: null,
                        week:
                            event.target.value === ''
                                ? null
                                : Number(event.target.value),
                    })
                }
                className={cn(
                    CONTROL_CLASS,
                    'w-full sm:w-auto',
                    query.week !== null && 'border-hq-lime text-hq-lime',
                )}
            >
                <option value="">Todas las jornadas</option>
                {Array.from({ length: weeksPlayed }, (_, index) => (
                    <option key={index + 1} value={index + 1}>
                        Jornada {index + 1}
                    </option>
                ))}
            </select>

            {query.team.length > 0 && (
                <div className="flex w-full flex-wrap gap-1.5">
                    {query.team.map((teamId) => {
                        const label =
                            teams.find((team) => team.id === teamId)
                                ?.main_name ?? String(teamId);

                        return (
                            <button
                                key={teamId}
                                type="button"
                                onClick={() =>
                                    visit({
                                        ...query,
                                        player: null,
                                        team: query.team.filter(
                                            (item) => item !== teamId,
                                        ),
                                    })
                                }
                                aria-label={`Quitar filtro ${label}`}
                                className="inline-flex min-h-9 cursor-pointer items-center gap-1.5 border border-hq-border-strong bg-hq-ink px-2 font-mono text-[11px] font-semibold text-hq-moss transition-colors hover:border-hq-live hover:text-hq-live sm:min-h-0 sm:py-1"
                            >
                                {label}
                                <X aria-hidden="true" className="size-3" />
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/**
 * A stat nobody has for these filters: a big dim «0» in the block's tone and
 * one button per active filter to widen it — in an overview cell (same height
 * as its filled neighbours) or as the ranking page's board.
 */
function EmptyStat({
    bad,
    query,
    size,
}: {
    bad: boolean;
    query: Query;
    size: 'cell' | 'page';
}) {
    const widen: { label: string; next: Partial<Query> }[] = [
        ...(query.position.length > 0
            ? [{ label: 'Todas las posiciones', next: { position: [] } }]
            : []),
        ...(query.week !== null
            ? [{ label: 'Toda la temporada', next: { week: null } }]
            : []),
        ...(query.seasonManager !== null
            ? [{ label: 'General', next: { seasonManager: null } }]
            : []),
        ...(query.team.length > 0
            ? [{ label: 'Todos los equipos', next: { team: [] } }]
            : []),
    ];

    return (
        <div
            className={cn(
                'flex',
                size === 'cell'
                    ? 'min-h-[170px] flex-1 flex-col items-start gap-2.5 px-3.5 pt-1 pb-3.5'
                    : 'flex-col gap-4 border-b border-hq-border bg-linear-to-b from-hq-well to-hq-ink px-3.5 py-6 sm:flex-row sm:items-center sm:gap-9 sm:px-7 sm:py-9',
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'font-dot leading-[0.8] font-black tabular-nums',
                    bad ? 'text-hq-live/25' : 'text-hq-lime/25',
                    size === 'cell'
                        ? 'flex-1 text-[104px]'
                        : 'text-[150px] sm:text-[230px]',
                )}
            >
                0
            </span>
            <span className="sr-only">Nadie suma con estos filtros.</span>
            {widen.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {widen.map((action) => (
                        <button
                            key={action.label}
                            type="button"
                            onClick={() =>
                                visit({
                                    ...query,
                                    player: null,
                                    ...action.next,
                                })
                            }
                            className={cn(
                                'inline-flex cursor-pointer items-center gap-2 border px-3 font-mono text-[11.5px] leading-none font-bold tracking-[0.05em] transition-colors',
                                size === 'cell'
                                    ? 'h-11 sm:h-8'
                                    : 'h-11 sm:h-[38px]',
                                bad
                                    ? 'border-hq-live bg-hq-live/8 text-hq-live hover:bg-hq-live/16'
                                    : 'border-hq-lime bg-hq-lime/8 text-hq-lime hover:bg-hq-lime/16',
                            )}
                        >
                            <Maximize2 aria-hidden="true" className="size-3" />
                            {action.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/** One action's cell of the overview: its name, the leader big, then the 2nd to 5th. */
function OverviewCell({
    stat,
    bad,
    count,
    rows,
    query,
}: {
    stat: string;
    bad: boolean;
    count: number;
    rows: RankingRow[];
    query: Query;
}) {
    const [leader, ...rest] = rows;
    const open = (player: number | null) => visit({ ...query, stat, player });

    return (
        <div className="flex min-w-0 flex-col border-b border-hq-border md:border-r md:[&:nth-child(2n)]:border-r-0 xl:[&:nth-child(2n)]:border-r xl:[&:nth-child(4n)]:border-r-0">
            <button
                type="button"
                onClick={() => open(null)}
                className="group flex cursor-pointer items-center gap-2 px-3.5 pt-3 pb-2.5 text-left transition-colors hover:bg-hq-panel"
            >
                <b className="text-sm leading-[1.15] font-extrabold text-hq-paper">
                    {statLabel(stat)}
                </b>
                <span className="font-mono text-xs font-semibold text-hq-moss-dim tabular-nums">
                    {formatNumber(count)}
                </span>
                <span className="ml-auto inline-flex items-center gap-1 font-mono text-[11px] font-semibold tracking-[0.04em] whitespace-nowrap text-hq-moss-dim group-hover:text-hq-lime">
                    Ver ranking
                    <ArrowRight aria-hidden="true" className="size-3" />
                </span>
            </button>

            {leader === undefined ? (
                <EmptyStat bad={bad} query={query} size="cell" />
            ) : (
                <>
                    <button
                        type="button"
                        onClick={() => open(leader.player.id)}
                        className="grid cursor-pointer grid-cols-[16px_44px_minmax(0,1fr)_auto] items-center gap-2.5 border-b border-dashed border-hq-border px-3.5 pt-2 pb-3 text-left transition-colors hover:bg-hq-panel"
                    >
                        <span className="text-right font-mono text-xs leading-none font-bold text-hq-lime">
                            {leader.rank}
                        </span>
                        <PlayerPhoto src={leader.player.image} size="lg" />
                        <span className="min-w-0">
                            <span className="block truncate text-[15px] leading-[1.15] font-extrabold text-hq-paper">
                                {leader.player.nickname}
                            </span>
                            <span className="mt-[5px] flex min-w-0 items-center gap-1.5 font-mono text-[11px] leading-none text-hq-moss-dim">
                                <TeamBit team={leader.player.team} />
                                <HqPositionTag
                                    position={leader.player.position}
                                />
                            </span>
                        </span>
                        <HqLed
                            tone={bad ? 'live' : 'lime'}
                            className="text-[30px]"
                        >
                            {formatNumber(leader.value)}
                        </HqLed>
                    </button>
                    {rest.map((row) => (
                        <button
                            key={row.player.id}
                            type="button"
                            onClick={() => open(row.player.id)}
                            className="grid cursor-pointer grid-cols-[16px_28px_minmax(0,1fr)_auto_auto] items-center gap-2.5 px-3.5 py-1.5 text-left transition-colors last:pb-2.5 hover:bg-hq-panel"
                        >
                            <span className="text-right font-mono text-xs leading-none font-bold text-hq-moss-dim">
                                {row.rank}
                            </span>
                            <PlayerPhoto src={row.player.image} size="sm" />
                            <span className="flex min-w-0 items-center gap-1.5 text-[13px] leading-[1.15] font-bold text-hq-paper">
                                <EntityImage
                                    src={row.player.team.logo}
                                    alt=""
                                    fallback={Shield}
                                    shape="square"
                                    className="size-3.5 shrink-0 rounded-none bg-transparent"
                                />
                                <span className="truncate">
                                    {row.player.nickname}
                                </span>
                            </span>
                            <HqPositionTag position={row.player.position} />
                            <span
                                className={cn(
                                    'min-w-7 text-right font-mono text-sm leading-none font-semibold tabular-nums',
                                    bad ? 'text-hq-live' : 'text-hq-lime',
                                )}
                            >
                                {formatNumber(row.value)}
                            </span>
                        </button>
                    ))}
                </>
            )}
        </div>
    );
}

/** Every manager who lined the player up, as chips that wrap onto more lines when they don't fit. */
function LinedUpBy({ managers }: { managers: RankingManager[] }) {
    if (managers.length === 0) {
        return (
            <span className="font-mono text-xs text-hq-moss-dim">Nadie</span>
        );
    }

    return (
        <span className="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1">
            {managers.map((manager) => (
                <HqManagerChip
                    key={manager.id}
                    manager={manager}
                    link={false}
                />
            ))}
        </span>
    );
}

function resultOf(match: BreakdownMatch) {
    if (match.own_score === null || match.rival_score === null) {
        return null;
    }

    if (match.own_score === match.rival_score) {
        return { label: 'E', className: 'bg-hq-gold/15 text-hq-gold' };
    }

    return match.own_score > match.rival_score
        ? { label: 'V', className: 'bg-hq-lime/15 text-hq-lime' }
        : { label: 'D', className: 'bg-hq-live/15 text-hq-live' };
}

/** The opened row: the player's value of the action in every match, against whom, who owned him and who lined him up. */
function Breakdown({
    stat,
    bad,
    playerId,
    matches,
}: {
    stat: string;
    bad: boolean;
    playerId: number;
    matches: BreakdownMatch[];
}) {
    const max = Math.max(1, ...matches.map((match) => match.value));
    const best = matches.reduce<BreakdownMatch | null>(
        (top, match) => (match.value > (top?.value ?? 0) ? match : top),
        null,
    );

    return (
        <div className="border-t border-hq-border px-3.5 pt-3 pb-3.5 md:pt-3.5 md:pr-4 md:pb-4 md:pl-[60px]">
            <div className="mb-2 flex justify-end">
                <Link
                    href={playersShow(playerId).url}
                    className="inline-flex min-h-11 items-center gap-1.5 px-0 font-mono text-xs font-bold tracking-[0.06em] text-hq-lime uppercase hover:bg-hq-panel-alt md:min-h-0 md:px-2 md:py-1.5"
                >
                    Ver ficha
                    <ArrowUpRight aria-hidden="true" className="size-3.5" />
                </Link>
            </div>
            <div className="flex [scrollbar-width:thin] overflow-x-auto border border-hq-border bg-hq-well">
                {matches.map((match) => {
                    const result = resultOf(match);
                    const isBest = best !== null && match === best;
                    const VenueIcon = match.is_home ? Home : Plane;

                    return (
                        <div
                            key={match.fixture_id}
                            title={`J${match.week_number} · ${match.rival.main_name} (${match.is_home ? 'casa' : 'fuera'}) · ${match.value} ${statLabel(stat).toLowerCase()}`}
                            className="flex max-w-28 min-w-[74px] flex-[1_0_74px] flex-col items-center border-r border-hq-border px-1 pt-2.5 pb-[9px] last:border-r-0 max-md:flex-[0_0_70px]"
                        >
                            <span
                                className={cn(
                                    'font-mono text-base leading-none font-bold tabular-nums',
                                    match.value === 0
                                        ? 'text-hq-moss-dim'
                                        : isBest
                                          ? bad
                                              ? 'text-hq-live'
                                              : 'text-hq-lime'
                                          : 'text-hq-paper',
                                )}
                            >
                                {match.value}
                            </span>
                            <span className="mt-1.5 flex h-16 w-[22px] items-end border-b border-hq-border-strong">
                                <i
                                    className={cn(
                                        'block w-full',
                                        bad ? 'bg-hq-live' : 'bg-hq-lime',
                                        isBest ? 'opacity-100' : 'opacity-80',
                                    )}
                                    style={{
                                        height: match.value
                                            ? `${Math.max(4, Math.round((match.value / max) * 64))}px`
                                            : 0,
                                    }}
                                />
                            </span>
                            <EntityImage
                                src={match.rival.logo}
                                alt=""
                                fallback={Shield}
                                shape="square"
                                className="mt-2 size-[22px] rounded-none bg-transparent"
                            />
                            <span className="mt-1 font-mono text-[11px] leading-none font-bold text-hq-paper">
                                {match.rival.short_name}
                            </span>
                            <span className="mt-[5px] flex items-center gap-[3px] font-mono text-[11px] leading-none whitespace-nowrap text-hq-moss-dim">
                                <VenueIcon
                                    aria-hidden="true"
                                    className="size-[11px]"
                                />
                                {match.is_home ? 'Casa' : 'Fuera'}
                            </span>
                            {result && (
                                <span className="mt-1.5 flex items-center gap-1">
                                    <span
                                        className={cn(
                                            'inline-flex size-[18px] items-center justify-center font-mono text-[11px] leading-none font-bold',
                                            result.className,
                                        )}
                                    >
                                        {result.label}
                                    </span>
                                    <b className="font-mono text-[11px] leading-none font-bold text-hq-paper">
                                        {match.own_score}–{match.rival_score}
                                    </b>
                                </span>
                            )}
                            {match.played ? (
                                <span className="mt-1.5 font-mono text-[11px] leading-none text-hq-moss-dim">
                                    {match.minutes}′
                                </span>
                            ) : (
                                <span className="mt-1.5 border border-hq-olive px-[3px] py-0.5 font-mono text-[10px] leading-none font-semibold tracking-[0.04em] text-hq-olive">
                                    No jugó
                                </span>
                            )}
                            <span className="mt-2 flex w-full flex-col items-center gap-1.5 border-t border-hq-border px-1 pt-1.5">
                                {match.lined_up_by.length === 0 ? (
                                    <ManagerMark manager={null} />
                                ) : (
                                    match.lined_up_by.map((manager) => (
                                        <ManagerMark
                                            key={manager.id}
                                            manager={manager}
                                        />
                                    ))
                                )}
                            </span>
                            <span className="mt-[7px] font-mono text-[11px] leading-none font-bold text-hq-moss">
                                J{match.week_number}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/** A manager who lined him up that jornada (— for nobody), in a breakdown column. */
function ManagerMark({ manager }: { manager: RankingManager | null }) {
    return (
        <span
            title={`Alineado por ${manager?.name ?? 'nadie'}`}
            className="flex w-full min-w-0 justify-center font-mono text-[11px] leading-none text-hq-moss-dim"
        >
            {manager === null ? (
                '—'
            ) : (
                <HqManagerChip
                    manager={manager}
                    link={false}
                    className="min-w-0 text-[11px]"
                />
            )}
        </span>
    );
}

/** One action's full ranking: rows that open the player's match-by-match breakdown. */
function RankingTable({
    stat,
    bad,
    rows,
    breakdown,
    query,
}: {
    stat: string;
    bad: boolean;
    rows: RankingRow[];
    breakdown: RankingsProps['breakdown'];
    query: Query;
}) {
    const [limit, setLimit] = useState(() => {
        const openIndex = rows.findIndex(
            (row) => row.player.id === query.player,
        );

        return Math.max(
            PAGE_SIZE,
            Math.ceil((openIndex + 1) / PAGE_SIZE) * PAGE_SIZE,
        );
    });
    const max = rows[0]?.value ?? 1;
    const toggle = (playerId: number) =>
        visit(
            { ...query, player: query.player === playerId ? null : playerId },
            ['breakdown'],
        );

    if (rows.length === 0) {
        return <EmptyStat bad={bad} query={query} size="page" />;
    }

    return (
        <>
            <div
                aria-hidden="true"
                className="hidden grid-cols-[30px_46px_minmax(160px,1.6fr)_44px_minmax(140px,1fr)_52px_minmax(150px,1fr)_22px] items-end gap-3 border-b border-hq-border-strong px-4 py-[9px] font-mono text-[10.5px] leading-tight font-semibold tracking-[0.07em] text-hq-moss-dim uppercase md:grid"
            >
                <span className="text-right">#</span>
                <span />
                <span>Jugador</span>
                <span className="text-center">Pos.</span>
                <span>Alineado por</span>
                <span className="text-right">PJ</span>
                <span className="text-right">{statLabel(stat)}</span>
                <span />
            </div>
            {rows.slice(0, limit).map((row) => {
                const open = query.player === row.player.id;

                return (
                    <div
                        key={row.player.id}
                        className={cn(
                            'border-b border-hq-border',
                            open &&
                                'bg-hq-panel shadow-[inset_3px_0_0_var(--color-hq-lime)]',
                        )}
                    >
                        <button
                            type="button"
                            onClick={() => toggle(row.player.id)}
                            aria-expanded={open}
                            className={cn(
                                'grid w-full cursor-pointer grid-cols-[22px_40px_minmax(0,1fr)_auto] items-center gap-2.5 px-3.5 py-3 text-left transition-colors hover:bg-hq-panel md:grid-cols-[30px_46px_minmax(160px,1.6fr)_44px_minmax(140px,1fr)_52px_minmax(150px,1fr)_22px] md:gap-3 md:px-4 md:py-2.5',
                                open && 'bg-hq-panel-alt hover:bg-hq-panel-alt',
                            )}
                        >
                            <span
                                className={cn(
                                    'text-right font-mono text-[13px] leading-none font-bold',
                                    row.rank <= 3
                                        ? bad
                                            ? 'text-hq-live'
                                            : 'text-hq-lime'
                                        : 'text-hq-moss-dim',
                                )}
                            >
                                {row.rank}
                            </span>
                            <PlayerPhoto src={row.player.image} size="md" />
                            <span className="min-w-0">
                                <span className="block truncate text-sm leading-[1.15] font-extrabold text-hq-paper">
                                    {row.player.nickname}
                                </span>
                                <span className="mt-[5px] flex min-w-0 items-center gap-1.5 font-mono text-[11px] leading-none text-hq-moss-dim">
                                    <TeamBit team={row.player.team} />
                                    <span className="flex items-center gap-1.5 md:hidden">
                                        <HqPositionTag
                                            position={row.player.position}
                                        />
                                        {row.matches} PJ
                                    </span>
                                </span>
                                <span className="mt-[5px] flex md:hidden">
                                    <LinedUpBy
                                        managers={row.lined_up_by ?? []}
                                    />
                                </span>
                            </span>
                            <span className="hidden justify-center md:flex">
                                <HqPositionTag position={row.player.position} />
                            </span>
                            <span className="hidden min-w-0 md:flex">
                                <LinedUpBy managers={row.lined_up_by ?? []} />
                            </span>
                            <span className="hidden text-right font-mono text-[13px] text-hq-moss tabular-nums md:block">
                                {row.matches}
                            </span>
                            <span className="flex flex-col items-end justify-end gap-1.5 md:flex-row md:items-center md:gap-3">
                                <span className="relative hidden h-1 max-w-[120px] flex-1 bg-hq-border md:block">
                                    <i
                                        className={cn(
                                            'absolute inset-y-0 left-0 opacity-75',
                                            bad ? 'bg-hq-live' : 'bg-hq-lime',
                                        )}
                                        style={{
                                            width: `${((row.value / max) * 100).toFixed(1)}%`,
                                        }}
                                    />
                                </span>
                                <HqLed
                                    tone={bad ? 'live' : 'lime'}
                                    className="min-w-11 text-right text-[22px] md:text-2xl"
                                >
                                    {formatNumber(row.value)}
                                </HqLed>
                                <ChevronDown
                                    aria-hidden="true"
                                    className={cn(
                                        'size-4 text-hq-moss-dim transition-transform md:hidden',
                                        open && 'rotate-180 text-hq-lime',
                                    )}
                                />
                            </span>
                            <span className="hidden justify-center md:flex">
                                <ChevronDown
                                    aria-hidden="true"
                                    className={cn(
                                        'size-4 text-hq-moss-dim transition-transform',
                                        open && 'rotate-180 text-hq-lime',
                                    )}
                                />
                            </span>
                        </button>
                        {open && breakdown?.player_id === row.player.id && (
                            <Breakdown
                                stat={stat}
                                bad={bad}
                                playerId={row.player.id}
                                matches={breakdown.matches}
                            />
                        )}
                    </div>
                );
            })}
            {rows.length > limit && (
                <div className="flex items-center gap-3 px-3.5 pt-3 pb-[18px] sm:px-4 sm:py-3.5">
                    <button
                        type="button"
                        onClick={() => setLimit(limit + PAGE_SIZE)}
                        className="h-11 cursor-pointer border border-hq-border-strong px-3.5 font-mono text-[11.5px] font-bold tracking-[0.06em] text-hq-moss uppercase transition-colors hover:border-hq-lime hover:text-hq-lime sm:h-[34px]"
                    >
                        Mostrar 25 más
                    </button>
                    <span className="ml-auto font-mono text-xs text-hq-moss-dim">
                        {limit} de {formatNumber(rows.length)}
                    </span>
                </div>
            )}
        </>
    );
}

export default function PlayersRankings({
    weeksPlayed,
    stats,
    stat,
    overview,
    ranking,
    breakdown,
    teams,
    seasonManagers,
    filters,
}: RankingsProps) {
    const query: Query = {
        stat,
        player: breakdown?.player_id ?? null,
        position: filters.position,
        team: filters.team,
        seasonManager: filters.seasonManager,
        week: filters.week,
    };
    const badStats = new Set(
        stats.filter((item) => item.bad).map((item) => item.key),
    );
    const managerName = seasonManagers.find(
        (manager) => manager.id === filters.seasonManager,
    )?.name;
    const scope = [
        managerName ? `Alineado por ${managerName}` : 'General',
        filters.week !== null ? `Jornada ${filters.week}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="flex-1">
            <Head title="Rankings · Jugadores" />

            <HqPageHeader code="BASE DE DATOS" title="Jugadores" />

            <HqPlayersViewTabs
                view="rankings"
                position={filters.position}
                team={filters.team}
            />

            <FilterBar
                query={query}
                stats={stats}
                teams={teams}
                seasonManagers={seasonManagers}
                weeksPlayed={weeksPlayed}
            />

            {overview &&
                [
                    { title: 'Buenas acciones', bad: false },
                    { title: 'Malas acciones', bad: true },
                ].map((group) => {
                    const cells = overview.filter(
                        (cell) => badStats.has(cell.stat) === group.bad,
                    );

                    return (
                        <section
                            key={group.title}
                            className={cn(
                                group.bad &&
                                    'mt-8 border-t border-hq-border sm:mt-10',
                            )}
                        >
                            <HqChannelHeader
                                title={group.title}
                                tone={group.bad ? 'live' : 'lime'}
                            />
                            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4">
                                {cells.map((cell) => (
                                    <OverviewCell
                                        key={cell.stat}
                                        stat={cell.stat}
                                        bad={group.bad}
                                        count={cell.count}
                                        rows={cell.rows}
                                        query={query}
                                    />
                                ))}
                            </div>
                        </section>
                    );
                })}

            {stat !== null && ranking && (
                <section>
                    <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1.5 border-b border-hq-border px-3.5 pt-4 pb-3 sm:px-4 sm:pt-5 sm:pb-3.5">
                        <h2 className="text-[26px] leading-[0.95] font-black tracking-[-0.01em] text-hq-paper uppercase sm:text-[34px]">
                            {statLabel(stat)}
                        </h2>
                        <span
                            className={cn(
                                'font-mono text-base font-bold tabular-nums sm:text-lg',
                                badStats.has(stat)
                                    ? 'text-hq-live'
                                    : 'text-hq-lime',
                            )}
                        >
                            {formatNumber(ranking.length)}
                        </span>
                        <span className="w-full hq-label sm:ml-auto sm:w-auto">
                            {scope}
                            {filters.week === null &&
                                ` · ${weeksPlayed} jornadas`}
                            {filters.position.length > 0 &&
                                ` · ${filters.position.map((position) => POSITION_ABBREVIATIONS[position]).join(' + ')}`}
                        </span>
                    </div>
                    <RankingTable
                        key={`${stat}-${filters.position.join()}-${filters.team.join()}-${filters.seasonManager ?? ''}`}
                        stat={stat}
                        bad={badStats.has(stat)}
                        rows={ranking}
                        breakdown={breakdown}
                        query={query}
                    />
                </section>
            )}
        </div>
    );
}

PlayersRankings.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
