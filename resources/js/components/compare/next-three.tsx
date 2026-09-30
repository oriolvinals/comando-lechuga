import { House, Plane, Shield, UserX } from 'lucide-react';
import type { DerivedPlayer } from '@/components/compare/derive';
import { EntityImage } from '@/components/entity-image';
import {
    HqDifficultyBars,
    HqDifficultyTooltip,
} from '@/components/hq-difficulty-bars';
import { COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { formatAverage } from '@/lib/format';
import {
    RIVAL_DIFFICULTY_BG_CLASSES,
    RIVAL_DIFFICULTY_EASY_BELOW,
    RIVAL_DIFFICULTY_HARD_FROM,
    RIVAL_DIFFICULTY_LABELS,
    RIVAL_DIFFICULTY_TEXT_CLASSES,
    formatDifficulty,
    rivalDifficultyLevel,
} from '@/lib/rival-difficulty';
import { cn } from '@/lib/utils';
import type { ComparedPlayer, NextFixtureSlot } from '@/types/models';

/** The "Próximos 3" strip's fácil / media / difícil zones, sized by the rival-difficulty thresholds on the 0–10 scale. */
const DIFFICULTY_ZONES = `${RIVAL_DIFFICULTY_EASY_BELOW}fr ${RIVAL_DIFFICULTY_HARD_FROM - RIVAL_DIFFICULTY_EASY_BELOW}fr ${10 - RIVAL_DIFFICULTY_HARD_FROM}fr`;

/** "sáb 10": a fixture's weekday and day of the month. */
export function shortDay(iso: string): string {
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
export function CompareNextThree({
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
