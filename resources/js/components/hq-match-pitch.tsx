import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqLineupPlayerToken } from '@/components/hq-lineup-player-token';
import { cn } from '@/lib/utils';
import type { FixtureLineupEntry, Team } from '@/types/models';

interface HqMatchPitchProps {
    lineups: FixtureLineupEntry[];
    localTeam: Team;
    guestTeam: Team;
    localFormation?: string | null;
    guestFormation?: string | null;
    onSelect?: (entry: FixtureLineupEntry) => void;
}

/** Horizontal pitch markings (mock PITCH_H): touchlines, halfway line, centre circle, both boxes. */
function PitchLines() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 1000 560"
            preserveAspectRatio="none"
            className="absolute inset-3.5 h-[calc(100%-28px)] w-[calc(100%-28px)] fill-none stroke-hq-pitch-line [stroke-width:1.2] [&>*]:[vector-effect:non-scaling-stroke]"
        >
            <rect x="0" y="0" width="1000" height="560" />
            <line x1="500" y1="0" x2="500" y2="560" />
            <circle cx="500" cy="280" r="74" />
            <rect x="0" y="140" width="130" height="280" />
            <rect x="0" y="210" width="46" height="140" />
            <rect x="870" y="140" width="130" height="280" />
            <rect x="954" y="210" width="46" height="140" />
        </svg>
    );
}

function FormationTag({
    team,
    formation,
    side,
}: {
    team: Team;
    formation: string;
    side: 'local' | 'guest';
}) {
    const crest = (
        <EntityImage
            src={team.logo}
            alt=""
            fallback={Shield}
            shape="square"
            className="h-3.5 w-3.5 rounded-none bg-transparent"
        />
    );

    return (
        <span
            className={cn(
                'absolute top-2 z-10 flex items-center gap-1.5 border border-hq-border-bright bg-hq-ink px-1.5 py-1 font-mono text-[10.5px] leading-none font-bold text-hq-moss',
                side === 'local' ? 'left-2' : 'right-2',
            )}
        >
            {side === 'local' && crest}
            {formation}
            {side === 'guest' && crest}
        </span>
    );
}

/**
 * Both starting XIs on one dark horizontal pitch (mock `.mpitch`), each
 * player at the x/y the backend resolved from their real match role, with
 * each side's formation tagged in its top corner.
 */
export function HqMatchPitch({
    lineups,
    localTeam,
    guestTeam,
    localFormation,
    guestFormation,
    onSelect,
}: HqMatchPitchProps) {
    const starters = lineups.filter(
        (entry) => entry.starter && entry.x !== null && entry.y !== null,
    );

    return (
        <div className="relative aspect-[16/9.2] w-full overflow-hidden border-b border-hq-border bg-hq-pitch">
            <div
                aria-hidden="true"
                className="absolute inset-0 bg-[linear-gradient(rgba(196,255,61,0.035)_1px,transparent_1px),linear-gradient(90deg,rgba(196,255,61,0.035)_1px,transparent_1px)] bg-size-[6.25%_10%]"
            />
            <PitchLines />

            {localFormation && (
                <FormationTag
                    team={localTeam}
                    formation={localFormation}
                    side="local"
                />
            )}
            {guestFormation && (
                <FormationTag
                    team={guestTeam}
                    formation={guestFormation}
                    side="guest"
                />
            )}

            {starters.map((entry) => (
                <div
                    key={entry.id}
                    className="absolute z-[2] -translate-x-1/2 -translate-y-1/2"
                    style={{ left: `${entry.x}%`, top: `${entry.y}%` }}
                >
                    <HqLineupPlayerToken
                        entry={entry}
                        variant="pitch"
                        onSelect={onSelect}
                    />
                </div>
            ))}
        </div>
    );
}
