import { Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Minus, Shield } from 'lucide-react';
import type { CSSProperties } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqSection } from '@/components/hq-section';
import { HqTooltip } from '@/components/hq-tooltip';
import { formatCurrency } from '@/lib/format';
import { teamFormBadgeClass } from '@/lib/points';
import { crestTintStyle } from '@/lib/season-manager-colors';
import {
    standingsPrize,
    standingsPrizeClass,
    standingsPrizeText,
} from '@/lib/standings-prizes';
import { cn } from '@/lib/utils';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { Season, SeasonManager } from '@/types/models';

interface StandingsTableProps {
    season: Season;
    standings: SeasonManager[];
}

const MEDAL_VARS = [
    'var(--color-hq-gold)',
    'var(--color-hq-silver)',
    'var(--color-hq-bronze)',
];

function medalStyle(index: number): CSSProperties | undefined {
    return index < 3
        ? ({ '--medal': MEDAL_VARS[index] } as CSSProperties)
        : undefined;
}

function Position({ team, index }: { team: SeasonManager; index: number }) {
    return (
        <HqLed
            className={cn(
                'inline-block min-w-[22px] text-center text-[22px]',
                index < 3 ? 'text-(--medal)' : 'text-hq-moss-dim',
            )}
        >
            {team.position}
        </HqLed>
    );
}

function places(count: number): string {
    return `${count} ${count === 1 ? 'puesto' : 'puestos'}`;
}

function MovementIcon({
    team,
    focusable = true,
}: {
    team: SeasonManager;
    /** Off inside a row that is itself a link — no focus stop nested in an anchor. */
    focusable?: boolean;
}) {
    const moved = team.position - team.last_position;

    if (moved < 0) {
        return (
            <HqTooltip
                label={`Sube ${places(-moved)} (antes ${team.last_position}º)`}
                focusable={focusable}
            >
                <ArrowUp
                    aria-label="Sube"
                    className="h-4 w-4 shrink-0 text-hq-lime"
                />
            </HqTooltip>
        );
    }

    if (moved > 0) {
        return (
            <HqTooltip
                label={`Baja ${places(moved)} (antes ${team.last_position}º)`}
                tone="neg"
                focusable={focusable}
            >
                <ArrowDown
                    aria-label="Baja"
                    className="h-4 w-4 shrink-0 text-hq-neg"
                />
            </HqTooltip>
        );
    }

    return (
        <HqTooltip label="Sin cambios" focusable={focusable}>
            <Minus
                aria-label="Sin cambios"
                className="h-4 w-4 shrink-0 text-hq-moss-dim"
            />
        </HqTooltip>
    );
}

function Crest({
    team,
    className,
}: {
    team: SeasonManager;
    className: string;
}) {
    return (
        <EntityImage
            src={team.logo}
            alt={team.name}
            fallback={Shield}
            shape="square"
            style={crestTintStyle(team.primary_color)}
            className={cn(
                'shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt p-[3px] text-hq-khaki',
                className,
            )}
        />
    );
}

function Prize({ position }: { position: number }) {
    const prize = standingsPrize(position);

    return (
        <span
            className={cn(
                'font-mono text-[13px] font-semibold tabular-nums',
                standingsPrizeClass(prize),
            )}
        >
            {standingsPrizeText(prize)}
        </span>
    );
}

export function StandingsTable({ season, standings }: StandingsTableProps) {
    const { liveMatchday } = usePage().props;
    const status = liveMatchday
        ? `J${season.current_week} en juego`
        : `tras la J${Math.max(season.current_week - 1, 0)}`;

    return (
        <HqSection
            code="CH·01"
            title="Clasificación"
            action={
                <span>
                    {season.name} · {status}
                </span>
            }
            flush
            className="border-b-0"
        >
            {/* Desktop / tablet: a ruled table */}
            <table className="hidden w-full border-collapse font-mono text-[13px] tabular-nums md:table">
                <thead>
                    <tr className="text-left text-[10.5px] font-semibold tracking-[0.07em] whitespace-nowrap text-hq-moss-dim uppercase">
                        <th className="border-b border-hq-border-strong py-[9px] pr-2.5 pl-4 font-semibold">
                            #
                        </th>
                        <th className="border-b border-hq-border-strong px-2.5 py-[9px] text-center font-semibold">
                            Mov
                        </th>
                        <th className="border-b border-hq-border-strong px-2.5 py-[9px] font-semibold">
                            Manager
                        </th>
                        <th className="border-b border-hq-border-strong px-2.5 py-[9px] text-center font-semibold">
                            Forma · últimas 3
                        </th>
                        <th className="border-b border-hq-border-strong px-2.5 py-[9px] text-right font-semibold">
                            Premio
                        </th>
                        <th className="border-b border-hq-border-strong py-[9px] pr-4 pl-2.5 text-right font-semibold">
                            Pts
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {standings.map((team, index) => {
                        const href = seasonManagersShow(team.id).url;

                        return (
                            <tr
                                key={team.id}
                                style={medalStyle(index)}
                                onClick={() => router.visit(href)}
                                className={cn(
                                    'cursor-pointer [&>td]:border-b [&>td]:border-hq-border [&>td]:px-2.5 [&>td]:py-[9px] hover:[&>td]:bg-hq-panel',
                                    index < 3 &&
                                        '[&>td]:bg-linear-to-r [&>td]:from-[color-mix(in_srgb,var(--medal)_7%,transparent)] [&>td]:to-transparent [&>td]:to-40% [&>td:first-child]:shadow-[inset_3px_0_0_var(--medal)]',
                                )}
                            >
                                <td className="pl-4!">
                                    <Position team={team} index={index} />
                                </td>
                                <td className="text-center">
                                    <MovementIcon team={team} />
                                </td>
                                <td className="w-full max-w-0">
                                    <Link
                                        href={href}
                                        onClick={(event) =>
                                            event.stopPropagation()
                                        }
                                        className="group flex min-w-0 items-center gap-2.5"
                                    >
                                        <Crest
                                            team={team}
                                            className="h-[38px] w-[38px]"
                                        />
                                        <span className="min-w-0">
                                            <span className="block truncate font-sans text-sm leading-tight font-bold text-hq-paper group-hover:text-hq-lime">
                                                {team.name}
                                            </span>
                                            <span className="mt-[3px] block text-[11px] leading-none text-hq-moss-dim">
                                                {formatCurrency(team.value)}
                                            </span>
                                        </span>
                                    </Link>
                                </td>
                                <td>
                                    <HqRecentScores
                                        scores={team.recent_form}
                                        badgeClass={teamFormBadgeClass}
                                        live={team.live_points}
                                        size="sm"
                                        className="justify-center"
                                    />
                                </td>
                                <td className="text-right whitespace-nowrap">
                                    <Prize position={team.position} />
                                </td>
                                <td className="pr-4! text-right">
                                    <HqLed tone="lime" className="text-[26px]">
                                        {team.total_points}
                                    </HqLed>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>

            {/* Mobile: two lines, nothing hidden */}
            <div className="md:hidden">
                {standings.map((team, index) => (
                    <Link
                        key={team.id}
                        href={seasonManagersShow(team.id).url}
                        style={medalStyle(index)}
                        className={cn(
                            'block border-b border-hq-border px-3.5 py-2.5 active:bg-hq-panel',
                            index < 3 && 'shadow-[inset_3px_0_0_var(--medal)]',
                        )}
                    >
                        <div className="flex items-center gap-2.5">
                            <Position team={team} index={index} />
                            <MovementIcon team={team} focusable={false} />
                            <Crest team={team} className="h-[34px] w-[34px]" />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm leading-tight font-extrabold text-hq-paper">
                                    {team.name}
                                </p>
                                <p className="mt-[3px] font-mono text-[11px] leading-none text-hq-moss-dim">
                                    {formatCurrency(team.value)}
                                </p>
                            </div>
                            <HqLed tone="lime" className="text-2xl">
                                {team.total_points}
                            </HqLed>
                        </div>
                        <div className="mt-2 flex items-center gap-2.5 border-t border-dashed border-hq-border pt-2">
                            <span className="hq-label">Forma</span>
                            <HqRecentScores
                                scores={team.recent_form}
                                badgeClass={teamFormBadgeClass}
                                live={team.live_points}
                                size="sm"
                            />
                            <span className="ml-auto">
                                <Prize position={team.position} />
                            </span>
                        </div>
                    </Link>
                ))}
            </div>
        </HqSection>
    );
}
