import { Head, Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { MatchEventIcons } from '@/components/match-event-icons';
import AppLayout from '@/layouts/app-layout';
import { FIXTURE_STATE_LABELS, isLiveFixtureState } from '@/lib/fixture-state';
import { formatMatchDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type { Fixture, PlayerScore } from '@/types/models';

interface FixtureShowProps {
    fixture: Fixture;
    weekFixtures: Fixture[];
    scores: PlayerScore[];
    [key: string]: unknown;
}

const LEGEND_ITEMS = [
    { icon: <span className="text-hq-lime">⚽</span>, label: 'Gol' },
    { icon: <span className="text-hq-live">⚽</span>, label: 'Gol en propia' },
    { icon: <span className="text-hq-med">➜</span>, label: 'Asistencia' },
    {
        icon: <span className="hq-crest-cut h-3.5 w-2.5 bg-hq-gold" />,
        label: 'Amarilla',
    },
    {
        icon: (
            <span className="relative inline-block h-3.5 w-4">
                <span className="hq-crest-cut absolute top-0.5 left-0 h-3 w-2 bg-hq-gold/60" />
                <span className="hq-crest-cut absolute top-0 left-1.5 h-3 w-2 bg-hq-gold" />
            </span>
        ),
        label: 'Doble amarilla',
    },
    {
        icon: <span className="hq-crest-cut h-3.5 w-2.5 bg-hq-live" />,
        label: 'Roja',
    },
    {
        icon: (
            <span className="border border-hq-gold px-1 font-mono text-[9px] font-bold text-hq-gold">
                P+
            </span>
        ),
        label: 'Provoca penalti',
    },
    {
        icon: (
            <span className="border border-hq-ember px-1 font-mono text-[9px] font-bold text-hq-ember">
                P−
            </span>
        ),
        label: 'Comete penalti',
    },
    {
        icon: (
            <span className="border border-hq-live px-1 font-mono text-[9px] font-bold text-hq-live">
                P✗
            </span>
        ),
        label: 'Penalti fallado',
    },
    {
        icon: (
            <span className="border border-hq-lime px-1 font-mono text-[9px] font-bold text-hq-lime">
                P✓
            </span>
        ),
        label: 'Penalti parado',
    },
    {
        icon: (
            <span className="border border-hq-lime px-1 font-mono text-[9px] font-bold text-hq-lime">
                0
            </span>
        ),
        label: 'Portería a cero',
    },
];

function didNotPlay(score: PlayerScore): boolean {
    return (score.stats.mins_played?.[0] ?? 0) === 0;
}

function TeamColumn({
    scores,
    minPlayedRows,
    showDazn,
}: {
    scores: PlayerScore[];
    minPlayedRows: number;
    showDazn: boolean;
}) {
    const played = scores.filter((score) => !didNotPlay(score));
    const benched = scores.filter(didNotPlay);
    const fillerCount = Math.max(0, minPlayedRows - played.length);

    return (
        <div className="border border-t-0 border-hq-border">
            {played.map((score, index) => (
                <PlayerRow
                    key={score.id}
                    score={score}
                    alt={index % 2 === 1}
                    showDazn={showDazn}
                />
            ))}
            {Array.from({ length: fillerCount }, (_, index) => (
                <div
                    key={`filler-${index}`}
                    className={cn(
                        'flex items-center gap-2.5 border-b border-hq-ink px-3 py-2.5 last:border-b-0',
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
                    showDazn={showDazn}
                />
            ))}
        </div>
    );
}

function pointsBadgeClass(points: number): string {
    if (points >= 12) {
        return 'bg-hq-lime text-hq-ink';
    }

    if (points >= 6) {
        return 'bg-hq-lime/15 text-hq-lime';
    }

    if (points >= 3) {
        return 'bg-hq-gold/20 text-hq-gold';
    }

    if (points < 0) {
        return 'bg-hq-live text-white';
    }

    return 'bg-hq-border text-hq-moss';
}

function PlayerRow({
    score,
    alt,
    showDazn,
}: {
    score: PlayerScore;
    alt: boolean;
    showDazn: boolean;
}) {
    const marcaPoints = score.stats.marca_points?.[1];

    return (
        <div
            className={cn(
                'flex items-center gap-2.5 border-b border-hq-ink px-3 py-2.5 last:border-b-0',
                alt && 'bg-hq-panel-alt/50',
            )}
        >
            <div className="relative h-14 w-11 shrink-0">
                <img
                    src={score.player.image}
                    alt={score.player.nickname}
                    className="absolute top-0 h-11 w-11 rounded-full bg-hq-border object-cover object-top"
                />
                <HqPositionTag
                    position={score.player.position}
                    className="absolute bottom-0 left-1/2 -translate-x-1/2 bg-hq-ink whitespace-nowrap"
                />
            </div>
            <div className="flex flex-1 flex-col justify-center gap-0.5">
                <span className="text-[12.5px] font-bold text-hq-paper">
                    {score.player.nickname}
                </span>
                <MatchEventIcons
                    stats={score.stats}
                    position={score.player.position}
                />
            </div>
            <div className="flex flex-col items-end gap-2.5">
                <span
                    className={cn(
                        'w-10 rounded-sm py-0.5 text-center font-display text-lg',
                        pointsBadgeClass(score.points),
                    )}
                >
                    {score.points}
                </span>
                {showDazn && marcaPoints !== undefined && (
                    <span className="flex items-center gap-1 font-mono text-[9px] text-hq-moss-dim">
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
    const isLive = isLiveFixtureState(fixture.state);
    const hasScore = isLive || fixture.state === 'finished';
    const localScores = scores.filter(
        (score) => score.team_id === fixture.local_team.id,
    );
    const guestScores = scores.filter(
        (score) => score.team_id === fixture.guest_team.id,
    );
    const minPlayedRows = Math.max(
        localScores.filter((score) => !didNotPlay(score)).length,
        guestScores.filter((score) => !didNotPlay(score)).length,
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
                            'flex items-center justify-center gap-7 border bg-gradient-to-br from-hq-panel-alt to-hq-panel px-6 py-6',
                            isLive ? 'border-hq-live' : 'border-hq-border-strong',
                        )}
                    >
                        <div className="flex w-36 flex-col items-center gap-2">
                            <EntityImage
                                src={fixture.local_team.logo}
                                alt={fixture.local_team.name}
                                fallback={Shield}
                                shape="square"
                                className="h-14 w-14 bg-transparent"
                            />
                            <span className="text-center font-display text-sm text-hq-paper uppercase">
                                {fixture.local_team.name}
                            </span>
                        </div>
                        <div className="text-center">
                            <p className="mb-1.5 font-mono text-[10px] tracking-widest text-hq-moss uppercase">
                                Jornada {fixture.week_number}
                            </p>
                            <div className="font-display text-4xl text-hq-paper">
                                {hasScore
                                    ? `${fixture.local_score} – ${fixture.guest_score}`
                                    : 'VS'}
                            </div>
                            <p
                                className={cn(
                                    'mt-1.5 flex items-center justify-center gap-1.5 font-mono text-[10px] tracking-widest uppercase',
                                    isLive
                                        ? 'text-hq-live'
                                        : 'text-hq-lime',
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
                        <div className="flex w-36 flex-col items-center gap-2">
                            <EntityImage
                                src={fixture.guest_team.logo}
                                alt={fixture.guest_team.name}
                                fallback={Shield}
                                shape="square"
                                className="h-14 w-14 bg-transparent"
                            />
                            <span className="text-center font-display text-sm text-hq-paper uppercase">
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
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <TeamColumn
                                scores={localScores}
                                minPlayedRows={minPlayedRows}
                                showDazn={fixture.state === 'finished'}
                            />
                            <TeamColumn
                                scores={guestScores}
                                minPlayedRows={minPlayedRows}
                                showDazn={fixture.state === 'finished'}
                            />
                        </div>
                    )}

                    {fixture.state !== 'scheduled' && (
                        <div className="mt-5 flex flex-wrap gap-2 border border-hq-border bg-hq-panel px-3.5 py-2.5 font-mono text-[10px] text-hq-moss">
                            {LEGEND_ITEMS.map((item) => (
                                <span
                                    key={item.label}
                                    className="inline-flex items-center gap-1.5 border border-hq-border bg-hq-panel-alt px-2 py-1"
                                >
                                    {item.icon}
                                    {item.label}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

FixtureShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
