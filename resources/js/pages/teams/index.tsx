import { Head, Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { show as teamsShow } from '@/routes/teams';
import type { StandingsRow } from '@/types/models';

interface TeamsIndexProps {
    standings: StandingsRow[];
    [key: string]: unknown;
}

const RESULT_LABEL: Record<'win' | 'draw' | 'loss', string> = {
    win: 'V',
    draw: 'E',
    loss: 'D',
};

const RESULT_CLASSES: Record<'win' | 'draw' | 'loss', string> = {
    win: 'bg-hq-lime/20 text-hq-lime',
    draw: 'bg-hq-moss/20 text-hq-moss',
    loss: 'bg-hq-live/20 text-hq-live',
};

export default function TeamsIndex({ standings }: TeamsIndexProps) {
    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto max-w-5xl px-6 py-9">
                <Head title="Equipos" />

                <h1 className="mb-6 font-display text-3xl text-hq-paper uppercase">
                    Equipos
                </h1>

                <div className="hq-card-cut overflow-x-auto">
                    <table className="w-full min-w-[680px] border-collapse font-mono text-[12px]">
                        <thead>
                            <tr className="border-b border-hq-border text-left text-[10px] text-hq-moss-dim uppercase">
                                <th className="px-3 py-2 text-center">#</th>
                                <th className="px-3 py-2">Equipo</th>
                                <th className="px-2 py-2 text-center">PJ</th>
                                <th className="px-2 py-2 text-center">PG</th>
                                <th className="px-2 py-2 text-center">PE</th>
                                <th className="px-2 py-2 text-center">PP</th>
                                <th className="px-2 py-2 text-center">GF</th>
                                <th className="px-2 py-2 text-center">GC</th>
                                <th className="px-2 py-2 text-center">DG</th>
                                <th className="px-3 py-2 text-center">Pts</th>
                                <th className="px-3 py-2 text-center">Forma</th>
                            </tr>
                        </thead>
                        <tbody>
                            {standings.map((row) => (
                                <tr
                                    key={row.team.id}
                                    className="border-b border-hq-ink last:border-b-0"
                                >
                                    <td className="px-3 py-2 text-center text-hq-moss-dim">
                                        {row.position}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={teamsShow(row.team.id).url}
                                            className="flex items-center gap-2 font-bold text-hq-paper hover:text-hq-lime"
                                        >
                                            <img
                                                src={row.team.logo}
                                                alt={row.team.main_name}
                                                className="h-5 w-5 object-contain"
                                            />
                                            {row.team.main_name}
                                            {row.is_live && (
                                                <span
                                                    className="h-1.5 w-1.5 shrink-0 animate-pulse rounded-full bg-hq-live"
                                                    title="En directo"
                                                />
                                            )}
                                        </Link>
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.played}
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.won}
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.drawn}
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.lost}
                                    </td>
                                    <td className="px-2 py-2 text-center text-hq-moss">
                                        {row.goals_for}
                                    </td>
                                    <td className="px-2 py-2 text-center text-hq-moss">
                                        {row.goals_against}
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.goal_difference > 0 ? '+' : ''}
                                        {row.goal_difference}
                                    </td>
                                    <td className="px-3 py-2 text-center font-bold text-hq-lime">
                                        {row.points}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="flex items-center justify-center gap-0.5">
                                            {row.recent_form.length === 0 ? (
                                                <span className="text-hq-moss-dim">
                                                    –
                                                </span>
                                            ) : (
                                                row.recent_form.map(
                                                    (result, index) => (
                                                        <span
                                                            key={index}
                                                            className={cn(
                                                                'flex h-4 w-4 items-center justify-center rounded-[2px] text-[8px] font-bold',
                                                                RESULT_CLASSES[
                                                                    result
                                                                ],
                                                            )}
                                                        >
                                                            {
                                                                RESULT_LABEL[
                                                                    result
                                                                ]
                                                            }
                                                        </span>
                                                    ),
                                                )
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

TeamsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
