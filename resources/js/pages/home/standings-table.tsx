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

/** What the manager's squad gained or lost in the latest daily market update. */
function DailyValueDifference({
    difference,
    className,
}: {
    difference: number;
    className?: string;
}) {
    return (
        <span
            title="Variación de valor de la plantilla hoy"
            className={cn(
                'font-mono text-[11px] leading-none font-semibold tabular-nums',
                difference > 0 && 'text-hq-lime',
                difference < 0 && 'text-hq-neg',
                difference === 0 && 'text-hq-moss-dim',
                className,
            )}
        >
            {difference > 0 ? '+' : difference < 0 ? '−' : '±'}
            {formatCurrency(Math.abs(difference))}
        </span>
    );
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

function Position({
    team,
    index,
    className = 'text-[22px]',
}: {
    team: SeasonManager;
    index: number;
    /** The LED's text size. */
    className?: string;
}) {
    return (
        <HqLed
            className={cn(
                'inline-block min-w-[22px] text-center',
                index < 3 ? 'text-(--medal)' : 'text-hq-moss-dim',
                className,
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

function LeaderBadge() {
    return (
        <span className="inline-flex h-[18px] shrink-0 items-center border border-hq-gold bg-hq-gold/10 px-[5px] font-mono text-[9.5px] font-bold tracking-[0.08em] text-hq-gold">
            LÍDER
        </span>
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
    const leaderPoints = standings[0]?.total_points ?? 0;
    const status = liveMatchday
        ? `J${season.current_week} en juego`
        : `tras la J${Math.max(season.current_week - 1, 0)}`;

    return (
        <HqSection
            title="Clasificación"
            action={
                <span>
                    {season.name} · {status}
                </span>
            }
            flush
        >
            {/* Desktop / tablet: a ruled, full-width table */}
            <table className="hidden w-full border-collapse font-mono text-[13px] tabular-nums md:table">
                <thead>
                    <tr className="text-left text-[10.5px] font-semibold tracking-[0.07em] whitespace-nowrap text-hq-moss-dim uppercase [&>th]:border-b [&>th]:border-hq-border-strong [&>th]:px-3.5 [&>th]:py-[9px] [&>th]:font-semibold">
                        <th className="pl-[18px]!">#</th>
                        <th className="text-center">Mov</th>
                        <th>Manager</th>
                        <th className="text-right">Valor de equipo</th>
                        <th className="text-center">Forma · últimas 3</th>
                        <th className="text-right">Dif. líder</th>
                        <th className="text-right">Premio</th>
                        <th className="pr-[18px]! text-right">Pts</th>
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
                                    'cursor-pointer whitespace-nowrap [&>td]:border-b [&>td]:border-hq-border [&>td]:px-3.5 [&>td]:py-[11px] last:[&>td]:border-b-0 hover:[&>td]:bg-hq-panel',
                                    index < 3 &&
                                        'bg-linear-to-r from-[color-mix(in_srgb,var(--medal)_12%,transparent)] to-transparent to-70% [&>td:first-child]:shadow-[inset_3px_0_0_var(--medal)]',
                                )}
                            >
                                <td className="pl-[18px]!">
                                    <Position
                                        team={team}
                                        index={index}
                                        className="text-[26px]"
                                    />
                                </td>
                                <td className="text-center">
                                    <MovementIcon team={team} />
                                </td>
                                <td className="w-[28%] max-w-0">
                                    <Link
                                        href={href}
                                        onClick={(event) =>
                                            event.stopPropagation()
                                        }
                                        className="group flex min-w-0 items-center gap-3"
                                    >
                                        <Crest
                                            team={team}
                                            className="h-[42px] w-[42px]"
                                        />
                                        <span className="min-w-0 truncate font-sans text-[15px] leading-tight font-extrabold text-hq-paper group-hover:text-hq-lime">
                                            {team.name}
                                        </span>
                                        {index === 0 && <LeaderBadge />}
                                    </Link>
                                </td>
                                <td className="text-right text-hq-paper">
                                    {formatCurrency(team.value)}
                                    <DailyValueDifference
                                        difference={team.daily_value_difference}
                                        className="mt-1 block"
                                    />
                                </td>
                                <td>
                                    <HqRecentScores
                                        scores={team.recent_form}
                                        badgeClass={teamFormBadgeClass}
                                        live={team.live_points}
                                        className="justify-center"
                                    />
                                </td>
                                <td className="text-right font-semibold text-hq-moss-dim">
                                    {index === 0
                                        ? '—'
                                        : `−${leaderPoints - team.total_points}`}
                                </td>
                                <td className="text-right">
                                    <Prize position={team.position} />
                                </td>
                                <td className="pr-[18px]! text-right">
                                    <HqLed tone="lime" className="text-[30px]">
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
                            'block border-b border-hq-border px-3.5 py-2.5 last:border-b-0 active:bg-hq-panel',
                            index < 3 &&
                                'bg-linear-to-r from-[color-mix(in_srgb,var(--medal)_12%,transparent)] to-transparent to-70% shadow-[inset_3px_0_0_var(--medal)]',
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
                                <p className="mt-[3px] flex flex-wrap gap-x-2 font-mono text-[11px] leading-none text-hq-moss-dim">
                                    {formatCurrency(team.value)}
                                    <DailyValueDifference
                                        difference={team.daily_value_difference}
                                    />
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
