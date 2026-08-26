import { ArrowDown, ArrowUp, Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency } from '@/lib/format';
import { crestTintStyle } from '@/lib/season-team-colors';
import type { Season, SeasonTeam } from '@/types/models';

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

interface TeamHeroProps {
    seasonTeam: SeasonTeam;
    season: Season;
}

export function TeamHero({ seasonTeam, season }: TeamHeroProps) {
    const medal = MEDAL_COLOR_VARS[seasonTeam.position];
    const borderColor = medal ?? 'var(--color-hq-border-strong)';
    const chipTextColor = medal ?? 'var(--color-hq-paper)';
    const crestFillStyle = medal
        ? {
              backgroundColor: `color-mix(in srgb, ${medal} 22%, var(--color-hq-border))`,
          }
        : crestTintStyle(seasonTeam.primary_color);

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
                            src={seasonTeam.logo}
                            alt={seasonTeam.name}
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
                        {seasonTeam.position}.º
                        <RankTrend
                            position={seasonTeam.position}
                            lastPosition={seasonTeam.last_position}
                        />
                    </div>
                </div>

                <h1 className="font-display text-3xl text-hq-paper uppercase">
                    {seasonTeam.name}
                </h1>

                <div className="ml-auto flex flex-wrap gap-2.5">
                    <div className="border border-hq-border bg-hq-panel-alt/80 px-4 py-2 text-center">
                        <div className="font-mono text-[9px] text-hq-moss uppercase">
                            Puntos
                        </div>
                        <div className="mt-0.5 font-display text-xl text-hq-paper">
                            {seasonTeam.total_points}
                        </div>
                    </div>
                    {seasonTeam.live_points !== null && (
                        <div className="border border-hq-border bg-hq-panel-alt/80 px-4 py-2 text-center">
                            <div className="font-mono text-[9px] text-hq-moss uppercase">
                                J{season.current_week} en directo
                            </div>
                            <div className="mt-0.5 font-display text-xl text-hq-lime">
                                +{seasonTeam.live_points}
                            </div>
                        </div>
                    )}
                    <div className="border border-hq-border bg-hq-panel-alt/80 px-4 py-2 text-center">
                        <div className="font-mono text-[9px] text-hq-moss uppercase">
                            Valor
                        </div>
                        <div className="mt-0.5 font-display text-xl text-hq-paper">
                            {formatCurrency(seasonTeam.value)}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
