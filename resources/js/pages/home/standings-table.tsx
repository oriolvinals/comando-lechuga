import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Minus, Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqSection } from '@/components/hq-section';
import { formatCurrency } from '@/lib/format';
import { crestTintStyle } from '@/lib/season-team-colors';
import { cn } from '@/lib/utils';
import { show as seasonTeamsShow } from '@/routes/season-teams';
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

const MEDAL_BACKGROUNDS = [
    'color-mix(in srgb, var(--color-hq-gold) 14%, var(--color-hq-panel))',
    'color-mix(in srgb, var(--color-hq-silver) 10%, var(--color-hq-panel))',
    'color-mix(in srgb, var(--color-hq-bronze) 12%, var(--color-hq-panel))',
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
                        <Link
                            key={team.id}
                            href={seasonTeamsShow(team.id).url}
                            style={
                                index < 3
                                    ? { backgroundColor: MEDAL_BACKGROUNDS[index] }
                                    : undefined
                            }
                            className={cn(
                                'flex items-center gap-4 border border-l-4 border-hq-border bg-hq-panel px-4 py-3 transition-[filter] hover:brightness-125',
                                index < 3 && MEDAL_BORDERS[index],
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
                                style={crestTintStyle(team.primary_color)}
                                className="hq-crest-cut h-16 w-16 bg-hq-border p-1.5 text-hq-khaki"
                            />
                            <div className="flex-1">
                                <p className="text-lg font-extrabold text-hq-paper">
                                    {team.name}
                                </p>
                                <p className="font-mono text-[11px] text-hq-moss-dim">
                                    {formatCurrency(team.value)}
                                </p>
                            </div>
                            {team.live_points !== null && (
                                <span className="mr-1 rounded bg-hq-lime/10 px-2 py-1 font-mono text-[11px] text-hq-lime">
                                    J{currentWeek} +{team.live_points}
                                </span>
                            )}
                            <span className="font-display text-3xl text-hq-paper">
                                {team.total_points}
                            </span>
                        </Link>
                    );
                })}
            </div>
        </HqSection>
    );
}
