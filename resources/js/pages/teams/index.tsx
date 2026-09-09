import { Head, Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqTooltip } from '@/components/hq-tooltip';
import AppLayout from '@/layouts/app-layout';
import { formatMatchDateShort } from '@/lib/format';
import {
    RESULT_BADGE_CLASSES,
    RESULT_BORDER_CLASSES,
    RESULT_LABEL,
} from '@/lib/team-fixture-result';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import { show as teamsShow } from '@/routes/teams';
import type { StandingsRow, Team } from '@/types/models';

interface TeamsIndexProps {
    standings: StandingsRow[];
    [key: string]: unknown;
}

/** The two teams a hovered match belongs to — both their rows get highlighted. */
type HoveredMatch = [number, number] | null;

/** "5-0 (BAR - ELC)", own team first, plus the match date on its own line. */
function MatchTooltip({
    prefix,
    own,
    opponent,
    score,
    date,
}: {
    prefix?: string;
    own: Team;
    opponent: Team;
    score?: string;
    date: string;
}) {
    return (
        <div className="text-center">
            {prefix && (
                <div className="font-bold text-hq-live">{prefix}</div>
            )}
            <div>
                {score && <span className="font-bold">{score}</span>}{' '}
                <span className="text-hq-moss">
                    ({own.short_name} - {opponent.short_name})
                </span>
            </div>
            <div className="mt-0.5 text-[10px] text-hq-moss-dim">
                {formatMatchDateShort(date)}
            </div>
        </div>
    );
}

function LiveBadge({
    team,
    live,
    onHover,
}: {
    team: Team;
    live: StandingsRow['live'];
    onHover: (match: HoveredMatch) => void;
}) {
    if (live === null) {
        return null;
    }

    return (
        <HqTooltip
            borderClassName={RESULT_BORDER_CLASSES[live.result]}
            label={
                <MatchTooltip
                    prefix="EN DIRECTO"
                    own={team}
                    opponent={live.opponent}
                    score={live.score}
                    date={live.date}
                />
            }
        >
            <Link
                href={fixturesShow(live.fixture_id).url}
                onMouseEnter={() => onHover([team.id, live.opponent.id])}
                onMouseLeave={() => onHover(null)}
                className={cn(
                    'rounded px-1.5 py-0.5 font-mono text-[11px] font-bold transition-[filter] hover:brightness-125 xl:px-2 xl:py-1 xl:text-[12px]',
                    RESULT_BADGE_CLASSES[live.result],
                )}
            >
                {live.score}
            </Link>
        </HqTooltip>
    );
}

/**
 * The Forma column's leading slot: this team's live match (if any), else its
 * next scheduled one, else nothing — followed by up to 4 finished results.
 */
function FormaStrip({
    row,
    onHover,
}: {
    row: StandingsRow;
    onHover: (match: HoveredMatch) => void;
}) {
    if (
        row.live === null &&
        row.next === null &&
        row.recent_form.length === 0
    ) {
        return <span className="text-hq-moss-dim">–</span>;
    }

    return (
        <div className="flex items-center justify-center gap-1">
            {row.live && (
                <HqTooltip
                    borderClassName={RESULT_BORDER_CLASSES[row.live.result]}
                    label={
                        <MatchTooltip
                            prefix="EN DIRECTO"
                            own={row.team}
                            opponent={row.live.opponent}
                            score={row.live.score}
                            date={row.live.date}
                        />
                    }
                >
                    <Link
                        href={fixturesShow(row.live.fixture_id).url}
                        onMouseEnter={() =>
                            onHover([row.team.id, row.live!.opponent.id])
                        }
                        onMouseLeave={() => onHover(null)}
                        className={cn(
                            'relative flex h-5 w-5 items-center justify-center rounded-[3px] border border-hq-live font-mono text-[11px] font-bold transition-[filter] hover:brightness-125 xl:h-6 xl:w-6 xl:text-[12px]',
                            RESULT_BADGE_CLASSES[row.live.result],
                        )}
                    >
                        {RESULT_LABEL[row.live.result]}
                        <span className="absolute -top-1 -right-1 h-1.5 w-1.5 animate-pulse rounded-full bg-hq-live ring-2 ring-hq-panel" />
                    </Link>
                </HqTooltip>
            )}
            {row.live === null && row.next && (
                <HqTooltip
                    label={
                        <MatchTooltip
                            prefix="PRÓXIMO"
                            own={row.team}
                            opponent={row.next.opponent}
                            date={row.next.date}
                        />
                    }
                >
                    <Link
                        href={fixturesShow(row.next.fixture_id).url}
                        onMouseEnter={() =>
                            onHover([row.team.id, row.next!.opponent.id])
                        }
                        onMouseLeave={() => onHover(null)}
                        className="flex h-5 w-5 items-center justify-center overflow-hidden rounded-[3px] border border-hq-border-strong bg-hq-panel-alt p-0.5 transition-[filter] hover:brightness-125 xl:h-6 xl:w-6"
                    >
                        <EntityImage
                            src={row.next.opponent.logo}
                            alt={row.next.opponent.main_name}
                            fallback={Shield}
                            shape="square"
                            className="h-full w-full"
                        />
                    </Link>
                </HqTooltip>
            )}
            {row.recent_form.map((entry) => (
                <HqTooltip
                    key={entry.fixture_id}
                    borderClassName={RESULT_BORDER_CLASSES[entry.result]}
                    label={
                        <MatchTooltip
                            own={row.team}
                            opponent={entry.opponent}
                            score={entry.score}
                            date={entry.date}
                        />
                    }
                >
                    <Link
                        href={fixturesShow(entry.fixture_id).url}
                        onMouseEnter={() =>
                            onHover([row.team.id, entry.opponent.id])
                        }
                        onMouseLeave={() => onHover(null)}
                        className={cn(
                            'flex h-5 w-5 items-center justify-center rounded-[3px] font-mono text-[11px] font-bold transition-[filter] hover:brightness-125 xl:h-6 xl:w-6 xl:text-[12px]',
                            RESULT_BADGE_CLASSES[entry.result],
                        )}
                    >
                        {RESULT_LABEL[entry.result]}
                    </Link>
                </HqTooltip>
            ))}
        </div>
    );
}

export default function TeamsIndex({ standings }: TeamsIndexProps) {
    const [hoveredMatch, setHoveredMatch] = useState<HoveredMatch>(null);

    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto max-w-7xl px-6 py-9">
                <Head title="Equipos" />

                <h1 className="mb-6 font-display text-3xl text-hq-paper uppercase">
                    Equipos
                </h1>

                <div className="hq-card-cut hidden overflow-x-auto xl:block">
                    <table className="w-full min-w-[760px] border-collapse font-mono text-[14px]">
                        <thead>
                            <tr className="border-b border-hq-border text-left text-[11px] text-hq-moss-dim uppercase">
                                <th className="px-3 py-3 text-center">#</th>
                                <th className="px-3 py-3">Equipo</th>
                                <th className="px-2 py-3 text-center">PJ</th>
                                <th className="px-2 py-3 text-center">PG</th>
                                <th className="px-2 py-3 text-center">PE</th>
                                <th className="px-2 py-3 text-center">PP</th>
                                <th className="px-2 py-3 text-center">GF</th>
                                <th className="px-2 py-3 text-center">GC</th>
                                <th className="px-2 py-3 text-center">DG</th>
                                <th className="px-3 py-3 text-center">Pts</th>
                                <th className="px-3 py-3 text-center">Forma</th>
                            </tr>
                        </thead>
                        <tbody>
                            {standings.map((row) => (
                                <tr
                                    key={row.team.id}
                                    className={cn(
                                        'border-b border-hq-ink transition-colors last:border-b-0',
                                        hoveredMatch?.includes(row.team.id) &&
                                            'bg-hq-lime/5',
                                    )}
                                >
                                    <td className="px-3 py-3 text-center text-hq-moss-dim">
                                        {row.position}
                                    </td>
                                    <td className="px-3 py-3">
                                        <div className="flex items-center gap-2.5 font-bold text-hq-paper">
                                            <Link
                                                href={
                                                    teamsShow(row.team.id).url
                                                }
                                                className="flex shrink-0 items-center hover:text-hq-lime"
                                            >
                                                <EntityImage
                                                    src={row.team.logo}
                                                    alt={row.team.main_name}
                                                    fallback={Shield}
                                                    shape="square"
                                                    className="h-6 w-6 object-contain"
                                                />
                                            </Link>
                                            <Link
                                                href={
                                                    teamsShow(row.team.id).url
                                                }
                                                className="hover:text-hq-lime"
                                            >
                                                {row.team.main_name}
                                            </Link>
                                            {row.live && (
                                                <>
                                                    <span className="h-2 w-2 shrink-0 animate-pulse rounded-full bg-hq-live" />
                                                    <LiveBadge
                                                        team={row.team}
                                                        live={row.live}
                                                        onHover={
                                                            setHoveredMatch
                                                        }
                                                    />
                                                </>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-2 py-3 text-center text-hq-paper">
                                        {row.played}
                                    </td>
                                    <td className="px-2 py-3 text-center text-hq-paper">
                                        {row.won}
                                    </td>
                                    <td className="px-2 py-3 text-center text-hq-paper">
                                        {row.drawn}
                                    </td>
                                    <td className="px-2 py-3 text-center text-hq-paper">
                                        {row.lost}
                                    </td>
                                    <td className="px-2 py-3 text-center text-hq-moss">
                                        {row.goals_for}
                                    </td>
                                    <td className="px-2 py-3 text-center text-hq-moss">
                                        {row.goals_against}
                                    </td>
                                    <td className="px-2 py-3 text-center text-hq-paper">
                                        {row.goal_difference > 0 ? '+' : ''}
                                        {row.goal_difference}
                                    </td>
                                    <td className="px-3 py-3 text-center font-display text-lg text-hq-lime">
                                        {row.points}
                                    </td>
                                    <td className="px-3 py-3">
                                        <FormaStrip
                                            row={row}
                                            onHover={setHoveredMatch}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="xl:hidden">
                    {standings.map((row) => (
                        <div
                            key={row.team.id}
                            className={cn(
                                'hq-card-cut mb-1.5 px-3.5 py-2.5 transition-colors',
                                hoveredMatch?.includes(row.team.id) &&
                                    'bg-hq-lime/5',
                            )}
                        >
                            <Link
                                href={teamsShow(row.team.id).url}
                                className="flex items-center justify-between transition-[filter] hover:brightness-125"
                            >
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <span className="w-5 shrink-0 text-center font-mono text-[11px] text-hq-moss-dim">
                                        {row.position}
                                    </span>
                                    <EntityImage
                                        src={row.team.logo}
                                        alt={row.team.main_name}
                                        fallback={Shield}
                                        shape="square"
                                        className="h-6 w-6 shrink-0"
                                    />
                                    <div className="min-w-0">
                                        <p className="flex items-center gap-1.5 truncate text-[13px] font-bold text-hq-paper">
                                            {row.team.short_name}
                                            {row.live && (
                                                <span className="h-1.5 w-1.5 shrink-0 animate-pulse rounded-full bg-hq-live" />
                                            )}
                                        </p>
                                        <p className="font-mono text-[10px] text-hq-moss-dim">
                                            PJ {row.played} · DG{' '}
                                            {row.goal_difference > 0
                                                ? '+'
                                                : ''}
                                            {row.goal_difference}
                                        </p>
                                    </div>
                                </div>
                                <span className="shrink-0 font-display text-lg text-hq-lime">
                                    {row.points}
                                </span>
                            </Link>
                            <div className="mt-2 flex items-center justify-between gap-2 border-t border-hq-ink pt-2">
                                <FormaStrip
                                    row={row}
                                    onHover={setHoveredMatch}
                                />
                                {row.live && (
                                    <span
                                        className={cn(
                                            'rounded px-1.5 py-0.5 font-mono text-[11px] font-bold',
                                            RESULT_BADGE_CLASSES[
                                                row.live.result
                                            ],
                                        )}
                                    >
                                        {row.live.score}
                                    </span>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

TeamsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
