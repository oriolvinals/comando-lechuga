import { HqLineupPlayerToken } from '@/components/hq-lineup-player-token';
import { cn } from '@/lib/utils';
import type { FixtureLineupEntry } from '@/types/models';

interface HqFixtureBenchProps {
    lineups: FixtureLineupEntry[];
    localTeamId: number;
    guestTeamId: number;
    onSelect?: (entry: FixtureLineupEntry) => void;
}

function BenchColumn({ entries, onSelect }: { entries: FixtureLineupEntry[]; onSelect?: (entry: FixtureLineupEntry) => void }) {
    return (
        <div className="divide-y divide-hq-border border border-hq-border bg-hq-panel">
            {entries.map((entry) => (
                <div key={entry.id} className={cn(!entry.subbed_in && 'opacity-55')}>
                    <HqLineupPlayerToken entry={entry} variant="bench" onSelect={onSelect} />
                </div>
            ))}
        </div>
    );
}

export function HqFixtureBench({ lineups, localTeamId, guestTeamId, onSelect }: HqFixtureBenchProps) {
    const bench = lineups.filter((entry) => !entry.starter);

    return (
        <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
            <div>
                <p className="mb-1.5 font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                    Banquillo · Local
                </p>
                <BenchColumn entries={bench.filter((entry) => entry.team_id === localTeamId)} onSelect={onSelect} />
            </div>
            <div>
                <p className="mb-1.5 font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                    Banquillo · Visitante
                </p>
                <BenchColumn entries={bench.filter((entry) => entry.team_id === guestTeamId)} onSelect={onSelect} />
            </div>
        </div>
    );
}
