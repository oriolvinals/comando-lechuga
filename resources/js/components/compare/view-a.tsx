import { Link } from '@inertiajs/react';
import {
    House,
    Plane,
    Plus,
    Repeat2,
    Shield,
    User,
    UserX,
    X,
} from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { useSlotHighlight } from '@/components/compare/chart-tooltip';
import { useCompare } from '@/components/compare/compare-context';
import type { DerivedPlayer } from '@/components/compare/derive';
import { winner } from '@/components/compare/derive';
import {
    CompareProperty,
    ComparePropertyLine,
} from '@/components/compare/property';
import { CompareValueChart } from '@/components/compare/value-chart';
import { EntityImage } from '@/components/entity-image';
import {
    HqDifficultyBars,
    HqDifficultyTooltip,
} from '@/components/hq-difficulty-bars';
import { HqLed } from '@/components/hq-led';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqStartMeter } from '@/components/hq-start-probability';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { COMPARE_MAX, COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import {
    formatAverage,
    formatCurrency,
    formatDecimal,
    formatMillions,
} from '@/lib/format';
import { daznPointsBadgeClass, matchPointsBadgeClass } from '@/lib/points';
import {
    RIVAL_DIFFICULTY_BG_CLASSES,
    RIVAL_DIFFICULTY_EASY_BELOW,
    RIVAL_DIFFICULTY_HARD_FROM,
    RIVAL_DIFFICULTY_LABELS,
    RIVAL_DIFFICULTY_TEXT_CLASSES,
    formatDifficulty,
    rivalDifficultyLevel,
} from '@/lib/rival-difficulty';
import { useMediaQuery } from '@/lib/use-media-query';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { ComparedPlayer, NextFixtureSlot } from '@/types/models';

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

interface SectionSpec {
    title: string;
    rows: (RowSpec | 'chart')[];
}

const DASH = <span className="font-mono text-sm text-hq-moss-dim">—</span>;

const BIG_NUMBER = 'font-mono text-[15px] font-bold text-hq-paper tabular-nums';
const SMALL_NOTE = 'font-mono text-[11px] text-hq-moss-dim';

/** The "Próximos 3" strip's fácil / media / difícil zones, sized by the rival-difficulty thresholds on the 0–10 scale. */
const DIFFICULTY_ZONES = `${RIVAL_DIFFICULTY_EASY_BELOW}fr ${RIVAL_DIFFICULTY_HARD_FROM - RIVAL_DIFFICULTY_EASY_BELOW}fr ${10 - RIVAL_DIFFICULTY_HARD_FROM}fr`;

function shortDay(iso: string): string {
    const date = new Date(iso);

    return `${new Intl.DateTimeFormat('es-ES', { weekday: 'short' }).format(date).replace('.', '')} ${date.getDate()}`;
}

function longDay(iso: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date(iso));
}

function lastName(name: string): string {
    return name.split(' ').slice(-1)[0];
}

/** Right side of a "Próximos 3" jornada: the ficha-list format (rival position, 0–10 gauge with its number under it, level). */
function FixtureDifficulty({ fixture }: { fixture: NextFixtureSlot }) {
    return (
        <HqDifficultyTooltip
            match={fixture}
            className="col-span-2 flex shrink-0 items-center justify-between gap-2 @min-[230px]:col-span-1 @min-[230px]:flex-col @min-[230px]:items-end @min-[230px]:justify-center @min-[230px]:gap-1"
        >
            {fixture.rival_position !== null && (
                <span className="font-mono text-[11px] leading-none text-hq-moss">
                    {fixture.rival_position}.º
                </span>
            )}
            {fixture.difficulty === null ? (
                <span className="font-mono text-[11px] leading-none text-hq-moss-dim">
                    –
                </span>
            ) : (
                <>
                    <HqDifficultyBars
                        difficulty={fixture.difficulty}
                        layout="gauge"
                    />
                    <span
                        className={cn(
                            'font-mono text-[10px] leading-none font-bold tracking-[0.07em] uppercase @max-[229px]:hidden',
                            RIVAL_DIFFICULTY_TEXT_CLASSES[
                                rivalDifficultyLevel(fixture.difficulty)
                            ],
                        )}
                    >
                        {
                            RIVAL_DIFFICULTY_LABELS[
                                rivalDifficultyLevel(fixture.difficulty)
                            ]
                        }
                    </span>
                </>
            )}
        </HqDifficultyTooltip>
    );
}

/**
 * Próximos 3: summary (level of the mean 0–10 difficulty, rival mean, home
 * count), a marker on the same fácil → difícil scale in every column (the
 * zones are the rival-difficulty levels) and the three jornadas in the same
 * order for everyone, each with the shared difficulty gauge and tooltip.
 */
function NextThree({
    player,
    derived,
    slot,
}: {
    player: ComparedPlayer;
    derived: DerivedPlayer;
    slot: number;
}) {
    if (derived.upcoming.length === 0) {
        return (
            <span className="font-mono text-xs text-hq-moss-dim">
                Sin partidos programados
            </span>
        );
    }

    const average = derived.nextAverageDifficulty;
    const level = average === null ? null : rivalDifficultyLevel(average);
    const rivalAverage = derived.nextAverageRivalPosition;

    return (
        <div className="@container flex w-full flex-col gap-2.5">
            <div className="flex flex-col gap-1.5">
                <div className="flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5 font-mono text-[11px] leading-[1.3] text-hq-moss-dim">
                    {level && (
                        <b
                            className={cn(
                                'text-xs font-bold tracking-[0.07em] uppercase',
                                RIVAL_DIFFICULTY_TEXT_CLASSES[level],
                            )}
                        >
                            Dificultad {RIVAL_DIFFICULTY_LABELS[level]}
                        </b>
                    )}
                    <span className="text-hq-moss tabular-nums">
                        {average !== null &&
                            `media ${formatDifficulty(average)} · `}
                        {rivalAverage !== null &&
                            `rival medio ${formatAverage(rivalAverage)}.º · `}
                        {derived.nextHomeCount} en casa
                    </span>
                </div>
                {average !== null && (
                    <>
                        <div
                            role="img"
                            aria-label={`Calendario de ${player.name}: dificultad media ${formatDifficulty(average)} de 10${rivalAverage !== null ? `, rival medio ${formatAverage(rivalAverage)}.º` : ''}, ${derived.nextHomeCount} de ${derived.upcoming.length} en casa`}
                            className="relative my-0.5 grid h-2 gap-0.5"
                            style={{ gridTemplateColumns: DIFFICULTY_ZONES }}
                        >
                            {(['easy', 'mid', 'hard'] as const).map((zone) => (
                                <i
                                    key={zone}
                                    className={cn(
                                        'block opacity-25',
                                        RIVAL_DIFFICULTY_BG_CLASSES[zone],
                                    )}
                                />
                            ))}
                            <span
                                className="absolute -top-1 -ml-0.5 h-4 w-1 shadow-[0_0_0_2px_var(--color-hq-ink)]"
                                style={{
                                    left: `${Math.max(0, Math.min(100, average * 10))}%`,
                                    background: COMPARE_SLOT_COLORS[slot],
                                }}
                            />
                        </div>
                        <div
                            aria-hidden="true"
                            className="flex justify-between font-mono text-[10px] leading-none tracking-[0.06em] text-hq-led-off uppercase @max-[170px]:hidden"
                        >
                            <span>fácil</span>
                            <span>difícil</span>
                        </div>
                    </>
                )}
            </div>
            <ul className="m-0 list-none border-t border-hq-border p-0">
                {player.next_fixtures.map((fixture, index) => {
                    if (!fixture) {
                        return (
                            <li
                                key={index}
                                className="flex min-h-[52px] items-center border-b border-dashed border-hq-border py-[7px] font-mono text-xs text-hq-moss-dim last:border-b-0"
                            >
                                Sin partido
                            </li>
                        );
                    }

                    return (
                        <li
                            key={index}
                            className="grid min-h-[52px] grid-cols-[22px_minmax(0,1fr)] items-center gap-x-2.5 gap-y-1.5 border-b border-dashed border-hq-border py-[7px] last:border-b-0 @min-[230px]:grid-cols-[22px_minmax(0,1fr)_auto]"
                        >
                            <span className="sr-only">
                                Jornada {fixture.week_number},{' '}
                                {longDay(fixture.date)},{' '}
                                {fixture.is_home
                                    ? 'en casa contra '
                                    : 'fuera contra '}
                                {fixture.opponent.main_name}
                                {fixture.rival_position !== null &&
                                    `, ${fixture.rival_position}.º de la tabla`}
                                {fixture.difficulty !== null &&
                                    `, dificultad ${formatDifficulty(fixture.difficulty)} de 10`}
                                {fixture.absence_adjusted === true &&
                                    ', bajas del rival'}
                            </span>
                            <EntityImage
                                src={fixture.opponent.logo}
                                alt=""
                                fallback={Shield}
                                shape="square"
                                className="size-[22px] rounded-none bg-transparent object-contain"
                            />
                            <span
                                aria-hidden="true"
                                className="flex min-w-0 flex-col gap-1"
                            >
                                <b className="truncate text-[13px] leading-[1.1] font-extrabold text-hq-paper">
                                    <span className="@min-[300px]:hidden">
                                        {fixture.opponent.short_name}
                                    </span>
                                    <span className="hidden @min-[300px]:inline">
                                        {fixture.opponent.main_name}
                                    </span>
                                </b>
                                <span className="flex flex-wrap items-center gap-x-1.5 gap-y-1 font-mono text-[11px] leading-none text-hq-moss-dim tabular-nums">
                                    <span className="font-bold text-hq-moss">
                                        J{fixture.week_number}
                                    </span>
                                    <span className="whitespace-nowrap">
                                        {shortDay(fixture.date)}
                                    </span>
                                    <span className="inline-flex items-center gap-1 text-hq-moss">
                                        {fixture.is_home ? (
                                            <House className="size-3" />
                                        ) : (
                                            <Plane className="size-3" />
                                        )}
                                        <span className="hidden @min-[300px]:inline">
                                            {fixture.is_home ? 'Casa' : 'Fuera'}
                                        </span>
                                    </span>
                                    {fixture.absence_adjusted === true && (
                                        <span className="inline-flex items-center gap-1 text-hq-moss">
                                            <UserX className="size-3" />
                                            <span className="hidden @min-[300px]:inline">
                                                Bajas del rival
                                            </span>
                                        </span>
                                    )}
                                </span>
                            </span>
                            <FixtureDifficulty fixture={fixture} />
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function sections(
    players: ComparedPlayer[],
    derived: DerivedPlayer[],
    currentWeek: number,
): SectionSpec[] {
    return [
        {
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
                'chart',
            ],
        },
        {
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
                            <span className={SMALL_NOTE}>
                                {derived[index].starts}{' '}
                                {derived[index].starts === 1
                                    ? 'titularidad'
                                    : 'titularidades'}
                            </span>
                        </>
                    )),
                    values: players.map((player) => player.points),
                },
                {
                    label: 'Media',
                    hint: 'por partido',
                    cells: players.map((player) => (
                        <span
                            className={cn(
                                'self-start px-2 py-1 font-mono text-sm font-bold tabular-nums',
                                matchPointsBadgeClass(player.average_points),
                            )}
                        >
                            {formatAverage(player.average_points)}
                        </span>
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
                    cells: players.map((player) =>
                        player.points_per_million ? (
                            <>
                                <span className={BIG_NUMBER}>
                                    {formatDecimal(
                                        player.points_per_million.value,
                                    )}
                                </span>
                                {player.points_per_million.rank !== null && (
                                    <span className={SMALL_NOTE}>
                                        {player.points_per_million.rank}.º de{' '}
                                        {player.points_per_million.ranked}
                                    </span>
                                )}
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
            title: 'Forma',
            rows: [
                {
                    label: 'Últimas jornadas',
                    hint: 'puntos por jornada',
                    cells: derived.map((item) => {
                        if (item.settledWeeks.length === 0) {
                            return DASH;
                        }

                        const last = (count: number) =>
                            item.settledWeeks.slice(-count);

                        return (
                            <>
                                <HqRecentScores
                                    className="max-sm:hidden"
                                    scores={last(5).map(
                                        (cell) => cell.score?.points ?? null,
                                    )}
                                    finished={last(5).map(() => true)}
                                    opponents={last(5).map(
                                        (cell) => cell.score?.opponent ?? null,
                                    )}
                                    focusable
                                />
                                <HqRecentScores
                                    className="sm:hidden"
                                    size="sm"
                                    scores={last(3).map(
                                        (cell) => cell.score?.points ?? null,
                                    )}
                                    finished={last(3).map(() => true)}
                                    opponents={last(3).map(
                                        (cell) => cell.score?.opponent ?? null,
                                    )}
                                    focusable
                                />
                            </>
                        );
                    }),
                    values: derived.map((item) =>
                        item.settledWeeks.length === 0
                            ? null
                            : item.settledWeeks
                                  .slice(-5)
                                  .reduce(
                                      (sum, cell) =>
                                          sum + (cell.score?.points ?? 0),
                                      0,
                                  ),
                    ),
                },
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
                        <NextThree
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

/** Whether the sentinel above the pinned player heads has scrolled under the shell header. */
function useStuck() {
    const sentinelRef = useRef<HTMLDivElement>(null);
    const [stuck, setStuck] = useState(false);

    useEffect(() => {
        const sentinel = sentinelRef.current;

        if (!sentinel) {
            return;
        }

        const offset =
            parseFloat(
                getComputedStyle(document.documentElement).getPropertyValue(
                    '--hq-header-h',
                ),
            ) || 52;
        const observer = new IntersectionObserver(
            ([entry]) =>
                setStuck(
                    !entry.isIntersecting &&
                        entry.boundingClientRect.top < offset + 1,
                ),
            { rootMargin: `-${offset + 1}px 0px 0px 0px` },
        );

        observer.observe(sentinel);

        return () => observer.disconnect();
    }, []);

    return { sentinelRef, stuck };
}

/**
 * A · Cara a cara: one column per player in themed sections; the best value
 * of each row gets a lime underline (no winner on a tie), each section and
 * the pinned player heads count the rows won, and the value chart can be
 * scrubbed with pointer and keyboard.
 */
export function CompareViewA() {
    const { players, derived, currentWeek, remove, openPicker } = useCompare();
    const { rootProps, bind, setHighlighted } = useSlotHighlight();
    const { sentinelRef, stuck } = useStuck();
    const phone = useMediaQuery('(max-width: 639px)');
    const columns =
        players.length < COMPARE_MAX ? players.length + 1 : COMPARE_MAX;
    const tally = players.map(() => 0);
    const specs = sections(players, derived, currentWeek);
    const grid =
        'grid grid-cols-[repeat(var(--cols),minmax(0,1fr))] sm:grid-cols-[minmax(128px,180px)_repeat(var(--cols),minmax(0,1fr))]';

    const renderedSections = specs.map((section) => {
        const sectionTally = players.map(() => 0);
        const rows = section.rows.map((row, rowIndex) => {
            if (row === 'chart') {
                return (
                    <div
                        key="chart"
                        className={cn(grid, 'border-b border-hq-border')}
                    >
                        <div className="col-span-full flex flex-wrap items-baseline gap-x-2 gap-y-[3px] px-3.5 pt-3 sm:col-span-1 sm:flex-col sm:px-4 sm:py-3.5">
                            <b className="text-xs font-extrabold text-hq-paper uppercase">
                                Evolución
                            </b>
                            <small className={SMALL_NOTE}>
                                30 días, % sobre el valor inicial
                            </small>
                        </div>
                        <div className="col-span-full flex min-w-0 flex-col gap-2 px-3.5 py-3 sm:col-span-(--cols) sm:px-4">
                            <div className="flex flex-wrap gap-x-4 gap-y-1 font-mono text-[11px]">
                                {players.map((player, index) => (
                                    <span
                                        key={player.id}
                                        data-slot={index}
                                        {...bind(index)}
                                        tabIndex={0}
                                        className="inline-flex cursor-default items-center gap-1.5 py-0.5 text-hq-moss outline-hq-lime focus-visible:outline-2"
                                    >
                                        <i
                                            className="inline-block h-0.5 w-3.5"
                                            style={{
                                                background:
                                                    COMPARE_SLOT_COLORS[index],
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
                                                    player.value_trend_30d
                                                        .multiple >= 1
                                                        ? 'text-hq-lime'
                                                        : 'text-hq-neg'
                                                }
                                            >
                                                {player.value_trend_30d
                                                    .multiple >= 1
                                                    ? '+'
                                                    : '−'}
                                                {Math.abs(
                                                    Math.round(
                                                        (player.value_trend_30d
                                                            .multiple -
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

            const best = row.values
                ? winner(row.values, row.lowerIsBetter)
                : null;

            if (best !== null) {
                tally[best]++;
                sectionTally[best]++;
            }

            return (
                <div
                    key={rowIndex}
                    className={cn(
                        grid,
                        'border-b border-hq-border',
                        row.stackOnPhone && 'max-sm:grid-cols-1',
                    )}
                >
                    <div className="col-span-full flex flex-wrap items-baseline gap-x-2 gap-y-[3px] px-3.5 pt-3 sm:col-span-1 sm:flex-col sm:px-4 sm:py-3.5">
                        <b className="text-xs font-extrabold text-hq-paper uppercase">
                            {row.label}
                        </b>
                        {row.hint && (
                            <small className={SMALL_NOTE}>{row.hint}</small>
                        )}
                    </div>
                    {row.cells.map((cell, index) => (
                        <div
                            key={players[index].id}
                            data-slot={index}
                            className={cn(
                                'flex min-w-0 flex-col items-start justify-center gap-1 px-3.5 py-3 sm:px-4',
                                row.stackOnPhone &&
                                    'max-sm:border-t max-sm:border-hq-border max-sm:py-2 max-sm:first-of-type:border-t-0',
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

        const winnable = section.rows.some(
            (row) => row !== 'chart' && row.values,
        );
        const sectionBest = Math.max(...sectionTally);

        return (
            <section key={section.title} aria-label={section.title}>
                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-hq-border-strong bg-linear-to-r from-hq-panel to-transparent to-70% px-3.5 py-2.5 sm:px-4">
                    <h2 className="hq-label text-hq-paper">{section.title}</h2>
                    {winnable && players.length > 1 && (
                        <span className="flex flex-wrap items-center gap-1.5 font-mono text-[11px] text-hq-moss">
                            {players.map((player, index) => (
                                <span key={player.id} data-slot={index}>
                                    {index > 0 && (
                                        <i
                                            aria-hidden="true"
                                            className="mr-1.5 text-hq-led-off not-italic"
                                        >
                                            ·
                                        </i>
                                    )}
                                    {lastName(player.name)}{' '}
                                    <b
                                        className={
                                            sectionTally[index] ===
                                                sectionBest && sectionBest > 0
                                                ? 'text-hq-lime'
                                                : 'text-hq-paper'
                                        }
                                    >
                                        {sectionTally[index]}
                                    </b>
                                </span>
                            ))}
                        </span>
                    )}
                </div>
                {rows}
            </section>
        );
    });

    const top = Math.max(...tally);

    return (
        <div {...rootProps} style={{ '--cols': columns } as CSSProperties}>
            <div ref={sentinelRef} aria-hidden="true" className="h-px" />
            <div
                data-stuck={stuck || undefined}
                className={cn(
                    grid,
                    'group sticky top-(--hq-header-h) z-20 -mt-px border-b border-hq-border-strong bg-hq-ink/96 backdrop-blur-[6px]',
                )}
            >
                <div className="hidden flex-col justify-end gap-1 px-4 py-3 sm:flex">
                    <span className="hq-label">
                        {players.length} de {COMPARE_MAX} jugadores
                    </span>
                    <span className="hq-label text-hq-led-off group-data-stuck:hidden">
                        filas ganadas ↓
                    </span>
                </div>
                {players.map((player, index) => (
                    <div
                        key={player.id}
                        data-slot={index}
                        {...bind(index)}
                        className="relative flex min-w-0 flex-col gap-2 border-t-[3px] border-l border-l-hq-border px-2.5 py-2.5 transition-[padding] motion-reduce:transition-none sm:px-4 sm:py-3 group-data-stuck:sm:py-2"
                        style={{ borderTopColor: COMPARE_SLOT_COLORS[index] }}
                    >
                        <div className="absolute top-1 right-1 flex">
                            <button
                                type="button"
                                data-replace={index}
                                onClick={() => openPicker(index)}
                                aria-label={`Cambiar a ${player.name}`}
                                className="flex size-9 cursor-pointer items-center justify-center text-hq-moss-dim transition-colors hover:bg-hq-panel-alt hover:text-hq-paper sm:size-8"
                            >
                                <Repeat2
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </button>
                            <button
                                type="button"
                                onClick={() => remove(index)}
                                aria-label={`Quitar a ${player.name}`}
                                className="flex size-9 cursor-pointer items-center justify-center text-hq-moss-dim transition-colors hover:bg-hq-panel-alt hover:text-hq-live sm:size-8"
                            >
                                <X aria-hidden="true" className="size-3.5" />
                            </button>
                        </div>
                        <div className="flex min-w-0 flex-col items-start gap-2 pt-7 sm:flex-row sm:items-center sm:gap-3 sm:pt-0 sm:pr-16">
                            <EntityImage
                                src={player.image}
                                alt=""
                                fallback={User}
                                shape="square"
                                className="size-10 shrink-0 rounded-none border border-hq-border-bright bg-hq-panel-alt object-cover object-top transition-[width,height] group-data-stuck:size-7 motion-reduce:transition-none sm:size-14 group-data-stuck:sm:size-9"
                            />
                            <div className="min-w-0">
                                <h2 className="line-clamp-2 text-[13px] leading-[1.05] font-black [overflow-wrap:anywhere] text-hq-paper uppercase sm:text-lg group-data-stuck:sm:text-base lg:text-xl">
                                    <Link
                                        href={playersShow(player.id).url}
                                        className="cursor-pointer hover:text-hq-lime"
                                    >
                                        {player.name}
                                    </Link>
                                </h2>
                                <div className="mt-1.5 flex flex-wrap items-center gap-1.5 font-mono text-[11px] text-hq-moss group-data-stuck:hidden">
                                    <HqPositionTag position={player.position} />
                                    <EntityImage
                                        src={player.team.logo}
                                        alt=""
                                        fallback={Shield}
                                        shape="square"
                                        className="size-3.5 rounded-none bg-transparent object-contain"
                                    />
                                    <span>{player.team.short_name}</span>
                                    <HqStatusBadge status={player.status} />
                                </div>
                            </div>
                        </div>
                        {players.length > 1 && (
                            <div
                                className={cn(
                                    'flex items-baseline gap-1.5 font-mono text-[10.5px] tracking-[0.06em] uppercase group-data-stuck:hidden',
                                    tally[index] === top
                                        ? 'text-hq-lime'
                                        : 'text-hq-moss-dim',
                                )}
                            >
                                <HqLed
                                    tone={
                                        tally[index] === top ? 'lime' : 'paper'
                                    }
                                    glow={tally[index] === top}
                                    className="text-xl"
                                >
                                    {tally[index]}
                                </HqLed>
                                {tally[index] === 1 ? 'fila' : 'filas'}
                            </div>
                        )}
                    </div>
                ))}
                {players.length < COMPARE_MAX && (
                    <div className="flex border-l border-hq-border p-2">
                        <button
                            type="button"
                            data-add=""
                            onClick={() => openPicker(null)}
                            className="flex flex-1 cursor-pointer flex-col items-center justify-center gap-1 border border-dashed border-hq-border-strong p-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase transition-colors group-data-stuck:flex-row group-data-stuck:p-2 hover:border-hq-lime hover:text-hq-lime"
                        >
                            <Plus aria-hidden="true" className="size-4" />
                            <span>
                                Añadir
                                <span className="max-sm:sr-only"> jugador</span>
                            </span>
                            <small className="font-normal tracking-normal text-hq-moss-dim normal-case group-data-stuck:hidden max-sm:hidden">
                                busca o pulsa /
                            </small>
                        </button>
                    </div>
                )}
            </div>
            {renderedSections}
        </div>
    );
}
