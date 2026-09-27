import { ArrowDown, ArrowUp, Drama, Shield, Trophy } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqTooltip } from '@/components/hq-tooltip';
import { formatCurrency } from '@/lib/format';
import { formatSignedPoints } from '@/lib/points';
import { crestTintStyle } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import type { Season, SeasonManager } from '@/types/models';

interface WeekBadge {
    week: number;
    type: 'won' | 'lost';
}

/**
 * Merges "won this jornada" and "lost this jornada" (farolillo rojo) week
 * numbers into one list ordered by jornada, so both render interleaved in a
 * single row instead of two separate stacks.
 */
function weekBadges(wonWeeks: number[], lostWeeks: number[]): WeekBadge[] {
    return [
        ...wonWeeks.map((week): WeekBadge => ({ week, type: 'won' })),
        ...lostWeeks.map((week): WeekBadge => ({ week, type: 'lost' })),
    ].sort((a, b) => a.week - b.week);
}

/** Medal color per podium spot — same palette as the home podium. Outside the podium the crest keeps a neutral frame. */
const MEDAL_COLOR_VARS: Record<number, string> = {
    1: 'var(--color-hq-gold)',
    2: 'var(--color-hq-silver)',
    3: 'var(--color-hq-bronze)',
};

function places(count: number): string {
    return `${count} ${count === 1 ? 'puesto' : 'puestos'}`;
}

function RankTrend({
    position,
    lastPosition,
}: {
    position: number;
    lastPosition: number;
}) {
    if (position === lastPosition) {
        return null;
    }

    const rose = position < lastPosition;
    const Icon = rose ? ArrowUp : ArrowDown;

    return (
        <HqTooltip
            label={`${rose ? 'Sube' : 'Baja'} ${places(Math.abs(position - lastPosition))} (antes ${lastPosition}º)`}
            focusable
        >
            <Icon
                aria-label={rose ? 'Sube' : 'Baja'}
                className={cn(
                    'h-3 w-3',
                    rose ? 'text-hq-lime' : 'text-hq-live',
                )}
                strokeWidth={3.5}
            />
        </HqTooltip>
    );
}

function Kpi({
    label,
    value,
    sub,
    className,
}: {
    label: ReactNode;
    value: ReactNode;
    sub?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'min-w-0 border-r border-hq-border px-3.5 py-3.5 sm:px-4',
                className,
            )}
        >
            <div className="hq-label">{label}</div>
            <div className="mt-2 font-mono text-lg leading-[1.1] font-semibold tracking-[-0.02em] whitespace-nowrap text-hq-paper tabular-nums sm:text-[22px]">
                {value}
            </div>
            {sub && (
                <div className="mt-1.5 truncate font-mono text-[11.5px] leading-[1.35] text-hq-moss">
                    {sub}
                </div>
            )}
        </div>
    );
}

interface ManagerHeroProps {
    seasonManager: SeasonManager;
    season: Season;
    wonWeeks: number[];
    lostWeeks: number[];
}

/**
 * The manager ficha's hero (mock `.mhero`): a wash in the manager's two
 * colours, the crest framed in its medal (or neutral) with the Doto rank
 * chip + movement arrow, channel code, name, won / farolillo jornada chips,
 * and a ruled KPI strip — points, live jornada points (while live), value.
 */
export function ManagerHero({
    seasonManager,
    season,
    wonWeeks,
    lostWeeks,
}: ManagerHeroProps) {
    const badges = weekBadges(wonWeeks, lostWeeks);
    const medal = MEDAL_COLOR_VARS[seasonManager.position];
    const frameColor = medal ?? 'var(--color-hq-border-bright)';
    const crestFillStyle = medal
        ? {
              backgroundColor: `color-mix(in srgb, ${medal} 22%, var(--color-hq-panel-alt))`,
          }
        : crestTintStyle(seasonManager.primary_color);
    const isLive = seasonManager.live_points !== null;

    return (
        <div
            className="relative border-b border-hq-border"
            style={
                {
                    '--pc': seasonManager.primary_color ?? 'transparent',
                    '--sc': seasonManager.secondary_color ?? 'transparent',
                    '--medal': frameColor,
                } as CSSProperties
            }
        >
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-0 top-0 h-[180px] bg-linear-100 from-(--pc) to-(--sc) [mask-image:linear-gradient(to_bottom,#000_10%,transparent)] opacity-[0.28]"
            />

            <div className="relative flex items-start gap-3.5 px-3.5 pt-[18px] pb-4 sm:items-center sm:gap-[22px] sm:px-5 sm:pt-[26px] sm:pb-[22px]">
                <div className="relative shrink-0">
                    <EntityImage
                        src={seasonManager.logo}
                        alt={seasonManager.name}
                        fallback={Shield}
                        shape="square"
                        style={crestFillStyle}
                        className="h-[70px] w-[70px] rounded-none bg-hq-panel-alt p-[9px] text-hq-khaki outline-[3px] outline-(--medal) outline-solid sm:h-[92px] sm:w-[92px]"
                    />
                    <span
                        className={cn(
                            'absolute -right-1.5 -bottom-1.5 flex items-center gap-0.5 border-2 border-(--medal) bg-hq-ink px-1.5 py-[3px]',
                            medal ? 'text-(--medal)' : 'text-hq-paper',
                        )}
                    >
                        <HqLed className="text-[17px] text-inherit">
                            {seasonManager.position}.º
                        </HqLed>
                        <RankTrend
                            position={seasonManager.position}
                            lastPosition={seasonManager.last_position}
                        />
                    </span>
                </div>

                <div className="min-w-0 grow">
                    <span className="font-mono text-[11px] leading-none font-semibold tracking-[0.14em] text-hq-lime">
                        CH·M{String(seasonManager.id).padStart(2, '0')} ·
                        MANAGER
                    </span>
                    <h1 className="mt-1 font-display text-[28px] leading-[0.95] break-words text-hq-paper uppercase sm:text-[42px]">
                        {seasonManager.name}
                    </h1>
                    {badges.length > 0 && (
                        <div className="mt-3 flex flex-wrap gap-1.5">
                            {badges.map(({ week, type }) => (
                                <HqTooltip
                                    key={`${type}-${week}`}
                                    label={
                                        type === 'won'
                                            ? `Ganó la jornada ${week}`
                                            : `Farolillo rojo de la jornada ${week}`
                                    }
                                    tone={type === 'won' ? 'gold' : 'live'}
                                    focusable
                                    className={cn(
                                        'items-center gap-[5px] border border-current px-[7px] py-1 font-mono text-[11px] leading-none font-bold',
                                        type === 'won'
                                            ? 'bg-hq-gold/10 text-hq-gold'
                                            : 'bg-hq-live/10 text-hq-live',
                                    )}
                                >
                                    {type === 'won' ? (
                                        <Trophy
                                            aria-hidden="true"
                                            className="h-3 w-3"
                                        />
                                    ) : (
                                        <Drama
                                            aria-hidden="true"
                                            className="h-3 w-3"
                                        />
                                    )}
                                    J{week}
                                </HqTooltip>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            <div
                className={cn(
                    'relative grid grid-cols-2 border-t border-hq-border bg-hq-ink/70 [&>*:last-child]:border-r-0 max-md:[&>*:nth-child(2n)]:border-r-0',
                    isLive ? 'md:grid-cols-3' : 'md:grid-cols-2',
                )}
            >
                <Kpi
                    label="Puntos"
                    className={cn(
                        'bg-linear-to-b from-hq-lime/6 to-transparent',
                        isLive && 'max-md:border-b',
                    )}
                    value={
                        <HqLed
                            tone="lime"
                            glow
                            className="text-[28px] sm:text-[34px]"
                        >
                            {seasonManager.total_points}
                        </HqLed>
                    }
                    sub={`${seasonManager.position}º en la general`}
                />
                {isLive && (
                    <Kpi
                        label={
                            <span className="text-hq-live">
                                ● J{season.current_week} en directo
                            </span>
                        }
                        value={
                            <HqLed
                                tone="live"
                                className="text-[28px] sm:text-[34px]"
                            >
                                {formatSignedPoints(
                                    seasonManager.live_points as number,
                                )}
                            </HqLed>
                        }
                        sub="la jornada sigue abierta"
                        className="max-md:border-b"
                    />
                )}
                <Kpi
                    label="Valor"
                    value={formatCurrency(seasonManager.value)}
                    className={cn(isLive && 'max-md:col-span-2')}
                />
            </div>
        </div>
    );
}
