import { Plus, Repeat2, Shield, User, X } from 'lucide-react';
import type {
    CSSProperties,
    FocusEvent,
    KeyboardEvent,
    MouseEvent,
    PointerEvent,
} from 'react';
import { memo, useMemo, useRef, useState } from 'react';
import {
    TipRow,
    useChartTooltip,
    useSlotHighlight,
} from '@/components/compare/chart-tooltip';
import { useCompare } from '@/components/compare/compare-context';
import type { TrackMetric, TrackScope } from '@/components/compare/derive';
import {
    beatsPercent,
    jitter,
    medianOf,
    rankIn,
    trackMetrics,
    trackPosition,
    trackValues,
    winner,
} from '@/components/compare/derive';
import {
    CompareProperty,
    ComparePropertyLine,
} from '@/components/compare/property';
import { EntityImage } from '@/components/entity-image';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { COMPARE_MAX, COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { formatAverage } from '@/lib/format';
import {
    POSITION_ABBREVIATIONS,
    STATUS_SHORT_LABELS,
} from '@/lib/player-labels';
import { daznPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { ComparedPlayer, LeagueCloudRow } from '@/types/models';

/** Height of the league dot cloud and of each compared player's lane (mock `--dust` / `--lane`). */
const DUST_HEIGHT = 26;
const LANE_HEIGHT = 34;
/** Space between the cloud and the first lane (mock `.t-body` padding + `.lanes` padding). */
const LANES_TOP = DUST_HEIGHT + 8 + 10;

interface TrackDot {
    row: LeagueCloudRow;
    value: number;
    /** 0–100 along the track. */
    x: number;
    /** 0–26 inside the cloud. */
    y: number;
}

interface TrackData {
    metric: TrackMetric;
    values: number[];
    domain: [number, number];
    median: number | null;
    dots: TrackDot[];
    playerValues: (number | null)[];
    best: number | null;
}

interface HoverState {
    track: number;
    dot: TrackDot;
    rank: number;
}

/** The league as a cloud of 3 px squares; memoised so scrubbing a track doesn't redraw ~2 600 rects. */
const TrackDust = memo(function TrackDust({ dots }: { dots: TrackDot[] }) {
    return (
        <svg
            viewBox={`0 0 1000 ${DUST_HEIGHT}`}
            preserveAspectRatio="none"
            aria-hidden="true"
            className="absolute inset-x-0 top-0 block w-full border-b border-hq-border-strong bg-linear-to-r from-white/[0.012] to-hq-lime/[0.035]"
            style={{ height: DUST_HEIGHT }}
        >
            {dots.map((dot) => (
                <rect
                    key={dot.row.id}
                    x={(dot.x * 9.96).toFixed(1)}
                    y={dot.y.toFixed(1)}
                    width={3}
                    height={3}
                    className="fill-hq-olive opacity-50"
                />
            ))}
        </svg>
    );
});

/** What the "Titularidad" track shows for a player who is out: his status, not "0 %". */
function shownValue(
    metric: TrackMetric,
    player: ComparedPlayer,
    isOut: boolean,
    value: number,
): string {
    if (metric.key === 'start' && isOut) {
        return STATUS_SHORT_LABELS[player.status] ?? 'Baja';
    }

    return metric.format(value);
}

/**
 * B · Pistas: each metric is a strip with the whole league as a dot cloud,
 * its median, and one lane per compared player with his marker, value and
 * rank. Scrubbing the cloud names the nearest league player and a click adds
 * him (when there's room); the markers read out value, rank and "supera al".
 */
export function CompareViewB() {
    const {
        players,
        derived,
        league,
        currentWeek,
        add,
        remove,
        openPicker,
        announce,
    } = useCompare();
    const { show, hide } = useChartTooltip();
    const { rootProps, bind, highlighted } = useSlotHighlight();
    const [scope, setScope] = useState<TrackScope>('all');
    const [focus, setFocus] = useState({ track: 0, player: 0 });
    const [hover, setHover] = useState<HoverState | null>(null);
    const tracksRef = useRef<HTMLDivElement>(null);
    const samePosition = players.every(
        (player) => player.position === players[0].position,
    );
    const scopeLabel = scope === 'position' ? 'de su puesto' : 'de la liga';
    const hasRoom = players.length < COMPARE_MAX;
    const isCompared = (id: number) =>
        players.some((player) => player.id === id);

    const tracks = useMemo<TrackData[]>(() => {
        const positions = players.map((player) => player.position);

        return trackMetrics(currentWeek).map((metric, trackIndex) => {
            const values = trackValues(league, metric, scope, positions);
            const playerValues = players.map((player, index) =>
                metric.player(player, derived[index]),
            );
            const known = [
                ...values,
                ...playerValues.filter(
                    (value): value is number => value !== null,
                ),
            ];
            const domain: [number, number] =
                metric.fixed ??
                (known.length === 0
                    ? [0, 1]
                    : [Math.min(...known), Math.max(...known)]);
            const dots: TrackDot[] = [];

            for (const row of league) {
                const value = metric.league(row);

                if (
                    value === null ||
                    (scope === 'position' && !positions.includes(row.position))
                ) {
                    continue;
                }

                dots.push({
                    row,
                    value,
                    x: trackPosition(value, domain, metric.scale),
                    y: 3 + jitter(row.id, trackIndex) * 17,
                });
            }

            return {
                metric,
                values,
                domain,
                median: medianOf(values),
                dots,
                playerValues,
                best: metric.noBest ? null : winner(playerValues),
            };
        });
    }, [league, scope, players, derived, currentWeek]);

    /** The one marker that takes Tab: the remembered one, else the first with a value on that track, else any. */
    const tabStop = (() => {
        const onTrack = (track: number) =>
            tracks[track]?.playerValues.findIndex((value) => value !== null) ??
            -1;

        if (tracks[focus.track]?.playerValues[focus.player] != null) {
            return focus;
        }

        if (onTrack(focus.track) >= 0) {
            return { track: focus.track, player: onTrack(focus.track) };
        }

        const track = tracks.findIndex((_, index) => onTrack(index) >= 0);

        return { track, player: track >= 0 ? onTrack(track) : -1 };
    })();

    const markerTip = (trackIndex: number, playerIndex: number) => {
        const { metric, values, playerValues } = tracks[trackIndex];
        const value = playerValues[playerIndex];
        const player = players[playerIndex];

        if (value === null) {
            return null;
        }

        const rank = rankIn(values, value);
        const shown = shownValue(
            metric,
            player,
            derived[playerIndex].startTone === 'out',
            value,
        );

        return {
            content: (
                <>
                    <b className="font-sans text-[12.5px] leading-[1.15] font-black uppercase">
                        {player.name}
                    </b>
                    <span className="mt-[3px] text-hq-moss-dim">
                        {metric.label} · {metric.note}
                    </span>
                    <span className="mt-[7px] font-mono text-xl leading-none font-bold text-hq-paper tabular-nums">
                        {shown}
                    </span>
                    <span className="mt-[3px] text-hq-moss-dim">
                        {rank}.º de {values.length} {scopeLabel} · supera al{' '}
                        {beatsPercent(values, value)} %
                    </span>
                    {players.length > 1 && (
                        <span className="mt-2 flex flex-col gap-1 border-t border-hq-border pt-[7px]">
                            {players.map((other, index) => {
                                const otherValue = playerValues[index];

                                return (
                                    <TipRow
                                        key={other.id}
                                        slotColor={COMPARE_SLOT_COLORS[index]}
                                        name={other.name}
                                        value={
                                            otherValue === null
                                                ? 'sin dato'
                                                : shownValue(
                                                      metric,
                                                      other,
                                                      derived[index]
                                                          .startTone === 'out',
                                                      otherValue,
                                                  )
                                        }
                                        extra={
                                            otherValue === null
                                                ? ''
                                                : `${rankIn(values, otherValue)}.º`
                                        }
                                        me={index === playerIndex}
                                    />
                                );
                            })}
                        </span>
                    )}
                </>
            ),
            text: `${player.name}, ${metric.label}: ${shown}, ${rank}.º de ${values.length} ${scopeLabel}, supera al ${beatsPercent(values, value)} %`,
        };
    };

    /** Tooltip + player highlight on a marker (hover, keyboard focus, tap). */
    const markerProps = (trackIndex: number, playerIndex: number) => {
        const highlight = bind(playerIndex);
        const open = (element: HTMLElement) => {
            const tip = markerTip(trackIndex, playerIndex);

            if (tip) {
                show(
                    tip.content,
                    () =>
                        element.isConnected
                            ? element.getBoundingClientRect()
                            : null,
                    element,
                );
            }
        };

        return {
            'data-cmp-tip': '',
            'data-hl-slot': playerIndex,
            onPointerEnter: (event: PointerEvent<HTMLElement>) => {
                highlight.onPointerEnter();
                open(event.currentTarget);
            },
            onPointerLeave: (event: PointerEvent<HTMLElement>) => {
                highlight.onPointerLeave();

                if (event.pointerType !== 'touch') {
                    hide(event.currentTarget);
                }
            },
            onFocus: (event: FocusEvent<HTMLElement>) => {
                highlight.onFocus();
                setFocus({ track: trackIndex, player: playerIndex });
                open(event.currentTarget);
            },
            onBlur: (event: FocusEvent<HTMLElement>) => {
                highlight.onBlur();
                hide(event.currentTarget);
            },
        };
    };

    /** One tab stop for every marker: ↑/↓ track, ←/→ player, Inicio/Fin (mock `findMk`). */
    const onTracksKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const marker = (event.target as Element).closest<HTMLElement>(
            '[data-bm]',
        );

        if (!marker) {
            return;
        }

        const current = {
            track: Number(marker.dataset.bm),
            player: Number(marker.dataset.bi),
        };
        const last = players.length - 1;
        const moves: Record<string, { track: number; player: number }> = {
            ArrowLeft: { ...current, player: Math.max(0, current.player - 1) },
            ArrowRight: {
                ...current,
                player: Math.min(last, current.player + 1),
            },
            ArrowUp: { ...current, track: Math.max(0, current.track - 1) },
            ArrowDown: {
                ...current,
                track: Math.min(tracks.length - 1, current.track + 1),
            },
            Home: { ...current, player: 0 },
            End: { ...current, player: last },
        };
        const next = moves[event.key];

        if (!next) {
            return;
        }

        event.preventDefault();
        const root = tracksRef.current;
        const onTrack = Array.from(
            root?.querySelectorAll<HTMLElement>(`[data-bm="${next.track}"]`) ??
                [],
        );
        const target =
            onTrack.find(
                (element) => Number(element.dataset.bi) === next.player,
            ) ?? onTrack[Math.min(next.player, onTrack.length - 1)];

        target?.focus();
    };

    /** Nearest league player to the pointer on this track (mock `nearest`); ties go to the one with more points. */
    const nearestAt = (
        event: MouseEvent<HTMLDivElement>,
        trackIndex: number,
    ): TrackDot | null => {
        const rect = event.currentTarget.getBoundingClientRect();
        const pointer = ((event.clientX - rect.left) / rect.width) * 100;
        let best: TrackDot | null = null;
        let bestDistance = Infinity;

        for (const dot of tracks[trackIndex].dots) {
            const distance = Math.abs(dot.x - pointer);

            if (
                distance < bestDistance ||
                (distance === bestDistance &&
                    best !== null &&
                    dot.row.points > best.row.points)
            ) {
                best = dot;
                bestDistance = distance;
            }
        }

        return best;
    };

    const onMarker = (event: MouseEvent) =>
        (event.target as Element).closest('[data-bm],[data-bv]') !== null;

    const hoverAt = (event: MouseEvent<HTMLDivElement>, trackIndex: number) => {
        // Over a compared player's marker or value, his tooltip wins over the league card.
        const dot = onMarker(event) ? null : nearestAt(event, trackIndex);

        setHover((previous) => {
            if (dot === null) {
                return null;
            }

            if (
                previous?.track === trackIndex &&
                previous.dot.row.id === dot.row.id
            ) {
                return previous;
            }

            return {
                track: trackIndex,
                dot,
                rank: rankIn(tracks[trackIndex].values, dot.value),
            };
        });
    };

    const addFrom = (event: MouseEvent<HTMLDivElement>, trackIndex: number) => {
        if (onMarker(event)) {
            return;
        }

        const dot = nearestAt(event, trackIndex);

        if (dot && hasRoom && !isCompared(dot.row.id)) {
            announce(`${dot.row.name} añadido a la comparación`);
            add(dot.row.id);
        }
    };

    return (
        <div {...rootProps}>
            <div className="flex flex-wrap items-center gap-3 border-b border-hq-border px-3.5 py-3 sm:px-4 sm:py-3.5">
                <span className="hq-label">Comparar contra</span>
                <div
                    role="group"
                    aria-label="Población de referencia"
                    className="inline-flex border border-hq-border-strong"
                >
                    {(['all', 'position'] as const).map((option) => (
                        <button
                            key={option}
                            type="button"
                            aria-pressed={scope === option}
                            onClick={() => {
                                setScope(option);
                                setHover(null);
                            }}
                            className={cn(
                                'h-11 cursor-pointer px-3 font-mono text-[11px] font-bold tracking-[0.06em] uppercase transition-colors not-last:border-r not-last:border-hq-border-strong sm:h-8',
                                scope === option
                                    ? 'bg-hq-lime text-hq-ink'
                                    : 'text-hq-moss hover:bg-hq-panel-alt hover:text-hq-paper',
                            )}
                        >
                            {option === 'all'
                                ? 'Toda la liga'
                                : samePosition
                                  ? `Solo ${POSITION_ABBREVIATIONS[players[0].position]}`
                                  : 'Su puesto'}
                        </button>
                    ))}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 border-b border-hq-border px-3.5 py-2.5 sm:px-4">
                {players.map((player, index) => (
                    <span
                        key={player.id}
                        data-slot={index}
                        {...bind(index)}
                        className="inline-flex min-w-0 items-center gap-2 border border-l-2 border-hq-border-strong py-0.5 pl-1 text-xs font-extrabold text-hq-paper uppercase"
                        style={{ borderLeftColor: COMPARE_SLOT_COLORS[index] }}
                    >
                        <EntityImage
                            src={player.image}
                            alt=""
                            fallback={User}
                            shape="square"
                            className="size-6 shrink-0 rounded-none object-cover object-top"
                        />
                        <span className="max-w-[16ch] truncate">
                            {player.name}
                        </span>
                        <HqPositionTag position={player.position} />
                        <span className="flex">
                            <button
                                type="button"
                                data-replace={index}
                                onClick={() => openPicker(index)}
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
                                onClick={() => remove(index)}
                                aria-label={`Quitar a ${player.name}`}
                                className="flex size-9 cursor-pointer items-center justify-center text-hq-moss-dim transition-colors hover:bg-hq-panel-alt hover:text-hq-live sm:size-7"
                            >
                                <X aria-hidden="true" className="size-3.5" />
                            </button>
                        </span>
                    </span>
                ))}
                {hasRoom ? (
                    <button
                        type="button"
                        data-add=""
                        onClick={() => openPicker(null)}
                        className="inline-flex h-11 cursor-pointer items-center gap-1.5 border border-dashed border-hq-border-strong px-3 font-mono text-[11px] font-bold tracking-[0.06em] text-hq-moss uppercase transition-colors hover:border-hq-lime hover:text-hq-lime sm:h-9"
                    >
                        <Plus aria-hidden="true" className="size-3.5" />
                        Añadir jugador
                    </button>
                ) : (
                    <span className="font-mono text-[11px] text-hq-moss-dim">
                        Máximo 3 · quita uno para añadir otro
                    </span>
                )}
            </div>

            <p className="m-0 flex flex-wrap items-center gap-x-4 gap-y-1 px-3.5 py-3 font-mono text-[11px] leading-normal text-hq-moss sm:px-4">
                <span className="inline-flex items-center gap-1.5">
                    <span aria-hidden="true" className="inline-flex gap-0.5">
                        {[0, 1, 2, 3].map((tick) => (
                            <i
                                key={tick}
                                className="h-2.5 w-0.5 bg-hq-olive opacity-55"
                            />
                        ))}
                    </span>
                    Cada marca es un jugador de LaLiga.
                </span>
                <span className="text-hq-lime">mejor →</span>
                <span>
                    Pasa el dedo por una pista para ver quién es y tócalo para
                    añadirlo.
                </span>
            </p>

            <section>
                <div className="flex flex-wrap items-center justify-between gap-2 border-y border-hq-border-strong bg-hq-panel px-3.5 py-2.5 sm:px-4">
                    <h2 className="hq-label">Dónde está cada uno</h2>
                    <span className="font-mono text-[11px] text-hq-moss-dim">
                        {tracks[0].values.length} jugadores con puntos
                        {scope === 'position' ? ' en su puesto' : ''}
                    </span>
                </div>
                <div
                    ref={tracksRef}
                    role="group"
                    aria-label="Pistas de la liga. Flechas arriba y abajo para cambiar de pista; izquierda y derecha para cambiar de jugador."
                    onKeyDown={onTracksKeyDown}
                >
                    {tracks.map((track, trackIndex) => {
                        const { metric, values, domain, median, dots } = track;
                        const hovering =
                            hover?.track === trackIndex ? hover : null;
                        const canAddHovered =
                            hovering !== null &&
                            hasRoom &&
                            !isCompared(hovering.dot.row.id);

                        return (
                            <div
                                key={metric.key}
                                className="grid grid-cols-1 gap-1.5 border-b border-hq-border px-3.5 pt-3.5 pb-4 min-[900px]:grid-cols-[190px_minmax(0,1fr)] min-[900px]:gap-6 min-[900px]:px-4 min-[900px]:pt-4 min-[900px]:pb-[18px]"
                            >
                                <div className="flex flex-wrap items-baseline gap-x-2.5 gap-y-1 min-[900px]:flex-col min-[900px]:gap-1.5">
                                    <h3 className="hq-label text-[11.5px] text-hq-paper">
                                        {metric.label}
                                    </h3>
                                    <span className="font-mono text-[11px] leading-[1.4] text-hq-moss-dim">
                                        {metric.note}
                                    </span>
                                </div>
                                <div
                                    className={cn(
                                        'relative touch-pan-y',
                                        canAddHovered
                                            ? 'cursor-pointer'
                                            : 'cursor-crosshair',
                                    )}
                                    style={{
                                        paddingTop: DUST_HEIGHT + 8,
                                        minHeight:
                                            DUST_HEIGHT +
                                            18 +
                                            players.length * LANE_HEIGHT,
                                    }}
                                    onMouseMove={(event) =>
                                        hoverAt(event, trackIndex)
                                    }
                                    onMouseLeave={() => setHover(null)}
                                    onClick={(event) =>
                                        addFrom(event, trackIndex)
                                    }
                                >
                                    <TrackDust dots={dots} />
                                    {median !== null && (
                                        <span
                                            className="pointer-events-none absolute top-0 w-0 border-l border-dashed border-hq-moss-dim"
                                            style={{
                                                left: `${trackPosition(median, domain, metric.scale)}%`,
                                                height: DUST_HEIGHT,
                                            }}
                                        >
                                            <span className="absolute top-[calc(100%+3px)] left-0 -translate-x-1/2 font-mono text-[10px] whitespace-nowrap text-hq-moss-dim">
                                                mediana {metric.format(median)}
                                            </span>
                                        </span>
                                    )}
                                    <span
                                        className="pointer-events-none absolute inset-x-0 flex translate-y-[3px] justify-between font-mono text-[10px] text-hq-led-off"
                                        style={{ top: DUST_HEIGHT }}
                                    >
                                        <span>{metric.format(domain[0])}</span>
                                        <span>{metric.format(domain[1])}</span>
                                    </span>
                                    {track.playerValues.map((value, index) =>
                                        value === null ? null : (
                                            <span
                                                key={players[index].id}
                                                data-slot={index}
                                                aria-hidden="true"
                                                className="pointer-events-none absolute w-px opacity-55 motion-safe:transition-[left] motion-safe:duration-[450ms] motion-safe:ease-[cubic-bezier(0.16,1,0.3,1)]"
                                                style={{
                                                    left: `${trackPosition(value, domain, metric.scale)}%`,
                                                    top: 13,
                                                    height:
                                                        LANES_TOP +
                                                        index * LANE_HEIGHT +
                                                        LANE_HEIGHT / 2 -
                                                        13 -
                                                        14,
                                                    background:
                                                        COMPARE_SLOT_COLORS[
                                                            index
                                                        ],
                                                }}
                                            />
                                        ),
                                    )}
                                    {hovering && (
                                        <>
                                            <span
                                                aria-hidden="true"
                                                className="pointer-events-none absolute top-0 w-px bg-hq-paper"
                                                style={{
                                                    left: `${hovering.dot.x}%`,
                                                    height: DUST_HEIGHT,
                                                }}
                                            />
                                            <span
                                                aria-hidden="true"
                                                className="pointer-events-none absolute z-20 flex items-center gap-2 border border-hq-border-bright bg-hq-panel py-[5px] pr-2 pl-[5px] text-xs font-bold whitespace-nowrap text-hq-paper shadow-[0_10px_26px_rgba(0,0,0,0.6)]"
                                                style={{
                                                    top: DUST_HEIGHT + 6,
                                                    ...(hovering.dot.x > 60
                                                        ? {
                                                              right: `${100 - hovering.dot.x}%`,
                                                          }
                                                        : {
                                                              left: `${hovering.dot.x}%`,
                                                          }),
                                                }}
                                            >
                                                <EntityImage
                                                    src={hovering.dot.row.image}
                                                    alt=""
                                                    fallback={User}
                                                    shape="square"
                                                    className="size-[26px] rounded-none bg-hq-panel-alt object-cover object-top"
                                                />
                                                <span>
                                                    {hovering.dot.row.name}
                                                    <small className="block font-mono text-[11px] font-medium text-hq-moss-dim">
                                                        {
                                                            hovering.dot.row
                                                                .team_short
                                                        }{' '}
                                                        ·{' '}
                                                        <b className="font-bold text-hq-paper">
                                                            {metric.format(
                                                                hovering.dot
                                                                    .value,
                                                            )}
                                                        </b>{' '}
                                                        · {hovering.rank}.º de{' '}
                                                        {values.length}
                                                    </small>
                                                </span>
                                                {canAddHovered && (
                                                    <em className="ml-1.5 font-mono text-[10px] font-bold tracking-[0.05em] text-hq-lime uppercase not-italic">
                                                        + añadir
                                                    </em>
                                                )}
                                            </span>
                                        </>
                                    )}
                                    <div className="relative pt-2.5">
                                        {players.map((player, index) => {
                                            const value =
                                                track.playerValues[index];
                                            const laneStyle = {
                                                height: LANE_HEIGHT,
                                                '--slot':
                                                    COMPARE_SLOT_COLORS[index],
                                            } as CSSProperties;

                                            if (value === null) {
                                                return (
                                                    <div
                                                        key={player.id}
                                                        data-slot={index}
                                                        className="relative before:absolute before:inset-x-0 before:top-1/2 before:border-t before:border-dashed before:border-hq-border"
                                                        style={laneStyle}
                                                    >
                                                        <span
                                                            data-bv=""
                                                            className="absolute top-1/2 left-0 -translate-y-1/2 cursor-default bg-hq-ink pr-2 font-mono text-[11px] text-hq-moss-dim"
                                                        >
                                                            {player.name} · sin
                                                            dato
                                                        </span>
                                                    </div>
                                                );
                                            }

                                            const x = trackPosition(
                                                value,
                                                domain,
                                                metric.scale,
                                            );
                                            const tip = markerTip(
                                                trackIndex,
                                                index,
                                            );
                                            const isTabStop =
                                                tabStop.track === trackIndex &&
                                                tabStop.player === index;
                                            const lit = highlighted === index;

                                            return (
                                                <div
                                                    key={player.id}
                                                    data-slot={index}
                                                    className="relative before:absolute before:inset-x-0 before:top-1/2 before:border-t before:border-dashed before:border-hq-border"
                                                    style={laneStyle}
                                                >
                                                    <span
                                                        role="img"
                                                        tabIndex={
                                                            isTabStop ? 0 : -1
                                                        }
                                                        data-bm={trackIndex}
                                                        data-bi={index}
                                                        aria-label={tip?.text}
                                                        {...markerProps(
                                                            trackIndex,
                                                            index,
                                                        )}
                                                        className={cn(
                                                            'absolute top-1/2 size-7 -translate-x-1/2 -translate-y-1/2 cursor-default overflow-hidden border-2 border-(--slot) bg-hq-panel-alt shadow-[0_0_0_3px_var(--color-hq-ink)] outline-offset-[5px] hover:shadow-[0_0_0_3px_var(--color-hq-ink),0_0_0_5px_var(--slot)] focus-visible:shadow-[0_0_0_3px_var(--color-hq-ink),0_0_0_5px_var(--slot)] motion-safe:transition-[left,box-shadow] motion-safe:duration-[450ms,150ms] motion-safe:ease-[cubic-bezier(0.16,1,0.3,1)]',
                                                            lit &&
                                                                'shadow-[0_0_0_3px_var(--color-hq-ink),0_0_0_5px_var(--slot)]',
                                                        )}
                                                        style={{
                                                            left: `${x}%`,
                                                        }}
                                                    >
                                                        <EntityImage
                                                            src={player.image}
                                                            alt=""
                                                            fallback={User}
                                                            shape="square"
                                                            className="size-full rounded-none object-cover object-top"
                                                        />
                                                    </span>
                                                    <span
                                                        aria-hidden="true"
                                                        data-bv=""
                                                        className={cn(
                                                            'absolute top-1/2 flex cursor-default items-baseline gap-[7px] font-mono text-[13px] leading-none font-bold whitespace-nowrap tabular-nums motion-safe:transition-[left] motion-safe:duration-[450ms] motion-safe:ease-[cubic-bezier(0.16,1,0.3,1)]',
                                                            x > 62
                                                                ? '-translate-x-[calc(100%+22px)] -translate-y-1/2'
                                                                : 'translate-x-[22px] -translate-y-1/2',
                                                            index === track.best
                                                                ? 'text-hq-lime'
                                                                : 'text-hq-paper',
                                                        )}
                                                        style={{
                                                            left: `${x}%`,
                                                        }}
                                                    >
                                                        <span className="font-sans text-xs font-extrabold text-hq-moss uppercase max-sm:hidden">
                                                            {player.name}
                                                        </span>
                                                        {shownValue(
                                                            metric,
                                                            player,
                                                            derived[index]
                                                                .startTone ===
                                                                'out',
                                                            value,
                                                        )}
                                                        <small
                                                            className={cn(
                                                                'font-mono text-[11px] font-medium',
                                                                index ===
                                                                    track.best
                                                                    ? 'text-hq-moss'
                                                                    : 'text-hq-moss-dim',
                                                            )}
                                                        >
                                                            {rankIn(
                                                                values,
                                                                value,
                                                            )}
                                                            .º de{' '}
                                                            {values.length}
                                                        </small>
                                                    </span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </section>

            <section>
                <div className="border-y border-hq-border-strong bg-hq-panel px-3.5 py-2.5 sm:px-4">
                    <h2 className="hq-label">Lo que no cabe en una pista</h2>
                </div>
                <div
                    className="grid grid-cols-1 min-[900px]:grid-cols-[repeat(var(--cols),minmax(0,1fr))]"
                    style={{ '--cols': players.length } as CSSProperties}
                >
                    {players.map((player, index) => {
                        const item = derived[index];

                        return (
                            <article
                                key={player.id}
                                data-slot={index}
                                {...bind(index)}
                                className="flex min-w-0 flex-col gap-3 border-t-2 border-b border-b-hq-border p-3.5 min-[900px]:border-r min-[900px]:border-b-0 min-[900px]:border-r-hq-border min-[900px]:p-4 min-[900px]:last:border-r-0"
                                style={{
                                    borderTopColor: COMPARE_SLOT_COLORS[index],
                                }}
                            >
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <EntityImage
                                        src={player.image}
                                        alt={player.name}
                                        fallback={User}
                                        shape="square"
                                        className="size-10 shrink-0 rounded-none border border-hq-border-bright bg-hq-panel-alt object-cover object-top"
                                    />
                                    <div className="min-w-0">
                                        <h3 className="truncate text-sm font-black text-hq-paper uppercase">
                                            {player.name}
                                        </h3>
                                        <div className="mt-1 flex flex-wrap items-center gap-1.5 font-mono text-[11px] text-hq-moss">
                                            <HqPositionTag
                                                position={player.position}
                                            />
                                            <EntityImage
                                                src={player.team.logo}
                                                alt=""
                                                fallback={Shield}
                                                shape="square"
                                                className="size-3.5 rounded-none bg-transparent object-contain"
                                            />
                                            <span>
                                                {player.team.short_name}
                                            </span>
                                            <HqStatusBadge
                                                status={player.status}
                                            />
                                        </div>
                                    </div>
                                </div>
                                <dl className="m-0 grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3.5 gap-y-3">
                                    <dt className="hq-label">Últimas 3</dt>
                                    <dd className="m-0">
                                        {item.last3.length === 0 ? (
                                            <span className="font-mono text-xs text-hq-moss-dim">
                                                —
                                            </span>
                                        ) : (
                                            <HqRecentScores
                                                size="sm"
                                                scores={item.last3.map(
                                                    (cell) =>
                                                        cell.score?.points ??
                                                        null,
                                                )}
                                                finished={item.last3.map(
                                                    () => true,
                                                )}
                                                opponents={item.last3.map(
                                                    (cell) =>
                                                        cell.score?.opponent ??
                                                        null,
                                                )}
                                                focusable
                                            />
                                        )}
                                    </dd>
                                    <dt className="hq-label">Próximos</dt>
                                    <dd className="m-0">
                                        <HqNextFixtures
                                            fixtures={player.next_fixtures}
                                            size="md"
                                        />
                                    </dd>
                                    <dt className="hq-label">DAZN</dt>
                                    <dd className="m-0">
                                        {item.daznAverage === null ? (
                                            <span className="font-mono text-xs text-hq-moss-dim">
                                                —
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-2">
                                                <span
                                                    className={cn(
                                                        'px-1.5 py-0.5 font-mono text-xs font-bold tabular-nums',
                                                        daznPointsBadgeClass(
                                                            item.daznAverage,
                                                        ),
                                                    )}
                                                >
                                                    {formatAverage(
                                                        item.daznAverage,
                                                    )}
                                                </span>
                                                <span className="font-mono text-[11px] text-hq-moss-dim">
                                                    media oficial
                                                </span>
                                            </span>
                                        )}
                                    </dd>
                                </dl>
                                <div className="border-t border-dashed border-hq-border pt-3">
                                    <div className="max-sm:hidden">
                                        <CompareProperty
                                            player={player}
                                            derived={item}
                                        />
                                    </div>
                                    <div className="sm:hidden">
                                        <ComparePropertyLine
                                            player={player}
                                            derived={item}
                                        />
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            </section>
            <p className="m-0 px-3.5 py-4 font-mono text-[11px] leading-normal text-hq-moss-dim sm:px-4">
                Pistas en escala lineal salvo valor y pts/M€ (raíz) y subida 30
                días (logarítmica). Titularidad: FútbolFantasy. Próximos:
                dificultad 0–10 según la fuerza del rival. DAZN: puntuación
                oficial.
            </p>
        </div>
    );
}
