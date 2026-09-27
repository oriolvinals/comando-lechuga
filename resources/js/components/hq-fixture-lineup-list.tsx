import { HqFixtureTeamColumns } from '@/components/hq-fixture-team-columns';
import { HqLineupPlayerToken } from '@/components/hq-lineup-player-token';
import { byPlayerPositionThenJersey } from '@/lib/lineup-sort';
import type { FixtureLineupEntry, Team } from '@/types/models';

interface HqFixtureLineupListProps {
    lineups: FixtureLineupEntry[];
    localTeam: Team;
    guestTeam: Team;
    localFormation: string | null;
    guestFormation: string | null;
    selectedTeamId: number;
    onSelectTeam: (teamId: number) => void;
    onSelect?: (entry: FixtureLineupEntry) => void;
}

/**
 * The starting XIs as ruled rows (the list view, and the only view below
 * `lg`). Starters only — substitutes have their own Suplentes section, so
 * listing them here would just duplicate it.
 */
export function HqFixtureLineupList({
    lineups,
    localTeam,
    guestTeam,
    localFormation,
    guestFormation,
    selectedTeamId,
    onSelectTeam,
    onSelect,
}: HqFixtureLineupListProps) {
    const starters = lineups
        .filter((entry) => entry.starter)
        .toSorted(byPlayerPositionThenJersey);

    return (
        <HqFixtureTeamColumns
            localTeam={localTeam}
            guestTeam={guestTeam}
            selectedTeamId={selectedTeamId}
            onSelectTeam={onSelectTeam}
            columnNote={(team) => {
                const formation =
                    team.id === localTeam.id ? localFormation : guestFormation;

                return formation ? `${formation} · titulares` : 'titulares';
            }}
            renderColumn={(team) =>
                starters
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
