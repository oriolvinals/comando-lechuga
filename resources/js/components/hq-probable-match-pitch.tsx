import { Shield } from 'lucide-react';
import { Fragment } from 'react';
import { EntityImage } from '@/components/entity-image';
import { MatchPitchLines } from '@/components/hq-match-pitch';
import { HqStartPitchToken } from '@/components/hq-start-probability';
import { landscapeSlots, splitStartEntries } from '@/lib/start-probability';
import { cn } from '@/lib/utils';
import type { StartProbabilityTeamBlock, Team } from '@/types/models';

type Side = 'local' | 'guest';

function SideTag({
    team,
    label,
    side,
}: {
    team: Team;
    label: string;
    side: Side;
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
                'absolute top-2 z-10 flex items-center gap-1.5 border border-hq-border-bright bg-hq-ink px-1.5 py-1 font-mono text-[10.5px] leading-none font-bold tracking-[0.05em] text-hq-moss uppercase',
                side === 'local' ? 'left-2' : 'right-2',
            )}
        >
            {side === 'local' && crest}
            {label}
            {side === 'guest' && crest}
        </span>
    );
}

/**
 * Both probable — or confirmed — XIs on HqMatchPitch's landscape pitch
 * (local attacking right), each player on the line of his fantasy
 * position with the strongest % in the middle. Desktop only, like
 * HqMatchPitch.
 */
export function HqProbableMatchPitch({
    local,
    guest,
    localTeam,
    guestTeam,
}: {
    local: StartProbabilityTeamBlock | null;
    guest: StartProbabilityTeamBlock | null;
    localTeam: Team;
    guestTeam: Team;
}) {
    const sides: {
        side: Side;
        team: Team;
        block: StartProbabilityTeamBlock | null;
    }[] = [
        { side: 'local', team: localTeam, block: local },
        { side: 'guest', team: guestTeam, block: guest },
    ];

    return (
        <div className="relative aspect-[16/9.2] w-full overflow-hidden border-b border-hq-border bg-hq-pitch">
            <MatchPitchLines />
            {sides.map(({ side, team, block }) => {
                const confirmed =
                    block !== null && block.confirmed_source !== null;
                const muted = block?.is_stale ?? false;
                const slots =
                    block === null
                        ? []
                        : landscapeSlots(
                              splitStartEntries(block.players, confirmed)
                                  .starters,
                              side,
                          );
                const label =
                    block === null
                        ? 'Sin datos'
                        : confirmed
                          ? 'XI confirmado'
                          : 'XI probable';

                return (
                    <Fragment key={side}>
                        <SideTag team={team} label={label} side={side} />
                        {slots.map(({ entry, left, top }) => (
                            <div
                                key={entry.player.id}
                                className="absolute z-[2] -translate-x-1/2 -translate-y-1/2"
                                style={{ left: `${left}%`, top: `${top}%` }}
                            >
                                <HqStartPitchToken
                                    entry={entry}
                                    confirmed={confirmed}
                                    muted={muted}
                                />
                            </div>
                        ))}
                    </Fragment>
                );
            })}
        </div>
    );
}
