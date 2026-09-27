import { HqFixtureTeamColumns } from '@/components/hq-fixture-team-columns';
import { HqLineupPlayerToken } from '@/components/hq-lineup-player-token';
import { byPlayerPositionThenJersey } from '@/lib/lineup-sort';
import type { FixtureLineupEntry, Team } from '@/types/models';

interface HqFixtureBenchProps {
    lineups: FixtureLineupEntry[];
    localTeam: Team;
    guestTeam: Team;
    selectedTeamId: number;
    onSelectTeam: (teamId: number) => void;
    onSelect?: (entry: FixtureLineupEntry) => void;
}

/** Suplentes of both teams: the ones who came on first, then the unused ones (dimmed), position/jersey order within each group. */
export function HqFixtureBench({
    lineups,
    localTeam,
    guestTeam,
    selectedTeamId,
    onSelectTeam,
    onSelect,
}: HqFixtureBenchProps) {
    const bench = lineups
        .filter((entry) => !entry.starter)
        .toSorted(
            (a, b) =>
                Number(b.subbed_in) - Number(a.subbed_in) ||
                byPlayerPositionThenJersey(a, b),
        );

    return (
        <HqFixtureTeamColumns
            localTeam={localTeam}
            guestTeam={guestTeam}
            selectedTeamId={selectedTeamId}
            onSelectTeam={onSelectTeam}
            renderColumn={(team) =>
                bench
                    .filter((entry) => entry.team_id === team.id)
                    .map((entry) => (
                        <HqLineupPlayerToken
                            key={entry.id}
                            entry={entry}
                            variant="bench"
                            onSelect={onSelect}
                        />
                    ))
            }
        />
    );
}
