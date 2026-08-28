import { ArrowDown, ArrowUp, Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency } from '@/lib/format';
import { formatSignedPoints } from '@/lib/points';
import { crestTintStyle } from '@/lib/season-manager-colors';
import type { Season, SeasonManager } from '@/types/models';

/** Medal color per podium spot — same palette as the home hero's PODIUM_SIZES. Outside the podium the crest keeps a neutral border. */
const MEDAL_COLOR_VARS: Record<number, string> = {
    1: 'var(--color-hq-gold)',
    2: 'var(--color-hq-silver)',
    3: 'var(--color-hq-bronze)',
};

function RankTrend({
    position,
    lastPosition,
}: {
    position: number;
    lastPosition: number;
}) {
    if (position < lastPosition) {
        return (
            <ArrowUp
                className="h-[11px] w-[11px] text-hq-lime"
                strokeWidth={3.5}
            />
        );
    }

    if (position > lastPosition) {
        return (
            <ArrowDown
                className="h-[11px] w-[11px] text-hq-live"
                strokeWidth={3.5}
            />
        );
    }

    return null;
}

interface ManagerHeroProps {
    seasonManager: SeasonManager;
    season: Season;
    wonWeeks: number[];
}

export function ManagerHero({
    seasonManager,
    season,
    wonWeeks,
}: ManagerHeroProps) {
    const medal = MEDAL_COLOR_VARS[seasonManager.position];
    const borderColor = medal ?? 'var(--color-hq-border-strong)';
    const chipTextColor = medal ?? 'var(--color-hq-paper)';
    const crestFillStyle = medal
        ? {
              backgroundColor: `color-mix(in srgb, ${medal} 22%, var(--color-hq-border))`,
          }
        : crestTintStyle(seasonManager.primary_color);

    return (
        <div className="relative p-6">
            <div className="relative flex flex-wrap items-center gap-5">
                <div className="relative h-[84px] w-[84px] shrink-0">
                    <div
                        className="h-full w-full p-[3px]"
                        style={{
                            backgroundColor: medal ?? borderColor,
                            clipPath:
                                'polygon(15% 0, 100% 0, 100% 85%, 85% 100%, 0 100%, 0 15%)',
                        }}
                    >
                        <EntityImage
                            src={seasonManager.logo}
                            alt={seasonManager.name}
                            fallback={Shield}
                            shape="square"
                            style={crestFillStyle}
                            className="hq-crest-cut h-full w-full bg-hq-border p-2 text-hq-khaki"
                        />
                    </div>
                    <div
                        className="absolute -right-2 -bottom-2 flex items-center gap-1 border-2 bg-hq-ink px-1.5 py-0.5 font-display text-sm"
                        style={{ borderColor, color: chipTextColor }}
                    >
                        {seasonManager.position}.º
                        <RankTrend
                            position={seasonManager.position}
                            lastPosition={seasonManager.last_position}
                        />
                    </div>
                </div>

                <div>
                    <h1 className="font-display text-3xl text-hq-paper uppercase">
                        {seasonManager.name}
                    </h1>
                    {wonWeeks.length > 0 && (
                        <div className="mt-1.5 flex flex-wrap gap-1.5">
                            {wonWeeks.map((week) => (
                                <span
                                    key={week}
                                    className="hq-tag-cut inline-flex items-center bg-hq-gold px-2 py-1 font-mono text-[10px] font-bold text-hq-ink"
                                >
                                    J{week}
                                </span>
                            ))}
                        </div>
                    )}
                </div>

                <div className="ml-auto flex flex-wrap gap-2.5">
                    <div className="border border-hq-border bg-hq-panel-alt/80 px-4 py-2 text-center">
                        <div className="font-mono text-[9px] text-hq-moss uppercase">
                            Puntos
                        </div>
                        <div className="mt-0.5 font-display text-xl text-hq-paper">
                            {seasonManager.total_points}
                        </div>
                    </div>
                    {seasonManager.live_points !== null && (
                        <div className="border border-hq-border bg-hq-panel-alt/80 px-4 py-2 text-center">
                            <div className="font-mono text-[9px] text-hq-moss uppercase">
                                J{season.current_week} en directo
                            </div>
                            <div className="mt-0.5 font-display text-xl text-hq-lime">
                                {formatSignedPoints(seasonManager.live_points)}
                            </div>
                        </div>
                    )}
                    <div className="border border-hq-border bg-hq-panel-alt/80 px-4 py-2 text-center">
                        <div className="font-mono text-[9px] text-hq-moss uppercase">
                            Valor
                        </div>
                        <div className="mt-0.5 font-display text-xl text-hq-paper">
                            {formatCurrency(seasonManager.value)}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
