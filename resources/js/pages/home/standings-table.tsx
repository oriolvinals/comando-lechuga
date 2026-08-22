import { ArrowDown, ArrowUp, Minus, Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqSection } from '@/components/hq-section';
import { formatCurrency } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SeasonTeam } from '@/types/models';

interface StandingsTableProps {
    standings: SeasonTeam[];
    currentWeek: number;
}

const MEDAL_BORDERS = [
    'border-l-hq-gold',
    'border-l-hq-silver',
    'border-l-hq-bronze',
];

export function StandingsTable({
    standings,
    currentWeek,
}: StandingsTableProps) {
    return (
        <HqSection title="Clasificación">
            <div className="flex flex-col gap-1.5">
                {standings.map((team, index) => {
                    const moved = team.position - team.last_position;

                    return (
                        <div
                            key={team.id}
                            className={cn(
                                'flex items-center gap-4 border border-l-4 border-hq-border bg-hq-panel px-4 py-3',
                                index < 3 && MEDAL_BORDERS[index],
                                index === 0 && 'bg-hq-panel-alt',
                            )}
                        >
                            <span className="w-6 font-display text-xl text-hq-moss-dim">
                                {team.position}
                            </span>
                            {moved < 0 ? (
                                <ArrowUp className="h-4 w-4 shrink-0 text-hq-lime" />
                            ) : moved > 0 ? (
                                <ArrowDown className="h-4 w-4 shrink-0 text-hq-live" />
                            ) : (
                                <Minus className="h-4 w-4 shrink-0 text-hq-moss-dim" />
                            )}
                            <EntityImage
                                src={team.logo}
                                alt={team.name}
                                fallback={Shield}
                                shape="square"
                                className="hq-crest-cut h-14 w-14 bg-hq-border p-1.5 text-hq-khaki"
                            />
                            <div className="flex-1">
                                <p className="text-lg font-extrabold text-hq-paper">
                                    {team.name}
                                </p>
                                <p className="font-mono text-[11px] text-hq-moss-dim">
                                    {formatCurrency(team.value)}
                                </p>
                            </div>
                            <span className="mr-1 rounded bg-hq-lime/10 px-2 py-1 font-mono text-[11px] text-hq-lime">
                                J{currentWeek} +{team.live_points}
                            </span>
                            <span className="font-display text-3xl text-hq-paper">
                                {team.total_points}
                            </span>
                        </div>
                    );
                })}
            </div>
        </HqSection>
    );
}
