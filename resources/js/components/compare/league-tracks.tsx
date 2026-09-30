import { User } from 'lucide-react';
import type {
    CSSProperties,
    FocusEvent,
    KeyboardEvent,
    MouseEvent,
    PointerEvent,
} from 'react';
import { memo, useRef, useState } from 'react';
import { TipRow, useChartTooltip } from '@/components/compare/chart-tooltip';
import { useCompare } from '@/components/compare/compare-context';
import {
    COMPARE_GRID,
    COMPARE_SPAN,
    CompareSectionHeader,
    CompareToggleGroup,
    RowLabel,
} from '@/components/compare/compare-grid';
import type {
    LeagueTrack,
    TrackDot,
    TrackMetric,
    TrackScope,
} from '@/components/compare/derive';
import {
    beatsPercent,
    rankIn,
    trackPosition,
} from '@/components/compare/derive';
import { EntityImage } from '@/components/entity-image';
import { COMPARE_MAX, COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import {
    POSITION_ABBREVIATIONS,
    STATUS_SHORT_LABELS,
} from '@/lib/player-labels';
import { useMediaQuery } from '@/lib/use-media-query';
import { cn } from '@/lib/utils';
import type { ComparedPlayer } from '@/types/models';

/** Height of the league dot cloud and of each compared player's lane (mock `.dust` / `.lane`). */
const DUST_HEIGHT = 26;
const LANE_HEIGHT = 34;
/** Space between the top of the track and the first lane (cloud, gap, lanes padding). */
const LANES_TOP = DUST_HEIGHT + 8 + 10;

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
 * En la liga: one track per metric spanning the player columns, with the
 * whole league as a dot cloud, its median and range, and one lane per
 * compared player with his photo marker, value and "N.º de N". Scrubbing a
 * cloud names the nearest league player and a click adds him (when there's
 * room); the markers read out value, rank and "supera al". One tab stop for
 * every marker: ↑/↓ track, ←/→ player, Inicio/Fin.
 */
export function CompareLeagueTracks({
    tracks,
    scope,
    onScopeChange,
}: {
    tracks: LeagueTrack[];
    scope: TrackScope;
    onScopeChange: (scope: TrackScope) => void;
}) {
    const { players, derived, add, announce, highlighted, bindSlot } =
        useCompare();
    const { show, hide } = useChartTooltip();
    const [focus, setFocus] = useState({ track: 0, player: 0 });
    const [hover, setHover] = useState<HoverState | null>(null);
    const tracksRef = useRef<HTMLDivElement>(null);
    const phone = useMediaQuery('(max-width: 639px)');
    const samePosition = players.every(
        (player) => player.position === players[0].position,
    );
    const scopeLabel = scope === 'position' ? 'de su puesto' : 'de la liga';
    const hasRoom = players.length < COMPARE_MAX;
    const isCompared = (id: number) =>
        players.some((player) => player.id === id);

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
        const highlight = bindSlot(playerIndex);
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
        const playerMoves: Record<string, number> = {
            ArrowLeft: Math.max(0, current.player - 1),
            ArrowRight: Math.min(last, current.player + 1),
            Home: 0,
            End: last,
        };
        const trackSteps: Record<string, number> = {
            ArrowUp: -1,
            ArrowDown: 1,
        };
        const step = trackSteps[event.key];

        if (!(event.key in playerMoves) && step === undefined) {
            return;
        }

        event.preventDefault();
        const markersOn = (track: number) =>
            Array.from(
                tracksRef.current?.querySelectorAll<HTMLElement>(
                    `[data-bm="${track}"]`,
                ) ?? [],
            );
        const player = playerMoves[event.key] ?? current.player;
        let onTrack = markersOn(current.track);

        // ↑/↓ skip tracks where nobody has a value; at either end, stay put.
        if (step !== undefined) {
            onTrack = [];

            for (
                let candidate = current.track + step;
                candidate >= 0 && candidate < tracks.length;
                candidate += step
            ) {
                const found = markersOn(candidate);

                if (found.length > 0) {
                    onTrack = found;
                    break;
                }
            }
        }

        const target =
            onTrack.find((element) => Number(element.dataset.bi) === player) ??
            onTrack[Math.min(player, onTrack.length - 1)];

        target?.focus();
    };

    /** Nearest league player to the pointer on this track; ties go to the one with more points. */
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
        <section aria-labelledby="s-liga-title">
            <CompareSectionHeader id="s-liga" title="En la liga">
                <span className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <span className="flex flex-wrap items-center gap-x-3.5 gap-y-1 font-mono text-[11px] leading-normal text-hq-moss">
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                aria-hidden="true"
                                className="inline-flex gap-0.5"
                            >
                                {[0, 1, 2, 3].map((tick) => (
                                    <i
                                        key={tick}
                                        className="h-2.5 w-0.5 bg-hq-olive opacity-55"
                                    />
                                ))}
                            </span>
                            cada marca, un jugador
                        </span>
                        <span className="text-hq-lime">mejor →</span>
                    </span>
                    <CompareToggleGroup
                        label="Población de referencia"
                        value={scope}
                        onChange={(next) => {
                            onScopeChange(next);
                            setHover(null);
                        }}
                        options={[
                            { value: 'all', label: 'Toda la liga' },
                            {
                                value: 'position',
                                label: samePosition
                                    ? `Solo ${POSITION_ABBREVIATIONS[players[0].position]}`
                                    : 'Su puesto',
                            },
                        ]}
                    />
                </span>
            </CompareSectionHeader>
            <div
                ref={tracksRef}
                role="group"
                aria-label="Pistas de la liga. Flechas arriba y abajo para cambiar de pista; izquierda y derecha para cambiar de jugador."
                onKeyDown={onTracksKeyDown}
            >
                {tracks.map((track, trackIndex) => {
                    const { metric, values, domain, median, dots } = track;
                    const hovering = hover?.track === trackIndex ? hover : null;
                    const canAddHovered =
                        hovering !== null &&
                        hasRoom &&
                        !isCompared(hovering.dot.row.id);

                    return (
                        <div
                            key={metric.key}
                            className={cn(
                                COMPARE_GRID,
                                'border-b border-hq-border',
                            )}
                        >
                            <RowLabel label={metric.label} hint={metric.note} />
                            <div className={cn(COMPARE_SPAN, 'min-w-0')}>
                                <div
                                    className={cn(
                                        'relative mx-3.5 mt-2 mb-3.5 touch-pan-y sm:mx-4 sm:mt-4 sm:mb-[18px]',
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
                                            // The value goes left of the marker past this point, earlier on narrow tracks.
                                            const flip = x > (phone ? 45 : 62);

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
                                                        aria-label={
                                                            tip?.text ??
                                                            player.name
                                                        }
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
                                                            flip
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
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
