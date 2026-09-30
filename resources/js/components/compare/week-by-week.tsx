import { Shield } from 'lucide-react';
import type {
    FocusEvent,
    HTMLAttributes,
    KeyboardEvent,
    PointerEvent,
    ReactNode,
} from 'react';
import { useRef, useState } from 'react';
import { TipRow, useChartTooltip } from '@/components/compare/chart-tooltip';
import { useCompare } from '@/components/compare/compare-context';
import {
    COMPARE_GRID,
    COMPARE_SPAN,
    CompareToggleGroup,
    RowLabel,
    SlotName,
} from '@/components/compare/compare-grid';
import type { WeekMetric } from '@/components/compare/derive';
import { weekValue, winner } from '@/components/compare/derive';
import { EntityImage } from '@/components/entity-image';
import { HqDaznBadge } from '@/components/hq-dazn-badge';
import { COMPARE_MAX, COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { ComparedPlayerScore } from '@/types/models';

const METRIC_OPTIONS: { value: WeekMetric; label: string }[] = [
    { value: 'points', label: 'Pts' },
    { value: 'dazn', label: 'DAZN' },
    { value: 'minutes', label: 'Min' },
];

const METRIC_HINTS: Record<WeekMetric, string> = {
    points: 'puntos por jornada',
    dazn: 'nota DAZN oficial',
    minutes: 'minutos por jornada',
};

/**
 * Forma · Jornada a jornada: J1 … J{CW−1} for each player in his column
 * (one line per player on phones), with the chosen figure, the opponent,
 * a minutes bar (starter / sub), the best-of-the-jornada tick, and NC or
 * PEND when he has no lineup row. One tab stop for every cell: ←/→
 * jornada, ↑/↓ player, Inicio/Fin.
 */
export function CompareWeekByWeek() {
    const { players, derived } = useCompare();
    const { show, hide } = useChartTooltip();
    const [metric, setMetric] = useState<WeekMetric>('points');
    const weekCount = derived[0]?.weeks.length ?? 0;
    const [focus, setFocus] = useState({
        lane: 0,
        column: Math.max(0, weekCount - 1),
    });
    const rowRef = useRef<HTMLDivElement>(null);
    const focusLane = Math.min(focus.lane, players.length - 1);
    const focusColumn = Math.min(focus.column, Math.max(0, weekCount - 1));
    // Pending and not-called-up weeks are null, so they never win.
    const tops = Array.from({ length: weekCount }, (_, column) =>
        winner(
            derived.map((item) =>
                weekValue(item.weeks[column]?.score ?? null, metric),
            ),
        ),
    );

    const weekText = (
        score: ComparedPlayerScore | null,
        pending = false,
    ): string => {
        if (!score) {
            return pending ? 'pendiente' : 'NC';
        }

        const value = weekValue(score, metric);

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

    /** Tooltip content and accessible name of one week of one player. */
    const cellInfo = (
        lane: number,
        column: number,
    ): { content: ReactNode; text: string } => {
        const player = players[lane];
        const { week, score, pending } = derived[lane].weeks[column];
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
                    {players.length > 1 && (
                        <span className="mt-2 flex flex-col gap-1 border-t border-hq-border pt-[7px]">
                            {players.map((other, index) => (
                                <TipRow
                                    key={other.id}
                                    slotColor={COMPARE_SLOT_COLORS[index]}
                                    name={other.name}
                                    value={weekText(
                                        derived[index].weeks[column].score,
                                        derived[index].weeks[column].pending,
                                    )}
                                    me={index === lane}
                                />
                            ))}
                        </span>
                    )}
                </>
            ),
            text: `Jornada ${week}, ${player.name}: ${score ? `${weekText(score)}, ${score.is_home ? 'en casa contra ' : 'fuera contra '}${score.opponent.name}, ${more}` : pending ? 'partido pendiente de jugar' : 'no convocado'}${top ? ', mejor de la jornada' : ''}`,
        };
    };

    const cellProps = (lane: number, column: number) => {
        const open = (element: HTMLElement) =>
            show(
                cellInfo(lane, column).content,
                () =>
                    element.isConnected
                        ? element.getBoundingClientRect()
                        : null,
                element,
            );

        return {
            role: 'img' as const,
            'data-cell': '',
            'data-lane': lane,
            'data-col': column,
            'data-cmp-tip': '',
            tabIndex: focusLane === lane && focusColumn === column ? 0 : -1,
            'aria-label': cellInfo(lane, column).text,
            onPointerEnter: (event: PointerEvent<HTMLElement>) =>
                open(event.currentTarget),
            onPointerLeave: (event: PointerEvent<HTMLElement>) => {
                if (event.pointerType !== 'touch') {
                    hide(event.currentTarget);
                }
            },
            onFocus: (event: FocusEvent<HTMLElement>) => {
                setFocus({ lane, column });
                open(event.currentTarget);
            },
            onBlur: (event: FocusEvent<HTMLElement>) =>
                hide(event.currentTarget),
        };
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (
            !(event.target instanceof HTMLElement) ||
            !event.target.closest('[data-cell]')
        ) {
            return;
        }

        const last = weekCount - 1;
        const moves: Record<string, { lane: number; column: number }> = {
            ArrowLeft: { lane: focusLane, column: focusColumn - 1 },
            ArrowRight: { lane: focusLane, column: focusColumn + 1 },
            ArrowUp: { lane: focusLane - 1, column: focusColumn },
            ArrowDown: { lane: focusLane + 1, column: focusColumn },
            Home: { lane: focusLane, column: 0 },
            End: { lane: focusLane, column: last },
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
            column: Math.max(0, Math.min(last, moves[event.key].column)),
        };
        setFocus(next);
        rowRef.current
            ?.querySelector<HTMLElement>(
                `[data-cell][data-lane="${next.lane}"][data-col="${next.column}"]`,
            )
            ?.focus();
    };

    return (
        <>
            <div
                ref={rowRef}
                role="group"
                aria-label="Jornada a jornada. Flechas izquierda y derecha para cambiar de jornada; arriba y abajo para cambiar de jugador."
                onKeyDown={onKeyDown}
                className={cn(
                    COMPARE_GRID,
                    'border-b border-hq-border max-sm:grid-cols-1',
                )}
            >
                <RowLabel
                    label="Jornada a jornada"
                    hint={METRIC_HINTS[metric]}
                    className="max-sm:pb-1"
                >
                    <CompareToggleGroup
                        label="Cifra por jornada"
                        value={metric}
                        onChange={setMetric}
                        options={METRIC_OPTIONS}
                        className="sm:mt-1.5"
                    />
                </RowLabel>
                {players.map((player, lane) => (
                    <div
                        key={player.id}
                        data-slot={lane}
                        className="flex min-w-0 flex-col items-start justify-start gap-1 px-3.5 py-3 max-sm:border-t max-sm:border-hq-border max-sm:py-2 max-sm:nth-2:border-t-0 sm:px-4"
                    >
                        <SlotName
                            slot={lane}
                            name={player.name}
                            className="sm:hidden"
                        />
                        {weekCount === 0 ? (
                            <span className="font-mono text-sm text-hq-moss-dim">
                                —
                            </span>
                        ) : (
                            <span className="grid w-full grid-cols-[repeat(auto-fill,minmax(34px,1fr))] gap-0.5 pt-1.5 max-sm:mt-1 sm:grid-cols-[repeat(auto-fill,minmax(38px,1fr))]">
                                {derived[lane].weeks.map(
                                    ({ week, score, pending }, column) => (
                                        <WeekCell
                                            key={week}
                                            week={week}
                                            score={score}
                                            pending={pending}
                                            metric={metric}
                                            top={tops[column] === lane}
                                            {...cellProps(lane, column)}
                                        />
                                    ),
                                )}
                            </span>
                        )}
                    </div>
                ))}
                {players.length < COMPARE_MAX && (
                    <div aria-hidden="true" className="max-sm:hidden" />
                )}
            </div>
            <div
                className={cn(
                    COMPARE_GRID,
                    'border-b border-dashed border-hq-border',
                )}
            >
                <div aria-hidden="true" className="max-sm:hidden" />
                <div
                    className={cn(
                        COMPARE_SPAN,
                        'flex flex-wrap gap-x-3.5 gap-y-1 px-3.5 py-2 font-mono text-[11px] text-hq-moss-dim sm:px-4',
                    )}
                >
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
                        minutos titular
                    </span>
                    <span className="inline-flex items-center gap-1.5">
                        <i
                            aria-hidden="true"
                            className="inline-block h-[3px] w-4 bg-hq-moss"
                        />
                        suplente
                    </span>
                    <span>
                        <b className="font-normal text-hq-led-off">NC</b> no
                        convocado ·{' '}
                        <b className="font-normal text-hq-moss">PEND</b> por
                        jugar
                    </span>
                </div>
            </div>
        </>
    );
}

/** One jornada of one player: "J{n}", the figure, the opponent's crest and the minutes bar. */
function WeekCell({
    week,
    score,
    pending,
    metric,
    top,
    ...props
}: {
    week: number;
    score: ComparedPlayerScore | null;
    pending: boolean;
    metric: WeekMetric;
    top: boolean;
} & HTMLAttributes<HTMLSpanElement>) {
    const value = weekValue(score, metric);
    const daznShown =
        metric === 'dazn' &&
        score !== null &&
        score.minutes > 0 &&
        (score.dazn_points !== null || score.dazn_estimate !== null);

    return (
        <span
            {...props}
            className="relative flex cursor-default flex-col items-center gap-[3px] pt-[3px] pb-1 hover:bg-[color-mix(in_srgb,var(--color-hq-paper)_7%,transparent)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-hq-lime"
        >
            {top && (
                <i
                    aria-hidden="true"
                    className="absolute -top-1.5 left-1/2 h-1.5 w-0.5 -translate-x-1/2 bg-hq-lime"
                />
            )}
            <span
                aria-hidden="true"
                className="font-mono text-[10px] leading-none text-hq-moss-dim"
            >
                J{week}
            </span>
            <span
                aria-hidden="true"
                className="pointer-events-none flex h-[22px] items-center"
            >
                {!score ? (
                    <span
                        className={cn(
                            'font-mono text-[10px]',
                            pending ? 'text-hq-moss' : 'text-hq-led-off',
                        )}
                    >
                        {pending ? 'PEND' : 'NC'}
                    </span>
                ) : daznShown ? (
                    <HqDaznBadge entry={score} size="row" />
                ) : metric !== 'minutes' &&
                  score.minutes > 0 &&
                  value === null ? (
                    <span className="font-mono text-[11px] text-hq-moss-dim">
                        —
                    </span>
                ) : value === null ? (
                    <span className="font-mono text-[11px] text-hq-moss-dim">
                        0'
                    </span>
                ) : metric === 'minutes' ? (
                    <span
                        className={cn(
                            'font-mono text-xs font-bold tabular-nums',
                            score.starter ? 'text-hq-paper' : 'text-hq-moss',
                        )}
                    >
                        {value}'
                    </span>
                ) : (
                    <span
                        className={cn(
                            'inline-flex h-[22px] min-w-7 items-center justify-center px-1 font-mono text-xs leading-none font-bold tabular-nums',
                            matchPointsBadgeClass(value),
                        )}
                    >
                        {value}
                    </span>
                )}
            </span>
            {score && (
                <>
                    <EntityImage
                        src={score.opponent.logo}
                        alt=""
                        fallback={Shield}
                        shape="square"
                        className="size-[13px] rounded-none bg-transparent object-contain"
                    />
                    <span
                        aria-hidden="true"
                        className="h-[3px] w-[26px] max-w-full bg-hq-border"
                    >
                        <i
                            className={cn(
                                'block h-full',
                                score.starter ? 'bg-hq-paper' : 'bg-hq-moss',
                            )}
                            style={{
                                width: `${Math.min(100, (score.minutes / 90) * 100)}%`,
                            }}
                        />
                    </span>
                </>
            )}
        </span>
    );
}
