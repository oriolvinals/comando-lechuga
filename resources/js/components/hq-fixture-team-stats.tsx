import { cn } from '@/lib/utils';
import type { FixtureTeamStat } from '@/types/models';

interface HqFixtureTeamStatsProps {
    stats: FixtureTeamStat[];
    /** Ball possession per side, in percent — null until the boxscore has been synced. */
    possession?: { local: number; guest: number } | null;
}

type Side = 'local' | 'guest';

const possessionFormat = new Intl.NumberFormat('es-ES', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

// Whichever side has more is the one that stands out. It deliberately doesn't
// judge whether more is better (fouls, cards...), and a tie leaves both lit.
function leaderOf(local: number, guest: number): Side | null {
    if (local === guest) {
        return null;
    }

    return local > guest ? 'local' : 'guest';
}

function numberClass(side: Side, leader: Side | null): string {
    return leader !== null && leader !== side
        ? 'text-hq-moss-dim'
        : 'text-hq-paper';
}

/**
 * Two bars growing outwards from a centre axis (local to the left, guest to
 * the right), both scaled to the larger of the two so the leader always fills
 * its half and the gap reads at a glance.
 */
function DivergingBar({
    local,
    guest,
    className,
}: {
    local: number;
    guest: number;
    className?: string;
}) {
    const max = Math.max(local, guest);
    const leader = leaderOf(local, guest);
    const width = (value: number) => (max === 0 ? 0 : (value / max) * 100);
    const fill = (side: Side) =>
        leader !== null && leader !== side ? 'bg-hq-khaki/35' : 'bg-hq-khaki';

    return (
        <div className={cn('grid grid-cols-2 gap-0.5', className)}>
            <div className="flex justify-end bg-hq-border">
                <span
                    className={fill('local')}
                    style={{ width: `${width(local)}%` }}
                />
            </div>
            <div className="flex bg-hq-border">
                <span
                    className={fill('guest')}
                    style={{ width: `${width(guest)}%` }}
                />
            </div>
        </div>
    );
}

function PossessionBlock({
    possession,
}: {
    possession: { local: number; guest: number };
}) {
    const leader = leaderOf(possession.local, possession.guest);

    return (
        <div className="mb-4 border-b border-hq-border pb-4">
            <p className="mb-2 text-center font-mono text-[10px] tracking-wide text-hq-moss uppercase">
                Posesión
            </p>
            <div className="flex items-baseline justify-between font-display text-4xl">
                <span className={numberClass('local', leader)}>
                    {possessionFormat.format(possession.local)}
                    <span className="ml-0.5 text-lg opacity-70">%</span>
                </span>
                <span className={numberClass('guest', leader)}>
                    {possessionFormat.format(possession.guest)}
                    <span className="ml-0.5 text-lg opacity-70">%</span>
                </span>
            </div>
            <DivergingBar
                local={possession.local}
                guest={possession.guest}
                className="mt-2 h-2.5"
            />
        </div>
    );
}

export function HqFixtureTeamStats({
    stats,
    possession = null,
}: HqFixtureTeamStatsProps) {
    return (
        <div className="border border-hq-border bg-hq-panel px-4 py-3.5">
            {possession && <PossessionBlock possession={possession} />}
            {stats.map((stat) => {
                const leader = leaderOf(stat.local, stat.guest);

                return (
                    <div key={stat.label} className="mb-3.5 last:mb-0">
                        <div className="mb-1 flex items-baseline justify-between font-mono text-xs">
                            <span
                                className={cn(
                                    'font-bold',
                                    numberClass('local', leader),
                                )}
                            >
                                {stat.local}
                            </span>
                            <span className="text-[10px] tracking-wide text-hq-moss uppercase">
                                {stat.label}
                            </span>
                            <span
                                className={cn(
                                    'font-bold',
                                    numberClass('guest', leader),
                                )}
                            >
                                {stat.guest}
                            </span>
                        </div>
                        <DivergingBar
                            local={stat.local}
                            guest={stat.guest}
                            className="h-1.5"
                        />
                    </div>
                );
            })}
        </div>
    );
}
