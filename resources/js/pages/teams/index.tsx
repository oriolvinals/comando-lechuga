import { Head, Link, router } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqPageHeader } from '@/components/hq-page-header';
import {
    HqTeamFormStrip,
    HqTeamLiveScore,
} from '@/components/hq-team-form-strip';
import type { HoveredMatch } from '@/components/hq-team-form-strip';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { show as teamsShow } from '@/routes/teams';
import type { StandingsRow } from '@/types/models';

interface TeamsIndexProps {
    standings: StandingsRow[];
    [key: string]: unknown;
}

function signed(value: number): string {
    return `${value > 0 ? '+' : ''}${value}`;
}

const TH = 'px-2.5 py-[9px] font-semibold whitespace-nowrap';
const TD = 'px-2.5 py-2 whitespace-nowrap';

export default function TeamsIndex({ standings }: TeamsIndexProps) {
    const [hoveredMatch, setHoveredMatch] = useState<HoveredMatch>(null);
    const playedWeeks = Math.max(0, ...standings.map((row) => row.played));
    const isHighlighted = (row: StandingsRow) =>
        hoveredMatch?.includes(row.team.id) ?? false;

    return (
        <div className="flex-1">
            <Head title="Equipos" />

            <HqPageHeader
                code="LALIGA"
                title="Equipos"
                meta={[{ label: 'Jornadas jugadas', value: playedWeeks }]}
            />

            <table className="hidden w-full border-collapse font-mono text-[13px] leading-tight tabular-nums lg:table">
                <thead>
                    <tr className="border-b border-hq-border-strong text-left text-[10.5px] tracking-[0.07em] text-hq-moss-dim uppercase">
                        <th className={cn(TH, 'pl-4 text-center')}>#</th>
                        <th className={TH}>Equipo</th>
                        <th className={cn(TH, 'text-center')}>PJ</th>
                        <th className={cn(TH, 'text-center')}>PG</th>
                        <th className={cn(TH, 'text-center')}>PE</th>
                        <th className={cn(TH, 'text-center')}>PP</th>
                        <th className={cn(TH, 'text-center')}>GF</th>
                        <th className={cn(TH, 'text-center')}>GC</th>
                        <th className={cn(TH, 'text-center')}>DG</th>
                        <th className={cn(TH, 'text-center')}>Pts</th>
                        <th className={cn(TH, 'pr-4')}>Forma · próximo</th>
                    </tr>
                </thead>
                <tbody>
                    {standings.map((row) => {
                        const teamUrl = teamsShow(row.team.id).url;

                        return (
                            <tr
                                key={row.team.id}
                                onClick={() => router.visit(teamUrl)}
                                className={cn(
                                    'cursor-pointer border-b border-hq-border transition-colors hover:bg-hq-panel',
                                    isHighlighted(row) && 'bg-hq-lime/5',
                                )}
                            >
                                <td className={cn(TD, 'pl-4 text-center')}>
                                    <HqLed className="text-[17px] text-hq-moss">
                                        {row.position}
                                    </HqLed>
                                </td>
                                <td className={TD}>
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <EntityImage
                                            src={row.team.logo}
                                            alt=""
                                            fallback={Shield}
                                            shape="square"
                                            className="size-[26px] shrink-0 rounded-none bg-transparent object-contain"
                                        />
                                        <Link
                                            href={teamUrl}
                                            onClick={(event) =>
                                                event.stopPropagation()
                                            }
                                            className="truncate font-sans text-sm font-bold text-hq-paper hover:text-hq-lime"
                                        >
                                            {row.team.main_name}
                                        </Link>
                                        {row.live && (
                                            <>
                                                <span
                                                    aria-label="En directo"
                                                    className="size-2 shrink-0 animate-hq-pulse rounded-full bg-hq-live"
                                                />
                                                <HqTeamLiveScore
                                                    row={row}
                                                    onHover={setHoveredMatch}
                                                />
                                            </>
                                        )}
                                    </div>
                                </td>
                                <td className={cn(TD, 'text-center')}>
                                    {row.played}
                                </td>
                                <td className={cn(TD, 'text-center')}>
                                    {row.won}
                                </td>
                                <td className={cn(TD, 'text-center')}>
                                    {row.drawn}
                                </td>
                                <td className={cn(TD, 'text-center')}>
                                    {row.lost}
                                </td>
                                <td
                                    className={cn(
                                        TD,
                                        'text-center text-hq-moss',
                                    )}
                                >
                                    {row.goals_for}
                                </td>
                                <td
                                    className={cn(
                                        TD,
                                        'text-center text-hq-moss',
                                    )}
                                >
                                    {row.goals_against}
                                </td>
                                <td className={cn(TD, 'text-center')}>
                                    {signed(row.goal_difference)}
                                </td>
                                <td className={cn(TD, 'text-center')}>
                                    <HqLed tone="lime" className="text-2xl">
                                        {row.points}
                                    </HqLed>
                                </td>
                                <td className={cn(TD, 'pr-4')}>
                                    <HqTeamFormStrip
                                        row={row}
                                        onHover={setHoveredMatch}
                                    />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>

            <div className="lg:hidden">
                {standings.map((row) => {
                    const teamUrl = teamsShow(row.team.id).url;

                    return (
                        <div
                            key={row.team.id}
                            className={cn(
                                'border-b border-hq-border px-3.5 py-2.5 transition-colors',
                                isHighlighted(row) && 'bg-hq-lime/5',
                            )}
                        >
                            <Link
                                href={teamUrl}
                                className="flex min-h-11 items-center gap-2.5"
                            >
                                <HqLed className="w-6 shrink-0 text-center text-[17px] text-hq-moss">
                                    {row.position}
                                </HqLed>
                                <EntityImage
                                    src={row.team.logo}
                                    alt=""
                                    fallback={Shield}
                                    shape="square"
                                    className="size-[26px] shrink-0 rounded-none bg-transparent object-contain"
                                />
                                <span className="min-w-0 grow">
                                    <span className="flex items-center gap-1.5 text-sm leading-[1.1] font-extrabold text-hq-paper">
                                        <span className="truncate">
                                            {row.team.short_name}
                                        </span>
                                        {row.live && (
                                            <span
                                                aria-label="En directo"
                                                className="size-2 shrink-0 animate-hq-pulse rounded-full bg-hq-live"
                                            />
                                        )}
                                    </span>
                                    <span className="mt-1 block font-mono text-[11px] leading-none text-hq-moss-dim tabular-nums">
                                        PJ {row.played} · {row.won}-{row.drawn}-
                                        {row.lost} · DG{' '}
                                        {signed(row.goal_difference)}
                                    </span>
                                </span>
                                <HqLed tone="lime" className="text-2xl">
                                    {row.points}
                                </HqLed>
                            </Link>
                            <div className="mt-2 flex items-center gap-2 border-t border-dashed border-hq-border pt-2">
                                <HqTeamFormStrip
                                    row={row}
                                    onHover={setHoveredMatch}
                                />
                                {row.live && (
                                    <span className="ml-auto">
                                        <HqTeamLiveScore
                                            row={row}
                                            onHover={setHoveredMatch}
                                        />
                                    </span>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

TeamsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
