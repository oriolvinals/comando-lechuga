import { useState } from 'react';
import { HqLineupPlayerToken } from '@/components/hq-lineup-player-token';
import { byPlayerPositionThenJersey } from '@/lib/lineup-sort';
import { cn } from '@/lib/utils';
import type { FixtureLineupEntry, Team } from '@/types/models';

interface HqFixtureLineupListProps {
    lineups: FixtureLineupEntry[];
    localTeam: Team;
    guestTeam: Team;
    onSelect?: (entry: FixtureLineupEntry) => void;
}

// Starters only — substitutes already have their own "Suplentes" tab below,
// listing them again here would just duplicate it.
function TeamStarters({ entries, onSelect }: { entries: FixtureLineupEntry[]; onSelect?: (entry: FixtureLineupEntry) => void }) {
    const sorted = entries.filter((entry) => entry.starter).toSorted(byPlayerPositionThenJersey);

    return (
        <div className="divide-y divide-hq-border border border-hq-border bg-hq-panel">
            {sorted.map((entry) => (
                <HqLineupPlayerToken key={entry.id} entry={entry} variant="bench" onSelect={onSelect} />
            ))}
        </div>
    );
}

export function HqFixtureLineupList({ lineups, localTeam, guestTeam, onSelect }: HqFixtureLineupListProps) {
    const [selectedTeamId, setSelectedTeamId] = useState(localTeam.id);

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
                    <TeamStarters entries={lineups.filter((entry) => entry.team_id === localTeam.id)} onSelect={onSelect} />
                </div>
                <div className={cn(selectedTeamId === guestTeam.id ? 'block' : 'hidden', 'sm:block')}>
                    <p className="mb-1.5 hidden font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase sm:block">
                        {guestTeam.main_name}
                    </p>
                    <TeamStarters entries={lineups.filter((entry) => entry.team_id === guestTeam.id)} onSelect={onSelect} />
                </div>
            </div>
        </div>
    );
}
