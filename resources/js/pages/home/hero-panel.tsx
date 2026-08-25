import { Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { CSSProperties } from 'react';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency } from '@/lib/format';
import { crestTintStyle } from '@/lib/season-team-colors';
import { cn } from '@/lib/utils';
import { show as seasonTeamsShow } from '@/routes/season-teams';
import type { SeasonTeam } from '@/types/models';

interface HeroPanelProps {
    week: number;
    standings: SeasonTeam[];
}

const PODIUM_SIZES = {
    1: {
        border: 'border-l-hq-gold',
        tint: 'rgba(224, 184, 63, 0.16)',
        row: 'gap-3 px-4 py-4 sm:gap-5 sm:px-6 sm:py-5',
        crest: 'h-20 w-20',
        name: 'text-2xl',
        value: 'text-xs',
        live: 'px-2 py-1 text-[11px]',
        points: 'text-5xl',
    },
    2: {
        border: 'border-l-hq-silver',
        tint: 'rgba(199, 205, 214, 0.12)',
        row: 'gap-2.5 px-3.5 py-3 sm:gap-4 sm:px-5 sm:py-3.5',
        crest: 'h-14 w-14',
        name: 'text-lg',
        value: 'text-[11px]',
        live: 'px-1.5 py-0.5 text-[10px]',
        points: 'text-3xl',
    },
    3: {
        border: 'border-l-hq-bronze',
        tint: 'rgba(201, 121, 63, 0.14)',
        row: 'gap-2 px-3 py-2.5 sm:gap-3.5 sm:px-4 sm:py-3',
        crest: 'h-11 w-11',
        name: 'text-base',
        value: 'text-[10px]',
        live: 'px-1.5 py-0.5 text-[9px]',
        points: 'text-2xl',
    },
} as const;

function PodiumRow({
    rank,
    team,
    week,
}: {
    rank: 1 | 2 | 3;
    team: SeasonTeam;
    week: number;
}) {
    const size = PODIUM_SIZES[rank];

    return (
        <Link
            href={seasonTeamsShow(team.id).url}
            style={{ '--hq-panel-tint': size.tint } as CSSProperties}
            className={cn(
                'hq-panel-cut flex items-center border-l-4 text-left transition-[filter] hover:brightness-125',
                size.border,
                size.row,
            )}
        >
            <EntityImage
                src={team.logo}
                alt={team.name}
                fallback={Shield}
                shape="square"
                style={crestTintStyle(team.primary_color)}
                className={cn(
                    'hq-crest-cut shrink-0 bg-hq-border p-1.5 text-hq-khaki',
                    size.crest,
                )}
            />
            <div className="min-w-0 flex-1">
                <p className={cn('truncate font-extrabold text-hq-paper', size.name)}>
                    {team.name}
                </p>
                <p className={cn('font-mono text-hq-moss-dim', size.value)}>
                    {formatCurrency(team.value)}
                </p>
            </div>
            <div className="shrink-0 text-right">
                {team.live_points !== null && (
                    <span
                        className={cn(
                            'mb-1 inline-block rounded bg-hq-lime/10 font-mono text-hq-lime',
                            size.live,
                        )}
                    >
                        J{week} +{team.live_points}
                    </span>
                )}
                <div className={cn('font-display text-hq-paper', size.points)}>
                    {team.total_points}
                </div>
            </div>
        </Link>
    );
}

export function HeroPanel({ week, standings }: HeroPanelProps) {
    const [leader, second, third] = standings;

    return (
        <div className="flex flex-col items-center gap-5 pt-9 pb-7 text-center sm:flex-row sm:items-center sm:gap-7 sm:text-left">
            <img
                src="/images/logo.png"
                alt="Comando Lechuga"
                className="h-56 w-56 shrink-0 object-contain sm:h-80 sm:w-80 lg:h-96 lg:w-96"
            />
            <div className="w-full flex-1">
                <p className="mb-3 font-mono text-[11px] font-bold tracking-[.25em] text-hq-lime">
                    ▸ PARTE DE OPERACIONES — JORNADA{' '}
                    {String(week).padStart(2, '0')}
                </p>
                <h1 className="mb-6 font-display text-2xl leading-[1.05] tracking-wide text-hq-paper uppercase [text-shadow:3px_3px_0_rgba(196,255,61,0.25)] sm:max-w-xl sm:text-3xl lg:text-4xl">
                    1 campeón.{' '}
                    <span className="text-hq-lime">
                        {Math.max(standings.length - 1, 0)} excusas.
                    </span>
                </h1>

                <div className="mx-auto flex max-w-2xl flex-col gap-2.5 sm:mx-0">
                    {leader && <PodiumRow rank={1} team={leader} week={week} />}
                    {second && <PodiumRow rank={2} team={second} week={week} />}
                    {third && <PodiumRow rank={3} team={third} week={week} />}
                </div>
            </div>
        </div>
    );
}
