import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqSection } from '@/components/hq-section';
import { cn } from '@/lib/utils';
import type { SeasonTeam } from '@/types/models';

interface StandingsTableProps {
    standings: SeasonTeam[];
}

export function StandingsTable({ standings }: StandingsTableProps) {
    return (
        <HqSection number="02" title="Parte de clasificación">
            <div className="flex flex-col gap-1.5">
                {standings.map((team, index) => (
                    <div
                        key={team.id}
                        className={cn(
                            'flex items-center gap-3.5 border border-l-[3px] border-hq-border bg-hq-panel px-3.5 py-2.5',
                            index === 0 && 'border-l-hq-lime bg-hq-panel-alt',
                        )}
                    >
                        <span
                            className={cn(
                                'w-6 font-display text-lg text-hq-moss-dim',
                                index === 0 && 'text-hq-lime',
                            )}
                        >
                            {index + 1}
                        </span>
                        <EntityImage
                            src={team.logo}
                            alt={team.name}
                            fallback={Shield}
                            shape="square"
                            className="hq-crest-cut h-8 w-8 bg-hq-border p-1 text-hq-khaki"
                        />
                        <span className="flex-1 text-sm font-bold text-hq-paper">
                            {team.name}
                        </span>
                        <span className="mr-2.5 rounded bg-hq-lime/10 px-1.5 py-0.5 font-mono text-[10px] text-hq-lime">
                            +{team.live_points} J
                        </span>
                        <span className="font-display text-lg text-hq-paper">
                            {team.total_points}
                        </span>
                    </div>
                ))}
            </div>
        </HqSection>
    );
}
