import { Armchair, Clock, Home, Plane, Shield, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { FIXTURE_STATE_LABELS, isLiveFixtureState } from '@/lib/fixture-state';
import { RESULT_STRIP_CLASSES, resultFor } from '@/lib/team-fixture-result';
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
    { position: 'goalkeeper', top: '6%' },
    { position: 'defender', top: '28%' },
    { position: 'midfield', top: '51%' },
    { position: 'striker', top: '74%' },
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
 * Same tiers as `matchPointsBadgeClass`, but opaque — the badge sits on
 * grass, not a dark panel, so the translucent tints used elsewhere lose
 * contrast here. Every tier (including "no data") gets a real color, never
 * black, so the badge is always legible against the pitch. "Not called up"
 * used to get its own dashed tier here — that distinction now lives in the
 * status badge instead (see `lineupBadgeState`), so a null score is always
 * just the plain "no data" tier.
 */
function pointsBadgeTierClass(points: number | null): string {
    if (points === null) {
        return 'border-hq-border-strong bg-hq-border-strong text-hq-moss';
    }

    if (points < 0) {
        return 'border-hq-live bg-hq-live text-white';
    }

    if (points < 5) {
        return 'border-hq-gold bg-hq-gold text-hq-ink';
    }

    if (points < 9) {
        return 'border-hq-lime bg-hq-lime text-hq-ink';
    }

    if (points < 14) {
        return 'border-hq-azure bg-hq-azure text-white';
    }

    return 'border-hq-violet bg-hq-violet text-white';
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

function statusBadgeTierClass(state: LineupBadgeState): string {
    if (state === 'starter' || state === 'subbed_in') {
        return 'border-hq-lime text-hq-lime';
    }

    if (state === 'not_played_yet') {
        return 'border-hq-azure text-hq-azure';
    }

    return 'border-hq-live text-hq-live';
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
        return <Clock className="h-2.5 w-2.5" />;
    }

    return <Armchair className="h-2.5 w-2.5" />;
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
 * The name pill's max-width, tuned per row density: 60px is the floor for a
 * full 5-player row, and it only needs to grow from there as a row has more
 * room to spare. A 1-2 player row has none of that pressure, so it's left
 * uncapped (just `w-full` inside the button).
 */
function nameMaxWidthForRowCount(count: number): string {
    if (count >= 5) {
        return 'max-w-[60px]';
    }

    if (count === 4) {
        return 'max-w-[70px]';
    }

    if (count === 3) {
        return 'max-w-[85px]';
    }

    return '';
}

interface PlayerTokenProps {
    entry: ManagerLineupPlayerEntry;
    onSelectPlayer: (entry: ManagerLineupPlayerEntry) => void;
    showTeamBadge: boolean;
    /** Off on a team's own ficha — every starter there played the full match by definition (there's no fantasy pick to second-guess), so the checkmark is redundant. Subs/bench/not-called-up badges still show. */
    showStarterBadge: boolean;
    /** Off on a team's own ficha — the pitch there already only shows that team's real XI for a match already known to be live from the scoreline above, so a per-player glow adds noise instead of signal. Still on for a fantasy manager's lineup, where it's the only cue for which picks are live right now. */
    showLiveIndicator: boolean;
    nameMaxWidth: string;
}

function PlayerToken({
    entry,
    onSelectPlayer,
    showTeamBadge,
    showStarterBadge,
    showLiveIndicator,
    nameMaxWidth,
}: PlayerTokenProps) {
    const badgeState = lineupBadgeState(entry);
    const liveNow = showLiveIndicator && isPlayerLiveNow(entry, badgeState);
    const showBadge = badgeState !== 'starter' || showStarterBadge;

    return (
        <button
            type="button"
            onClick={() => onSelectPlayer(entry)}
            className="relative shrink-0 cursor-pointer"
        >
            <span className="relative block h-14 w-14">
                {/* Clipped separately from the status/points badges below — those
                    need to poke out past this box's own border, which a shared
                    overflow:hidden would cut off. */}
                <span
                    className={cn(
                        'absolute inset-0 overflow-hidden rounded-[3px] border-2 bg-hq-ink',
                        liveNow ? 'border-transparent' : 'border-white',
                    )}
                >
                    {/* Sits behind the photo — the photo is a cutout with
                        transparent padding around the player, so the crest reads
                        through it instead of needing its own reserved corner. */}
                    {showTeamBadge && (
                        <EntityImage
                            src={entry.player.team.logo}
                            alt={entry.player.team.main_name}
                            fallback={Shield}
                            shape="square"
                            className="absolute top-[38%] -left-1.5 h-7 w-7 -translate-y-1/2"
                        />
                    )}
                    <EntityImage
                        src={entry.player.image}
                        alt={entry.player.nickname}
                        fallback={User}
                        shape="square"
                        className="absolute inset-0 h-full w-full rounded-none border-0 object-cover"
                        style={{ objectPosition: 'center calc(45% + 6px)' }}
                    />
                </span>
                {/* Drawn as its own layer instead of animating the photo box's
                    border directly — that box's opacity would also fade the
                    photo underneath, when only the border should pulse. The
                    glow (not just the border color) is what keeps this
                    legible against a bright/busy player photo. */}
                {liveNow && (
                    <span className="pointer-events-none absolute inset-0 animate-pulse rounded-[3px] border-2 border-hq-live shadow-[0_0_8px_2px_rgba(255,61,90,0.65)]" />
                )}
                {showBadge && (
                    <span
                        className={cn(
                            'absolute -top-2 left-1/2 z-10 flex h-4 -translate-x-1/2 items-center justify-center gap-0.5 rounded-[3px] border bg-hq-ink px-1 font-mono text-[9px] leading-none font-bold whitespace-nowrap',
                            statusBadgeTierClass(badgeState),
                        )}
                    >
                        <StatusBadgeContent
                            state={badgeState}
                            subMinute={entry.sub_minute}
                        />
                    </span>
                )}
                <span
                    className={cn(
                        'absolute right-0 bottom-0 z-10 flex h-3.5 min-w-[17px] items-center justify-center rounded-[2px] border px-0.5 font-mono text-[9px] leading-none font-bold',
                        pointsBadgeTierClass(entry.points),
                    )}
                >
                    {entry.points ?? '–'}
                </span>
            </span>
            <span
                className={cn(
                    'absolute top-full left-1/2 mt-1 min-w-0 -translate-x-1/2 rounded-[3px] bg-hq-ink/85 px-1.5 py-px text-center',
                    nameMaxWidth,
                )}
            >
                <span className="block min-w-0 truncate font-mono text-[10px] font-bold text-hq-paper">
                    {entry.player.nickname}
                </span>
            </span>
        </button>
    );
}

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
            <div className="relative aspect-[280/440] w-full border-2 border-[#0e4a24] bg-[#1a6b37]">
                <div className="absolute inset-0 overflow-hidden">
                    <div
                        className="absolute inset-2 border-2 border-white/75"
                        style={{
                            background:
                                'repeating-linear-gradient(180deg, #1f7a3f 0%, #1f7a3f 12.5%, #1a6b37 12.5%, #1a6b37 25%)',
                        }}
                    />
                    <div className="absolute top-1/2 right-2 left-2 border-t-2 border-white/75" />
                    <div className="absolute top-1/2 left-1/2 aspect-square w-[26%] -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white/75" />
                    <div className="absolute top-2 left-1/2 h-[12.5%] w-[55%] -translate-x-1/2 border-2 border-t-0 border-white/75" />
                    <div className="absolute bottom-2 left-1/2 h-[12.5%] w-[55%] -translate-x-1/2 border-2 border-b-0 border-white/75" />
                </div>

                {formationLabel && (
                    <span className="absolute top-2 left-2 z-20 border border-hq-border-strong bg-hq-panel px-1.5 py-0.5 font-mono text-xs font-bold tracking-wider text-hq-moss uppercase">
                        {formationLabel}
                    </span>
                )}

                {matchStateLabel && (
                    <span
                        className={cn(
                            'absolute top-2 left-1/2 z-20 -translate-x-1/2 border bg-hq-panel px-1.5 py-0.5 font-mono text-[10px] font-bold tracking-wider whitespace-nowrap uppercase',
                            matchStateLabel.isLive
                                ? 'border-hq-live text-hq-live'
                                : 'border-hq-border-strong text-hq-moss',
                        )}
                    >
                        {matchStateLabel.text}
                    </span>
                )}

                {scoreboard && (
                    <span
                        className={cn(
                            'absolute top-2 right-2 z-20 flex items-center gap-1 border bg-hq-panel px-1.5 py-0.5 font-mono text-xs font-bold tracking-wider uppercase',
                            RESULT_STRIP_CLASSES[scoreboard.result],
                        )}
                    >
                        {scoreboard.isLive && (
                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-hq-live" />
                        )}
                        {scoreboard.ownScore}-{scoreboard.rivalScore}
                    </span>
                )}

                {venue && (
                    <span
                        title={venue.isHome ? 'Casa' : 'Fuera'}
                        className="absolute right-2 bottom-2 z-20 flex h-6 w-6 items-center justify-center border border-hq-border-strong bg-hq-panel text-hq-moss"
                    >
                        <venue.Icon className="h-3.5 w-3.5" />
                    </span>
                )}

                {useRealCoordinates
                    ? players.map((entry) => (
                          <div
                              key={entry.id}
                              className="absolute z-10 -translate-x-1/2"
                              style={{
                                  top: `${entry.pitch_top}%`,
                                  left: `${entry.pitch_left}%`,
                              }}
                          >
                              <PlayerToken
                                  entry={entry}
                                  onSelectPlayer={onSelectPlayer}
                                  showTeamBadge={showTeamBadge}
                                  showStarterBadge={showStarterBadge}
                                  showLiveIndicator={showLiveIndicator}
                                  nameMaxWidth={nameMaxWidthForRowCount(
                                      lineSizes.get(
                                          entry.pitch_top as number,
                                      ) ?? 1,
                                  )}
                              />
                          </div>
                      ))
                    : rows.map((row) => {
                          const nameMaxWidth = nameMaxWidthForRowCount(
                              row.entries.length + row.emptySlots,
                          );

                          return (
                              <div
                                  key={row.position}
                                  className="absolute right-2 left-2 z-10 flex justify-evenly"
                                  style={{ top: row.top }}
                              >
                                  {row.entries.map((entry) => (
                                      <PlayerToken
                                          key={entry.id}
                                          entry={entry}
                                          onSelectPlayer={onSelectPlayer}
                                          showTeamBadge={showTeamBadge}
                                          showStarterBadge={showStarterBadge}
                                          showLiveIndicator={showLiveIndicator}
                                          nameMaxWidth={nameMaxWidth}
                                      />
                                  ))}
                                  {Array.from({ length: row.emptySlots }).map(
                                      (_, index) => (
                                          <div
                                              key={`empty-${row.position}-${index}`}
                                              className="flex h-14 w-14 shrink-0 items-center justify-center rounded-[3px] border-2 border-dashed border-white/40"
                                          >
                                              <User className="h-5 w-5 text-white/40" />
                                          </div>
                                      ),
                                  )}
                              </div>
                          );
                      })}
            </div>

            {substitutes.length > 0 && (
                <div className="mt-4">
                    <p className="mb-2.5 text-center font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                        Suplentes
                    </p>
                    <div className="flex flex-wrap justify-center gap-x-3 gap-y-7">
                        {substitutes.map((entry) => (
                            <PlayerToken
                                key={entry.id}
                                entry={entry}
                                onSelectPlayer={onSelectPlayer}
                                showTeamBadge={showTeamBadge}
                                showStarterBadge={showStarterBadge}
                                showLiveIndicator={showLiveIndicator}
                                nameMaxWidth=""
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
