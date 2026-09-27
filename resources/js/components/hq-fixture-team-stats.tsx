import { HqLed } from '@/components/hq-led';
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

function isTrailing(side: Side, leader: Side | null): boolean {
    return leader !== null && leader !== side;
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
        isTrailing(side, leader) ? 'bg-hq-khaki/32' : 'bg-hq-khaki';

    return (
        <div
            aria-hidden="true"
            className={cn('grid grid-cols-2 gap-0.5', className)}
        >
            <div className="flex justify-end bg-[#1b2014]">
                <span
                    className={fill('local')}
                    style={{ width: `${width(local)}%` }}
                />
            </div>
            <div className="flex bg-[#1b2014]">
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
    const reading = (side: Side, value: number) => (
        <HqLed
            tone={isTrailing(side, leader) ? 'off' : 'lime'}
            className="text-[44px]"
        >
            {possessionFormat.format(value)}
            <small className="text-xl opacity-70">%</small>
        </HqLed>
    );

    return (
        <div className="border-b border-hq-border px-4 pt-4 pb-3.5">
            <p className="mb-1.5 text-center hq-label">Posesión</p>
            <div className="flex items-baseline justify-between">
                {reading('local', possession.local)}
                {reading('guest', possession.guest)}
            </div>
            <DivergingBar
                local={possession.local}
                guest={possession.guest}
                className="mt-2 h-2.5"
            />
        </div>
    );
}

/** Datos del partido: possession as two dot-matrix readouts, then one diverging bar per boxscore stat (leader lit). */
export function HqFixtureTeamStats({
    stats,
    possession = null,
}: HqFixtureTeamStatsProps) {
    if (!possession && stats.length === 0) {
        return (
            <p className="m-3.5 border border-dashed border-hq-border-bright px-4 py-6 text-center font-mono text-xs text-hq-moss-dim sm:m-4">
                Sin datos del partido todavía
            </p>
        );
    }

    return (
        <div>
            {possession && <PossessionBlock possession={possession} />}
            {stats.map((stat) => {
                const leader = leaderOf(stat.local, stat.guest);

                return (
                    <div
                        key={stat.label}
                        className="border-b border-hq-border px-4 py-[9px]"
                    >
                        <div className="mb-1.5 flex items-baseline justify-between font-mono text-[13px] leading-none font-bold tabular-nums">
                            <span
                                className={
                                    isTrailing('local', leader)
                                        ? 'text-hq-moss-dim'
                                        : 'text-hq-paper'
                                }
                            >
                                {stat.local}
                            </span>
                            <span className="text-[10.5px] font-semibold tracking-[0.07em] text-hq-moss uppercase">
                                {stat.label}
                            </span>
                            <span
                                className={
                                    isTrailing('guest', leader)
                                        ? 'text-hq-moss-dim'
                                        : 'text-hq-paper'
                                }
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
