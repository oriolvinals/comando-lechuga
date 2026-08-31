import { Shield, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { cn } from '@/lib/utils';
import type {
    PlayerPosition,
    ManagerLineupPlayerEntry,
} from '@/types/models';

/**
 * Top-to-bottom row order and vertical anchor (% of pitch height), matching
 * the official LaLiga Fantasy app: goalkeeper at the top. Each row sits in
 * its logical zone — keeper inside their own box, defenders just outside it,
 * midfield around the halfway line, attackers in the final third short of
 * the opposite box — with an even ~22-23% rhythm between lines.
 */
const ROWS: { position: PlayerPosition; top: string }[] = [
    { position: 'goalkeeper', top: '6%' },
    { position: 'defender', top: '28%' },
    { position: 'midfield', top: '51%' },
    { position: 'striker', top: '74%' },
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

interface HqLineupPitchProps {
    players: ManagerLineupPlayerEntry[];
    onSelectPlayer: (entry: ManagerLineupPlayerEntry) => void;
}

export function HqLineupPitch({
    players,
    onSelectPlayer,
}: HqLineupPitchProps) {
    const rows = ROWS.map((row) => ({
        ...row,
        entries: players.filter((entry) => entry.position === row.position),
    })).filter((row) => row.entries.length > 0);

    return (
        <div>
            <div
                className="relative aspect-[280/440] w-full border-2 border-[#0e4a24]"
                style={{
                    background:
                        'repeating-linear-gradient(180deg, #1f7a3f 0px, #1f7a3f 11%, #1a6b37 11%, #1a6b37 22%)',
                }}
            >
                <div className="absolute inset-0 overflow-hidden">
                    <div className="absolute inset-2 border-2 border-white/75" />
                    <div className="absolute top-1/2 right-2 left-2 border-t-2 border-white/75" />
                    <div className="absolute top-1/2 left-1/2 aspect-square w-[26%] -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white/75" />
                    <div className="absolute top-2 left-1/2 h-[13.5%] w-[55%] -translate-x-1/2 border-2 border-t-0 border-white/75" />
                    <div className="absolute bottom-2 left-1/2 h-[13.5%] w-[55%] -translate-x-1/2 border-2 border-b-0 border-white/75" />
                </div>

                {rows.map((row) => {
                    const nameMaxWidth = nameMaxWidthForRowCount(
                        row.entries.length,
                    );

                    return (
                        <div
                            key={row.position}
                            className="absolute right-2 left-2 z-10 flex justify-evenly"
                            style={{ top: row.top }}
                        >
                            {row.entries.map((entry) => (
                                <button
                                    key={entry.id}
                                    type="button"
                                    onClick={() => onSelectPlayer(entry)}
                                    className="relative shrink-0 cursor-pointer"
                                >
                                    <EntityImage
                                        src={entry.player.image}
                                        alt={entry.player.nickname}
                                        fallback={User}
                                        shape="square"
                                        className="h-12 w-12 rounded-[3px] border-2 border-white bg-hq-border object-cover object-bottom"
                                    />
                                    <EntityImage
                                        src={entry.player.team.logo}
                                        alt={entry.player.team.main_name}
                                        fallback={Shield}
                                        shape="square"
                                        className="absolute -top-2.5 -left-2.5 h-6 w-6 rounded-[3px] bg-hq-panel p-1"
                                    />
                                    <span
                                        className={cn(
                                            'absolute -right-1.5 -bottom-1 flex h-[18px] w-6 items-center justify-center rounded-[3px] border font-mono text-[11px] leading-none font-bold',
                                            pointsBadgeTierClass(
                                                entry.points,
                                                entry.points === null &&
                                                    entry.match_finished,
                                            ),
                                        )}
                                    >
                                        {entry.points ??
                                            (entry.match_finished
                                                ? 'NC'
                                                : '–')}
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
                            ))}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
