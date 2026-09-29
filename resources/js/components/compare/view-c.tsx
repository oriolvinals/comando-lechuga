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
import type { FocusEvent, KeyboardEvent, PointerEvent, ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import {
    TipRow,
    useChartTooltip,
    useSlotHighlight,
} from '@/components/compare/chart-tooltip';
import { useCompare } from '@/components/compare/compare-context';
import { winner } from '@/components/compare/derive';
import { ComparePropertyLine } from '@/components/compare/property';
import { CompareValueChart } from '@/components/compare/value-chart';
import { EntityImage } from '@/components/entity-image';
import { HqDaznBadge } from '@/components/hq-dazn-badge';
import {
    HqDifficultyBars,
    difficultySummary,
} from '@/components/hq-difficulty-bars';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { COMPARE_MAX, COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import {
    formatAverage,
    formatDecimal,
    formatMatchDay,
    formatMillions,
} from '@/lib/format';
import { STATUS_LABELS, STATUS_SHORT_LABELS } from '@/lib/player-labels';
import { matchPointsBadgeClass } from '@/lib/points';
import {
    RIVAL_DIFFICULTY_BG_CLASSES,
    RIVAL_DIFFICULTY_LABELS,
    formatDifficulty,
    rivalDifficultyLevel,
} from '@/lib/rival-difficulty';
import { START_TONE_TEXT_CLASSES } from '@/lib/start-probability';
import { cn } from '@/lib/utils';
import type { ComparedPlayerScore } from '@/types/models';

type LaneMetric = 'points' | 'dazn' | 'minutes';

const METRIC_LABELS: Record<LaneMetric, string> = {
    points: 'Puntos',
    dazn: 'DAZN',
    minutes: 'Minutos',
};

/** The lane's figure for one jornada: null = didn't play (minutes still show 0'); DAZN counts official ratings only. */
function cellValue(
    score: ComparedPlayerScore | null,
    metric: LaneMetric,
): number | null {
    if (!score) {
        return null;
    }

    if (metric === 'minutes') {
        return score.minutes;
    }

    if (score.minutes === 0) {
        return null;
    }

    return metric === 'dazn' ? score.dazn_points : score.points;
}

/** "sáb 4 oct" — the day of a future jornada in its header. */
function headerDay(iso: string): string {
    return formatMatchDay(iso).replace(',', '');
}

/**
 * View C · Carriles: one lane per player, jornada by jornada from J1 to the
 * next three, with the chosen figure (points, DAZN or minutes) in every past
 * cell, the shared difficulty gauge in the future ones, a summary column and
 * the market and ownership rows below. One tab stop for the whole grid:
 * ←/→ jornada, ↑/↓ lane, Inicio/Fin.
 */
export function CompareViewC() {
    const { players, derived, currentWeek, totalWeeks, remove, openPicker } =
        useCompare();
    const { show, hide } = useChartTooltip();
    const { rootProps, bind } = useSlotHighlight();
    const [metric, setMetric] = useState<LaneMetric>('points');
    const [focus, setFocus] = useState({
        lane: 0,
        column: Math.max(0, currentWeek - 2),
    });
    const [litWeek, setLitWeek] = useState<number | 'sum' | null>(null);
    const scrollRef = useRef<HTMLDivElement>(null);
    const nowRef = useRef<HTMLDivElement>(null);
    const pastWeeks = Array.from(
        { length: Math.max(0, currentWeek - 1) },
        (_, index) => index + 1,
    );
    // Keyed by jornada, never by slot: a team may skip one (postponed), and nothing past the last jornada.
    const futureWeeks = [0, 1, 2]
        .map((offset) => currentWeek + offset)
        .filter((week) => week <= totalWeeks);
    const fixtureIn = (lane: number, week: number) =>
        players[lane].next_fixtures.find(
            (slot) => slot !== null && slot.week_number === week,
        ) ?? null;
    const lastColumn = pastWeeks.length + futureWeeks.length;
    const focusLane = Math.min(focus.lane, players.length - 1);
    const focusColumn = Math.min(focus.column, lastColumn);

    // Open on "HOY": the past to the left, the next three to the right.
    useEffect(() => {
        const wrap = scrollRef.current;
        const now = nowRef.current;

        if (wrap && now && wrap.scrollWidth > wrap.clientWidth) {
            wrap.scrollLeft = Math.max(
                0,
                now.offsetLeft - wrap.clientWidth * 0.5,
            );
        }
    }, [players]);

    const tops = pastWeeks.map((week) =>
        winner(
            derived.map((item) =>
                cellValue(item.weeks[week - 1]?.score ?? null, metric),
            ),
        ),
    );
    const sums = players.map((player, index) => {
        if (metric === 'minutes') {
            return derived[index].minutes;
        }

        if (metric === 'dazn') {
            const average = derived[index].daznAverage;

            return average === null ? null : Math.round(average * 10) / 10;
        }

        return player.points;
    });
    const sumBest = winner(sums);
    const sumLabel =
        metric === 'minutes'
            ? 'Minutos'
            : metric === 'dazn'
              ? 'Media DAZN'
              : 'Total';

    const sumText = (value: number | null): string => {
        if (value === null) {
            return '—';
        }

        if (metric === 'dazn') {
            return formatAverage(value);
        }

        return metric === 'minutes' ? `${value}'` : String(value);
    };

    const weekText = (
        score: ComparedPlayerScore | null,
        pending = false,
    ): string => {
        if (!score) {
            return pending ? 'pendiente' : 'NC';
        }

        const value = cellValue(score, metric);

        if (
            metric === 'dazn' &&
            score.minutes > 0 &&
            score.dazn_points === null
        ) {
            return score.dazn_estimate === null
                ? 'DAZN —'
                : `~${score.dazn_estimate} DAZN (provisional)`;
        }

        if (metric === 'points' && score.minutes > 0 && value === null) {
            return '—';
        }

        if (value === null) {
            return metric === 'minutes' ? "0'" : 'no jugó';
        }

        if (metric === 'minutes') {
            return `${value}'`;
        }

        return metric === 'dazn' ? `${value} DAZN` : `${value} pts`;
    };

    /** Tooltip content and accessible name of a cell (mock `cInfo`). Columns: past weeks, then the future ones, then the summary. */
    const cellInfo = (
        lane: number,
        column: number,
    ): { content: ReactNode; text: string } => {
        const player = players[lane];
        const rows = (
            valueOf: (index: number) => string,
            extraOf?: (index: number) => string,
        ) =>
            players.length > 1 ? (
                <span className="mt-2 flex flex-col gap-1 border-t border-hq-border pt-[7px]">
                    {players.map((other, index) => (
                        <TipRow
                            key={other.id}
                            slotColor={COMPARE_SLOT_COLORS[index]}
                            name={other.name}
                            value={valueOf(index)}
                            extra={extraOf?.(index)}
                            me={index === lane}
                        />
                    ))}
                </span>
            ) : null;

        if (column === lastColumn) {
            const label =
                metric === 'minutes'
                    ? 'Minutos en la temporada'
                    : metric === 'dazn'
                      ? 'Media DAZN (oficial)'
                      : 'Puntos en la temporada';

            return {
                content: (
                    <>
                        <b className="font-sans text-[12.5px] font-black uppercase">
                            {player.name}
                        </b>
                        <span className="mt-[3px] text-hq-moss-dim">
                            {label}
                        </span>
                        <span
                            className={cn(
                                'mt-[7px] text-xl font-bold tabular-nums',
                                sumBest === lane
                                    ? 'text-hq-lime'
                                    : 'text-hq-paper',
                            )}
                        >
                            {sumText(sums[lane])}
                        </span>
                        {rows((index) => sumText(sums[index]))}
                    </>
                ),
                text: `${player.name}, ${label}: ${sumText(sums[lane])}`,
            };
        }

        if (column < pastWeeks.length) {
            const week = column + 1;
            const { score, pending } = derived[lane].weeks[column];
            const top = tops[column] === lane;
            const more = score
                ? [
                      metric !== 'points' ? `${score.points ?? '—'} pts` : '',
                      metric === 'dazn' &&
                      score.dazn_points !== null &&
                      score.dazn_estimate !== null
                          ? `estimación congelada ${score.dazn_estimate}`
                          : '',
                      metric === 'dazn' &&
                      score.dazn_points === null &&
                      score.dazn_estimate !== null &&
                      score.minutes > 0
                          ? 'provisional, en juego o sin publicar'
                          : '',
                      metric !== 'dazn'
                          ? `DAZN ${score.dazn_points ?? (score.dazn_estimate === null ? '—' : `~${score.dazn_estimate}`)}`
                          : '',
                      `${metric !== 'minutes' ? `${score.minutes}' ` : ''}${score.starter ? 'titular' : 'suplente'}`,
                  ]
                      .filter(Boolean)
                      .join(' · ')
                : '';

            return {
                content: (
                    <>
                        <b className="font-sans text-[12.5px] font-black uppercase">
                            J{week} · {player.name}
                        </b>
                        <span className="mt-[3px] text-hq-moss-dim">
                            {score
                                ? `${score.is_home ? 'vs ' : '@ '}${score.opponent.short_name} · ${score.is_home ? 'en casa' : 'fuera'}`
                                : pending
                                  ? 'Partido pendiente de jugar'
                                  : 'No convocado'}
                        </span>
                        <span
                            className={cn(
                                'mt-[7px] text-xl font-bold tabular-nums',
                                top ? 'text-hq-lime' : 'text-hq-paper',
                            )}
                        >
                            {weekText(score, pending)}
                        </span>
                        {more && (
                            <span className="mt-[3px] text-hq-moss-dim">
                                {more}
                            </span>
                        )}
                        {top && (
                            <span className="mt-[5px] text-[10px] font-bold tracking-[0.06em] text-hq-lime uppercase">
                                Mejor de la jornada
                            </span>
                        )}
                        {rows((index) =>
                            weekText(
                                derived[index].weeks[column].score,
                                derived[index].weeks[column].pending,
                            ),
                        )}
                    </>
                ),
                text: `Jornada ${week}, ${player.name}: ${score ? `${weekText(score)}, ${score.is_home ? 'en casa contra ' : 'fuera contra '}${score.opponent.name}, ${more}` : pending ? 'partido pendiente de jugar' : 'no convocado'}${top ? ', mejor de la jornada' : ''}`,
            };
        }

        const week = futureWeeks[column - pastWeeks.length];
        const fixture = fixtureIn(lane, week);

        if (!fixture) {
            return {
                content: (
                    <>
                        <b className="font-sans text-[12.5px] font-black uppercase">
                            J{week} · {player.name}
                        </b>
                        <span className="mt-[3px] text-hq-moss-dim">
                            Sin partido programado
                        </span>
                    </>
                ),
                text: `Jornada ${week}, ${player.name}: sin partido`,
            };
        }

        const difficulty = fixture.difficulty;
        const difficultyText =
            difficulty === null
                ? ', sin dificultad'
                : `, dificultad ${formatDifficulty(difficulty)} de 10 (${RIVAL_DIFFICULTY_LABELS[rivalDifficultyLevel(difficulty)]})`;
        const item = derived[lane];
        const start =
            week === currentWeek
                ? item.startTone === 'out'
                    ? STATUS_LABELS[player.status]
                    : item.startProbability === null
                      ? ''
                      : `Titularidad ${item.startProbability} % (FútbolFantasy)`
                : '';

        return {
            content: (
                <>
                    <b className="font-sans text-[12.5px] font-black uppercase">
                        J{fixture.week_number} · {player.name}
                    </b>
                    <span className="mt-[3px] text-hq-moss-dim">
                        {formatMatchDay(fixture.date)} ·{' '}
                        {fixture.is_home ? 'en casa' : 'fuera'}
                    </span>
                    <span className="mt-[7px] text-base font-bold text-hq-paper">
                        {fixture.is_home ? 'vs ' : '@ '}
                        {fixture.opponent.main_name}
                    </span>
                    <span className="mt-[3px] text-hq-moss">
                        {difficultySummary(fixture)}
                    </span>
                    {start && (
                        <span className="mt-[3px] text-hq-moss-dim">
                            {start}
                        </span>
                    )}
                    {rows(
                        (index) => {
                            const other = fixtureIn(index, week);

                            return other
                                ? `${other.is_home ? 'vs ' : '@ '}${other.opponent.short_name}`
                                : '—';
                        },
                        (index) => {
                            const other = fixtureIn(index, week);

                            return other?.difficulty != null
                                ? formatDifficulty(other.difficulty)
                                : '';
                        },
                    )}
                </>
            ),
            text: `Jornada ${fixture.week_number}, ${player.name}: ${fixture.is_home ? 'en casa contra ' : 'fuera contra '}${fixture.opponent.main_name}${fixture.rival_position === null ? '' : `, ${fixture.rival_position}.º de la tabla`}${difficultyText}${fixture.absence_adjusted === true ? ', bajas del rival' : ''}${start ? `, ${start}` : ''}`,
        };
    };

    /** Common props of every focusable cell: roving tabindex, side tooltip, jornada column light-up. */
    const cellProps = (lane: number, column: number, week: number | 'sum') => {
        const open = (element: HTMLElement) => {
            setLitWeek(week);
            show(
                cellInfo(lane, column).content,
                () => {
                    if (!element.isConnected) {
                        return null;
                    }

                    const rect = element.getBoundingClientRect();

                    return {
                        left: rect.left,
                        right: rect.right,
                        top: rect.top,
                        bottom: rect.bottom,
                        side: true,
                    };
                },
                element,
            );
        };

        return {
            'data-cell': '',
            'data-lane': lane,
            'data-col': column,
            'data-slot': lane,
            'data-cmp-tip': '',
            tabIndex: focusLane === lane && focusColumn === column ? 0 : -1,
            'aria-label': cellInfo(lane, column).text,
            role: 'gridcell' as const,
            onPointerEnter: (event: PointerEvent<HTMLElement>) =>
                open(event.currentTarget),
            onPointerLeave: (event: PointerEvent<HTMLElement>) => {
                if (event.pointerType !== 'touch') {
                    setLitWeek(null);
                    hide(event.currentTarget);
                }
            },
            onFocus: (event: FocusEvent<HTMLElement>) => {
                setFocus({ lane, column });
                open(event.currentTarget);
            },
            onBlur: (event: FocusEvent<HTMLElement>) => {
                setLitWeek(null);
                hide(event.currentTarget);
            },
        };
    };

    const onGridKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (
            !(event.target instanceof HTMLElement) ||
            !event.target.closest('[data-cell]')
        ) {
            return;
        }

        const moves: Record<string, { lane: number; column: number }> = {
            ArrowLeft: { lane: focusLane, column: focusColumn - 1 },
            ArrowRight: { lane: focusLane, column: focusColumn + 1 },
            ArrowUp: { lane: focusLane - 1, column: focusColumn },
            ArrowDown: { lane: focusLane + 1, column: focusColumn },
            Home: { lane: focusLane, column: 0 },
            End: { lane: focusLane, column: lastColumn },
        };

        if (!(event.key in moves)) {
            return;
        }

        event.preventDefault();
        const next = {
            lane: Math.max(
                0,
                Math.min(players.length - 1, moves[event.key].lane),
            ),
            column: Math.max(0, Math.min(lastColumn, moves[event.key].column)),
        };
        setFocus(next);
        scrollRef.current
            ?.querySelector<HTMLElement>(
                `[data-cell][data-lane="${next.lane}"][data-col="${next.column}"]`,
            )
            ?.focus();
    };

    const lit = (week: number | 'sum') =>
        litWeek === week
            ? 'bg-[color-mix(in_srgb,var(--color-hq-paper)_7%,transparent)]'
            : '';
    const columns = [
        'var(--id-col)',
        pastWeeks.length > 0 ? `repeat(${pastWeeks.length}, 44px)` : '',
        '28px',
        futureWeeks.length > 0 ? `repeat(${futureWeeks.length}, 58px)` : '',
        '78px',
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <div {...rootProps}>
            <div className="flex flex-wrap items-center gap-3 border-b border-hq-border px-3.5 py-3 sm:px-4 sm:py-3.5">
                <span className="hq-label">Cifra en cada jornada</span>
                <div
                    role="group"
                    aria-label="Métrica"
                    className="inline-flex border border-hq-border-strong"
                >
                    {(Object.keys(METRIC_LABELS) as LaneMetric[]).map(
                        (option) => (
                            <button
                                key={option}
                                type="button"
                                aria-pressed={metric === option}
                                onClick={() => setMetric(option)}
                                className={cn(
                                    'h-11 cursor-pointer px-3 font-mono text-[11px] font-bold tracking-[0.06em] uppercase transition-colors not-last:border-r not-last:border-hq-border-strong sm:h-8',
                                    metric === option
                                        ? 'bg-hq-lime text-hq-ink'
                                        : 'text-hq-moss hover:bg-hq-panel-alt hover:text-hq-paper',
                                )}
                            >
                                {METRIC_LABELS[option]}
                            </button>
                        ),
                    )}
                </div>
            </div>

            <section>
                <div className="flex items-center justify-between border-b border-hq-border-strong bg-hq-panel px-3.5 py-2.5 sm:px-4">
                    <h2 className="hq-label">Temporada, jornada a jornada</h2>
                    <span className="font-mono text-[11px] text-hq-moss-dim">
                        J1 → J{futureWeeks.at(-1) ?? currentWeek - 1}
                    </span>
                </div>
                <div className="flex flex-wrap gap-x-4 gap-y-1 px-3.5 py-2 font-mono text-[11px] text-hq-moss-dim sm:px-4">
                    <span className="inline-flex items-center gap-1.5">
                        <i
                            aria-hidden="true"
                            className="inline-block h-2 w-0.5 bg-hq-lime"
                        />
                        mejor de la jornada
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <i
                            aria-hidden="true"
                            className="inline-block h-[3px] w-4 bg-hq-paper"
                        />
                        minutos (titular)
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <i
                            aria-hidden="true"
                            className="inline-block h-[3px] w-4 bg-hq-moss"
                        />
                        minutos (suplente)
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <span
                            aria-hidden="true"
                            className="flex h-[3px] w-6 gap-px"
                        >
                            {(['easy', 'mid', 'hard'] as const).map((level) => (
                                <i
                                    key={level}
                                    className={cn(
                                        'block flex-1',
                                        RIVAL_DIFFICULTY_BG_CLASSES[level],
                                    )}
                                />
                            ))}
                        </span>
                        dificultad del rival (0–10)
                    </span>
                </div>
                <div
                    ref={scrollRef}
                    className="overflow-x-auto [--id-col:120px] sm:[--id-col:180px]"
                >
                    <div
                        role="grid"
                        aria-label="Jornada a jornada. Flechas izquierda y derecha para cambiar de jornada; arriba y abajo para cambiar de jugador."
                        onKeyDown={onGridKeyDown}
                        className="grid min-w-max"
                        style={{ gridTemplateColumns: columns }}
                    >
                        <div role="row" className="contents">
                            <div
                                role="columnheader"
                                className="sticky left-0 z-10 flex items-center bg-hq-ink px-3 py-2 hq-label"
                            >
                                {players.length} de {COMPARE_MAX}
                            </div>
                            {pastWeeks.map((week) => (
                                <div
                                    key={week}
                                    role="columnheader"
                                    className={cn(
                                        'flex items-center justify-center py-2 font-mono text-[11px] text-hq-moss-dim',
                                        lit(week),
                                        litWeek === week &&
                                            'text-hq-paper shadow-[inset_0_-2px_0_var(--color-hq-lime)]',
                                    )}
                                >
                                    J{week}
                                </div>
                            ))}
                            <div
                                ref={nowRef}
                                role="columnheader"
                                aria-label="Hoy"
                                className="flex items-center justify-center py-1 font-mono text-[10px] font-bold tracking-[0.1em] text-hq-lime [writing-mode:vertical-rl]"
                            >
                                HOY
                            </div>
                            {futureWeeks.map((week) => {
                                const sample = players
                                    .map((_, lane) => fixtureIn(lane, week))
                                    .find((slot) => slot !== null);

                                return (
                                    <div
                                        key={week}
                                        role="columnheader"
                                        className={cn(
                                            'py-2 text-center font-mono text-[11px] text-hq-khaki',
                                            lit(week),
                                            litWeek === week &&
                                                'shadow-[inset_0_-2px_0_var(--color-hq-lime)]',
                                        )}
                                    >
                                        J{week}
                                        {sample && (
                                            <small className="block text-[10px] text-hq-moss-dim">
                                                {headerDay(sample.date)}
                                            </small>
                                        )}
                                    </div>
                                );
                            })}
                            <div
                                role="columnheader"
                                className={cn(
                                    'flex items-center justify-center py-2 text-center hq-label',
                                    lit('sum'),
                                    litWeek === 'sum' &&
                                        'shadow-[inset_0_-2px_0_var(--color-hq-lime)]',
                                )}
                            >
                                {sumLabel}
                            </div>
                        </div>

                        {players.map((player, lane) => {
                            const item = derived[lane];

                            return (
                                <div
                                    key={player.id}
                                    role="row"
                                    className="contents"
                                >
                                    <div
                                        role="rowheader"
                                        data-slot={lane}
                                        {...bind(lane)}
                                        className="sticky left-0 z-10 flex min-w-0 items-center gap-2 border-t border-l-2 border-hq-border bg-hq-ink py-2 pr-1 pl-2"
                                        style={{
                                            borderLeftColor:
                                                COMPARE_SLOT_COLORS[lane],
                                        }}
                                    >
                                        <EntityImage
                                            src={player.image}
                                            alt=""
                                            fallback={User}
                                            shape="square"
                                            className="size-8 shrink-0 rounded-none object-cover object-top max-sm:hidden"
                                        />
                                        <span className="flex min-w-0 flex-1 flex-col gap-1">
                                            <b className="block truncate text-xs font-black text-hq-paper uppercase">
                                                {player.name}
                                            </b>
                                            <span className="flex min-w-0 items-center gap-1 font-mono text-[10.5px] text-hq-moss-dim">
                                                <HqPositionTag
                                                    position={player.position}
                                                />
                                                <span className="inline-flex min-w-0 items-center gap-1 max-sm:hidden">
                                                    <EntityImage
                                                        src={player.team.logo}
                                                        alt=""
                                                        fallback={Shield}
                                                        shape="square"
                                                        className="size-3 shrink-0 rounded-none bg-transparent"
                                                    />
                                                    <span className="truncate">
                                                        {player.team.short_name}
                                                    </span>
                                                </span>
                                                <HqStatusBadge
                                                    status={player.status}
                                                    className="max-sm:hidden"
                                                />
                                            </span>
                                        </span>
                                        <span className="flex shrink-0 flex-col">
                                            <button
                                                type="button"
                                                data-replace={lane}
                                                onClick={() => openPicker(lane)}
                                                aria-label={`Cambiar a ${player.name}`}
                                                className="flex size-9 cursor-pointer items-center justify-center text-hq-moss-dim transition-colors hover:bg-hq-panel-alt hover:text-hq-paper sm:size-7"
                                            >
                                                <Repeat2
                                                    aria-hidden="true"
                                                    className="size-3.5"
                                                />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => remove(lane)}
                                                aria-label={`Quitar a ${player.name}`}
                                                className="flex size-9 cursor-pointer items-center justify-center text-hq-moss-dim transition-colors hover:bg-hq-panel-alt hover:text-hq-live sm:size-7"
                                            >
                                                <X
                                                    aria-hidden="true"
                                                    className="size-3.5"
                                                />
                                            </button>
                                        </span>
                                    </div>

                                    {item.weeks.map(
                                        ({ week, score, pending }, column) => {
                                            const value = cellValue(
                                                score,
                                                metric,
                                            );
                                            const top = tops[column] === lane;
                                            const daznShown =
                                                metric === 'dazn' &&
                                                score !== null &&
                                                score.minutes > 0 &&
                                                (score.dazn_points !== null ||
                                                    score.dazn_estimate !==
                                                        null);

                                            return (
                                                <div
                                                    key={week}
                                                    {...cellProps(
                                                        lane,
                                                        column,
                                                        week,
                                                    )}
                                                    className={cn(
                                                        'relative flex cursor-default flex-col items-center justify-center gap-1 border-t border-hq-border py-1.5 focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-hq-lime',
                                                        lit(week),
                                                    )}
                                                >
                                                    {top && (
                                                        <i
                                                            aria-hidden="true"
                                                            className="absolute top-0 left-1/2 h-1.5 w-0.5 -translate-x-1/2 bg-hq-lime"
                                                        />
                                                    )}
                                                    {!score ? (
                                                        <span
                                                            aria-hidden="true"
                                                            className={cn(
                                                                'font-mono text-[10px]',
                                                                pending
                                                                    ? 'text-hq-moss'
                                                                    : 'text-hq-led-off',
                                                            )}
                                                        >
                                                            {pending
                                                                ? 'PEND'
                                                                : 'NC'}
                                                        </span>
                                                    ) : (
                                                        <>
                                                            <span
                                                                aria-hidden="true"
                                                                className="pointer-events-none flex h-[22px] items-center"
                                                            >
                                                                {daznShown ? (
                                                                    <HqDaznBadge
                                                                        entry={
                                                                            score
                                                                        }
                                                                        size="row"
                                                                    />
                                                                ) : metric !==
                                                                      'minutes' &&
                                                                  score.minutes >
                                                                      0 &&
                                                                  value ===
                                                                      null ? (
                                                                    <span className="font-mono text-[11px] text-hq-moss-dim">
                                                                        —
                                                                    </span>
                                                                ) : value ===
                                                                  null ? (
                                                                    <span className="font-mono text-[11px] text-hq-moss-dim">
                                                                        0'
                                                                    </span>
                                                                ) : metric ===
                                                                  'minutes' ? (
                                                                    <span
                                                                        className={cn(
                                                                            'font-mono text-xs font-bold tabular-nums',
                                                                            score.starter
                                                                                ? 'text-hq-paper'
                                                                                : 'text-hq-moss',
                                                                        )}
                                                                    >
                                                                        {value}'
                                                                    </span>
                                                                ) : (
                                                                    <span
                                                                        className={cn(
                                                                            'inline-flex h-[22px] min-w-[30px] items-center justify-center px-[5px] font-mono text-xs leading-none font-bold tabular-nums',
                                                                            matchPointsBadgeClass(
                                                                                value,
                                                                            ),
                                                                        )}
                                                                    >
                                                                        {value}
                                                                    </span>
                                                                )}
                                                            </span>
                                                            <EntityImage
                                                                src={
                                                                    score
                                                                        .opponent
                                                                        .logo
                                                                }
                                                                alt=""
                                                                fallback={
                                                                    Shield
                                                                }
                                                                shape="square"
                                                                className="size-3.5 rounded-none bg-transparent object-contain"
                                                            />
                                                            <span
                                                                aria-hidden="true"
                                                                className="h-[3px] w-7 bg-hq-border"
                                                            >
                                                                <i
                                                                    className={cn(
                                                                        'block h-full',
                                                                        score.starter
                                                                            ? 'bg-hq-paper'
                                                                            : 'bg-hq-moss',
                                                                    )}
                                                                    style={{
                                                                        width: `${Math.min(100, (score.minutes / 90) * 100)}%`,
                                                                    }}
                                                                />
                                                            </span>
                                                        </>
                                                    )}
                                                </div>
                                            );
                                        },
                                    )}

                                    <div
                                        aria-hidden="true"
                                        className="border-t border-r border-l border-dashed border-hq-border-strong"
                                    />

                                    {futureWeeks.map((week, offset) => {
                                        const fixture = fixtureIn(lane, week);
                                        const column =
                                            pastWeeks.length + offset;

                                        return (
                                            <div
                                                key={week}
                                                {...cellProps(
                                                    lane,
                                                    column,
                                                    week,
                                                )}
                                                className={cn(
                                                    'relative flex cursor-default flex-col items-center justify-center gap-1 border-t border-hq-border px-1.5 py-1.5 focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-hq-lime',
                                                    lit(week),
                                                )}
                                            >
                                                {!fixture ? (
                                                    <span
                                                        aria-hidden="true"
                                                        className="font-mono text-xs text-hq-moss-dim"
                                                    >
                                                        –
                                                    </span>
                                                ) : (
                                                    <>
                                                        <span
                                                            aria-hidden="true"
                                                            className="relative"
                                                        >
                                                            <EntityImage
                                                                src={
                                                                    fixture
                                                                        .opponent
                                                                        .logo
                                                                }
                                                                alt=""
                                                                fallback={
                                                                    Shield
                                                                }
                                                                shape="square"
                                                                className="size-5 rounded-none bg-transparent object-contain"
                                                            />
                                                            <span className="absolute -right-2 -bottom-1 text-hq-moss">
                                                                {fixture.is_home ? (
                                                                    <House className="size-2.5" />
                                                                ) : (
                                                                    <Plane className="size-2.5" />
                                                                )}
                                                            </span>
                                                        </span>
                                                        {fixture.absence_adjusted ===
                                                            true && (
                                                            <UserX
                                                                aria-hidden="true"
                                                                className="absolute top-1 right-1 size-[9px] text-hq-moss"
                                                                strokeWidth={
                                                                    2.4
                                                                }
                                                            />
                                                        )}
                                                        {fixture.difficulty ===
                                                        null ? (
                                                            <span
                                                                aria-hidden="true"
                                                                className="font-mono text-[9px] text-hq-moss-dim"
                                                            >
                                                                –
                                                            </span>
                                                        ) : (
                                                            <HqDifficultyBars
                                                                difficulty={
                                                                    fixture.difficulty
                                                                }
                                                                layout="inline"
                                                            />
                                                        )}
                                                        {week ===
                                                            currentWeek && (
                                                            <span
                                                                aria-hidden="true"
                                                                className={cn(
                                                                    'font-mono text-[10.5px] font-bold tabular-nums',
                                                                    START_TONE_TEXT_CLASSES[
                                                                        item
                                                                            .startTone
                                                                    ],
                                                                )}
                                                            >
                                                                {item.startTone ===
                                                                'out'
                                                                    ? (STATUS_SHORT_LABELS[
                                                                          player
                                                                              .status
                                                                      ] ??
                                                                      STATUS_LABELS[
                                                                          player
                                                                              .status
                                                                      ])
                                                                    : item.startProbability ===
                                                                        null
                                                                      ? '—'
                                                                      : `${item.startProbability}%`}
                                                            </span>
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                        );
                                    })}

                                    <div
                                        {...cellProps(lane, lastColumn, 'sum')}
                                        className={cn(
                                            'flex cursor-default flex-col items-center justify-center gap-0.5 border-t border-hq-border py-1.5 focus-visible:outline-2 focus-visible:outline-offset-[-3px] focus-visible:outline-hq-lime',
                                            lit('sum'),
                                        )}
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'font-mono text-lg font-bold tabular-nums',
                                                sumBest === lane
                                                    ? 'text-hq-lime'
                                                    : 'text-hq-paper',
                                            )}
                                        >
                                            {sumText(sums[lane])}
                                        </span>
                                        <small
                                            aria-hidden="true"
                                            className="font-mono text-[10px] text-hq-moss-dim"
                                        >
                                            {metric === 'points'
                                                ? `media ${formatAverage(player.average_points)}`
                                                : metric === 'minutes'
                                                  ? `${item.starts}/${item.settledWeeks.length} titular`
                                                  : 'oficial'}
                                        </small>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
                {players.length < COMPARE_MAX && (
                    <div className="border-t border-hq-border px-3.5 py-3 sm:px-4">
                        <button
                            type="button"
                            data-add=""
                            onClick={() => openPicker(null)}
                            className="inline-flex h-11 cursor-pointer items-center gap-2 border border-dashed border-hq-border-strong px-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase transition-colors hover:border-hq-lime hover:text-hq-lime sm:h-9"
                        >
                            <Plus aria-hidden="true" className="size-3.5" />
                            Añadir un carril
                        </button>
                    </div>
                )}
            </section>

            <section>
                <div className="flex items-center justify-between border-y border-hq-border-strong bg-hq-panel px-3.5 py-2.5 sm:px-4">
                    <h2 className="hq-label">Mercado y propiedad</h2>
                    <span className="font-mono text-[11px] text-hq-moss-dim">
                        últimos 30 días
                    </span>
                </div>
                {players.map((player, lane) => {
                    const todayBest = winner(
                        players.map((other) => other.difference),
                    );
                    const trendBest = winner(
                        players.map(
                            (other) => other.value_trend_30d?.multiple ?? null,
                        ),
                    );

                    return (
                        <div
                            key={player.id}
                            data-slot={lane}
                            {...bind(lane)}
                            className="grid grid-cols-1 gap-2.5 border-b border-l-2 border-hq-border px-3.5 py-3 sm:grid-cols-[180px_minmax(0,1fr)_minmax(0,1fr)] sm:items-center sm:px-4"
                            style={{
                                borderLeftColor: COMPARE_SLOT_COLORS[lane],
                            }}
                        >
                            <span className="flex min-w-0 items-center gap-2 text-xs font-black text-hq-paper uppercase">
                                <EntityImage
                                    src={player.image}
                                    alt=""
                                    fallback={User}
                                    shape="square"
                                    className="size-7 shrink-0 rounded-none object-cover object-top"
                                />
                                <span className="truncate">{player.name}</span>
                            </span>
                            <div className="grid grid-cols-[auto_auto_minmax(0,1fr)] items-center gap-3">
                                <div
                                    className={cn(
                                        'flex flex-col gap-1 pb-0.5',
                                        lane === todayBest &&
                                            'shadow-[inset_0_-2px_0_var(--color-hq-lime)]',
                                    )}
                                >
                                    <span className="font-mono text-sm font-bold text-hq-paper tabular-nums">
                                        {formatMillions(player.value)}
                                    </span>
                                    <HqMarketValueDifference
                                        difference={player.difference}
                                        trend={player.trend}
                                        className="text-xs"
                                    />
                                </div>
                                <div
                                    className={cn(
                                        'flex flex-col gap-1 pb-0.5',
                                        lane === trendBest &&
                                            'shadow-[inset_0_-2px_0_var(--color-hq-lime)]',
                                    )}
                                >
                                    <span className="font-mono text-sm font-bold text-hq-paper tabular-nums">
                                        {player.value_trend_30d
                                            ? `×${formatDecimal(player.value_trend_30d.multiple)}`
                                            : '—'}
                                    </span>
                                    <span className="font-mono text-[11px] text-hq-moss-dim">
                                        desde{' '}
                                        {player.value_trend_30d
                                            ? formatMillions(
                                                  player.value_trend_30d.value,
                                              )
                                            : '—'}
                                    </span>
                                </div>
                                <div className="min-w-0">
                                    <CompareValueChart
                                        series={[
                                            {
                                                slot: lane,
                                                name: player.name,
                                                history: player.market_history,
                                            },
                                        ]}
                                        width={300}
                                        height={40}
                                        padRight={4}
                                        showAxis={false}
                                        label={`Evolución del valor de ${player.name} en los últimos 30 días`}
                                    />
                                </div>
                            </div>
                            <ComparePropertyLine
                                player={player}
                                derived={derived[lane]}
                            />
                        </div>
                    );
                })}
            </section>
            <p className="m-0 px-3.5 py-4 font-mono text-[11px] leading-normal text-hq-moss-dim sm:px-4">
                DAZN: puntuación oficial; en juego o sin publicar, la estimación
                provisional (no cuenta en la media). Titularidad J{currentWeek}:
                FútbolFantasy. Dificultad 0–10 según la fuerza del rival y si se
                juega en casa.
            </p>
        </div>
    );
}
