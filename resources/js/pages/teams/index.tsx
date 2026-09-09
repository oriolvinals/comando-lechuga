import { Head, Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';
import { show as teamsShow } from '@/routes/teams';
import type { StandingsRow } from '@/types/models';

interface TeamsIndexProps {
    standings: StandingsRow[];
    [key: string]: unknown;
}

export default function TeamsIndex({ standings }: TeamsIndexProps) {
    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto max-w-5xl px-6 py-9">
                <Head title="Equipos" />

                <h1 className="mb-6 font-display text-3xl text-hq-paper uppercase">
                    Equipos
                </h1>

                <div className="hq-card-cut overflow-x-auto">
                    <table className="w-full min-w-[560px] border-collapse font-mono text-[12px]">
                        <thead>
                            <tr className="border-b border-hq-border text-left text-[10px] text-hq-moss-dim uppercase">
                                <th className="px-3 py-2 text-center">#</th>
                                <th className="px-3 py-2">Equipo</th>
                                <th className="px-2 py-2 text-center">PJ</th>
                                <th className="px-2 py-2 text-center">PG</th>
                                <th className="px-2 py-2 text-center">PE</th>
                                <th className="px-2 py-2 text-center">PP</th>
                                <th className="px-2 py-2 text-center">
                                    GF-GC
                                </th>
                                <th className="px-2 py-2 text-center">DG</th>
                                <th className="px-3 py-2 text-center">Pts</th>
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
                                        {row.goals_for}-{row.goals_against}
                                    </td>
                                    <td className="px-2 py-2 text-center">
                                        {row.goal_difference > 0 ? '+' : ''}
                                        {row.goal_difference}
                                    </td>
                                    <td className="px-3 py-2 text-center font-bold text-hq-lime">
                                        {row.points}
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
