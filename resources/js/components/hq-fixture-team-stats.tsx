import type { FixtureTeamStat } from '@/types/models';

interface HqFixtureTeamStatsProps {
    stats: FixtureTeamStat[];
    /** Ball possession per side, in percent — null until the boxscore has been synced. */
    possession?: { local: number; guest: number } | null;
}

const possessionFormat = new Intl.NumberFormat('es-ES', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
});

function PossessionBlock({
    possession,
}: {
    possession: { local: number; guest: number };
}) {
    const total = possession.local + possession.guest;
    const localPct = total === 0 ? 50 : (possession.local / total) * 100;

    return (
        <div className="mb-4 border-b border-hq-border pb-4">
            <p className="mb-2 text-center font-mono text-[10px] tracking-wide text-hq-moss uppercase">
                Posesión
            </p>
            <div className="flex items-baseline justify-between font-display text-4xl">
                <span className="text-hq-lime">
                    {possessionFormat.format(possession.local)}
                    <span className="ml-0.5 text-lg opacity-70">%</span>
                </span>
                <span className="text-hq-azure">
                    {possessionFormat.format(possession.guest)}
                    <span className="ml-0.5 text-lg opacity-70">%</span>
                </span>
            </div>
            <div className="mt-2 flex h-2.5 gap-0.5 overflow-hidden">
                <span
                    className="bg-hq-lime"
                    style={{ width: `${localPct}%` }}
                />
                <span
                    className="bg-hq-azure"
                    style={{ width: `${100 - localPct}%` }}
                />
            </div>
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
                const total = stat.local + stat.guest;
                const localPct = total === 0 ? 50 : (stat.local / total) * 100;

                return (
                    <div key={stat.label} className="mb-3.5 last:mb-0">
                        <div className="mb-1 flex items-baseline justify-between font-mono text-xs">
                            <span className="font-bold text-hq-lime">
                                {stat.local}
                            </span>
                            <span className="text-[10px] tracking-wide text-hq-moss uppercase">
                                {stat.label}
                            </span>
                            <span className="font-bold text-hq-azure">
                                {stat.guest}
                            </span>
                        </div>
                        <div className="flex h-1.5 overflow-hidden bg-hq-border">
                            <span
                                className="bg-hq-lime"
                                style={{ width: `${localPct}%` }}
                            />
                            <span
                                className="bg-hq-azure"
                                style={{ width: `${100 - localPct}%` }}
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
