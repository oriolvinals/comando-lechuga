import { useState } from 'react';
import { HqLineupPlayerToken } from '@/components/hq-lineup-player-token';
import { byPlayerPositionThenJersey } from '@/lib/lineup-sort';
import { cn } from '@/lib/utils';
import type { FixtureLineupEntry, Team } from '@/types/models';

interface HqFixtureBenchProps {
    lineups: FixtureLineupEntry[];
    localTeam: Team;
    guestTeam: Team;
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

export function HqFixtureBench({ lineups, localTeam, guestTeam, onSelect }: HqFixtureBenchProps) {
    const [selectedTeamId, setSelectedTeamId] = useState(localTeam.id);
    // Played subs first, then unused ones — position/jersey order within each group.
    const bench = lineups
        .filter((entry) => !entry.starter)
        .toSorted((a, b) => Number(b.subbed_in) - Number(a.subbed_in) || byPlayerPositionThenJersey(a, b));

    return (
        <div>
            {/* Team switcher: only needed below `sm`, where the two columns stack
                instead of sitting side by side. */}
            <div className="mb-3.5 flex gap-0.5 border-b border-hq-border-strong sm:hidden">
                {[localTeam, guestTeam].map((team) => (
                    <button
                        key={team.id}
                        type="button"
                        onClick={() => setSelectedTeamId(team.id)}
                        className={cn(
                            '-mb-px flex-1 border-b-2 px-4 py-2.5 text-center font-mono text-[11px] font-bold tracking-wider uppercase',
                            selectedTeamId === team.id
                                ? 'border-hq-lime text-hq-lime'
                                : 'border-transparent text-hq-moss hover:text-hq-paper',
                        )}
                    >
                        {team.main_name}
                    </button>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                <div className={cn(selectedTeamId === localTeam.id ? 'block' : 'hidden', 'sm:block')}>
                    <p className="mb-1.5 hidden font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase sm:block">
                        {localTeam.main_name}
                    </p>
                    <BenchColumn entries={bench.filter((entry) => entry.team_id === localTeam.id)} onSelect={onSelect} />
                </div>
                <div className={cn(selectedTeamId === guestTeam.id ? 'block' : 'hidden', 'sm:block')}>
                    <p className="mb-1.5 hidden font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase sm:block">
                        {guestTeam.main_name}
                    </p>
                    <BenchColumn entries={bench.filter((entry) => entry.team_id === guestTeam.id)} onSelect={onSelect} />
                </div>
            </div>
        </div>
    );
}
