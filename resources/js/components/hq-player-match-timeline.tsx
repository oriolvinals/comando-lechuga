import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useState } from 'react';
import { HqJornadaStatsGrid } from '@/components/hq-jornada-stats-grid';
import { HqScrollRow } from '@/components/hq-scroll-row';
import { MatchEventIcons } from '@/components/match-event-icons';
import { formatMatchDateTime } from '@/lib/format';
import { didNotPlayMatch } from '@/lib/player-labels';
import { matchPointsBadgeClass } from '@/lib/points';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { Fixture, PlayerFichaScore, PlayerPosition } from '@/types/models';

interface HqPlayerMatchTimelineProps {
    scores: PlayerFichaScore[];
    teamFixtures: Fixture[];
    currentWeek: number;
    playerPosition: PlayerPosition;
    teamId: number;
}

export function HqPlayerMatchTimeline({
    scores,
    teamFixtures,
    currentWeek,
    playerPosition,
    teamId,
}: HqPlayerMatchTimelineProps) {
    const [selectedWeek, setSelectedWeek] = useState(currentWeek);
    const scoresByWeek = new Map(
        scores.map((score) => [score.fixture.week_number, score]),
    );
    const fixturesByWeek = new Map(
        teamFixtures.map((fixture) => [fixture.week_number, fixture]),
    );
    const selectedScore = scoresByWeek.get(selectedWeek) ?? null;
    const selectedFixture = fixturesByWeek.get(selectedWeek) ?? null;

    return (
        <div>
            <h2 className="mb-3 font-display text-lg tracking-wide text-hq-paper uppercase">
                Partidos
            </h2>

            <HqScrollRow contentClassName="px-1 py-1 pb-3">
                {Array.from(
                    { length: currentWeek },
                    (_, index) => index + 1,
                ).map((week) => {
                    const score = scoresByWeek.get(week);
                    const fixture = fixturesByWeek.get(week);
                    const notCalledUp = !score && fixture?.state === 'finished';
                    const opponent = fixture
                        ? fixture.local_team.id === teamId
                            ? fixture.guest_team
                            : fixture.local_team
                        : null;

                    return (
                        <button
                            key={week}
                            type="button"
                            onClick={() => setSelectedWeek(week)}
                            className={cn(
                                'relative flex h-14 w-14 shrink-0 cursor-pointer flex-col items-center justify-center border-2 font-mono',
                                selectedWeek === week
                                    ? 'border-hq-paper'
                                    : 'border-transparent hover:border-hq-border-strong',
                                score && score.points !== null
                                    ? matchPointsBadgeClass(score.points)
                                    : notCalledUp
                                      ? 'border-dashed border-hq-live text-hq-live'
                                      : 'border-dashed border-hq-border-strong text-hq-moss-dim',
                            )}
                        >
                            <span className="text-[10px] font-bold opacity-80">
                                J{week}
                            </span>
                            <span className="font-display text-lg leading-none">
                                {score && score.points !== null
                                    ? score.points
                                    : notCalledUp
                                      ? 'NC'
                                      : '—'}
                            </span>
                            {opponent && (
                                <img
                                    src={opponent.logo}
                                    alt={opponent.main_name}
                                    title={opponent.main_name}
                                    className="absolute -bottom-2 left-1/2 h-3.5 w-3.5 -translate-x-1/2 object-contain drop-shadow-[0_1px_2px_rgba(0,0,0,0.9)]"
                                />
                            )}
                        </button>
                    );
                })}
            </HqScrollRow>

            {selectedScore ? (
                (() => {
                    const isHome =
                        selectedScore.team_id ===
                        selectedScore.fixture.local_team.id;
                    const scoreOpponent = isHome
                        ? selectedScore.fixture.guest_team
                        : selectedScore.fixture.local_team;

                    return (
                        <div className="hq-card-cut">
                            <div className="flex items-center gap-3.5 border-b border-hq-border bg-gradient-to-br from-hq-lime/5 to-transparent p-4">
                                <img
                                    src={scoreOpponent.logo}
                                    alt={scoreOpponent.main_name}
                                    className="h-11 w-11 shrink-0 object-contain"
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-baseline gap-2">
                                        <span className="font-display text-xs tracking-wide text-hq-moss uppercase">
                                            J{selectedWeek}
                                        </span>
                                        <span className="truncate font-mono text-[13px] font-bold text-hq-paper">
                                            vs {scoreOpponent.main_name}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-2 font-mono text-[11px] text-hq-moss-dim">
                                        <span className="font-bold text-hq-khaki">
                                            {
                                                selectedScore.fixture.local_team
                                                    .short_name
                                            }{' '}
                                            {selectedScore.fixture.local_score}–
                                            {selectedScore.fixture.guest_score}{' '}
                                            {
                                                selectedScore.fixture.guest_team
                                                    .short_name
                                            }
                                        </span>
                                        <span className="border border-hq-border-strong px-1 py-px text-[9px] font-bold tracking-wide uppercase">
                                            {isHome ? 'Casa' : 'Fuera'}
                                        </span>
                                    </div>
                                </div>
                                <div className="shrink-0 text-right">
                                    <p
                                        className={cn(
                                            'font-display text-3xl leading-none',
                                            selectedScore.points !== null
                                                ? 'text-hq-lime'
                                                : 'text-hq-moss-dim',
                                        )}
                                    >
                                        {selectedScore.points ?? '—'}
                                    </p>
                                    {selectedScore.stats?.marca_points && (
                                        <p className="mt-0.5 flex items-center justify-end gap-1 font-mono text-xs text-hq-moss-dim">
                                            <img
                                                src="/images/dazn-logo.png"
                                                alt="DAZN"
                                                className="h-3.5 w-3.5"
                                            />
                                            {
                                                selectedScore.stats
                                                    .marca_points[1]
                                            }
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-2.5 border-b border-hq-border px-4 py-2.5">
                                <MatchEventIcons
                                    stats={selectedScore.stats ?? {}}
                                    position={playerPosition}
                                />
                                {didNotPlayMatch(
                                    selectedScore.stats ?? {},
                                    selectedScore.fixture.state,
                                ) && (
                                    <span className="border border-hq-moss-dim px-1.5 py-0.5 font-mono text-[10px] font-bold text-hq-moss-dim uppercase">
                                        No jugó
                                    </span>
                                )}
                                <div className="ml-auto flex items-center gap-2.5">
                                    {selectedScore.lineup_manager && (
                                        <Link
                                            href={
                                                seasonManagersShow(
                                                    selectedScore.lineup_manager
                                                        .id,
                                                ).url
                                            }
                                            className="flex items-center gap-1.5 border border-hq-border-strong bg-hq-panel-alt px-1.5 py-0.5 font-mono text-[11px] text-hq-paper hover:bg-hq-panel"
                                        >
                                            <span
                                                className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                                                style={{
                                                    backgroundColor:
                                                        managerColor(
                                                            selectedScore
                                                                .lineup_manager
                                                                .primary_color,
                                                        ),
                                                }}
                                            />
                                            {selectedScore.lineup_manager.name}
                                        </Link>
                                    )}
                                    <Link
                                        href={
                                            fixturesShow(
                                                selectedScore.fixture.id,
                                            ).url
                                        }
                                        className="flex items-center gap-1 border border-hq-lime px-2 py-1 font-mono text-[11px] font-bold text-hq-lime hover:bg-hq-lime/10"
                                    >
                                        VER PARTIDO
                                        <ArrowUpRight className="h-3 w-3" />
                                    </Link>
                                </div>
                            </div>

                            <HqJornadaStatsGrid
                                stats={selectedScore.stats}
                                columns={3}
                            />
                        </div>
                    );
                })()
            ) : selectedFixture?.state === 'finished' ? (
                <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                    <p className="font-display text-lg text-hq-paper uppercase">
                        Jornada {selectedWeek} — no convocado
                    </p>
                    <p className="mt-1.5 font-mono text-[11px] text-hq-moss-dim">
                        {selectedFixture.local_team.short_name}{' '}
                        {selectedFixture.local_score}–
                        {selectedFixture.guest_score}{' '}
                        {selectedFixture.guest_team.short_name}
                    </p>
                    <Link
                        href={fixturesShow(selectedFixture.id).url}
                        className="mt-3 inline-flex items-center gap-1 border border-hq-lime px-2 py-1 font-mono text-[11px] font-bold text-hq-lime hover:bg-hq-lime/10"
                    >
                        VER PARTIDO
                        <ArrowUpRight className="h-3 w-3" />
                    </Link>
                </div>
            ) : (
                <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                    <p className="font-display text-lg text-hq-paper uppercase">
                        Jornada {selectedWeek} — aún no jugada
                    </p>
                    {selectedFixture ? (
                        <>
                            <p className="mt-1.5 font-mono text-[11px] text-hq-moss-dim">
                                {selectedFixture.local_team.short_name} vs{' '}
                                {selectedFixture.guest_team.short_name} ·{' '}
                                {formatMatchDateTime(selectedFixture.date)}
                            </p>
                            <Link
                                href={fixturesShow(selectedFixture.id).url}
                                className="mt-3 inline-flex items-center gap-1 border border-hq-lime px-2 py-1 font-mono text-[11px] font-bold text-hq-lime hover:bg-hq-lime/10"
                            >
                                VER PARTIDO
                                <ArrowUpRight className="h-3 w-3" />
                            </Link>
                        </>
                    ) : (
                        <p className="mt-1.5 font-mono text-[11px] text-hq-moss-dim">
                            Todavía no hay datos de este jugador para esta
                            jornada
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
