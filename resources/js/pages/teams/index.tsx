import { Head, Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqTooltip } from '@/components/hq-tooltip';
import AppLayout from '@/layouts/app-layout';
import { RESULT_BADGE_CLASSES, RESULT_LABEL } from '@/lib/team-fixture-result';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import { show as teamsShow } from '@/routes/teams';
import type { StandingsRow, Team } from '@/types/models';

interface TeamsIndexProps {
    standings: StandingsRow[];
    [key: string]: unknown;
}

/** "BAR - VAL · 5-0" — own team first (matching `score`'s own-perspective order). */
function matchupLabel(own: Team, opponent: Team, score: string): string {
    return `${own.short_name} - ${opponent.short_name} · ${score}`;
}

function LiveBadge({ team, live }: { team: Team; live: StandingsRow['live'] }) {
    if (live === null) {
        return null;
    }

    return (
        <HqTooltip
            label={
                <>
                    <span className="font-bold text-hq-live">
                        EN DIRECTO
                    </span>{' '}
                    {matchupLabel(team, live.opponent, live.score)}
                </>
            }
        >
            <Link
                href={fixturesShow(live.fixture_id).url}
                className={cn(
                    'rounded px-1.5 py-0.5 font-mono text-[11px] font-bold transition-[filter] hover:brightness-125',
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
function FormaStrip({ row }: { row: StandingsRow }) {
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
                    label={
                        <>
                            <span className="font-bold text-hq-live">
                                EN DIRECTO
                            </span>{' '}
                            {matchupLabel(
                                row.team,
                                row.live.opponent,
                                row.live.score,
                            )}
                        </>
                    }
                >
                    <Link
                        href={fixturesShow(row.live.fixture_id).url}
                        className={cn(
                            'relative flex h-5 w-5 items-center justify-center rounded-[3px] border border-hq-live font-mono text-[11px] font-bold transition-[filter] hover:brightness-125',
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
                        <>
                            <span className="font-bold text-hq-moss">
                                PRÓXIMO
                            </span>{' '}
                            {row.team.short_name} -{' '}
                            {row.next.opponent.short_name}
                        </>
                    }
                >
                    <Link
                        href={fixturesShow(row.next.fixture_id).url}
                        className="flex h-5 w-5 items-center justify-center overflow-hidden rounded-[3px] border border-hq-border-strong bg-hq-panel-alt p-0.5 transition-[filter] hover:brightness-125"
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
                    label={matchupLabel(
                        row.team,
                        entry.opponent,
                        entry.score,
                    )}
                >
                    <Link
                        href={fixturesShow(entry.fixture_id).url}
                        className={cn(
                            'flex h-5 w-5 items-center justify-center rounded-[3px] font-mono text-[11px] font-bold transition-[filter] hover:brightness-125',
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
    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto max-w-7xl px-6 py-9">
                <Head title="Equipos" />

                <h1 className="mb-6 font-display text-3xl text-hq-paper uppercase">
                    Equipos
                </h1>

                <div className="hq-card-cut hidden overflow-x-auto xl:block">
                    <table className="w-full min-w-[680px] border-collapse font-mono text-[12px]">
                        <thead>
                            <tr className="border-b border-hq-border text-left text-[10px] text-hq-moss-dim uppercase">
                                <th className="px-3 py-2 text-center">#</th>
                                <th className="px-3 py-2">Equipo</th>
                                <th className="px-2 py-2 text-center">PJ</th>
                                <th className="px-2 py-2 text-center">PG</th>
                                <th className="px-2 py-2 text-center">PE</th>
                                <th className="px-2 py-2 text-center">PP</th>
                                <th className="px-2 py-2 text-center">GF</th>
                                <th className="px-2 py-2 text-center">GC</th>
                                <th className="px-2 py-2 text-center">DG</th>
                                <th className="px-3 py-2 text-center">Pts</th>
                                <th className="px-3 py-2 text-center">Forma</th>
                            </tr>
                        </thead>
                        <tbody>
                            {standings.map((row) => (
                                <tr
                                    key={row.team.id}
                                    className="border-b border-hq-ink last:border-b-0"
                                >
                                    <td className="px-3 py-2 text-center text-hq-moss-dim">
                                        {row.position}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="flex items-center gap-2 font-bold text-hq-paper">
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
                                                    className="h-5 w-5 object-contain"
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
                                                    <span className="h-1.5 w-1.5 shrink-0 animate-pulse rounded-full bg-hq-live" />
                                                    <LiveBadge
                                                        team={row.team}
                                                        live={row.live}
                                                    />
                                                </>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-2 py-2 text-center text-hq-paper">
                                        {row.played}
                                    </td>
                                    <td className="px-2 py-2 text-center text-hq-paper">
                                        {row.won}
                                    </td>
                                    <td className="px-2 py-2 text-center text-hq-paper">
                                        {row.drawn}
                                    </td>
                                    <td className="px-2 py-2 text-center text-hq-paper">
                                        {row.lost}
                                    </td>
                                    <td className="px-2 py-2 text-center text-hq-moss">
                                        {row.goals_for}
                                    </td>
                                    <td className="px-2 py-2 text-center text-hq-moss">
                                        {row.goals_against}
                                    </td>
                                    <td className="px-2 py-2 text-center text-hq-paper">
                                        {row.goal_difference > 0 ? '+' : ''}
                                        {row.goal_difference}
                                    </td>
                                    <td className="px-3 py-2 text-center font-bold text-hq-lime">
                                        {row.points}
                                    </td>
                                    <td className="px-3 py-2">
                                        <FormaStrip row={row} />
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
                            className="hq-card-cut mb-1.5 px-3.5 py-2.5"
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
                                <FormaStrip row={row} />
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
