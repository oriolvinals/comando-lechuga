import { Shield } from 'lucide-react';
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { cn } from '@/lib/utils';
import type { Team } from '@/types/models';

interface HqFixtureTeamColumnsProps {
    localTeam: Team;
    guestTeam: Team;
    /** The team shown below `md`, where only one column fits. */
    selectedTeamId: number;
    onSelectTeam: (teamId: number) => void;
    /** Right-hand note in each column head ("4-3-3 · titulares", "suplentes"). */
    columnNote: (team: Team) => ReactNode;
    renderColumn: (team: Team) => ReactNode;
}

/**
 * Both teams side by side (mock `.twocol`), each under a ruled head with its
 * crest, name and a note. Below `md` the columns collapse to one and a
 * full-width team switcher (44px tabs) picks which side is shown.
 */
export function HqFixtureTeamColumns({
    localTeam,
    guestTeam,
    selectedTeamId,
    onSelectTeam,
    columnNote,
    renderColumn,
}: HqFixtureTeamColumnsProps) {
    const teams = [localTeam, guestTeam];

    return (
        <div>
            <div
                role="group"
                aria-label="Equipo a mostrar"
                className="flex border-b border-hq-border-strong md:hidden"
            >
                {teams.map((team) => (
                    <button
                        key={team.id}
                        type="button"
                        aria-pressed={selectedTeamId === team.id}
                        onClick={() => onSelectTeam(team.id)}
                        className={cn(
                            '-mb-px min-h-11 flex-1 truncate border-b-2 px-4 font-mono text-[11.5px] font-bold tracking-[0.07em] uppercase transition-colors',
                            selectedTeamId === team.id
                                ? 'border-hq-lime text-hq-lime'
                                : 'border-transparent text-hq-moss hover:text-hq-paper',
                        )}
                    >
                        {team.main_name}
                    </button>
                ))}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2">
                {teams.map((team, index) => (
                    <div
                        key={team.id}
                        className={cn(
                            'min-w-0',
                            selectedTeamId === team.id ? 'block' : 'hidden',
                            'md:block',
                            index === 1 && 'md:border-l md:border-hq-border',
                        )}
                    >
                        <div className="flex items-center gap-2 border-b border-hq-border px-3.5 py-[9px] font-mono text-[11px] leading-none font-bold tracking-[0.08em] text-hq-moss uppercase">
                            <EntityImage
                                src={team.logo}
                                alt=""
                                fallback={Shield}
                                shape="square"
                                className="h-4 w-4 rounded-none bg-transparent"
                            />
                            <span className="truncate">{team.main_name}</span>
                            <span className="ml-auto shrink-0 font-medium text-hq-moss-dim">
                                {columnNote(team)}
                            </span>
                        </div>
                        {renderColumn(team)}
                    </div>
                ))}
            </div>
        </div>
    );
}
