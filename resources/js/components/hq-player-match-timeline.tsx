import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useState } from 'react';
import { MatchEventIcons } from '@/components/match-event-icons';
import { formatMatchDateTime } from '@/lib/format';
import { didNotPlayMatch, JORNADA_STAT_LABELS, JORNADA_STAT_ORDER } from '@/lib/player-labels';
import { pointsBadgeClass } from '@/lib/points';
import { seasonTeamColor } from '@/lib/season-team-colors';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type { Fixture, PlayerFichaScore, PlayerPosition } from '@/types/models';

const BODY_STAT_ORDER = JORNADA_STAT_ORDER.filter((key) => key !== 'marca_points');

interface HqPlayerMatchTimelineProps {
    scores: PlayerFichaScore[];
    teamFixtures: Fixture[];
    currentWeek: number;
    playerPosition: PlayerPosition;
}

export function HqPlayerMatchTimeline({
    scores,
    teamFixtures,
    currentWeek,
    playerPosition,
}: HqPlayerMatchTimelineProps) {
    const [selectedWeek, setSelectedWeek] = useState(currentWeek);
    const scoresByWeek = new Map(scores.map((score) => [score.fixture.week_number, score]));
    const fixturesByWeek = new Map(teamFixtures.map((fixture) => [fixture.week_number, fixture]));
    const selectedScore = scoresByWeek.get(selectedWeek) ?? null;
    const selectedFixture = fixturesByWeek.get(selectedWeek) ?? null;

    return (
        <div>
            <h2 className="mb-3 font-display text-lg tracking-wide text-hq-paper uppercase">
                Partidos
            </h2>

            <div className="relative">
                <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-9 bg-gradient-to-r from-hq-ink to-transparent" />
                <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-9 bg-gradient-to-l from-hq-ink to-transparent" />
                <div className="flex gap-2 overflow-x-auto px-1 py-1 pb-3">
                    {Array.from({ length: currentWeek }, (_, index) => index + 1).map(
                        (week) => {
                            const score = scoresByWeek.get(week);

                            return (
                                <button
                                    key={week}
                                    type="button"
                                    onClick={() => setSelectedWeek(week)}
                                    className={cn(
                                        'flex h-14 w-14 shrink-0 flex-col items-center justify-center border-2 font-mono',
                                        selectedWeek === week
                                            ? 'border-hq-paper'
                                            : 'border-transparent',
                                        score
                                            ? pointsBadgeClass(score.points)
                                            : 'border-dashed border-hq-border-strong text-hq-moss-dim',
                                    )}
                                >
                                    <span className="text-[10px] font-bold opacity-80">
                                        J{week}
                                    </span>
                                    <span className="font-display text-lg leading-none">
                                        {score ? score.points : '—'}
                                    </span>
                                </button>
                            );
                        },
                    )}
                </div>
            </div>

            {selectedScore ? (
                <div className="hq-card-cut p-4">
                    <div className="mb-3.5 flex flex-wrap items-center gap-3">
                        <span className="font-display text-lg text-hq-moss">
                            J{selectedWeek}
                        </span>
                        <span
                            className={cn(
                                'rounded-sm px-3 py-0.5 font-display text-xl',
                                pointsBadgeClass(selectedScore.points),
                            )}
                        >
                            {selectedScore.points}
                        </span>
                        <span className="font-mono text-xs text-hq-moss">
                            {selectedScore.fixture.local_team.short_name}{' '}
                            {selectedScore.fixture.local_score}–
                            {selectedScore.fixture.guest_score}{' '}
                            {selectedScore.fixture.guest_team.short_name}
                        </span>
                        {didNotPlayMatch(
                            selectedScore.stats,
                            selectedScore.fixture.state,
                        ) && (
                            <span className="border border-hq-moss-dim px-1.5 py-0.5 font-mono text-[10px] font-bold text-hq-moss-dim uppercase">
                                No jugó
                            </span>
                        )}
                        <MatchEventIcons
                            stats={selectedScore.stats}
                            position={playerPosition}
                        />
                        <div className="ml-auto flex items-center gap-2.5">
                            {selectedScore.lineup_team && (
                                <span className="flex items-center gap-1.5 border border-hq-border-strong bg-hq-panel-alt px-1.5 py-0.5 font-mono text-[11px] text-hq-paper">
                                    <span
                                        className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                                        style={{
                                            backgroundColor: seasonTeamColor(
                                                selectedScore.lineup_team.id,
                                            ),
                                        }}
                                    />
                                    {selectedScore.lineup_team.name}
                                </span>
                            )}
                            {selectedScore.stats.marca_points && (
                                <span className="border border-hq-khaki px-1.5 py-0.5 font-mono text-[11px] text-hq-khaki">
                                    DAZN {selectedScore.stats.marca_points[1]}
                                </span>
                            )}
                            <Link
                                href={fixturesShow(selectedScore.fixture.id).url}
                                className="flex items-center gap-1 border border-hq-lime px-2 py-1 font-mono text-[11px] font-bold text-hq-lime hover:bg-hq-lime/10"
                            >
                                VER PARTIDO
                                <ArrowUpRight className="h-3 w-3" />
                            </Link>
                        </div>
                    </div>
                    <div className="grid grid-cols-2">
                        {BODY_STAT_ORDER.map((key, index) => {
                            const [value, delta] = selectedScore.stats[key] ?? [0, 0];
                            const isZero = value === 0;

                            return (
                                <div
                                    key={key}
                                    className={cn(
                                        'flex flex-col gap-0.5 border-t border-hq-border px-4 py-1.5',
                                        index % 2 === 1 && 'border-l border-hq-border',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'font-mono text-[10px] tracking-wide text-hq-moss uppercase',
                                            isZero && 'opacity-40',
                                        )}
                                    >
                                        {JORNADA_STAT_LABELS[key] ?? key}
                                    </span>
                                    <span
                                        className={cn(
                                            'flex items-center gap-1.5 font-mono',
                                            isZero && 'opacity-40',
                                        )}
                                    >
                                        <span className="font-bold text-hq-paper">
                                            {value}
                                        </span>
                                        {delta !== 0 && (
                                            <span
                                                className={cn(
                                                    'text-[10px] font-bold',
                                                    delta > 0
                                                        ? 'text-hq-lime'
                                                        : 'text-hq-live',
                                                )}
                                            >
                                                {delta > 0 ? '+' : ''}
                                                {delta}
                                            </span>
                                        )}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>
            ) : (
                <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                    <p className="font-display text-lg text-hq-paper uppercase">
                        Jornada {selectedWeek} — aún no jugada
                    </p>
                    {selectedFixture ? (
                        <>
                            <p className="mt-1.5 font-mono text-[11px] text-hq-moss-dim">
                                {selectedFixture.local_team.short_name}{' '}
                                vs {selectedFixture.guest_team.short_name} ·{' '}
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
                            Todavía no hay datos de este jugador para esta jornada
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
