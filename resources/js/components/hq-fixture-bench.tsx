import { HqLineupPlayerToken } from '@/components/hq-lineup-player-token';
import { cn } from '@/lib/utils';
import type { FixtureLineupEntry, PlayerPosition } from '@/types/models';

interface HqFixtureBenchProps {
    lineups: FixtureLineupEntry[];
    localTeamId: number;
    guestTeamId: number;
    onSelect?: (entry: FixtureLineupEntry) => void;
}

// worldcup26 tags every bench player's raw `position` as "Substitute" — it
// doesn't distinguish goalkeeper/defender/etc. for subs — so ordering the
// bench by real position has to come from the linked Player's own (Fantasy)
// position instead. An unresolved entry has no Player, so no position to
// sort by; it sorts after every resolved one.
const POSITION_ORDER: Record<PlayerPosition, number> = {
    goalkeeper: 0,
    defender: 1,
    midfield: 2,
    striker: 3,
    coach: 4,
};

function bySortedPosition(a: FixtureLineupEntry, b: FixtureLineupEntry): number {
    const orderA = a.player ? POSITION_ORDER[a.player.position] : 5;
    const orderB = b.player ? POSITION_ORDER[b.player.position] : 5;

    if (orderA !== orderB) {
        return orderA - orderB;
    }

    return Number(a.jersey) - Number(b.jersey);
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
    const bench = lineups.filter((entry) => !entry.starter).toSorted(bySortedPosition);

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
