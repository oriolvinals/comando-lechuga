import { Head, Link } from '@inertiajs/react';
import { LayoutGrid, List, Shield } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import type { ReactElement } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqFixtureBench } from '@/components/hq-fixture-bench';
import { HqFixtureFantasyScoreboard } from '@/components/hq-fixture-fantasy-scoreboard';
import { HqFixtureLineupList } from '@/components/hq-fixture-lineup-list';
import { HqFixtureMatchDetails } from '@/components/hq-fixture-match-details';
import { HqFixtureRefreshButton } from '@/components/hq-fixture-refresh-button';
import { HqFixtureTeamStats } from '@/components/hq-fixture-team-stats';
import { HqFixtureTimeline } from '@/components/hq-fixture-timeline';
import { HqLed } from '@/components/hq-led';
import { HqMatchPitch } from '@/components/hq-match-pitch';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import type { HqPlayerStatsEntry } from '@/components/hq-player-stats-modal';
import { HqScrollRow } from '@/components/hq-scroll-row';
import { HqChannelHeader, HqSection } from '@/components/hq-section';
import { HqStartProbabilitiesSection } from '@/components/hq-start-probabilities-section';
import { HqTooltip } from '@/components/hq-tooltip';
import AppLayout from '@/layouts/app-layout';
import {
    COUNTDOWN_THRESHOLD_MS,
    FIXTURE_STATE_LABELS,
    formatFixtureSecondaryText,
    isInFixtureRefreshWindow,
    isLiveFixtureState,
} from '@/lib/fixture-state';
import {
    getStoredFixtureViewMode,
    setStoredFixtureViewMode,
} from '@/lib/fixture-view-mode';
import type { FixtureViewMode } from '@/lib/fixture-view-mode';
import { formatMatchDateTime } from '@/lib/format';
import { useCountdown } from '@/lib/use-countdown';
import { useLiveFixtureRefresh } from '@/lib/use-live-fixture-refresh';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import { show as teamsShow } from '@/routes/teams';
import type {
    Fixture,
    FixtureEventEntry,
    FixtureFantasyScoreboard,
    FixtureLineupEntry,
    FixtureStartProbabilities,
    FixtureTeamStat,
    JornadaStats,
    Team,
} from '@/types/models';

const LIVE_REFRESH_PROPS = [
    'fixture',
    'weekFixtures',
    'lineups',
    'events',
    'team_stats',
    'fantasy_scoreboard',
    'startProbabilities',
];

interface FixtureShowProps {
    fixture: Fixture;
    weekFixtures: Fixture[];
    lineups: FixtureLineupEntry[];
    events: FixtureEventEntry[];
    team_stats: FixtureTeamStat[];
    fantasy_scoreboard: FixtureFantasyScoreboard | null;
    startProbabilities: FixtureStartProbabilities | null;
    [key: string]: unknown;
}

/** Where a fixture is in its life, for the state line: live, starting within 2h, scheduled further out, or done/postponed. */
function useFixtureTiming(fixture: Fixture) {
    const countdown = useCountdown(fixture.date);
    const now = useNow();
    const isLive = isLiveFixtureState(fixture.state);
    const isScheduled = fixture.state === 'scheduled';
    const remainingMs = new Date(fixture.date).getTime() - now;
    const startsSoon =
        isScheduled && remainingMs > 0 && remainingMs < COUNTDOWN_THRESHOLD_MS;
    const secondaryText = startsSoon
        ? formatMatchDateTime(fixture.date)
        : formatFixtureSecondaryText(
              fixture.state,
              fixture.date,
              fixture.display_clock,
              formatMatchDateTime,
          );

    return {
        countdown,
        isLive,
        isScheduled,
        startsSoon,
        hasScore: isLive || fixture.state === 'finished',
        secondaryText,
    };
}

// This match's kit colours — left half primary, right half alternate — not
// the club's default palette, since a team can play in a different kit from
// match to match (e.g. an away kit to avoid a colour clash).
function KitSwatch({
    color,
    alternateColor,
    className,
}: {
    color: string | null;
    alternateColor: string | null;
    className?: string;
}) {
    if (!color || !alternateColor) {
        return null;
    }

    return (
        <HqTooltip
            label="Equipación de este partido"
            className={cn('h-1.5 w-11 md:w-16', className)}
        >
            <i className="flex-1" style={{ backgroundColor: `#${color}` }} />
            <i
                className="flex-1"
                style={{ backgroundColor: `#${alternateColor}` }}
            />
        </HqTooltip>
    );
}

/** One fixture of the jornada in the strip above the scoreboard (mock `.wstrip`). */
function WeekFixtureLink({
    weekFixture,
    isCurrent,
}: {
    weekFixture: Fixture;
    isCurrent: boolean;
}) {
    const {
        countdown,
        isLive,
        isScheduled,
        startsSoon,
        hasScore,
        secondaryText,
    } = useFixtureTiming(weekFixture);

    const line = (team: Team, score: number | null) => (
        <div className="flex items-center gap-[7px] font-mono text-[12.5px] leading-[1.3] font-bold text-hq-paper">
            <EntityImage
                src={team.logo}
                alt=""
                fallback={Shield}
                shape="square"
                className="h-[15px] w-[15px] shrink-0 rounded-none bg-transparent"
            />
            <span>{team.short_name}</span>
            <b className="ml-auto">{hasScore ? score : ''}</b>
        </div>
    );

    return (
        <Link
            href={fixturesShow(weekFixture.id).url}
            aria-current={isCurrent ? 'page' : undefined}
            data-current={isCurrent || undefined}
            className={cn(
                'min-w-[118px] shrink-0 border-r border-hq-border px-3 py-[9px] font-mono text-[11px] leading-[1.25] transition-colors hover:bg-hq-panel',
                isCurrent &&
                    'bg-hq-panel-alt shadow-[inset_0_-2px_0_var(--color-hq-lime)]',
                isLive &&
                    !isCurrent &&
                    'shadow-[inset_0_-2px_0_var(--color-hq-live)]',
            )}
        >
            {line(weekFixture.local_team, weekFixture.local_score)}
            {line(weekFixture.guest_team, weekFixture.guest_score)}
            <div
                className={cn(
                    'mt-1 text-[10px] tracking-[0.04em] whitespace-nowrap uppercase',
                    isLive
                        ? 'text-hq-live'
                        : startsSoon
                          ? 'font-bold text-hq-gold'
                          : 'text-hq-moss-dim',
                )}
            >
                {isLive && '● '}
                {isScheduled
                    ? startsSoon
                        ? countdown
                        : formatMatchDateTime(weekFixture.date)
                    : FIXTURE_STATE_LABELS[weekFixture.state]}
            </div>
            {secondaryText && (
                <div
                    className={cn(
                        'mt-0.5 text-[10px] tracking-[0.04em] whitespace-nowrap uppercase',
                        isLive ? 'text-hq-live' : 'text-hq-moss-dim',
                    )}
                >
                    {secondaryText}
                </div>
            )}
        </Link>
    );
}

function WeekStrip({
    weekFixtures,
    currentId,
}: {
    weekFixtures: Fixture[];
    currentId: number;
}) {
    const stripRef = useRef<HTMLElement>(null);

    // Slide only the strip (never the page) so the open match is in view.
    useLayoutEffect(() => {
        const scroller =
            stripRef.current?.querySelector<HTMLElement>('[data-scroll-row]');
        const current = scroller?.querySelector<HTMLElement>('[data-current]');

        if (!scroller || !current) {
            return;
        }

        scroller.scrollLeft =
            current.offsetLeft -
            scroller.clientWidth / 2 +
            current.offsetWidth / 2;
    }, [currentId]);

    return (
        <nav
            ref={stripRef}
            aria-label="Partidos de la jornada"
            className="border-b border-hq-border"
        >
            {/* HqScrollRow adds prev/next arrows for desktop mice; phones keep drag. */}
            <HqScrollRow contentClassName="gap-0" showProgress={false}>
                {weekFixtures.map((weekFixture) => (
                    <WeekFixtureLink
                        key={weekFixture.id}
                        weekFixture={weekFixture}
                        isCurrent={weekFixture.id === currentId}
                    />
                ))}
            </HqScrollRow>
        </nav>
    );
}

function ScoreboardSide({
    team,
    color,
    alternateColor,
    formation,
    side,
}: {
    team: Team;
    color: string | null;
    alternateColor: string | null;
    formation: string | null;
    side: 'local' | 'guest';
}) {
    return (
        <Link
            href={teamsShow(team.id).url}
            className={cn(
                'group flex min-w-0 flex-col items-center gap-2 text-center md:gap-4',
                side === 'local'
                    ? 'md:flex-row md:text-left'
                    : 'md:flex-row-reverse md:text-right',
            )}
        >
            <EntityImage
                src={team.logo}
                alt=""
                fallback={Shield}
                shape="square"
                className="h-10 w-10 shrink-0 rounded-none bg-transparent md:h-16 md:w-16"
            />
            <span
                className={cn(
                    'flex min-w-0 flex-col items-center',
                    side === 'local' ? 'md:items-start' : 'md:items-end',
                )}
            >
                <span className="font-display text-[12.5px] leading-[1.15] text-hq-paper uppercase group-hover:text-hq-lime md:text-[26px] md:leading-none">
                    {team.main_name}
                </span>
                <KitSwatch
                    color={color}
                    alternateColor={alternateColor}
                    className="mt-2 md:mt-[9px]"
                />
                {formation && (
                    <span className="mt-1.5 font-mono text-[10.5px] leading-none text-hq-moss md:mt-2 md:text-xs">
                        {formation}
                    </span>
                )}
            </span>
        </Link>
    );
}

function ViewModeToggle({
    viewMode,
    onChange,
}: {
    viewMode: FixtureViewMode;
    onChange: (mode: FixtureViewMode) => void;
}) {
    const option = (
        mode: FixtureViewMode,
        label: string,
        Icon: typeof LayoutGrid,
    ) => (
        <button
            type="button"
            onClick={() => onChange(mode)}
            aria-label={label}
            aria-pressed={viewMode === mode}
            className={cn(
                'inline-flex h-[30px] items-center px-2.5 transition-colors',
                viewMode === mode
                    ? 'bg-hq-lime text-hq-ink'
                    : 'text-hq-moss hover:text-hq-paper',
            )}
        >
            <Icon aria-hidden="true" className="h-[13px] w-[13px]" />
        </button>
    );

    return (
        <span className="hidden divide-x divide-hq-border-strong border border-hq-border-strong bg-hq-ink lg:inline-flex">
            {option('pitch', 'Vista de campo', LayoutGrid)}
            {option('list', 'Vista de lista', List)}
        </span>
    );
}

export default function FixtureShow({
    fixture,
    weekFixtures,
    lineups,
    events,
    team_stats,
    fantasy_scoreboard,
    startProbabilities,
}: FixtureShowProps) {
    const [viewMode, setViewModeState] = useState<FixtureViewMode>(() =>
        getStoredFixtureViewMode(),
    );
    const setViewMode = (mode: FixtureViewMode) => {
        setViewModeState(mode);
        setStoredFixtureViewMode(mode);
    };
    // Which side the one-column layout (below md) shows — shared by the
    // starters list and Suplentes so both follow the same team.
    const [mobileTeamId, setMobileTeamId] = useState(fixture.local_team.id);
    // Stored as an id, not the entry itself, so the modal re-renders with
    // fresh points/stats whenever `lineups` refreshes while it's open — see
    // useLiveFixtureRefresh below.
    const [selectedEntryId, setSelectedEntryId] = useState<number | null>(null);
    const selectedEntry =
        lineups.find((entry) => entry.id === selectedEntryId) ?? null;
    const {
        countdown,
        isLive,
        isScheduled,
        startsSoon,
        hasScore,
        secondaryText,
    } = useFixtureTiming(fixture);
    const refreshClock = useNow(60_000);
    useLiveFixtureRefresh(
        isInFixtureRefreshWindow(fixture, refreshClock),
        LIVE_REFRESH_PROPS,
        { whileHidden: true },
    );
    const hasLineups = fixture.state !== 'scheduled' && lineups.length > 0;
    // Before kickoff (and until a live lineup takes over) the section shows
    // FútbolFantasy's probable XIs, or the confirmed ones — its own campo/lista toggle too.
    const showsStartProbabilities = !hasLineups && startProbabilities !== null;
    const localLoses =
        hasScore &&
        fixture.local_score !== null &&
        fixture.guest_score !== null &&
        fixture.local_score < fixture.guest_score;
    const guestLoses =
        hasScore &&
        fixture.local_score !== null &&
        fixture.guest_score !== null &&
        fixture.guest_score < fixture.local_score;

    const handleSelectLineupEntry = (entry: FixtureLineupEntry) => {
        if (entry.player) {
            setSelectedEntryId(entry.id);
        }
    };

    return (
        <>
            <Head
                title={
                    hasScore
                        ? `${fixture.local_team.main_name} ${fixture.local_score} - ${fixture.guest_score} ${fixture.guest_team.main_name}`
                        : `${fixture.local_team.main_name} vs ${fixture.guest_team.main_name}`
                }
            />
            <div className="flex-1">
                <WeekStrip weekFixtures={weekFixtures} currentId={fixture.id} />

                <div
                    className={cn(
                        'hq-scanlines relative overflow-hidden border-b border-hq-border-strong bg-hq-well',
                        isLive &&
                            'shadow-[inset_0_0_0_1px_var(--color-hq-live)]',
                    )}
                >
                    {(isLive || hasLineups || showsStartProbabilities) && (
                        <div
                            className={cn(
                                'relative z-[3] justify-end gap-1.5 px-2.5 pt-2.5 lg:absolute lg:top-2.5 lg:right-2.5 lg:p-0',
                                isLive ? 'flex' : 'hidden lg:flex',
                            )}
                        >
                            {isLive && (
                                <HqFixtureRefreshButton
                                    only={LIVE_REFRESH_PROPS}
                                />
                            )}
                            {(hasLineups || showsStartProbabilities) && (
                                <ViewModeToggle
                                    viewMode={viewMode}
                                    onChange={setViewMode}
                                />
                            )}
                        </div>
                    )}

                    <div className="relative z-[1] grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 px-3 pt-[18px] pb-3.5 md:gap-6 md:px-6 md:pt-[26px] md:pb-5">
                        <ScoreboardSide
                            team={fixture.local_team}
                            color={fixture.local_color}
                            alternateColor={fixture.local_alternate_color}
                            formation={fixture.local_formation}
                            side="local"
                        />
                        <div className="text-center">
                            <p className="font-mono text-[10px] leading-none font-semibold tracking-[0.1em] text-hq-moss uppercase md:text-[11px] md:tracking-[0.16em]">
                                Jornada {fixture.week_number}
                            </p>
                            <div className="mt-2 mb-1.5 flex items-center justify-center gap-2 md:gap-3.5">
                                {hasScore ? (
                                    <>
                                        <HqLed
                                            tone="lime"
                                            glow={!localLoses}
                                            className={cn(
                                                'text-[50px] md:text-[78px]',
                                                localLoses && 'text-[#6f7a55]',
                                            )}
                                        >
                                            {fixture.local_score}
                                        </HqLed>
                                        <span
                                            aria-hidden="true"
                                            className="font-dot text-[28px] leading-none font-black text-hq-border-bright md:text-[40px]"
                                        >
                                            -
                                        </span>
                                        <HqLed
                                            tone="lime"
                                            glow={!guestLoses}
                                            className={cn(
                                                'text-[50px] md:text-[78px]',
                                                guestLoses && 'text-[#6f7a55]',
                                            )}
                                        >
                                            {fixture.guest_score}
                                        </HqLed>
                                    </>
                                ) : (
                                    <HqLed
                                        tone="off"
                                        className="text-[40px] md:text-[54px]"
                                    >
                                        VS
                                    </HqLed>
                                )}
                            </div>
                            <p
                                className={cn(
                                    'inline-flex items-center gap-[7px] font-mono text-[10px] leading-none font-bold tracking-[0.14em] whitespace-nowrap uppercase md:text-[11.5px]',
                                    isLive
                                        ? 'text-hq-live'
                                        : startsSoon
                                          ? 'text-hq-gold'
                                          : isScheduled
                                            ? 'text-hq-moss'
                                            : fixture.state === 'postponed'
                                              ? 'text-hq-moss'
                                              : 'text-hq-lime',
                                )}
                            >
                                {!(isScheduled && !startsSoon) && (
                                    <i
                                        aria-hidden="true"
                                        className={cn(
                                            'block h-[7px] w-[7px] shrink-0 rounded-full',
                                            fixture.state === 'postponed'
                                                ? 'border border-current'
                                                : 'bg-current',
                                            isLive && 'animate-hq-pulse',
                                        )}
                                    />
                                )}
                                {isScheduled ? (
                                    startsSoon ? (
                                        <HqLed tone="gold" className="text-lg">
                                            {countdown}
                                        </HqLed>
                                    ) : (
                                        formatMatchDateTime(fixture.date)
                                    )
                                ) : (
                                    FIXTURE_STATE_LABELS[fixture.state]
                                )}
                            </p>
                            {secondaryText && (
                                <div
                                    className={cn(
                                        'mt-1.5 font-mono text-[10px] leading-none tracking-[0.1em] uppercase md:text-[11px]',
                                        isLive
                                            ? 'text-hq-live'
                                            : 'text-hq-moss-dim',
                                    )}
                                >
                                    {isLive ? (
                                        <HqLed
                                            tone="amber"
                                            className="text-xl md:text-[26px]"
                                        >
                                            {secondaryText}
                                        </HqLed>
                                    ) : (
                                        secondaryText
                                    )}
                                </div>
                            )}
                        </div>
                        <ScoreboardSide
                            team={fixture.guest_team}
                            color={fixture.guest_color}
                            alternateColor={fixture.guest_alternate_color}
                            formation={fixture.guest_formation}
                            side="guest"
                        />
                    </div>
                    <HqFixtureMatchDetails fixture={fixture} />
                </div>

                {fantasy_scoreboard && (
                    <HqFixtureFantasyScoreboard
                        scoreboard={fantasy_scoreboard}
                        lineups={lineups}
                        onSelect={handleSelectLineupEntry}
                    />
                )}

                {!hasLineups ? (
                    startProbabilities ? (
                        <HqStartProbabilitiesSection
                            probabilities={startProbabilities}
                            fixture={fixture}
                            viewMode={viewMode}
                        />
                    ) : (
                        <HqEmptyState
                            glyph="⚽"
                            title="Todavía no hay datos de jugadores"
                        >
                            Cuando empiece el partido aparecerán aquí los puntos
                            de cada jugador
                        </HqEmptyState>
                    )
                ) : (
                    <>
                        <div
                            className={
                                viewMode === 'pitch'
                                    ? 'hidden lg:block'
                                    : 'hidden'
                            }
                        >
                            <HqMatchPitch
                                lineups={lineups}
                                localTeam={fixture.local_team}
                                guestTeam={fixture.guest_team}
                                localFormation={fixture.local_formation}
                                guestFormation={fixture.guest_formation}
                                onSelect={handleSelectLineupEntry}
                            />
                        </div>
                        <section
                            className={cn(
                                'border-b border-hq-border',
                                viewMode === 'list' ? 'block' : 'lg:hidden',
                            )}
                        >
                            <HqChannelHeader code="XI" title="Titulares" />
                            <HqFixtureLineupList
                                lineups={lineups}
                                localTeam={fixture.local_team}
                                guestTeam={fixture.guest_team}
                                localFormation={fixture.local_formation}
                                guestFormation={fixture.guest_formation}
                                selectedTeamId={mobileTeamId}
                                onSelectTeam={setMobileTeamId}
                                onSelect={handleSelectLineupEntry}
                            />
                        </section>

                        <HqSection title="Suplentes" flush>
                            <HqFixtureBench
                                lineups={lineups}
                                localTeam={fixture.local_team}
                                guestTeam={fixture.guest_team}
                                selectedTeamId={mobileTeamId}
                                onSelectTeam={setMobileTeamId}
                                onSelect={handleSelectLineupEntry}
                            />
                        </HqSection>

                        <div className="grid grid-cols-1 md:grid-cols-2">
                            <HqSection
                                title="Cronología"
                                flush
                                className="min-w-0"
                            >
                                <HqFixtureTimeline
                                    events={events}
                                    localTeam={fixture.local_team}
                                    guestTeam={fixture.guest_team}
                                />
                            </HqSection>
                            <HqSection
                                title="Datos del partido"
                                flush
                                className="min-w-0 md:border-l"
                            >
                                <HqFixtureTeamStats
                                    stats={team_stats}
                                    possession={
                                        fixture.local_possession !== null &&
                                        fixture.guest_possession !== null
                                            ? {
                                                  local: fixture.local_possession,
                                                  guest: fixture.guest_possession,
                                              }
                                            : null
                                    }
                                />
                            </HqSection>
                        </div>
                    </>
                )}
            </div>
            <HqPlayerStatsModal
                entry={
                    selectedEntry && selectedEntry.player
                        ? ({
                              player: selectedEntry.player,
                              team:
                                  selectedEntry.team_id ===
                                  fixture.local_team.id
                                      ? fixture.local_team
                                      : fixture.guest_team,
                              points: selectedEntry.points ?? 0,
                              dazn:
                                  selectedEntry.starter ||
                                  selectedEntry.subbed_in
                                      ? selectedEntry
                                      : undefined,
                              stats:
                                  selectedEntry.stats ?? ({} as JornadaStats),
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
                onClose={() => setSelectedEntryId(null)}
            />
        </>
    );
}

FixtureShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
