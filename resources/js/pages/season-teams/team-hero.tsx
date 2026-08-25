import { ArrowDown, ArrowUp, Shield } from 'lucide-react';
import type { CSSProperties } from 'react';
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

    return (
        <div
            className="relative overflow-hidden p-6"
            style={
                {
                    '--pc': seasonTeam.primary_color ?? 'transparent',
                    '--sc': seasonTeam.secondary_color ?? 'transparent',
                } as CSSProperties
            }
        >
            <div
                className="pointer-events-none absolute inset-0 opacity-25"
                style={{
                    background:
                        'linear-gradient(100deg, var(--pc) 0%, var(--sc) 100%)',
                }}
            />
            <div className="relative flex flex-wrap items-center gap-5">
                <div className="relative h-[84px] w-[84px] shrink-0">
                    <div
                        className="h-full w-full p-[3px]"
                        style={{
                            backgroundColor: medal
                                ? `color-mix(in srgb, ${medal} 60%, transparent)`
                                : borderColor,
                            clipPath:
                                'polygon(15% 0, 100% 0, 100% 85%, 85% 100%, 0 100%, 0 15%)',
                        }}
                    >
                        <EntityImage
                            src={seasonTeam.logo}
                            alt={seasonTeam.name}
                            fallback={Shield}
                            shape="square"
                            style={crestTintStyle(seasonTeam.primary_color)}
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
