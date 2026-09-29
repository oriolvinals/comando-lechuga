import { Link, usePage } from '@inertiajs/react';
import { Shield, UserX } from 'lucide-react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import {
    HqDifficultyBars,
    HqDifficultyTooltip,
} from '@/components/hq-difficulty-bars';
import { formatMatchDateTime } from '@/lib/format';
import {
    RIVAL_DIFFICULTY_BG_CLASSES,
    RIVAL_DIFFICULTY_LABELS,
    RIVAL_DIFFICULTY_TINT_CLASSES,
    formatDifficulty,
    rivalDifficultyLevel,
} from '@/lib/rival-difficulty';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import { show as teamsShow } from '@/routes/teams';
import type { FixtureCalendarMatch, FixtureCalendarRow } from '@/types/models';

/** How many matches per team can be shown — 10 matches FixtureCalendar::MATCHES on the backend. */
type MatchCount = 5 | 10;

const MATCH_COUNTS: MatchCount[] = [5, 10];

/** The `?partidos` query parameter that remembers the 5/10 choice. */
const MATCH_COUNT_PARAM = 'partidos';

function matchCountFromUrl(url: string): MatchCount {
    const query = url.split('?')[1] ?? '';

    return new URLSearchParams(query).get(MATCH_COUNT_PARAM) === '5' ? 5 : 10;
}

/** Mean 0–10 difficulty of the rated matches, or null with none. */
function averageDifficulty(matches: FixtureCalendarMatch[]): number | null {
    const difficulties = matches
        .map((match) => match.difficulty)
        .filter((difficulty): difficulty is number => difficulty !== null);

    if (difficulties.length === 0) {
        return null;
    }

    return (
        difficulties.reduce((sum, difficulty) => sum + difficulty, 0) /
        difficulties.length
    );
}

interface VisibleCalendarRow {
    row: FixtureCalendarRow;
    matches: FixtureCalendarMatch[];
    average: number | null;
}

/**
 * The rows cut to `matchCount` matches. With all 10 the backend's average and
 * order stand; with 5 the average is recomputed over those 5 and the rows
 * re-sorted easiest first (stable, so ties keep the backend order; a team with
 * no rated match goes last).
 */
function visibleRows(
    rows: FixtureCalendarRow[],
    matchCount: MatchCount,
): VisibleCalendarRow[] {
    if (matchCount === 10) {
        return rows.map((row) => ({
            row,
            matches: row.matches,
            average: row.average,
        }));
    }

    return rows
        .map((row) => {
            const matches = row.matches.slice(0, matchCount);

            return { row, matches, average: averageDifficulty(matches) };
        })
        .sort(
            (a, b) =>
                (a.average ?? Number.POSITIVE_INFINITY) -
                (b.average ?? Number.POSITIVE_INFINITY),
        );
}

/** A match cell: jornada and C/F on top, crest, rival, then the bars with the number under them. */
const CELL =
    'relative flex h-[66px] w-[62px] shrink-0 flex-col items-center border';

/**
 * Every match column is this fixed width — the 62px cell plus 2px either
 * side, so neighbouring cells sit 4px apart — with its heading centered over
 * it. The leftover row width goes to a filler column at the end.
 */
const MATCH_COLUMN = 'w-[66px] min-w-[66px] px-0.5';

function MatchCell({ match }: { match: FixtureCalendarMatch }) {
    const level =
        match.difficulty === null
            ? null
            : rivalDifficultyLevel(match.difficulty);
    const venue = match.is_home ? 'En casa' : 'Fuera';
    const label = [
        `J${match.week_number}`,
        `${venue} contra ${match.opponent.main_name}`,
        match.difficulty === null
            ? 'sin dificultad'
            : `dificultad ${formatDifficulty(match.difficulty)} sobre 10`,
        ...(match.absence_adjusted === true ? ['bajas del rival'] : []),
    ].join(', ');

    return (
        <HqDifficultyTooltip
            match={match}
            focusable={false}
            details={
                <>
                    <br />
                    {formatMatchDateTime(match.date)}
                    {match.rescheduled && (
                        <>
                            <br />
                            <span className="text-hq-amber">
                                Aplazado de la J{match.week_number}
                            </span>
                        </>
                    )}
                </>
            }
        >
            <Link
                href={fixturesShow(match.fixture_id).url}
                aria-label={label}
                className={cn(
                    CELL,
                    'cursor-pointer transition-[filter] hover:brightness-125 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-hq-lime',
                    level === null
                        ? 'border-hq-border-strong bg-hq-panel-alt'
                        : RIVAL_DIFFICULTY_TINT_CLASSES[level],
                )}
            >
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
                <span className="absolute top-[3px] right-1 font-mono text-[8.5px] leading-none font-bold text-hq-moss-dim">
                    {match.is_home ? 'C' : 'F'}
                </span>
                {match.absence_adjusted === true && (
                    <UserX
                        aria-hidden="true"
                        className="absolute top-[13px] right-[3px] size-[9px] text-hq-moss"
                        strokeWidth={2.4}
                    />
                )}
                <EntityImage
                    src={match.opponent.logo}
                    alt=""
                    fallback={Shield}
                    shape="square"
                    className="mt-3 size-[18px] rounded-none bg-transparent object-contain"
                />
                <span className="mt-[3px] font-mono text-[9.5px] leading-none font-bold tracking-[0.04em] text-hq-paper uppercase">
                    {match.opponent.short_name}
                </span>
                {match.difficulty !== null && (
                    <HqDifficultyBars
                        difficulty={match.difficulty}
                        layout="inline"
                        className="mt-1 px-[5px]"
                    />
                )}
            </Link>
        </HqDifficultyTooltip>
    );
}

function AverageGauge({ average }: { average: number | null }) {
    if (average === null) {
        return <span className="font-mono text-xs text-hq-moss-dim">–</span>;
    }

    return (
        <span className="inline-flex justify-center">
            <HqDifficultyBars difficulty={average} layout="gauge" />
            <span className="sr-only">
                Media {formatDifficulty(average)} sobre 10,{' '}
                {RIVAL_DIFFICULTY_LABELS[rivalDifficultyLevel(average)]}
            </span>
        </span>
    );
}

/**
 * The 5/10 switch. The page already has all 10 matches, so it only changes
 * local state and rewrites `?partidos` in the address bar (keeping Inertia's
 * history state) — no request.
 */
function MatchCountToggle({
    matchCount,
    onChange,
}: {
    matchCount: MatchCount;
    onChange: (next: MatchCount) => void;
}) {
    const select = (next: MatchCount) => {
        if (next === matchCount) {
            return;
        }

        const url = new URL(window.location.href);

        if (next === 10) {
            url.searchParams.delete(MATCH_COUNT_PARAM);
        } else {
            url.searchParams.set(MATCH_COUNT_PARAM, String(next));
        }

        window.history.replaceState(
            window.history.state,
            '',
            `${url.pathname}${url.search}${url.hash}`,
        );
        onChange(next);
    };

    return (
        <div
            role="group"
            aria-label="Partidos por equipo"
            className="inline-flex shrink-0 border border-hq-border-strong bg-hq-ink"
        >
            {MATCH_COUNTS.map((option) => (
                <button
                    key={option}
                    type="button"
                    onClick={() => select(option)}
                    aria-pressed={matchCount === option}
                    className={cn(
                        'min-h-11 cursor-pointer border-l border-hq-border-strong px-3 font-mono text-[11px] leading-none font-bold tracking-[0.05em] uppercase first:border-l-0 sm:min-h-[28px] sm:px-2.5',
                        matchCount === option
                            ? 'bg-hq-lime text-hq-ink'
                            : 'text-hq-moss hover:text-hq-paper',
                    )}
                >
                    {option}
                </button>
            ))}
        </div>
    );
}

/**
 * Each team's next 5 or 10 scheduled matches in date order (`?partidos=5`
 * picks 5), every cell tinted by its 0–10 difficulty level (lime easy, amber
 * mid, red hard) with the 5-bar gauge and the number under it; each cell
 * opens that match's ficha. A rescheduled match keeps its own jornada label
 * (amber when it comes out of jornada order). Rows are sorted easiest run
 * first — lowest average. The fixed-width match columns sit packed next to
 * the team, with the average right after them; a filler column takes the
 * leftover width at the end of the row.
 */
export function FixtureCalendarTable({ rows }: { rows: FixtureCalendarRow[] }) {
    const { url } = usePage();
    const [matchCount, setMatchCount] = useState<MatchCount>(() =>
        matchCountFromUrl(url),
    );
    const visible = visibleRows(rows, matchCount);

    return (
        <>
            <div className="flex flex-wrap items-center gap-x-3.5 gap-y-2 border-b border-hq-border bg-hq-panel px-3.5 py-2.5 font-mono text-[11px] font-semibold tracking-[0.06em] text-hq-moss-dim uppercase sm:px-5">
                <MatchCountToggle
                    matchCount={matchCount}
                    onChange={setMatchCount}
                />
                <span>Próximos {matchCount} partidos · más fácil primero</span>
                <span className="ml-auto flex items-center gap-2.5">
                    {(['easy', 'mid', 'hard'] as const).map((level) => (
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

            {/* relative: keeps the sr-only (absolute) labels inside the scroll box, not widening the page. */}
            <div className="relative overflow-x-auto">
                <table className="w-full border-collapse font-mono text-[13px] tabular-nums">
                    <caption className="sr-only">
                        Próximos {matchCount} partidos de cada equipo por
                        dificultad
                    </caption>
                    <thead>
                        <tr className="border-b border-hq-border-strong text-[10.5px] tracking-[0.07em] text-hq-moss-dim uppercase">
                            <th className="sticky left-0 z-10 bg-hq-ink px-3.5 py-[9px] text-left font-semibold sm:px-5">
                                Equipo
                            </th>
                            {Array.from({ length: matchCount }, (_, index) => (
                                <th
                                    key={index}
                                    className={cn(
                                        MATCH_COLUMN,
                                        'py-[9px] text-center font-semibold',
                                    )}
                                >
                                    {index + 1}º
                                </th>
                            ))}
                            <th className="px-3.5 py-[9px] text-center font-semibold whitespace-nowrap">
                                Media
                            </th>
                            <td aria-hidden="true" className="w-full p-0" />
                        </tr>
                    </thead>
                    <tbody>
                        {visible.map(({ row, matches, average }) => (
                            <tr
                                key={row.team.id}
                                className="border-b border-hq-border"
                            >
                                <td className="sticky left-0 z-10 bg-hq-ink px-3.5 py-1 whitespace-nowrap sm:px-5">
                                    <Link
                                        href={teamsShow(row.team.id).url}
                                        className="flex min-h-11 cursor-pointer items-center gap-2 font-sans text-[13px] font-bold text-hq-paper hover:text-hq-lime"
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
                                    { length: matchCount },
                                    (_, index) => {
                                        const match = matches[index];

                                        return (
                                            <td
                                                key={index}
                                                className={cn(
                                                    MATCH_COLUMN,
                                                    'py-1 text-center',
                                                )}
                                            >
                                                {match ? (
                                                    <MatchCell match={match} />
                                                ) : (
                                                    <span
                                                        aria-label="Sin partido"
                                                        className={cn(
                                                            CELL,
                                                            'inline-flex justify-center border-dashed border-hq-border-strong font-mono text-[11px] text-hq-moss-dim',
                                                        )}
                                                    >
                                                        –
                                                    </span>
                                                )}
                                            </td>
                                        );
                                    },
                                )}
                                <td className="px-3.5 py-1 text-center">
                                    <AverageGauge average={average} />
                                </td>
                                <td aria-hidden="true" className="p-0" />
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}
