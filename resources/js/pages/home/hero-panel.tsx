import { Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { CSSProperties } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqTooltip } from '@/components/hq-tooltip';
import { formatCurrency } from '@/lib/format';
import { formatSignedPoints, teamFormBadgeClass } from '@/lib/points';
import { crestTintStyle } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { SeasonManager } from '@/types/models';

interface HeroPanelProps {
    /** The jornada in play (or next up) — the live chip's points belong to it, not to the jornada browsed below. */
    currentWeek: number;
    standings: SeasonManager[];
}

type PodiumRank = 1 | 2 | 3;

const MEDAL_VARS: Record<PodiumRank, string> = {
    1: 'var(--color-hq-gold)',
    2: 'var(--color-hq-silver)',
    3: 'var(--color-hq-bronze)',
};

const PODIUM_SIZES: Record<
    PodiumRank,
    { crest: string; name: string; points: string }
> = {
    1: {
        crest: 'h-12 w-12 md:h-16 md:w-16',
        name: 'text-base md:text-[22px]',
        points: 'text-[34px] md:text-[52px]',
    },
    2: {
        crest: 'h-10 w-10 md:h-[52px] md:w-[52px]',
        name: 'text-[15px] md:text-lg',
        points: 'text-[28px] md:text-[40px]',
    },
    3: {
        crest: 'h-10 w-10 md:h-11 md:w-11',
        name: 'text-sm md:text-base',
        points: 'text-2xl md:text-[34px]',
    },
};

function PodiumRow({
    rank,
    team,
    currentWeek,
}: {
    rank: PodiumRank;
    team: SeasonManager;
    currentWeek: number;
}) {
    const size = PODIUM_SIZES[rank];

    return (
        <Link
            href={seasonManagersShow(team.id).url}
            style={{ '--medal': MEDAL_VARS[rank] } as CSSProperties}
            className="relative grid grid-cols-[30px_auto_minmax(0,1fr)_auto] items-center gap-x-2.5 border-b border-dashed border-hq-border-strong bg-linear-to-r from-[color-mix(in_srgb,var(--medal)_13%,transparent)] to-transparent to-60% py-2.5 pr-3 transition-colors last:border-b-0 hover:from-[color-mix(in_srgb,var(--medal)_22%,transparent)] md:grid-cols-[44px_auto_minmax(0,1fr)_auto_auto] md:gap-x-3.5 md:pr-4"
        >
            <span
                aria-hidden="true"
                className="absolute inset-y-0 left-0 w-1 bg-(--medal)"
            />
            <HqLed className="row-span-2 text-center text-[22px] text-(--medal) md:row-span-1 md:text-[30px]">
                {rank}
            </HqLed>
            <EntityImage
                src={team.logo}
                alt={team.name}
                fallback={Shield}
                shape="square"
                style={crestTintStyle(team.primary_color)}
                className={cn(
                    'row-span-2 shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt p-[3px] text-hq-khaki md:row-span-1',
                    size.crest,
                )}
            />
            <span className="min-w-0">
                <span
                    className={cn(
                        'block truncate leading-[1.05] font-extrabold text-hq-paper uppercase',
                        size.name,
                    )}
                >
                    {team.name}
                </span>
                <span className="mt-1 block font-mono text-[10.5px] text-hq-moss md:text-[11.5px]">
                    {formatCurrency(team.value)}
                    {rank === 1 && (
                        <span className="text-hq-lime"> · líder</span>
                    )}
                </span>
            </span>
            {team.live_points !== null && (
                <HqTooltip
                    label={`Jornada ${currentWeek} en curso`}
                    className="col-start-3 row-start-2 justify-self-start md:col-start-auto md:row-start-auto"
                >
                    <span
                        className={cn(
                            'relative inline-flex h-6 items-center px-1.5 font-mono text-[11px] font-bold tabular-nums outline -outline-offset-1 outline-hq-live',
                            teamFormBadgeClass(team.live_points),
                        )}
                    >
                        J{currentWeek} {formatSignedPoints(team.live_points)}
                        <span className="absolute -top-[3px] -right-[3px] h-1.5 w-1.5 animate-hq-pulse rounded-full bg-hq-live" />
                    </span>
                </HqTooltip>
            )}
            <HqLed
                className={cn(
                    'col-start-4 row-span-2 row-start-1 text-right md:col-start-auto md:row-span-1 md:row-start-auto md:min-w-[92px]',
                    size.points,
                )}
            >
                {team.total_points}
            </HqLed>
        </Link>
    );
}

export function HeroPanel({ currentWeek, standings }: HeroPanelProps) {
    const podium = standings.slice(0, 3);

    return (
        <section className="grid grid-cols-1 border-b border-hq-border md:grid-cols-[240px_minmax(0,1fr)] min-[73.75rem]:grid-cols-[330px_minmax(0,1fr)]">
            <div className="hq-hud relative mx-3.5 mt-3.5 flex h-[170px] items-center justify-center overflow-hidden border border-hq-border-strong bg-hq-well bg-[radial-gradient(ellipse_at_50%_45%,rgba(196,255,61,0.08),transparent_65%)] md:m-[18px] md:aspect-square md:h-auto">
                <span className="absolute top-2.5 left-3 z-10 hq-label">
                    CAM 01 · CUARTEL
                </span>
                <span
                    aria-hidden="true"
                    className="absolute top-2.5 right-3 z-10 animate-pulse font-mono text-[10.5px] font-bold tracking-[0.1em] text-hq-live"
                >
                    ● REC
                </span>
                <img
                    src="/images/logo.png"
                    alt="Comando Lechuga"
                    className="h-[88%] w-auto object-contain drop-shadow-[0_10px_30px_rgba(0,0,0,0.6)] md:h-[86%] md:w-[86%]"
                />
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 bg-[repeating-linear-gradient(0deg,rgba(255,255,255,0.025)_0_1px,transparent_1px_3px)]"
                />
            </div>
            <div className="flex min-w-0 flex-col justify-center px-3.5 py-4 md:py-[26px] md:pr-6 md:pl-1.5">
                <p className="mb-2.5 font-mono text-[10px] leading-none font-bold tracking-[0.14em] text-hq-lime md:text-[11.5px] md:tracking-[0.24em]">
                    ▸ CUARTEL DE OPERACIONES — JORNADA{' '}
                    {String(currentWeek).padStart(2, '0')}
                </p>
                <h1 className="mb-3.5 text-[30px] leading-[0.95] font-black tracking-[-0.015em] text-hq-paper uppercase md:mb-5 md:text-4xl min-[73.75rem]:text-[44px]">
                    1 campeón.{' '}
                    <span className="text-hq-lime">
                        {Math.max(standings.length - 1, 0)} excusas.
                    </span>
                </h1>

                {podium.length > 0 && (
                    <div className="flex flex-col border border-hq-border-strong bg-hq-well">
                        {podium.map((team, index) => (
                            <PodiumRow
                                key={team.id}
                                rank={(index + 1) as PodiumRank}
                                team={team}
                                currentWeek={currentWeek}
                            />
                        ))}
                    </div>
                )}
            </div>
        </section>
    );
}
