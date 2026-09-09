import { Shield, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { cn } from '@/lib/utils';
import type { PlayerPosition, ManagerLineupPlayerEntry } from '@/types/models';

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
 * black, so the badge is always legible against the pitch. A player whose
 * team's match already finished but who has no score wasn't called up —
 * that's a distinct tier from simply "not played yet".
 */
function pointsBadgeTierClass(
    points: number | null,
    notCalledUp: boolean,
): string {
    if (points === null) {
        return notCalledUp
            ? 'border-dashed border-hq-live bg-hq-border-strong text-hq-live'
            : 'border-hq-border-strong bg-hq-border-strong text-hq-moss';
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
    nameMaxWidth: string;
}

function PlayerToken({
    entry,
    onSelectPlayer,
    showTeamBadge,
    nameMaxWidth,
}: PlayerTokenProps) {
    return (
        <button
            type="button"
            onClick={() => onSelectPlayer(entry)}
            className="relative shrink-0 cursor-pointer"
        >
            <span className="block h-12 w-12 overflow-hidden rounded-[3px] border-2 border-white bg-hq-border">
                <EntityImage
                    src={entry.player.image}
                    alt={entry.player.nickname}
                    fallback={User}
                    shape="square"
                    className="h-full w-full translate-y-[8%] object-cover object-bottom"
                />
            </span>
            {showTeamBadge && (
                <EntityImage
                    src={entry.player.team.logo}
                    alt={entry.player.team.main_name}
                    fallback={Shield}
                    shape="square"
                    className="absolute -top-2.5 -left-2.5 h-6 w-6 rounded-[3px] bg-hq-panel p-1"
                />
            )}
            <span
                className={cn(
                    'absolute -right-1.5 -bottom-1 flex h-[18px] w-6 items-center justify-center rounded-[3px] border font-mono text-[11px] leading-none font-bold',
                    pointsBadgeTierClass(
                        entry.points,
                        entry.points === null && entry.match_finished,
                    ),
                )}
            >
                {entry.points ?? (entry.match_finished ? 'NC' : '–')}
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
    tacticalFormation?: number[] | null;
    onSelectPlayer: (entry: ManagerLineupPlayerEntry) => void;
    /** Show each player's club crest badge. Off on a team's own ficha, where every player is the same club. */
    showTeamBadge?: boolean;
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
    tacticalFormation,
    onSelectPlayer,
    showTeamBadge = true,
}: HqLineupPitchProps) {
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
                                  nameMaxWidth={nameMaxWidthForRowCount(
                                      lineSizes.get(entry.pitch_top as number) ??
                                          1,
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
                                          nameMaxWidth={nameMaxWidth}
                                      />
                                  ))}
                                  {Array.from({ length: row.emptySlots }).map(
                                      (_, index) => (
                                          <div
                                              key={`empty-${row.position}-${index}`}
                                              className="flex h-12 w-12 shrink-0 items-center justify-center rounded-[3px] border-2 border-dashed border-white/40"
                                          >
                                              <User className="h-5 w-5 text-white/40" />
                                          </div>
                                      ),
                                  )}
                              </div>
                          );
                      })}
            </div>
        </div>
    );
}
