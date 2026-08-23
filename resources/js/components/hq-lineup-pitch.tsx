import { Shield, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { cn } from '@/lib/utils';
import type {
    PlayerPosition,
    SeasonTeamLineupPlayerEntry,
} from '@/types/models';

/**
 * Top-to-bottom row order and vertical anchor (% of pitch height), matching
 * the official LaLiga Fantasy app: goalkeeper at the top. Each row sits in
 * its logical zone — keeper inside their own box, defenders just outside it,
 * midfield around the halfway line, attackers in the final third short of
 * the opposite box — with an even ~22-23% rhythm between lines.
 */
const ROWS: { position: PlayerPosition; top: string }[] = [
    { position: 'goalkeeper', top: '10%' },
    { position: 'defender', top: '32%' },
    { position: 'midfield', top: '55%' },
    { position: 'striker', top: '78%' },
];

/**
 * Same tiers as `matchPointsBadgeClass`, but opaque — the badge sits on
 * grass, not a dark panel, so the translucent tints used elsewhere lose
 * contrast here. Every tier (including "no data") gets a real color, never
 * black, so the badge is always legible against the pitch.
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
 * A row with 5 players (e.g. a back five) has meaningfully less width per
 * player than a row of 2-3 — give the name pill less room to grow as the row
 * gets denser, rather than letting flexbox squeeze it to an unreadable
 * sliver at a fixed max-width. The avatar itself stays a constant size.
 */
function nameMaxWidthForRowCount(count: number): string {
    if (count <= 2) {
        return 'max-w-28';
    }
    if (count === 3) {
        return 'max-w-24';
    }
    if (count === 4) {
        return 'max-w-20';
    }
    return 'max-w-16';
}

interface HqLineupPitchProps {
    players: SeasonTeamLineupPlayerEntry[];
    onSelectPlayer: (entry: SeasonTeamLineupPlayerEntry) => void;
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
                    <div className="absolute top-1/2 left-1/2 h-[26%] w-[26%] -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white/75" />
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
                                    className="flex min-w-0 flex-col items-center gap-1"
                                >
                                    <span className="relative shrink-0">
                                        <EntityImage
                                            src={entry.player.image}
                                            alt={entry.player.nickname}
                                            fallback={User}
                                            className="h-10 w-10 border-2 border-white bg-hq-border"
                                        />
                                        <span
                                            className={cn(
                                                'absolute -right-1.5 -bottom-1 flex h-[18px] w-6 items-center justify-center rounded-[3px] border font-mono text-[11px] leading-none font-bold',
                                                pointsBadgeTierClass(
                                                    entry.points,
                                                ),
                                            )}
                                        >
                                            {entry.points ?? '–'}
                                        </span>
                                    </span>
                                    <span
                                        className={cn(
                                            'flex w-full min-w-0 items-center gap-1 rounded-[3px] bg-hq-ink/85 px-1.5 py-px',
                                            nameMaxWidth,
                                        )}
                                    >
                                        <EntityImage
                                            src={entry.player.team.logo}
                                            alt={entry.player.team.name}
                                            fallback={Shield}
                                            shape="square"
                                            className="h-3 w-3 shrink-0"
                                        />
                                        <span className="min-w-0 truncate font-mono text-[10px] font-bold text-hq-paper">
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
