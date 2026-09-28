import { Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqTooltip } from '@/components/hq-tooltip';
import { formatDecimal, formatMatchDateTime } from '@/lib/format';
import {
    RIVAL_DIFFICULTY_BG_CLASSES,
    RIVAL_DIFFICULTY_LABELS,
    RIVAL_DIFFICULTY_TINT_CLASSES,
    rivalDifficultyLevel,
} from '@/lib/rival-difficulty';
import type { RivalDifficultyLevel } from '@/lib/rival-difficulty';
import { cn } from '@/lib/utils';
import { show as teamsShow } from '@/routes/teams';
import type { FixtureCalendarMatch, FixtureCalendarRow } from '@/types/models';

/** Columns shown per team — matches FixtureCalendar::MATCHES on the backend. */
const MATCH_COLUMNS = 10;

/** Below −0.15 the run is tough, above +0.15 it's kind — amber in between. */
function averageLevel(average: number): RivalDifficultyLevel {
    if (average <= -0.15) {
        return 'hard';
    }

    return average >= 0.15 ? 'easy' : 'mid';
}

const AVERAGE_TEXT_CLASSES: Record<RivalDifficultyLevel, string> = {
    hard: 'text-hq-live',
    mid: 'text-hq-amber',
    easy: 'text-hq-lime',
};

function signedDecimal(value: number): string {
    return `${value > 0 ? '+' : ''}${formatDecimal(value)}`;
}

const CELL =
    'relative flex h-11 w-[62px] shrink-0 flex-col items-center justify-center border';

function MatchCell({ match }: { match: FixtureCalendarMatch }) {
    const level = rivalDifficultyLevel(match.difficulty);
    const venue = match.is_home ? 'En casa' : 'Fuera';

    return (
        <HqTooltip
            focusable
            wrap
            label={
                <>
                    <b className="font-bold">
                        J{match.week_number} · vs {match.opponent.main_name}
                    </b>
                    <br />
                    {venue} · {formatMatchDateTime(match.date)}
                    {match.rescheduled && (
                        <>
                            <br />
                            <span className="text-hq-amber">
                                Aplazado de la J{match.week_number}
                            </span>
                        </>
                    )}
                    <br />
                    <span className="text-hq-moss-dim">Rival</span>{' '}
                    {match.rival_position}º · dificultad{' '}
                    {formatDecimal(match.difficulty)} (
                    {RIVAL_DIFFICULTY_LABELS[level]})
                </>
            }
        >
            <span className={cn(CELL, RIVAL_DIFFICULTY_TINT_CLASSES[level])}>
                <span
                    className={cn(
                        'absolute top-[3px] left-1 font-mono text-[8.5px] leading-none font-bold',
                        match.rescheduled
                            ? 'text-hq-amber'
                            : 'text-hq-moss-dim',
                    )}
                >
                    J{match.week_number}
                </span>
                <span
                    aria-label={venue}
                    className="absolute top-[3px] right-1 font-mono text-[8.5px] leading-none font-bold text-hq-moss-dim"
                >
                    {match.is_home ? 'C' : 'F'}
                </span>
                <EntityImage
                    src={match.opponent.logo}
                    alt=""
                    fallback={Shield}
                    shape="square"
                    className="mt-1.5 size-[18px] rounded-none bg-transparent object-contain"
                />
                <span className="mt-[3px] font-mono text-[9.5px] leading-none font-bold tracking-[0.04em] text-hq-paper uppercase">
                    {match.opponent.short_name}
                </span>
                <span
                    aria-hidden="true"
                    className={cn(
                        'absolute inset-x-[-1px] bottom-[-1px] h-[3px]',
                        RIVAL_DIFFICULTY_BG_CLASSES[level],
                    )}
                />
            </span>
        </HqTooltip>
    );
}

function AverageBar({ average }: { average: number | null }) {
    if (average === null) {
        return <span className="font-mono text-xs text-hq-moss-dim">–</span>;
    }

    const level = averageLevel(average);

    return (
        <span className="inline-flex items-center justify-end gap-1.5">
            <span className="h-1.5 w-[46px] bg-hq-border">
                <span
                    className={cn(
                        'block h-full',
                        RIVAL_DIFFICULTY_BG_CLASSES[level],
                    )}
                    style={{
                        width: `${Math.round(((average + 1) / 2) * 100)}%`,
                    }}
                />
            </span>
            <span
                className={cn(
                    'w-[38px] text-right font-mono text-xs font-bold',
                    AVERAGE_TEXT_CLASSES[level],
                )}
            >
                {signedDecimal(average)}
            </span>
        </span>
    );
}

/**
 * Each team's next 10 scheduled matches in date order, every cell tinted by
 * the rival's current table position (red = top, amber = mid, lime =
 * bottom). A rescheduled match keeps its own jornada label (amber when it
 * comes out of jornada order). Rows arrive sorted easiest run first.
 */
export function FixtureCalendarTable({ rows }: { rows: FixtureCalendarRow[] }) {
    return (
        <>
            <div className="flex flex-wrap items-center gap-x-3.5 gap-y-2 border-b border-hq-border bg-hq-panel px-3.5 py-2.5 font-mono text-[11px] font-semibold tracking-[0.06em] text-hq-moss-dim uppercase sm:px-5">
                <span>
                    Próximos {MATCH_COLUMNS} partidos · más fácil primero
                </span>
                <span className="ml-auto flex items-center gap-2.5">
                    {(['hard', 'mid', 'easy'] as const).map((level) => (
                        <span key={level} className="flex items-center gap-1">
                            <i
                                aria-hidden="true"
                                className={cn(
                                    'inline-block size-2.5',
                                    RIVAL_DIFFICULTY_BG_CLASSES[level],
                                )}
                            />
                            {RIVAL_DIFFICULTY_LABELS[level]}
                        </span>
                    ))}
                </span>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse font-mono text-[13px] tabular-nums">
                    <caption className="sr-only">
                        Próximos {MATCH_COLUMNS} partidos de cada equipo por
                        dificultad del rival
                    </caption>
                    <thead>
                        <tr className="border-b border-hq-border-strong text-[10.5px] tracking-[0.07em] text-hq-moss-dim uppercase">
                            <th className="sticky left-0 z-10 bg-hq-ink px-3.5 py-[9px] text-left font-semibold sm:px-5">
                                Equipo
                            </th>
                            {Array.from(
                                { length: MATCH_COLUMNS },
                                (_, index) => (
                                    <th
                                        key={index}
                                        className="px-1 py-[9px] text-center font-semibold"
                                    >
                                        {index + 1}º
                                    </th>
                                ),
                            )}
                            <th className="px-3.5 py-[9px] text-right font-semibold sm:pr-5">
                                Media
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr
                                key={row.team.id}
                                className="border-b border-hq-border"
                            >
                                <td className="sticky left-0 z-10 bg-hq-ink px-3.5 py-1 whitespace-nowrap sm:px-5">
                                    <Link
                                        href={teamsShow(row.team.id).url}
                                        className="flex min-h-11 items-center gap-2 font-sans text-[13px] font-bold text-hq-paper hover:text-hq-lime"
                                    >
                                        <span className="w-6 font-mono text-[11px] font-semibold text-hq-moss-dim">
                                            {row.position}º
                                        </span>
                                        <EntityImage
                                            src={row.team.logo}
                                            alt=""
                                            fallback={Shield}
                                            shape="square"
                                            className="size-5 shrink-0 rounded-none bg-transparent object-contain"
                                        />
                                        <span className="sm:hidden">
                                            {row.team.short_name}
                                        </span>
                                        <span className="hidden sm:inline">
                                            {row.team.main_name}
                                        </span>
                                    </Link>
                                </td>
                                {Array.from(
                                    { length: MATCH_COLUMNS },
                                    (_, index) => {
                                        const match = row.matches[index];

                                        return (
                                            <td
                                                key={index}
                                                className="px-1 py-1"
                                            >
                                                {match ? (
                                                    <MatchCell match={match} />
                                                ) : (
                                                    <span
                                                        aria-label="Sin partido"
                                                        className={cn(
                                                            CELL,
                                                            'border-dashed border-hq-border-strong font-mono text-[11px] text-hq-moss-dim',
                                                        )}
                                                    >
                                                        –
                                                    </span>
                                                )}
                                            </td>
                                        );
                                    },
                                )}
                                <td className="px-3.5 py-1 text-right sm:pr-5">
                                    <AverageBar average={row.average} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <p className="px-3.5 py-2.5 font-sans text-xs leading-normal text-hq-moss-dim sm:px-5">
                Dificultad según la posición actual del rival en la tabla real
                (líder = más difícil). Media = dificultad media de esos
                partidos: más alta, calendario más fácil. Los aplazados sin
                fecha no cuentan.
            </p>
        </>
    );
}
