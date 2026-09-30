import type { ReactNode } from 'react';
import { Fragment, useMemo, useState } from 'react';
import { useCompare } from '@/components/compare/compare-context';
import {
    BIG_NUMBER,
    COMPARE_GRID,
    COMPARE_SPAN,
    CompareSectionHeader,
    RowLabel,
    SMALL_NOTE,
} from '@/components/compare/compare-grid';
import type {
    DerivedPlayer,
    LeagueTrack,
    TrackMetric,
    TrackScope,
} from '@/components/compare/derive';
import { leagueTracks, trackRank, winner } from '@/components/compare/derive';
import { CompareLeagueTracks } from '@/components/compare/league-tracks';
import { CompareNextThree, shortDay } from '@/components/compare/next-three';
import {
    CompareProperty,
    ComparePropertyLine,
} from '@/components/compare/property';
import { CompareValueChart } from '@/components/compare/value-chart';
import { CompareWeekByWeek } from '@/components/compare/week-by-week';
import { HqLed } from '@/components/hq-led';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqStartMeter } from '@/components/hq-start-probability';
import { COMPARE_MAX, COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import {
    formatAverage,
    formatCurrency,
    formatDecimal,
    formatMillions,
} from '@/lib/format';
import { daznPointsBadgeClass, matchPointsBadgeClass } from '@/lib/points';
import { useMediaQuery } from '@/lib/use-media-query';
import { cn } from '@/lib/utils';
import type { ComparedPlayer } from '@/types/models';

interface RowSpec {
    label: string;
    hint?: string;
    cells: ReactNode[];
    /** Numbers the row winner is picked from; no `values` = no winner ("sin ganador"). */
    values?: (number | null)[];
    lowerIsBetter?: boolean;
    cellClassName?: string;
    /** Propiedad: one full-width line per player on phones. */
    stackOnPhone?: boolean;
}

/** A row drawn by its own component (the value chart, "Jornada a jornada"). */
interface CustomRow {
    key: string;
    node: ReactNode;
}

interface SectionSpec {
    id: string;
    title: string;
    rows: (RowSpec | CustomRow)[];
}

const DASH = <span className="font-mono text-sm text-hq-moss-dim">—</span>;

function isCustom(row: RowSpec | CustomRow): row is CustomRow {
    return 'node' in row;
}

/** "133.º de 439" under a Rendimiento figure: his place on that "En la liga" track (the whole league). */
function LeagueRank({
    track,
    playerIndex,
    suffix,
}: {
    track: LeagueTrack | undefined;
    playerIndex: number;
    suffix?: string;
}) {
    const place = trackRank(track, playerIndex);

    if (!place && !suffix) {
        return null;
    }

    return (
        <span className={SMALL_NOTE}>
            {place && (
                <>
                    <b className="font-bold text-hq-moss">{place.rank}.º</b> de{' '}
                    {place.of}
                </>
            )}
            {place && suffix && ' · '}
            {suffix}
        </span>
    );
}

function ValueChartRow({ players }: { players: ComparedPlayer[] }) {
    const { bindSlot, setHighlighted } = useCompare();
    const phone = useMediaQuery('(max-width: 639px)');

    return (
        <div className={cn(COMPARE_GRID, 'border-b border-hq-border')}>
            <RowLabel
                label="Evolución"
                hint="30 días, % sobre el valor inicial"
            />
            <div
                className={cn(
                    COMPARE_SPAN,
                    'flex min-w-0 flex-col gap-2 px-3.5 py-3 sm:px-4',
                )}
            >
                <div className="flex flex-wrap gap-x-4 gap-y-1 font-mono text-[11px]">
                    {players.map((player, index) => (
                        <span
                            key={player.id}
                            data-slot={index}
                            {...bindSlot(index)}
                            tabIndex={0}
                            className="inline-flex cursor-default items-center gap-1.5 py-0.5 text-hq-moss outline-hq-lime focus-visible:outline-2"
                        >
                            <i
                                className="inline-block h-0.5 w-3.5"
                                style={{
                                    background: COMPARE_SLOT_COLORS[index],
                                }}
                            />
                            {player.name}
                            {player.market_history.length < 2 && (
                                <span className="text-hq-moss-dim">
                                    sin histórico
                                </span>
                            )}
                            {player.value_trend_30d && (
                                <b
                                    className={
                                        player.value_trend_30d.multiple >= 1
                                            ? 'text-hq-lime'
                                            : 'text-hq-neg'
                                    }
                                >
                                    {player.value_trend_30d.multiple >= 1
                                        ? '+'
                                        : '−'}
                                    {Math.abs(
                                        Math.round(
                                            (player.value_trend_30d.multiple -
                                                1) *
                                                100,
                                        ),
                                    )}{' '}
                                    %
                                </b>
                            )}
                        </span>
                    ))}
                </div>
                <CompareValueChart
                    series={players.map((player, index) => ({
                        slot: index,
                        name: player.name,
                        history: player.market_history,
                    }))}
                    width={phone ? 360 : 900}
                    height={phone ? 120 : 130}
                    label="Evolución del valor en los últimos 30 días"
                    onHighlight={setHighlighted}
                />
            </div>
        </div>
    );
}

function sections(
    players: ComparedPlayer[],
    derived: DerivedPlayer[],
    currentWeek: number,
    rankTrack: (key: TrackMetric['key']) => LeagueTrack | undefined,
): SectionSpec[] {
    return [
        {
            id: 's-mercado',
            title: 'Mercado',
            rows: [
                {
                    label: 'Valor',
                    hint: 'sin ganador',
                    cells: players.map((player) => (
                        <span className={BIG_NUMBER}>
                            <span className="max-sm:hidden">
                                {formatCurrency(player.value)}
                            </span>
                            <span className="sm:hidden">
                                {formatMillions(player.value)}
                            </span>
                        </span>
                    )),
                },
                {
                    label: 'Hoy',
                    hint: 'cambio de valor',
                    cells: players.map((player) =>
                        player.difference === 0 && player.trend === null ? (
                            DASH
                        ) : (
                            <HqMarketValueDifference
                                difference={player.difference}
                                trend={player.trend}
                                className="text-sm max-sm:text-xs"
                            />
                        ),
                    ),
                    values: players.map((player) => player.difference),
                },
                {
                    label: '30 días',
                    hint: 'multiplicador',
                    cells: players.map((player) =>
                        player.value_trend_30d ? (
                            <>
                                <span className={BIG_NUMBER}>
                                    ×
                                    {formatDecimal(
                                        player.value_trend_30d.multiple,
                                    )}
                                </span>
                                <span className={SMALL_NOTE}>
                                    desde{' '}
                                    {formatMillions(
                                        player.value_trend_30d.value,
                                    )}
                                </span>
                            </>
                        ) : (
                            DASH
                        ),
                    ),
                    values: players.map(
                        (player) => player.value_trend_30d?.multiple ?? null,
                    ),
                },
                { key: 'chart', node: <ValueChartRow players={players} /> },
            ],
        },
        {
            id: 's-rendimiento',
            title: 'Rendimiento',
            rows: [
                {
                    label: 'Puntos',
                    hint: 'temporada',
                    cells: players.map((player, index) => (
                        <>
                            <HqLed tone="lime" className="text-2xl">
                                {player.points}
                            </HqLed>
                            <LeagueRank
                                track={rankTrack('points')}
                                playerIndex={index}
                                suffix={`${derived[index].starts} tit.`}
                            />
                        </>
                    )),
                    values: players.map((player) => player.points),
                },
                {
                    label: 'Media',
                    hint: 'por partido',
                    cells: players.map((player, index) => (
                        <>
                            <span
                                className={cn(
                                    'self-start px-2 py-1 font-mono text-sm font-bold tabular-nums',
                                    matchPointsBadgeClass(
                                        player.average_points,
                                    ),
                                )}
                            >
                                {formatAverage(player.average_points)}
                            </span>
                            <LeagueRank
                                track={rankTrack('average')}
                                playerIndex={index}
                            />
                        </>
                    )),
                    values: players.map((player) => player.average_points),
                },
                {
                    label: 'Media DAZN',
                    hint: 'solo notas oficiales',
                    cells: derived.map((item) =>
                        item.daznAverage === null ? (
                            DASH
                        ) : (
                            <span
                                className={cn(
                                    'self-start px-2 py-1 font-mono text-sm font-bold tabular-nums',
                                    daznPointsBadgeClass(item.daznAverage),
                                )}
                            >
                                {formatAverage(item.daznAverage)}
                            </span>
                        ),
                    ),
                    values: derived.map((item) =>
                        item.daznAverage === null
                            ? null
                            : Math.round(item.daznAverage * 10) / 10,
                    ),
                },
                {
                    label: 'Pts / M€',
                    hint: 'puntos por millón',
                    cells: players.map((player, index) =>
                        player.points_per_million ? (
                            <>
                                <span className={BIG_NUMBER}>
                                    {formatDecimal(
                                        player.points_per_million.value,
                                    )}
                                </span>
                                <LeagueRank
                                    track={rankTrack('ppm')}
                                    playerIndex={index}
                                />
                            </>
                        ) : (
                            DASH
                        ),
                    ),
                    values: players.map(
                        (player) => player.points_per_million?.value ?? null,
                    ),
                },
                {
                    label: 'Minutos',
                    hint: 'temporada',
                    cells: derived.map((item) => (
                        <>
                            <span className={BIG_NUMBER}>{item.minutes}'</span>
                            <span className={SMALL_NOTE}>
                                {item.minutesShare === null
                                    ? '—'
                                    : `${item.minutesShare} % posibles`}
                            </span>
                        </>
                    )),
                    values: derived.map((item) => item.minutes),
                },
            ],
        },
        {
            id: 's-forma',
            title: 'Forma',
            rows: [
                { key: 'weeks', node: <CompareWeekByWeek /> },
                {
                    label: 'Últimas 3',
                    hint: 'puntos · minutos',
                    cells: derived.map((item) =>
                        item.last3.length === 0 ? (
                            DASH
                        ) : (
                            <>
                                <span className={BIG_NUMBER}>
                                    {item.last3Points} pts
                                </span>
                                <span className={SMALL_NOTE}>
                                    {item.last3Minutes}' de{' '}
                                    {item.last3.length * 90}'
                                </span>
                            </>
                        ),
                    ),
                    values: derived.map((item) =>
                        item.last3.length === 0 ? null : item.last3Points,
                    ),
                },
            ],
        },
        {
            id: 's-calendario',
            title: 'Calendario',
            rows: [
                {
                    label: `Titularidad J${currentWeek}`,
                    hint: 'FútbolFantasy',
                    cells: players.map((player, index) => (
                        <>
                            <HqStartMeter
                                probability={derived[index].startProbability}
                                status={player.status}
                                size="sm"
                                fetchedAt={
                                    player.next_start?.fetched_at ?? null
                                }
                            />
                            {player.next_start && (
                                <span className={SMALL_NOTE}>
                                    {player.next_start.is_home ? 'vs ' : '@ '}
                                    {
                                        player.next_start.opponent.short_name
                                    } · {shortDay(player.next_start.date)}
                                </span>
                            )}
                        </>
                    )),
                    values: derived.map((item) =>
                        item.startTone === 'out' ? -1 : item.startProbability,
                    ),
                },
                {
                    label: 'Próximos 3',
                    hint: 'gana la dificultad media más baja',
                    cellClassName: 'justify-start',
                    cells: players.map((player, index) => (
                        <CompareNextThree
                            player={player}
                            derived={derived[index]}
                            slot={index}
                        />
                    )),
                    values: derived.map((item) =>
                        item.nextAverageDifficulty === null
                            ? null
                            : Math.round(item.nextAverageDifficulty * 10) / 10,
                    ),
                    lowerIsBetter: true,
                },
            ],
        },
        {
            id: 's-propiedad',
            title: 'Propiedad',
            rows: [
                {
                    label: 'Dueño y cláusula',
                    hint: 'sin ganador',
                    stackOnPhone: true,
                    cells: players.map((player, index) => (
                        <>
                            <span className="block w-full max-sm:hidden">
                                <CompareProperty
                                    player={player}
                                    derived={derived[index]}
                                />
                            </span>
                            <span className="block w-full sm:hidden">
                                <ComparePropertyLine
                                    player={player}
                                    derived={derived[index]}
                                />
                            </span>
                        </>
                    )),
                },
            ],
        },
    ];
}

/**
 * Mercado, Rendimiento, En la liga, Forma, Calendario and Propiedad, on the
 * strip's columns: the best value of each row gets a lime underline (no
 * winner on a tie).
 */
export function CompareBody() {
    const { players, derived, league, currentWeek } = useCompare();
    const [scope, setScope] = useState<TrackScope>('all');
    const allLeague = useMemo(
        () => leagueTracks(league, players, derived, currentWeek, 'all'),
        [league, players, derived, currentWeek],
    );
    const scoped = useMemo(
        () =>
            scope === 'all'
                ? allLeague
                : leagueTracks(league, players, derived, currentWeek, scope),
        [scope, allLeague, league, players, derived, currentWeek],
    );
    const rankTrack = (key: TrackMetric['key']) =>
        allLeague.find((track) => track.metric.key === key);
    const specs = sections(players, derived, currentWeek, rankTrack);

    const renderSection = (section: SectionSpec) => {
        const rows = section.rows.map((row, rowIndex) => {
            if (isCustom(row)) {
                return <Fragment key={row.key}>{row.node}</Fragment>;
            }

            const best = row.values
                ? winner(row.values, row.lowerIsBetter)
                : null;

            return (
                <div
                    key={rowIndex}
                    className={cn(
                        COMPARE_GRID,
                        'border-b border-hq-border',
                        row.stackOnPhone && 'max-sm:grid-cols-1',
                    )}
                >
                    <RowLabel label={row.label} hint={row.hint} />
                    {row.cells.map((cell, index) => (
                        <div
                            key={players[index].id}
                            data-slot={index}
                            className={cn(
                                'flex min-w-0 flex-col items-start justify-center gap-1 px-3.5 py-3 sm:px-4',
                                row.stackOnPhone &&
                                    'max-sm:border-t max-sm:border-hq-border max-sm:py-2 max-sm:nth-2:border-t-0',
                                index === best &&
                                    'shadow-[inset_0_-2px_0_var(--color-hq-lime)]',
                                row.cellClassName,
                            )}
                        >
                            {index === best && (
                                <span className="sr-only">Mejor: </span>
                            )}
                            {cell}
                        </div>
                    ))}
                    {players.length < COMPARE_MAX && (
                        <div
                            aria-hidden="true"
                            className={cn(row.stackOnPhone && 'max-sm:hidden')}
                        />
                    )}
                </div>
            );
        });

        return (
            <section key={section.id} aria-labelledby={`${section.id}-title`}>
                <CompareSectionHeader id={section.id} title={section.title} />
                {rows}
            </section>
        );
    };

    return (
        <>
            {specs.slice(0, 2).map(renderSection)}
            <CompareLeagueTracks
                tracks={scoped}
                scope={scope}
                onScopeChange={setScope}
            />
            {specs.slice(2).map(renderSection)}
        </>
    );
}
