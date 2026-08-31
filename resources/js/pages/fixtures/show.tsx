import { Head, Link } from '@inertiajs/react';
import { LayoutGrid, List, Shield } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqFixtureBench } from '@/components/hq-fixture-bench';
import { HqFixtureLineupList } from '@/components/hq-fixture-lineup-list';
import { HqFixtureTeamStats } from '@/components/hq-fixture-team-stats';
import { HqFixtureTimeline } from '@/components/hq-fixture-timeline';
import { HqMatchPitch } from '@/components/hq-match-pitch';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import type { HqPlayerStatsEntry } from '@/components/hq-player-stats-modal';
import { HqScrollRow } from '@/components/hq-scroll-row';
import AppLayout from '@/layouts/app-layout';
import { FIXTURE_STATE_LABELS, isLiveFixtureState } from '@/lib/fixture-state';
import { formatMatchDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type {
    Fixture,
    FixtureEventEntry,
    FixtureLineupEntry,
    FixtureTeamStat,
    JornadaStats,
} from '@/types/models';

interface FixtureShowProps {
    fixture: Fixture;
    weekFixtures: Fixture[];
    lineups: FixtureLineupEntry[];
    events: FixtureEventEntry[];
    team_stats: FixtureTeamStat[];
    [key: string]: unknown;
}

// This match's kit colors — left half primary, right half alternate — not the
// club's default palette, since a team can play in a different kit from
// match to match (e.g. an away kit to avoid a color clash).
function TeamColorSwatch({ color, alternateColor }: { color: string | null; alternateColor: string | null }) {
    if (!color || !alternateColor) {
        return null;
    }

    return (
        <div className="flex h-2 w-20 overflow-hidden rounded-[1px]">
            <span className="flex-1" style={{ backgroundColor: `#${color}` }} />
            <span className="flex-1" style={{ backgroundColor: `#${alternateColor}` }} />
        </div>
    );
}

export default function FixtureShow({
    fixture,
    weekFixtures,
    lineups,
    events,
    team_stats,
}: FixtureShowProps) {
    const [activeTab, setActiveTab] = useState<'bench' | 'stats' | 'timeline'>(
        'bench',
    );
    const [viewMode, setViewMode] = useState<'pitch' | 'list'>('pitch');
    const [selectedEntry, setSelectedEntry] = useState<FixtureLineupEntry | null>(
        null,
    );
    const isLive = isLiveFixtureState(fixture.state);
    const hasScore = isLive || fixture.state === 'finished';

    const handleSelectLineupEntry = (entry: FixtureLineupEntry) => {
        if (entry.player) {
            setSelectedEntry(entry);
        }
    };

    return (
        <>
            <Head
                title={`${fixture.local_team.main_name} vs ${fixture.guest_team.main_name}`}
            />
            <div className="hq-texture hq-bleed min-h-[calc(100vh-95px)] border-y border-hq-border">
                <div className="mx-auto max-w-7xl px-6 py-9">
                    <HqScrollRow className="mb-5">
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
                                        'shrink-0 border bg-hq-panel px-3 py-2.5 text-center font-mono transition-colors',
                                        weekFixture.id === fixture.id
                                            ? 'border-hq-lime bg-hq-panel-alt'
                                            : weekFixtureIsLive
                                              ? 'border-hq-live'
                                              : 'border-hq-border hover:border-hq-border-strong',
                                    )}
                                >
                                    <div className="mb-1 flex items-center justify-center gap-2">
                                        <img
                                            src={weekFixture.local_team.logo}
                                            alt={weekFixture.local_team.main_name}
                                            className="h-5 w-5 object-contain"
                                        />
                                        <span className="text-sm font-bold text-hq-paper">
                                            {weekFixtureHasScore
                                                ? weekFixture.local_score
                                                : ''}
                                        </span>
                                    </div>
                                    <div className="mb-1.5 flex items-center justify-center gap-2">
                                        <img
                                            src={weekFixture.guest_team.logo}
                                            alt={weekFixture.guest_team.main_name}
                                            className="h-5 w-5 object-contain"
                                        />
                                        <span className="text-sm font-bold text-hq-paper">
                                            {weekFixtureHasScore
                                                ? weekFixture.guest_score
                                                : ''}
                                        </span>
                                    </div>
                                    <div
                                        className={cn(
                                            'flex items-center justify-center gap-1 text-[9px] uppercase',
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
                                    {weekFixture.state !== 'scheduled' && (
                                        <div className="mt-0.5 text-center text-[9px] text-hq-moss-dim uppercase">
                                            {formatMatchDateTime(
                                                weekFixture.date,
                                            )}
                                        </div>
                                    )}
                                </Link>
                            );
                        })}
                    </HqScrollRow>

                    <div
                        className={cn(
                            'relative flex items-center justify-between gap-2 border bg-gradient-to-br from-hq-panel-alt to-hq-panel px-4 py-4 sm:justify-center sm:gap-7 sm:px-6 sm:py-6',
                            isLive
                                ? 'border-hq-live'
                                : 'border-hq-border-strong',
                        )}
                    >
                        {fixture.state !== 'scheduled' && lineups.length > 0 && (
                            <div className="absolute top-2 right-2 z-10 hidden overflow-hidden border border-hq-border-strong bg-hq-panel xl:flex">
                                <button
                                    type="button"
                                    onClick={() => setViewMode('pitch')}
                                    aria-label="Vista de campo"
                                    className={cn(
                                        'flex h-6 w-7 items-center justify-center transition-colors',
                                        viewMode === 'pitch'
                                            ? 'bg-hq-lime text-hq-ink'
                                            : 'text-hq-moss hover:text-hq-paper',
                                    )}
                                >
                                    <LayoutGrid className="h-3.5 w-3.5" />
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setViewMode('list')}
                                    aria-label="Vista de lista"
                                    className={cn(
                                        'flex h-6 w-7 items-center justify-center transition-colors',
                                        viewMode === 'list'
                                            ? 'bg-hq-lime text-hq-ink'
                                            : 'text-hq-moss hover:text-hq-paper',
                                    )}
                                >
                                    <List className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        )}
                        <div className="flex w-20 min-w-0 flex-col items-center gap-1.5 sm:w-36 sm:gap-2">
                            <EntityImage
                                src={fixture.local_team.logo}
                                alt={fixture.local_team.main_name}
                                fallback={Shield}
                                shape="square"
                                className="h-9 w-9 bg-transparent sm:h-14 sm:w-14"
                            />
                            <span className="text-center font-display text-[10px] text-hq-paper uppercase sm:text-sm">
                                {fixture.local_team.main_name}
                            </span>
                            <TeamColorSwatch color={fixture.local_color} alternateColor={fixture.local_alternate_color} />
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
                            <div className="mt-1 sm:mt-1.5">
                                <p
                                    className={cn(
                                        'flex items-center justify-center gap-1.5 font-mono text-[8px] tracking-widest whitespace-nowrap uppercase sm:text-[10px]',
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
                                {fixture.state !== 'scheduled' && (
                                    <p className="mt-1 font-mono text-[8px] tracking-widest whitespace-nowrap text-hq-moss-dim uppercase sm:text-[9px]">
                                        {formatMatchDateTime(fixture.date)}
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="flex w-20 min-w-0 flex-col items-center gap-1.5 sm:w-36 sm:gap-2">
                            <EntityImage
                                src={fixture.guest_team.logo}
                                alt={fixture.guest_team.main_name}
                                fallback={Shield}
                                shape="square"
                                className="h-9 w-9 bg-transparent sm:h-14 sm:w-14"
                            />
                            <span className="text-center font-display text-[10px] text-hq-paper uppercase sm:text-sm">
                                {fixture.guest_team.main_name}
                            </span>
                            <TeamColorSwatch color={fixture.guest_color} alternateColor={fixture.guest_alternate_color} />
                        </div>
                    </div>

                    {fixture.state === 'scheduled' || lineups.length === 0 ? (
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
                            <div className={cn('mt-6', viewMode === 'pitch' ? 'hidden xl:block' : 'hidden')}>
                                <HqMatchPitch
                                    lineups={lineups}
                                    localFormation={fixture.local_formation}
                                    guestFormation={fixture.guest_formation}
                                    onSelect={handleSelectLineupEntry}
                                />
                            </div>
                            <div className={cn('mt-6', viewMode === 'list' ? 'block' : 'block xl:hidden')}>
                                <HqFixtureLineupList
                                    lineups={lineups}
                                    localTeam={fixture.local_team}
                                    guestTeam={fixture.guest_team}
                                    onSelect={handleSelectLineupEntry}
                                />
                            </div>

                            <div className="mt-5 flex gap-0.5 border-b border-hq-border-strong">
                                {(
                                    [
                                        ['bench', 'Suplentes'],
                                        ['stats', 'Datos del partido'],
                                        ['timeline', 'Cronología'],
                                    ] as const
                                ).map(([tab, label]) => (
                                    <button
                                        key={tab}
                                        type="button"
                                        onClick={() => setActiveTab(tab)}
                                        className={cn(
                                            '-mb-px border-b-2 px-4 py-2.5 font-mono text-[11px] font-bold tracking-wider uppercase',
                                            activeTab === tab
                                                ? 'border-hq-lime text-hq-lime'
                                                : 'border-transparent text-hq-moss hover:text-hq-paper',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>

                            <div className="mt-4">
                                {activeTab === 'bench' && (
                                    <HqFixtureBench
                                        lineups={lineups}
                                        localTeam={fixture.local_team}
                                        guestTeam={fixture.guest_team}
                                        onSelect={handleSelectLineupEntry}
                                    />
                                )}
                                {activeTab === 'stats' && (
                                    <HqFixtureTeamStats stats={team_stats} />
                                )}
                                {activeTab === 'timeline' && (
                                    <HqFixtureTimeline
                                        events={events}
                                        localTeamId={fixture.local_team.id}
                                    />
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>
            <HqPlayerStatsModal
                entry={
                    selectedEntry && selectedEntry.player
                        ? ({
                              player: selectedEntry.player,
                              team:
                                  selectedEntry.team_id === fixture.local_team.id
                                      ? fixture.local_team
                                      : fixture.guest_team,
                              points: selectedEntry.points ?? 0,
                              daznPoints:
                                  fixture.state === 'finished'
                                      ? (selectedEntry.dazn_points ?? undefined)
                                      : undefined,
                              stats: selectedEntry.stats ?? ({} as JornadaStats),
                              lineupManager: selectedEntry.lineup_manager,
                              subMinute:
                                  selectedEntry.sub_minute === null
                                      ? null
                                      : {
                                            minute: selectedEntry.sub_minute,
                                            direction: selectedEntry.subbed_out
                                                ? ('out' as const)
                                                : ('in' as const),
                                        },
                          } satisfies HqPlayerStatsEntry)
                        : null
                }
                onClose={() => setSelectedEntry(null)}
            />
        </>
    );
}

FixtureShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
