import { Armchair, Clock, Home, Plane, Shield, User } from 'lucide-react';
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqTooltip } from '@/components/hq-tooltip';
import { FIXTURE_STATE_LABELS, isLiveFixtureState } from '@/lib/fixture-state';
import { dataAgeTooltipLabel, startTone } from '@/lib/start-probability';
import type { StartTone } from '@/lib/start-probability';
import { RESULT_STRIP_CLASSES, resultFor } from '@/lib/team-fixture-result';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import type {
    PlayerPosition,
    ManagerLineupPlayerEntry,
    Fixture,
} from '@/types/models';

/**
 * Top-to-bottom row order and vertical anchor (% of pitch height), matching
 * the official LaLiga Fantasy app: goalkeeper at the top. Each row sits in
 * its logical zone — keeper inside their own box, defenders just outside it,
 * midfield around the halfway line, attackers in the final third short of
 * the opposite box — with an even ~22-23% rhythm between lines. Only used
 * for a fantasy manager's lineup — see the module doc comment below for why
 * a team ficha's real match lineup can't use this same row grouping.
 */
const ROWS: { position: PlayerPosition; top: string }[] = [
    { position: 'goalkeeper', top: '5%' },
    { position: 'defender', top: '27%' },
    { position: 'midfield', top: '50%' },
    { position: 'striker', top: '73%' },
];

/**
 * `tactical_formation` is stored as [defenders, midfielders, strikers] — the
 * goalkeeper slot is always exactly 1 and isn't part of that array.
 */
const FORMATION_ROW_POSITIONS: PlayerPosition[] = [
    'defender',
    'midfield',
    'striker',
];

/**
 * Same tiers as `matchPointsBadgeClass`, but solid — the badge sits on the
 * dark pitch over a photo, where the translucent tints used elsewhere lose
 * contrast. Every tier (including "no data") gets a real fill. "Not called
 * up" lives in the status badge instead (see `lineupBadgeState`), so a null
 * score is always just the plain "no data" tier.
 */
function pointsBadgeTierClass(points: number | null): string {
    if (points === null) {
        return 'bg-hq-border-strong text-hq-moss';
    }

    if (points < 0) {
        return 'bg-hq-live text-white';
    }

    if (points < 5) {
        return 'bg-hq-gold text-hq-ink';
    }

    if (points < 9) {
        return 'bg-hq-lime text-hq-ink';
    }

    if (points < 14) {
        return 'bg-hq-azure text-hq-ink';
    }

    return 'bg-hq-violet text-hq-ink';
}

/**
 * Same tone scale as `startTone` (`@/lib/start-probability`), but solid —
 * this badge sits on the dark pitch over a photo, same reason as
 * `pointsBadgeTierClass` above.
 */
function startBadgeTierClass(tone: StartTone): string {
    if (tone === 'sure') {
        return 'bg-hq-violet text-hq-ink';
    }

    if (tone === 'high') {
        return 'bg-hq-lime text-hq-ink';
    }

    if (tone === 'low') {
        return 'bg-hq-gold text-hq-ink';
    }

    if (tone === 'out') {
        return 'bg-hq-live text-white';
    }

    return 'bg-hq-border-strong text-hq-moss';
}

interface StartBadge {
    label: string;
    className: string;
    content: ReactNode;
}

/**
 * The bottom-left mirror of the points chip: while the pick's own fixture
 * hasn't kicked off, either the confirmed lineup (✓ titular / bench glyph
 * suplente) or FútbolFantasy's % on the same colour scale used everywhere
 * else (lilac ≥ 90 %, lime 70–89 %, gold < 70 %, red injured/suspended) —
 * its tooltip also says how old that % is, once confirmed the lineup itself
 * is the source of truth so no age is shown. Null once the match has started
 * or finished, or without any data — the backend
 * (`StartProbabilities::forLineupEntries`) already omits `start` in both
 * cases, so this only needs to read it.
 */
function lineupStartBadge(
    entry: ManagerLineupPlayerEntry,
    now: number,
): StartBadge | null {
    const start = entry.start;

    if (!start) {
        return null;
    }

    if (start.confirmed_starter !== null) {
        return start.confirmed_starter
            ? {
                  label: 'Titular confirmado',
                  className: 'bg-hq-lime text-hq-ink',
                  content: '✓',
              }
            : {
                  label: 'Suplente confirmado',
                  className: 'bg-hq-border-strong text-hq-moss',
                  content: (
                      <Armchair aria-hidden="true" className="h-2.5 w-2.5" />
                  ),
              };
    }

    if (start.probability === null) {
        return null;
    }

    return {
        label: start.fetched_at
            ? `${start.probability} % de ser titular · ${dataAgeTooltipLabel(start.fetched_at, now)}`
            : `${start.probability} % de ser titular`,
        className: startBadgeTierClass(
            startTone(start.probability, entry.player.status),
        ),
        content: `${start.probability}%`,
    };
}

/**
 * The player's REAL match role that week, derived from `starter` +
 * `sub_minute` + `subbed_out` (see `ManagerLineupPlayerEntry`). `starter`
 * being null means no `FixtureLineup` ever resolved for this pick — once
 * the match has finished that means "not called up" at all; before that,
 * their team just hasn't played yet.
 */
type LineupBadgeState =
    | 'starter'
    | 'subbed_out'
    | 'subbed_in'
    | 'bench'
    | 'not_called_up'
    | 'not_played_yet';

function lineupBadgeState(entry: ManagerLineupPlayerEntry): LineupBadgeState {
    if (entry.starter === null) {
        return entry.match_finished ? 'not_called_up' : 'not_played_yet';
    }

    if (entry.sub_minute !== null) {
        return entry.subbed_out ? 'subbed_out' : 'subbed_in';
    }

    return entry.starter ? 'starter' : 'bench';
}

function statusBadgeToneClass(state: LineupBadgeState): string {
    if (state === 'starter' || state === 'subbed_in') {
        return 'text-hq-lime';
    }

    if (state === 'not_played_yet') {
        return 'text-hq-azure';
    }

    return 'text-hq-live';
}

function statusBadgeLabel(
    state: LineupBadgeState,
    subMinute: number | null,
): string {
    switch (state) {
        case 'starter':
            return 'Titular';
        case 'subbed_in':
            return `Entró en el ${subMinute}'`;
        case 'subbed_out':
            return `Sustituido en el ${subMinute}'`;
        case 'not_called_up':
            return 'No convocado';
        case 'not_played_yet':
            return 'Su partido aún no se ha jugado';
        case 'bench':
            return 'Suplente sin minutos';
    }
}

function StatusBadgeContent({
    state,
    subMinute,
}: {
    state: LineupBadgeState;
    subMinute: number | null;
}) {
    if (state === 'starter') {
        return '✓';
    }

    if (state === 'subbed_out' || state === 'subbed_in') {
        return <>↳{subMinute}'</>;
    }

    if (state === 'not_called_up') {
        return '✕';
    }

    if (state === 'not_played_yet') {
        return <Clock aria-hidden="true" className="h-2.5 w-2.5" />;
    }

    return <Armchair aria-hidden="true" className="h-2.5 w-2.5" />;
}

/**
 * True while this player's real-life match is live right now and they're
 * still in play or available to come on — starter, subbed in, or still on
 * the bench (never once subbed out) — same live-state check as the fixture
 * card's own pulse dot, so both use one definition of "live".
 */
function isPlayerLiveNow(
    entry: ManagerLineupPlayerEntry,
    badgeState: LineupBadgeState,
): boolean {
    return (
        (badgeState === 'starter' ||
            badgeState === 'subbed_in' ||
            badgeState === 'bench') &&
        entry.fixture !== null &&
        isLiveFixtureState(entry.fixture.state)
    );
}

/**
 * The token's width (and so its name pill's): an even share of the pitch's
 * width for the players in that row, so names use all the room the row has
 * instead of a fixed pixel width.
 */
function tokenWidthForRowCount(count: number): string {
    if (count >= 5) {
        return 'w-[19.5%]';
    }

    if (count === 4) {
        return 'w-[24.5%]';
    }

    if (count === 3) {
        return 'w-[30%]';
    }

    return 'w-[36%]';
}

interface PlayerTokenProps {
    entry: ManagerLineupPlayerEntry;
    onSelectPlayer: (entry: ManagerLineupPlayerEntry) => void;
    /** For the start badge's data-age tooltip — see {@link lineupStartBadge}. */
    now: number;
    showTeamBadge: boolean;
    /** Off on a team's own ficha — every starter there played the full match by definition (there's no fantasy pick to second-guess), so the checkmark is redundant. Subs/bench/not-called-up badges still show. */
    showStarterBadge: boolean;
    /** Off on a team's own ficha — the pitch there already only shows that team's real XI for a match already known to be live from the scoreline above, so a per-player glow adds noise instead of signal. Still on for a fantasy manager's lineup, where it's the only cue for which picks are live right now. */
    showLiveIndicator: boolean;
    widthClass: string;
}

/**
 * A pitch token (mock `.tok`): 48px photo in a paper frame with the club
 * crest showing through behind the cut-out, the real-match status badge on
 * top (✓ / ↳min' / ✕ / clock / armchair), the solid points tier chip at the
 * bottom-right corner, its start-probability/confirmed-lineup mirror at the
 * bottom-left (see `lineupStartBadge`) while the pick's own match hasn't
 * kicked off, a pulsing red frame while the match is live, and the name pill
 * below. Opens the player's jornada modal.
 */
function PlayerToken({
    entry,
    onSelectPlayer,
    now,
    showTeamBadge,
    showStarterBadge,
    showLiveIndicator,
    widthClass,
}: PlayerTokenProps) {
    const badgeState = lineupBadgeState(entry);
    const liveNow = showLiveIndicator && isPlayerLiveNow(entry, badgeState);
    const showBadge = badgeState !== 'starter' || showStarterBadge;
    const stateLabel = statusBadgeLabel(badgeState, entry.sub_minute);
    const pointsLabel =
        entry.points === null ? 'sin puntos' : `${entry.points} puntos`;
    const startBadge = lineupStartBadge(entry, now);

    return (
        <button
            type="button"
            onClick={() => onSelectPlayer(entry)}
            aria-label={`${entry.player.nickname} · ${stateLabel} · ${pointsLabel}${startBadge ? ` · ${startBadge.label}` : ''}${liveNow ? ' · en directo' : ''}`}
            className={cn(
                'group relative flex shrink-0 cursor-pointer flex-col items-center outline-none',
                widthClass,
            )}
        >
            <span className="relative block h-12 w-12">
                {/* Clipped separately from the status/points badges below — those
                    need to poke out past this box's own border, which a shared
                    overflow:hidden would cut off. */}
                <span
                    className={cn(
                        'absolute inset-0 overflow-hidden border-[1.5px] bg-hq-well transition-colors group-hover:border-hq-lime group-focus-visible:border-hq-lime',
                        liveNow ? 'border-transparent' : 'border-hq-paper/85',
                    )}
                >
                    {/* Sits behind the photo — the photo is a cutout with
                        transparent padding around the player, so the crest reads
                        through it instead of needing its own reserved corner. */}
                    {showTeamBadge && (
                        <EntityImage
                            src={entry.player.team.logo}
                            alt=""
                            fallback={Shield}
                            shape="square"
                            className="absolute top-1 -left-[5px] h-[22px] w-[22px] rounded-none bg-transparent opacity-90"
                        />
                    )}
                    <EntityImage
                        src={entry.player.image}
                        alt=""
                        fallback={User}
                        shape="square"
                        className="absolute inset-0 h-full w-full rounded-none border-0 bg-transparent object-cover"
                        style={{ objectPosition: 'center 30%' }}
                    />
                </span>
                {/* Its own layer so only the frame pulses, not the photo. */}
                {liveNow && (
                    <span className="pointer-events-none absolute -inset-px animate-hq-pulse border-2 border-hq-live shadow-[0_0_10px_2px_rgba(255,77,94,0.6)]" />
                )}
                {showBadge && (
                    <HqTooltip
                        label={stateLabel}
                        className={cn(
                            'absolute -top-2 left-1/2 z-10 h-4 min-w-[18px] -translate-x-1/2 items-center justify-center gap-0.5 border border-current bg-hq-ink px-1 font-mono text-[9.5px] leading-none font-bold whitespace-nowrap',
                            statusBadgeToneClass(badgeState),
                        )}
                    >
                        <StatusBadgeContent
                            state={badgeState}
                            subMinute={entry.sub_minute}
                        />
                    </HqTooltip>
                )}
                <span
                    aria-hidden="true"
                    className={cn(
                        'absolute -right-[7px] -bottom-[5px] z-10 flex h-4 min-w-5 items-center justify-center px-[3px] font-mono text-[10.5px] leading-none font-extrabold tabular-nums',
                        pointsBadgeTierClass(entry.points),
                    )}
                >
                    {entry.points ?? '–'}
                </span>
                {startBadge && (
                    <HqTooltip
                        label={startBadge.label}
                        className={cn(
                            'absolute -bottom-[5px] -left-[7px] z-10 h-4 min-w-5 items-center justify-center px-[3px] font-mono text-[10.5px] leading-none font-extrabold tabular-nums',
                            startBadge.className,
                        )}
                    >
                        {startBadge.content}
                    </HqTooltip>
                )}
            </span>
            <span className="mt-[5px] block max-w-full truncate bg-[rgba(6,7,5,0.86)] px-1 py-0.5 font-mono text-[10px] leading-[1.1] font-bold text-hq-paper">
                {entry.player.nickname}
            </span>
        </button>
    );
}

function EmptySlot({ widthClass }: { widthClass: string }) {
    return (
        <span
            aria-label="Hueco sin jugador"
            className={cn('flex shrink-0 flex-col items-center', widthClass)}
        >
            <span className="flex h-12 w-12 items-center justify-center border-[1.5px] border-dashed border-hq-paper/30 text-hq-paper/30">
                <User aria-hidden="true" className="h-5 w-5" />
            </span>
        </span>
    );
}

/** The pitch markings (mock PITCH_V) on plain turf: touchlines, halfway line, centre circle, both boxes. */
function PitchLines() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 280 430"
            preserveAspectRatio="none"
            className="absolute inset-0 h-full w-full fill-none stroke-hq-pitch-line [stroke-width:1.2] [&>*]:[vector-effect:non-scaling-stroke]"
        >
            <rect x="8" y="8" width="264" height="414" />
            <line x1="8" y1="215" x2="272" y2="215" />
            <circle cx="140" cy="215" r="36" />
            <rect x="63" y="8" width="154" height="62" />
            <rect x="104" y="8" width="72" height="22" />
            <rect x="63" y="360" width="154" height="62" />
            <rect x="104" y="400" width="72" height="22" />
        </svg>
    );
}

const PITCH_TAG_CLASS =
    'absolute z-20 border bg-hq-ink px-1.5 py-1 font-mono text-[10.5px] leading-none font-bold tracking-[0.06em] uppercase';

interface HqLineupPitchProps {
    players: ManagerLineupPlayerEntry[];
    /** Bench players for the same week, listed below the pitch in the same token style. Only populated for a team's own ficha — a fantasy manager's lineup has no bench concept. */
    substitutes?: ManagerLineupPlayerEntry[];
    tacticalFormation?: number[] | null;
    onSelectPlayer: (entry: ManagerLineupPlayerEntry) => void;
    /** Show each player's club crest badge. Off on a team's own ficha, where every player is the same club. */
    showTeamBadge?: boolean;
    /** Show the starter checkmark badge. Off on a team's own ficha, where it's redundant with just being placed on the pitch. */
    showStarterBadge?: boolean;
    /** Show the pulsing live-match glow. Off on a team's own ficha, where it's redundant with the match state already shown above the pitch. */
    showLiveIndicator?: boolean;
    /** The match this pitch belongs to, to show its scoreline. Only set on a team's own ficha — a fantasy manager's lineup spans one player per real fixture, so there's no single match result to show. */
    fixture?: Fixture;
    /** Whose perspective to show `fixture`'s score from (own score first). Required together with `fixture`. */
    teamId?: number;
}

/**
 * A dark tactical pitch (mock `.pitch`): plain turf, hairline markings,
 * the formation tag top-left and, on a team ficha, the match state, result
 * and home/away tags.
 *
 * A fantasy manager's lineup is always exactly a GK + 3 outfield rows
 * (defender/midfield/striker — `Player::position`'s only 4 buckets), so
 * grouping by that broad category and spacing rows evenly always matches
 * the real shape. A team ficha's lineup is a REAL match XI, which can use
 * more than 3 outfield lines (e.g. 4-2-3-1's double pivot + advanced trio)
 * that the same 4-bucket category can't represent, and doesn't preserve
 * left-to-right order within a line either. When the backend has resolved
 * each starter's actual match role into `pitch_top`/`pitch_left` (see
 * TeamsController::pitchTop/pitchLeft), place every player at that exact
 * spot instead of forcing them into the fantasy row grouping.
 */
export function HqLineupPitch({
    players,
    substitutes = [],
    tacticalFormation,
    onSelectPlayer,
    showTeamBadge = true,
    showStarterBadge = true,
    showLiveIndicator = true,
    fixture,
    teamId,
}: HqLineupPitchProps) {
    const now = useNow(60_000);
    const scoreboard = (() => {
        if (!fixture || teamId === undefined) {
            return null;
        }

        const result = resultFor(fixture, teamId);

        if (!result) {
            return null;
        }

        const isLocal = fixture.local_team.id === teamId;

        return {
            result,
            isLive: isLiveFixtureState(fixture.state),
            ownScore: isLocal ? fixture.local_score : fixture.guest_score,
            rivalScore: isLocal ? fixture.guest_score : fixture.local_score,
        };
    })();

    const matchStateLabel = (() => {
        if (!fixture) {
            return null;
        }

        const label = FIXTURE_STATE_LABELS[fixture.state];

        if (!label) {
            return null;
        }

        const isLive = isLiveFixtureState(fixture.state);

        return {
            text:
                isLive && fixture.display_clock
                    ? `${label} · ${fixture.display_clock}`
                    : label,
            isLive,
        };
    })();

    const venue =
        fixture && teamId !== undefined
            ? {
                  isHome: fixture.local_team.id === teamId,
                  Icon: fixture.local_team.id === teamId ? Home : Plane,
              }
            : null;

    const useRealCoordinates =
        players.length > 0 &&
        players.every(
            (entry) =>
                entry.pitch_top !== undefined && entry.pitch_left !== undefined,
        );

    const expectedCounts: Partial<Record<PlayerPosition, number>> = {
        goalkeeper: 1,
    };

    if (
        !useRealCoordinates &&
        tacticalFormation?.length === FORMATION_ROW_POSITIONS.length
    ) {
        FORMATION_ROW_POSITIONS.forEach((position, index) => {
            expectedCounts[position] = tacticalFormation[index];
        });
    }

    const rows = ROWS.map((row) => {
        const entries = players.filter(
            (entry) => entry.position === row.position,
        );
        const emptySlots = Math.max(
            (expectedCounts[row.position] ?? entries.length) - entries.length,
            0,
        );

        return { ...row, entries, emptySlots };
    }).filter((row) => row.entries.length > 0 || row.emptySlots > 0);

    const lineSizes = new Map<number, number>();

    if (useRealCoordinates) {
        players.forEach((entry) => {
            const top = entry.pitch_top as number;
            lineSizes.set(top, (lineSizes.get(top) ?? 0) + 1);
        });
    }

    const formationLabel =
        tacticalFormation && tacticalFormation.length > 0
            ? tacticalFormation.join('-')
            : null;

    return (
        <div>
            <div className="hq-hud relative aspect-[280/430] w-full border border-hq-border-strong bg-hq-pitch">
                <PitchLines />

                {formationLabel && (
                    <span
                        className={cn(
                            PITCH_TAG_CLASS,
                            'top-2 left-2 border-hq-border-bright text-hq-moss',
                        )}
                    >
                        {formationLabel}
                    </span>
                )}

                {matchStateLabel && (
                    <span
                        className={cn(
                            PITCH_TAG_CLASS,
                            'top-2 left-1/2 -translate-x-1/2 whitespace-nowrap',
                            matchStateLabel.isLive
                                ? 'border-hq-live text-hq-live'
                                : 'border-hq-border-bright text-hq-moss',
                        )}
                    >
                        {matchStateLabel.text}
                    </span>
                )}

                {scoreboard && (
                    <span
                        className={cn(
                            PITCH_TAG_CLASS,
                            'top-2 right-2 flex items-center gap-1',
                            RESULT_STRIP_CLASSES[scoreboard.result],
                        )}
                    >
                        {scoreboard.isLive && (
                            <span className="h-1.5 w-1.5 animate-hq-pulse rounded-full bg-hq-live" />
                        )}
                        {scoreboard.ownScore}-{scoreboard.rivalScore}
                    </span>
                )}

                {venue && (
                    <HqTooltip
                        label={venue.isHome ? 'Casa' : 'Fuera'}
                        focusable
                        className={cn(
                            PITCH_TAG_CLASS,
                            'right-2 bottom-2 border-hq-border-bright p-[3px] text-hq-moss',
                        )}
                    >
                        <venue.Icon
                            aria-label={venue.isHome ? 'Casa' : 'Fuera'}
                            className="h-[13px] w-[13px]"
                        />
                    </HqTooltip>
                )}

                {useRealCoordinates
                    ? players.map((entry) => (
                          <div
                              key={entry.id}
                              className={cn(
                                  'absolute z-10 flex -translate-x-1/2 justify-center',
                                  tokenWidthForRowCount(
                                      lineSizes.get(
                                          entry.pitch_top as number,
                                      ) ?? 1,
                                  ),
                              )}
                              style={{
                                  top: `${entry.pitch_top}%`,
                                  left: `${entry.pitch_left}%`,
                              }}
                          >
                              <PlayerToken
                                  entry={entry}
                                  onSelectPlayer={onSelectPlayer}
                                  now={now}
                                  showTeamBadge={showTeamBadge}
                                  showStarterBadge={showStarterBadge}
                                  showLiveIndicator={showLiveIndicator}
                                  widthClass="w-full"
                              />
                          </div>
                      ))
                    : rows.map((row) => {
                          const widthClass = tokenWidthForRowCount(
                              row.entries.length + row.emptySlots,
                          );

                          return (
                              <div
                                  key={row.position}
                                  className="absolute right-1.5 left-1.5 z-10 flex justify-evenly"
                                  style={{ top: row.top }}
                              >
                                  {row.entries.map((entry) => (
                                      <PlayerToken
                                          key={entry.id}
                                          entry={entry}
                                          onSelectPlayer={onSelectPlayer}
                                          now={now}
                                          showTeamBadge={showTeamBadge}
                                          showStarterBadge={showStarterBadge}
                                          showLiveIndicator={showLiveIndicator}
                                          widthClass={widthClass}
                                      />
                                  ))}
                                  {Array.from({ length: row.emptySlots }).map(
                                      (_, index) => (
                                          <EmptySlot
                                              key={`empty-${row.position}-${index}`}
                                              widthClass={widthClass}
                                          />
                                      ),
                                  )}
                              </div>
                          );
                      })}
            </div>

            {substitutes.length > 0 && (
                <div className="mt-3.5">
                    <p className="mb-3 text-center hq-label">Suplentes</p>
                    <div className="flex flex-wrap justify-center gap-x-2 gap-y-4">
                        {substitutes.map((entry) => (
                            <PlayerToken
                                key={entry.id}
                                entry={entry}
                                onSelectPlayer={onSelectPlayer}
                                now={now}
                                showTeamBadge={showTeamBadge}
                                showStarterBadge={showStarterBadge}
                                showLiveIndicator={showLiveIndicator}
                                widthClass="w-[72px]"
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
