import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Minus, Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqSection } from '@/components/hq-section';
import { formatCurrency } from '@/lib/format';
import { formatSignedPoints, teamFormBadgeClass } from '@/lib/points';
import { crestTintStyle } from '@/lib/season-manager-colors';
import {
    standingsPrize,
    standingsPrizeClass,
    standingsPrizeText,
} from '@/lib/standings-prizes';
import { cn } from '@/lib/utils';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { SeasonManager } from '@/types/models';

interface StandingsTableProps {
    standings: SeasonManager[];
}

const MEDAL_BORDERS = [
    'border-l-hq-gold',
    'border-l-hq-silver',
    'border-l-hq-bronze',
];

const MEDAL_TEXT = ['text-hq-gold', 'text-hq-silver', 'text-hq-bronze'];

const MEDAL_BACKGROUNDS = [
    'color-mix(in srgb, var(--color-hq-gold) 14%, var(--color-hq-panel))',
    'color-mix(in srgb, var(--color-hq-silver) 10%, var(--color-hq-panel))',
    'color-mix(in srgb, var(--color-hq-bronze) 12%, var(--color-hq-panel))',
];

function StandingsHeader() {
    return (
        <div className="mb-2 hidden items-center gap-4 border-l-4 border-transparent px-4 font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase md:flex">
            <span className="w-6 shrink-0" />
            <span className="w-4 shrink-0" />
            <span className="w-14 shrink-0" />
            <span className="min-w-0 flex-1">Manager</span>
            <span className="w-28 shrink-0 text-center">Forma</span>
            <span className="w-14 shrink-0 text-right">Premio</span>
            <span className="w-12 shrink-0 text-center">Pts</span>
        </div>
    );
}

function PositionBadge({
    team,
    index,
}: {
    team: SeasonManager;
    index: number;
}) {
    return (
        <span
            className={cn(
                'w-6 shrink-0 font-display text-xl',
                index < 3 ? MEDAL_TEXT[index] : 'text-hq-moss-dim',
            )}
        >
            {team.position}
        </span>
    );
}

function MovementIcon({ moved }: { moved: number }) {
    if (moved < 0) {
        return <ArrowUp className="h-4 w-4 shrink-0 text-hq-lime" />;
    }

    if (moved > 0) {
        return <ArrowDown className="h-4 w-4 shrink-0 text-hq-live" />;
    }

    return <Minus className="h-4 w-4 shrink-0 text-hq-moss-dim" />;
}

function PrizeTag({ prize }: { prize: number | null }) {
    return (
        <span
            className={cn(
                'shrink-0 font-mono text-xs font-bold',
                standingsPrizeClass(prize),
            )}
        >
            {standingsPrizeText(prize)}
        </span>
    );
}

export function StandingsTable({ standings }: StandingsTableProps) {
    return (
        <HqSection title="Clasificación">
            <StandingsHeader />
            <div className="flex flex-col gap-1.5">
                {standings.map((team, index) => {
                    const moved = team.position - team.last_position;
                    const prize = standingsPrize(team.position);

                    return (
                        <Link
                            key={team.id}
                            href={seasonManagersShow(team.id).url}
                            style={
                                index < 3
                                    ? { backgroundColor: MEDAL_BACKGROUNDS[index] }
                                    : undefined
                            }
                            className={cn(
                                'block border border-l-4 border-hq-border bg-hq-panel px-4 py-3 transition-[filter] hover:brightness-125',
                                index < 3 && MEDAL_BORDERS[index],
                            )}
                        >
                            {/* Desktop / tablet: single line */}
                            <div className="hidden items-center gap-4 md:flex">
                                <PositionBadge team={team} index={index} />
                                <MovementIcon moved={moved} />
                                <EntityImage
                                    src={team.logo}
                                    alt={team.name}
                                    fallback={Shield}
                                    shape="square"
                                    style={crestTintStyle(team.primary_color)}
                                    className="hq-crest-cut h-14 w-14 shrink-0 bg-hq-border p-1.5 text-hq-khaki"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-lg font-extrabold text-hq-paper">
                                        {team.name}
                                    </p>
                                    <p className="font-mono text-[11px] text-hq-moss">
                                        {formatCurrency(team.value)}
                                    </p>
                                </div>
                                <HqRecentScores
                                    scores={team.recent_form}
                                    badgeClass={teamFormBadgeClass}
                                    className="w-28 justify-center"
                                />
                                <div className="w-14 shrink-0 text-right">
                                    <PrizeTag prize={prize} />
                                </div>
                                <div className="flex w-12 shrink-0 flex-col items-center gap-1">
                                    {team.live_points !== null && (
                                        <span
                                            className={cn(
                                                'rounded px-1.5 py-0.5 font-mono text-[10px]',
                                                teamFormBadgeClass(
                                                    team.live_points,
                                                ),
                                            )}
                                        >
                                            {formatSignedPoints(team.live_points)}
                                        </span>
                                    )}
                                    <span className="text-center font-display text-xl text-hq-lime">
                                        {team.total_points}
                                    </span>
                                </div>
                            </div>

                            {/* Mobile: two lines, nothing hidden */}
                            <div className="md:hidden">
                                <div className="flex items-center gap-3">
                                    <PositionBadge team={team} index={index} />
                                    <EntityImage
                                        src={team.logo}
                                        alt={team.name}
                                        fallback={Shield}
                                        shape="square"
                                        style={crestTintStyle(team.primary_color)}
                                        className="hq-crest-cut h-10 w-10 shrink-0 bg-hq-border p-1 text-hq-khaki"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-extrabold text-hq-paper">
                                            {team.name}
                                        </p>
                                        <p className="font-mono text-[10px] text-hq-moss">
                                            {formatCurrency(team.value)}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        {team.live_points !== null && (
                                            <span
                                                className={cn(
                                                    'rounded px-1.5 py-0.5 font-mono text-[10px]',
                                                    teamFormBadgeClass(
                                                        team.live_points,
                                                    ),
                                                )}
                                            >
                                                {formatSignedPoints(team.live_points)}
                                            </span>
                                        )}
                                        <span className="font-display text-xl text-hq-lime">
                                            {team.total_points}
                                        </span>
                                    </div>
                                </div>
                                <div className="mt-2 flex items-center gap-2 border-t border-hq-ink pt-2">
                                    <span className="font-mono text-[8px] font-bold tracking-wide text-hq-moss-dim uppercase">
                                        Forma
                                    </span>
                                    <HqRecentScores
                                        scores={team.recent_form}
                                        badgeClass={teamFormBadgeClass}
                                        size="sm"
                                    />
                                    <div className="ml-auto">
                                        <PrizeTag prize={prize} />
                                    </div>
                                </div>
                            </div>
                        </Link>
                    );
                })}
            </div>
        </HqSection>
    );
}
