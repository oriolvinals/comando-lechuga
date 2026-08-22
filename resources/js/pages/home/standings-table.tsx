import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency } from '@/lib/format';
import type { SeasonTeam } from '@/types/models';

interface StandingsTableProps {
    standings: SeasonTeam[];
}

export function StandingsTable({ standings }: StandingsTableProps) {
    return (
        <section aria-labelledby="standings-heading">
            <h2 id="standings-heading" className="text-lg font-semibold">
                Clasificación general
            </h2>

            <table className="mt-4 w-full text-sm">
                <thead>
                    <tr className="text-left text-neutral-500">
                        <th scope="col" className="py-2 font-medium">
                            #
                        </th>
                        <th scope="col" className="font-medium">
                            Equipo
                        </th>
                        <th scope="col" className="text-right font-medium">
                            Puntos
                        </th>
                        <th scope="col" className="text-right font-medium">
                            Valor
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-neutral-200">
                    {standings.map((team, index) => (
                        <tr key={team.id}>
                            <td className="py-2 text-neutral-500">
                                {index + 1}
                            </td>
                            <td>
                                <div className="flex items-center gap-2">
                                    <EntityImage
                                        src={team.logo}
                                        alt={team.name}
                                        fallback={Shield}
                                        className="h-6 w-6"
                                    />
                                    <span className="font-medium">
                                        {team.name}
                                    </span>
                                </div>
                            </td>
                            <td className="text-right font-semibold">
                                {team.total_points}
                            </td>
                            <td className="text-right text-neutral-500">
                                {formatCurrency(team.value)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </section>
    );
}
