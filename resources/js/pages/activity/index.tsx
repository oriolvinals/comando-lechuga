import { Head, Link, router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { ActivityEntry, TYPE_LABELS } from '@/components/activity-entry';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as activityIndex } from '@/routes/activity';
import type {
    Paginated,
    SeasonActivity,
    SeasonActivityType,
} from '@/types/models';

interface TeamOption {
    id: number;
    name: string;
}

interface ActivityIndexProps {
    activities: Paginated<SeasonActivity>;
    teams: TeamOption[];
    filters: { team: number[]; type: SeasonActivityType[] };
    [key: string]: unknown;
}

function groupByDay(
    activities: SeasonActivity[],
): [string, SeasonActivity[]][] {
    const groups = new Map<string, SeasonActivity[]>();

    for (const activity of activities) {
        const day = new Intl.DateTimeFormat('es-ES', {
            dateStyle: 'full',
        }).format(new Date(activity.occurred_at));
        const existing = groups.get(day) ?? [];
        existing.push(activity);
        groups.set(day, existing);
    }

    return Array.from(groups.entries());
}

export default function ActivityIndex({
    activities,
    teams,
    filters,
}: ActivityIndexProps) {
    const applyFilters = (team: number[], type: SeasonActivityType[]) => {
        router.get(
            activityIndex().url,
            {
                team: team.join(',') || undefined,
                type: type.join(',') || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const toggleTeam = (id: number) => {
        const next = filters.team.includes(id)
            ? filters.team.filter((team) => team !== id)
            : [...filters.team, id];
        applyFilters(next, filters.type);
    };

    const toggleType = (value: SeasonActivityType) => {
        const next = filters.type.includes(value)
            ? filters.type.filter((type) => type !== value)
            : [...filters.type, value];
        applyFilters(filters.team, next);
    };

    const groups = groupByDay(activities.data);

    return (
        <>
            <Head title="Actividad" />

            <div className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-medium text-neutral-500">
                        Equipo:
                    </span>
                    {teams.map((team) => (
                        <button
                            key={team.id}
                            type="button"
                            onClick={() => toggleTeam(team.id)}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs transition-colors',
                                filters.team.includes(team.id)
                                    ? 'border-neutral-900 bg-neutral-900 text-white'
                                    : 'border-neutral-300 text-neutral-600 hover:bg-neutral-100',
                            )}
                        >
                            {team.name}
                        </button>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-medium text-neutral-500">
                        Tipo:
                    </span>
                    {(
                        Object.entries(TYPE_LABELS) as [
                            SeasonActivityType,
                            string,
                        ][]
                    ).map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => toggleType(value)}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs transition-colors',
                                filters.type.includes(value)
                                    ? 'border-neutral-900 bg-neutral-900 text-white'
                                    : 'border-neutral-300 text-neutral-600 hover:bg-neutral-100',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            {activities.data.length === 0 ? (
                <p className="mt-8 text-neutral-500">
                    No hay actividad que coincida con estos filtros.
                </p>
            ) : (
                <div className="mt-8 flex flex-col gap-8">
                    {groups.map(([day, entries]) => (
                        <section key={day}>
                            <h2 className="text-sm font-semibold text-neutral-500 capitalize">
                                {day}
                            </h2>
                            <ul className="mt-2 divide-y divide-neutral-200">
                                {entries.map((entry) => (
                                    <ActivityEntry
                                        key={entry.id}
                                        activity={entry}
                                    />
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            )}

            {activities.last_page > 1 && (
                <nav
                    aria-label="Paginación"
                    className="mt-8 flex flex-wrap gap-1"
                >
                    {activities.links.map((link, index) => (
                        <Link
                            key={index}
                            href={link.url ?? '#'}
                            preserveScroll
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm',
                                link.active
                                    ? 'bg-neutral-900 text-white'
                                    : 'text-neutral-600 hover:bg-neutral-100',
                                !link.url && 'pointer-events-none opacity-40',
                            )}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </nav>
            )}
        </>
    );
}

ActivityIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
