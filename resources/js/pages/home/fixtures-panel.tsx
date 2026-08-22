import { router } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { formatMatchDateTime } from '@/lib/format';
import { home } from '@/routes';
import type { Fixture, Season } from '@/types/models';

interface FixturesPanelProps {
    fixtures: Fixture[];
    season: Season;
    week: number;
}

export function FixturesPanel({ fixtures, season, week }: FixturesPanelProps) {
    const goToWeek = (nextWeek: number) => {
        router.get(home().url, { week: nextWeek }, { preserveScroll: true });
    };

    return (
        <section aria-labelledby="fixtures-heading">
            <div className="flex items-center justify-between">
                <h2 id="fixtures-heading" className="text-lg font-semibold">
                    Jornada {week}
                </h2>
                <div className="flex gap-2">
                    <button
                        type="button"
                        onClick={() => goToWeek(week - 1)}
                        disabled={week <= 1}
                        className="rounded-md px-3 py-1 text-sm text-neutral-600 hover:bg-neutral-100 disabled:opacity-40"
                    >
                        Anterior
                    </button>
                    <button
                        type="button"
                        onClick={() => goToWeek(week + 1)}
                        disabled={week >= season.total_weeks}
                        className="rounded-md px-3 py-1 text-sm text-neutral-600 hover:bg-neutral-100 disabled:opacity-40"
                    >
                        Siguiente
                    </button>
                </div>
            </div>

            {fixtures.length === 0 ? (
                <p className="mt-4 text-neutral-500">
                    No hay partidos programados para esta jornada.
                </p>
            ) : (
                <ul className="mt-4 divide-y divide-neutral-200">
                    {fixtures.map((fixture) => (
                        <li
                            key={fixture.id}
                            className="flex items-center justify-between gap-4 py-3"
                        >
                            <div className="flex flex-1 items-center gap-2">
                                <EntityImage
                                    src={fixture.local_team.logo}
                                    alt={fixture.local_team.name}
                                    fallback={Shield}
                                    className="h-8 w-8"
                                />
                                <span className="text-sm font-medium">
                                    {fixture.local_team.short_name}
                                </span>
                            </div>

                            <div className="shrink-0 text-center text-sm text-neutral-500">
                                {fixture.state === 'finished' ? (
                                    <span className="text-base font-semibold text-neutral-900">
                                        {fixture.local_score} -{' '}
                                        {fixture.guest_score}
                                    </span>
                                ) : (
                                    <span>
                                        {formatMatchDateTime(fixture.date)}
                                    </span>
                                )}
                            </div>

                            <div className="flex flex-1 items-center justify-end gap-2">
                                <span className="text-sm font-medium">
                                    {fixture.guest_team.short_name}
                                </span>
                                <EntityImage
                                    src={fixture.guest_team.logo}
                                    alt={fixture.guest_team.name}
                                    fallback={Shield}
                                    className="h-8 w-8"
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
