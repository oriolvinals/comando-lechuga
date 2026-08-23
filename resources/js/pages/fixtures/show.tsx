import { Head, Link } from '@inertiajs/react';
import { Shield, User } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import { HqPositionTag } from '@/components/hq-position-tag';
import { MatchEventIcons } from '@/components/match-event-icons';
import AppLayout from '@/layouts/app-layout';
import { FIXTURE_STATE_LABELS, isLiveFixtureState } from '@/lib/fixture-state';
import { formatMatchDateTime } from '@/lib/format';
import { didNotPlayMatch } from '@/lib/player-labels';
import { pointsBadgeClass } from '@/lib/points';
import { teamColor } from '@/lib/season-team-colors';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import { show as seasonTeamsShow } from '@/routes/season-teams';
import type { Fixture, PlayerScore } from '@/types/models';

interface FixtureShowProps {
    fixture: Fixture;
    weekFixtures: Fixture[];
    scores: PlayerScore[];
    [key: string]: unknown;
}

const LEGEND_ITEMS = [
    { icon: <span className="text-base">⚽</span>, label: 'Gol' },
    {
        icon: (
            <span className="border border-hq-live px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-live">
                PP
            </span>
        ),
        label: 'Gol en propia',
    },
    {
        icon: <span className="text-base text-hq-med">➜</span>,
        label: 'Asistencia',
    },
    {
        icon: <span className="hq-crest-cut h-5 w-3.5 bg-hq-gold" />,
        label: 'Amarilla',
    },
    {
        icon: (
            <span className="relative inline-block h-5 w-5">
                <span className="hq-crest-cut absolute top-0.5 left-0 h-4 w-3 bg-hq-gold/60" />
                <span className="hq-crest-cut absolute top-0 left-2 h-4 w-3 bg-hq-gold" />
            </span>
        ),
        label: 'Doble amarilla',
    },
    {
        icon: <span className="hq-crest-cut h-5 w-3.5 bg-hq-live" />,
        label: 'Roja',
    },
    {
        icon: (
            <span className="border border-hq-gold px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-gold">
                P+
            </span>
        ),
        label: 'Provoca penalti',
    },
    {
        icon: (
            <span className="border border-hq-ember px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-ember">
                P−
            </span>
        ),
        label: 'Comete penalti',
    },
    {
        icon: (
            <span className="border border-hq-live px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-live">
                P✗
            </span>
        ),
        label: 'Penalti fallado',
    },
    {
        icon: (
            <span className="border border-hq-lime px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-lime">
                P✓
            </span>
        ),
        label: 'Penalti parado',
    },
    {
        icon: (
            <span className="border border-hq-lime px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-lime">
                0
            </span>
        ),
        label: 'Portería a cero',
    },
];

function TeamColumn({
    scores,
    minPlayedRows,
    showDazn,
    fixtureState,
    onSelect,
}: {
    scores: PlayerScore[];
    minPlayedRows: number;
    showDazn: boolean;
    fixtureState: Fixture['state'];
    onSelect: (score: PlayerScore) => void;
}) {
    const played = scores.filter(
        (score) => !didNotPlayMatch(score.stats, fixtureState),
    );
    const benched = scores.filter((score) =>
        didNotPlayMatch(score.stats, fixtureState),
    );
    const fillerCount = Math.max(0, minPlayedRows - played.length);

    return (
        <div className="border border-t-0 border-hq-border">
            {played.map((score, index) => (
                <PlayerRow
                    key={score.id}
                    score={score}
                    alt={index % 2 === 1}
                    showDazn={showDazn}
                    onSelect={onSelect}
                />
            ))}
            {Array.from({ length: fillerCount }, (_, index) => (
                <div
                    key={`filler-${index}`}
                    className={cn(
                        'hidden items-center gap-2.5 border-b border-hq-ink px-3 py-2.5 last:border-b-0 sm:flex',
                        (played.length + index) % 2 === 1 &&
                            'bg-hq-panel-alt/50',
                    )}
                >
                    <div className="h-14 w-11 shrink-0" />
                </div>
            ))}
            {benched.length > 0 && (
                <p className="mt-3 border-b border-hq-ink bg-hq-panel-alt px-3 py-1.5 font-mono text-[10px] font-bold tracking-widest text-hq-moss-dim uppercase">
                    No jugaron
                </p>
            )}
            {benched.map((score, index) => (
                <PlayerRow
                    key={score.id}
                    score={score}
                    alt={index % 2 === 1}
                    showDazn={false}
                    onSelect={onSelect}
                />
            ))}
        </div>
    );
}

function PlayerRow({
    score,
    alt,
    showDazn,
    onSelect,
}: {
    score: PlayerScore;
    alt: boolean;
    showDazn: boolean;
    onSelect: (score: PlayerScore) => void;
}) {
    const marcaPoints = score.stats.marca_points?.[1];

    return (
        <div
            onClick={() => onSelect(score)}
            className={cn(
                'flex cursor-pointer items-center gap-2.5 border-b border-hq-ink px-3 py-2.5 last:border-b-0',
                alt && 'bg-hq-panel-alt/50',
            )}
        >
            <div className="relative h-14 w-11 shrink-0">
                <EntityImage
                    src={score.player.image}
                    alt={score.player.nickname}
                    fallback={User}
                    className="absolute top-0 h-11 w-11 bg-hq-border"
                />
                <HqPositionTag
                    position={score.player.position}
                    className="absolute bottom-0 left-1/2 -translate-x-1/2 bg-hq-ink whitespace-nowrap"
                />
            </div>
            <div className="flex flex-1 flex-col items-start justify-center gap-0.5">
                <span className="text-[12.5px] font-bold text-hq-paper">
                    {score.player.nickname}
                </span>
                <MatchEventIcons
                    stats={score.stats}
                    position={score.player.position}
                />
                {score.lineup_team && (
                    <Link
                        href={seasonTeamsShow(score.lineup_team.id).url}
                        onClick={(event) => event.stopPropagation()}
                        className="flex items-center gap-1.5 font-mono text-[12px] font-bold text-hq-moss hover:text-hq-paper"
                    >
                        <span
                            className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                            style={{
                                backgroundColor: teamColor(
                                    score.lineup_team.primary_color,
                                ),
                            }}
                        />
                        {score.lineup_team.name}
                    </Link>
                )}
            </div>
            <div className="flex flex-col items-end gap-2.5">
                <span
                    className={cn(
                        'hq-tag-cut w-10 py-0.5 text-center font-display text-lg',
                        pointsBadgeClass(score.points),
                    )}
                >
                    {score.points}
                </span>
                {showDazn && marcaPoints !== undefined && (
                    <span className="flex items-center gap-1 font-mono text-[11px] text-hq-moss-dim">
                        <img
                            src="/images/dazn-logo.png"
                            alt="DAZN"
                            className="h-4 w-4"
                        />
                        {marcaPoints}
                    </span>
                )}
            </div>
        </div>
    );
}

export default function FixtureShow({
    fixture,
    weekFixtures,
    scores,
}: FixtureShowProps) {
    const [activeTeam, setActiveTeam] = useState<'local' | 'guest'>('local');
    const [selectedScore, setSelectedScore] = useState<PlayerScore | null>(
        null,
    );
    const isLive = isLiveFixtureState(fixture.state);
    const hasScore = isLive || fixture.state === 'finished';
    const localScores = scores.filter(
        (score) => score.team_id === fixture.local_team.id,
    );
    const guestScores = scores.filter(
        (score) => score.team_id === fixture.guest_team.id,
    );
    const minPlayedRows = Math.max(
        localScores.filter((score) => !didNotPlayMatch(score.stats, fixture.state)).length,
        guestScores.filter((score) => !didNotPlayMatch(score.stats, fixture.state)).length,
    );

    return (
        <>
            <Head
                title={`${fixture.local_team.name} vs ${fixture.guest_team.name}`}
            />
            <div className="hq-texture hq-bleed min-h-[calc(100vh-95px)] border-y border-hq-border">
                <div className="mx-auto max-w-7xl px-6 py-9">
                    <div className="mb-5 flex gap-2 overflow-x-auto pb-1">
                        {weekFixtures.map((weekFixture) => {
                            const weekFixtureIsLive = isLiveFixtureState(
                                weekFixture.state,
                            );
                            const weekFixtureHasScore =
                                weekFixtureIsLive ||
                                weekFixture.state === 'finished';

                            return (
                                <Link
                                    key={weekFixture.id}
                                    href={fixturesShow(weekFixture.id).url}
                                    className={cn(
                                        'shrink-0 border bg-hq-panel px-2.5 py-2 text-center font-mono transition-colors',
                                        weekFixture.id === fixture.id
                                            ? 'border-hq-lime bg-hq-panel-alt'
                                            : weekFixtureIsLive
                                              ? 'border-hq-live'
                                              : 'border-hq-border hover:border-hq-border-strong',
                                    )}
                                >
                                    <div className="mb-0.5 flex items-center justify-center gap-1.5">
                                        <img
                                            src={weekFixture.local_team.logo}
                                            alt={weekFixture.local_team.name}
                                            className="h-[18px] w-[18px] object-contain"
                                        />
                                        <span className="text-xs font-bold text-hq-paper">
                                            {weekFixtureHasScore
                                                ? weekFixture.local_score
                                                : ''}
                                        </span>
                                    </div>
                                    <div className="mb-1 flex items-center justify-center gap-1.5">
                                        <img
                                            src={weekFixture.guest_team.logo}
                                            alt={weekFixture.guest_team.name}
                                            className="h-[18px] w-[18px] object-contain"
                                        />
                                        <span className="text-xs font-bold text-hq-paper">
                                            {weekFixtureHasScore
                                                ? weekFixture.guest_score
                                                : ''}
                                        </span>
                                    </div>
                                    <div
                                        className={cn(
                                            'flex items-center justify-center gap-1 text-[8px] uppercase',
                                            weekFixtureIsLive
                                                ? 'text-hq-live'
                                                : 'text-hq-moss-dim',
                                        )}
                                    >
                                        {weekFixtureIsLive && (
                                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-hq-live" />
                                        )}
                                        {weekFixture.state === 'scheduled'
                                            ? formatMatchDateTime(
                                                  weekFixture.date,
                                              )
                                            : FIXTURE_STATE_LABELS[
                                                  weekFixture.state
                                              ]}
                                    </div>
                                </Link>
                            );
                        })}
                    </div>

                    <div
                        className={cn(
                            'flex items-center justify-between gap-2 border bg-gradient-to-br from-hq-panel-alt to-hq-panel px-4 py-4 sm:justify-center sm:gap-7 sm:px-6 sm:py-6',
                            isLive
                                ? 'border-hq-live'
                                : 'border-hq-border-strong',
                        )}
                    >
                        <div className="flex w-20 min-w-0 flex-col items-center gap-1.5 sm:w-36 sm:gap-2">
                            <EntityImage
                                src={fixture.local_team.logo}
                                alt={fixture.local_team.name}
                                fallback={Shield}
                                shape="square"
                                className="h-9 w-9 bg-transparent sm:h-14 sm:w-14"
                            />
                            <span className="text-center font-display text-[10px] text-hq-paper uppercase sm:text-sm">
                                {fixture.local_team.name}
                            </span>
                        </div>
                        <div className="shrink-0 text-center">
                            <p className="mb-1 font-mono text-[9px] tracking-widest text-hq-moss uppercase sm:mb-1.5 sm:text-[10px]">
                                Jornada {fixture.week_number}
                            </p>
                            <div className="font-display text-2xl whitespace-nowrap text-hq-paper sm:text-4xl">
                                {hasScore
                                    ? `${fixture.local_score} – ${fixture.guest_score}`
                                    : 'VS'}
                            </div>
                            <p
                                className={cn(
                                    'mt-1 flex items-center justify-center gap-1.5 font-mono text-[8px] tracking-widest whitespace-nowrap uppercase sm:mt-1.5 sm:text-[10px]',
                                    isLive ? 'text-hq-live' : 'text-hq-lime',
                                )}
                            >
                                {isLive && (
                                    <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-hq-live" />
                                )}
                                {fixture.state === 'scheduled'
                                    ? formatMatchDateTime(fixture.date)
                                    : FIXTURE_STATE_LABELS[fixture.state]}
                            </p>
                        </div>
                        <div className="flex w-20 min-w-0 flex-col items-center gap-1.5 sm:w-36 sm:gap-2">
                            <EntityImage
                                src={fixture.guest_team.logo}
                                alt={fixture.guest_team.name}
                                fallback={Shield}
                                shape="square"
                                className="h-9 w-9 bg-transparent sm:h-14 sm:w-14"
                            />
                            <span className="text-center font-display text-[10px] text-hq-paper uppercase sm:text-sm">
                                {fixture.guest_team.name}
                            </span>
                        </div>
                    </div>

                    {fixture.state === 'scheduled' ? (
                        <div className="mt-6 border border-dashed border-hq-border-strong px-6 py-9 text-center">
                            <p className="mb-2 text-3xl">⚽</p>
                            <p className="font-display text-lg text-hq-paper uppercase">
                                Todavía no hay datos de jugadores
                            </p>
                            <p className="mt-1.5 font-mono text-[11px] text-hq-moss-dim">
                                Cuando empiece el partido aparecerán aquí los
                                puntos de cada jugador
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="mt-6 flex border border-b-0 border-hq-border sm:hidden">
                                <button
                                    type="button"
                                    onClick={() => setActiveTeam('local')}
                                    className={cn(
                                        'flex flex-1 items-center justify-center gap-2 py-2 font-mono text-xs font-bold tracking-wider uppercase transition-colors',
                                        activeTeam === 'local'
                                            ? 'bg-hq-lime text-hq-ink'
                                            : 'border-b border-hq-border text-hq-moss',
                                    )}
                                >
                                    <img
                                        src={fixture.local_team.logo}
                                        alt=""
                                        className="h-4 w-4 object-contain"
                                    />
                                    {fixture.local_team.short_name}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setActiveTeam('guest')}
                                    className={cn(
                                        'flex flex-1 items-center justify-center gap-2 py-2 font-mono text-xs font-bold tracking-wider uppercase transition-colors',
                                        activeTeam === 'guest'
                                            ? 'bg-hq-lime text-hq-ink'
                                            : 'border-b border-hq-border text-hq-moss',
                                    )}
                                >
                                    <img
                                        src={fixture.guest_team.logo}
                                        alt=""
                                        className="h-4 w-4 object-contain"
                                    />
                                    {fixture.guest_team.short_name}
                                </button>
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div
                                    className={cn(
                                        activeTeam !== 'local' &&
                                            'hidden sm:block',
                                    )}
                                >
                                    <TeamColumn
                                        scores={localScores}
                                        minPlayedRows={minPlayedRows}
                                        showDazn={fixture.state === 'finished'}
                                        fixtureState={fixture.state}
                                        onSelect={setSelectedScore}
                                    />
                                </div>
                                <div
                                    className={cn(
                                        activeTeam !== 'guest' &&
                                            'hidden sm:block',
                                    )}
                                >
                                    <TeamColumn
                                        scores={guestScores}
                                        minPlayedRows={minPlayedRows}
                                        showDazn={fixture.state === 'finished'}
                                        fixtureState={fixture.state}
                                        onSelect={setSelectedScore}
                                    />
                                </div>
                            </div>
                        </>
                    )}

                    {fixture.state !== 'scheduled' && (
                        <>
                            <div className="mt-6 mb-2.5 flex items-center gap-2.5">
                                <span className="h-px flex-1 bg-hq-border" />
                                <span className="font-mono text-[10px] tracking-[.15em] text-hq-moss-dim uppercase">
                                    Leyenda de iconos
                                </span>
                                <span className="h-px flex-1 bg-hq-border" />
                            </div>
                            <div className="flex flex-wrap gap-2.5 border border-hq-border bg-hq-panel px-4 py-3 font-mono text-[12px] text-hq-moss">
                                {LEGEND_ITEMS.map((item) => (
                                    <span
                                        key={item.label}
                                        className="inline-flex items-center gap-2 border border-hq-border bg-hq-panel-alt px-2.5 py-1.5"
                                    >
                                        {item.icon}
                                        {item.label}
                                    </span>
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </div>
            <HqPlayerStatsModal
                entry={
                    selectedScore
                        ? {
                              player: selectedScore.player,
                              team: selectedScore.team,
                              points: selectedScore.points,
                              daznPoints:
                                  fixture.state === 'finished'
                                      ? selectedScore.stats.marca_points?.[1]
                                      : undefined,
                              stats: selectedScore.stats,
                              lineupTeam: selectedScore.lineup_team,
                          }
                        : null
                }
                onClose={() => setSelectedScore(null)}
            />
        </>
    );
}

FixtureShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
