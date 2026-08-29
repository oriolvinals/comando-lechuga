import type { FixtureTeamStat } from '@/types/models';

interface HqFixtureTeamStatsProps {
    stats: FixtureTeamStat[];
}

export function HqFixtureTeamStats({ stats }: HqFixtureTeamStatsProps) {
    return (
        <div className="border border-hq-border bg-hq-panel px-4 py-3.5">
            {stats.map((stat) => {
                const total = stat.local + stat.guest || 1;
                const localPct = (stat.local / total) * 100;

                return (
                    <div key={stat.label} className="mb-3.5 last:mb-0">
                        <div className="mb-1 flex items-baseline justify-between font-mono text-xs">
                            <span className="font-bold text-hq-lime">{stat.local}</span>
                            <span className="text-[10px] tracking-wide text-hq-moss uppercase">{stat.label}</span>
                            <span className="font-bold text-hq-azure">{stat.guest}</span>
                        </div>
                        <div className="flex h-1.5 overflow-hidden bg-hq-border">
                            <span className="bg-hq-lime" style={{ width: `${localPct}%` }} />
                            <span className="bg-hq-azure" style={{ width: `${100 - localPct}%` }} />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
