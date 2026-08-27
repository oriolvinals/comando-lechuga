import { Head, Link, router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { TYPE_LABELS } from '@/components/activity-helpers';
import { HqActivityTimelineEntry } from '@/components/hq-activity-timeline-entry';
import { HqMultiSelect } from '@/components/hq-multi-select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { index as activityIndex } from '@/routes/activity';
import type {
    Paginated,
    Activity,
    SeasonActivityType,
} from '@/types/models';

interface ManagerOption {
    id: number;
    name: string;
}

interface ActivityIndexProps {
    activities: Paginated<Activity>;
    managers: ManagerOption[];
    filters: { manager: number[]; type: SeasonActivityType[] };
    [key: string]: unknown;
}

function groupByDay(
    activities: Activity[],
): [string, Activity[]][] {
    const groups = new Map<string, Activity[]>();

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
    managers,
    filters,
}: ActivityIndexProps) {
    const applyFilters = (manager: number[], type: SeasonActivityType[]) => {
        router.get(
            activityIndex().url,
            {
                manager: manager.join(',') || undefined,
                type: type.join(',') || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const groups = groupByDay(activities.data);

    const managerOptions = managers.map((manager) => ({
        value: String(manager.id),
        label: manager.name,
    }));
    const typeOptions = (
        Object.entries(TYPE_LABELS) as [SeasonActivityType, string][]
    ).map(([value, label]) => ({ value, label }));

    return (
        <div className="hq-texture hq-bleed min-h-[calc(100vh-95px)] border-y border-hq-border">
            <div className="mx-auto max-w-7xl px-6 py-9">
                <Head title="Actividad" />

                <h1 className="mb-6 font-display text-3xl text-hq-paper uppercase">
                    Actividad
                </h1>

                <div className="mb-7 flex flex-wrap gap-2.5">
                    <HqMultiSelect
                        label="Manager"
                        options={managerOptions}
                        selected={filters.manager.map(String)}
                        onChange={(next) =>
                            applyFilters(next.map(Number), filters.type)
                        }
                    />

                    <HqMultiSelect
                        label="Tipo"
                        options={typeOptions}
                        selected={filters.type}
                        onChange={(next) =>
                            applyFilters(
                                filters.manager,
                                next as SeasonActivityType[],
                            )
                        }
                    />
                </div>

                {activities.data.length === 0 ? (
                    <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                        <p className="mb-2 text-3xl">📋</p>
                        <p className="font-display text-lg text-hq-paper uppercase">
                            Sin actividad
                        </p>
                        <p className="mt-1.5 font-mono text-[11px] text-hq-moss-dim">
                            No hay actividad que coincida con estos filtros.
                        </p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-7">
                        {groups.map(([day, entries]) => (
                            <section key={day}>
                                <h2 className="mb-2.5 border-b border-hq-border pb-1.5 font-mono text-[10px] tracking-widest text-hq-moss-dim uppercase">
                                    {day}
                                </h2>
                                <div className="grid grid-cols-1 gap-2.5 md:grid-cols-2 lg:grid-cols-3">
                                    {entries.map((entry) => (
                                        <div
                                            key={entry.id}
                                            className="hq-card-cut px-4 py-1"
                                        >
                                            <HqActivityTimelineEntry
                                                activity={entry}
                                            />
                                        </div>
                                    ))}
                                </div>
                            </section>
                        ))}
                    </div>
                )}

                {activities.last_page > 1 && (
                    <nav
                        aria-label="Paginación"
                        className="mt-8 flex flex-wrap gap-1.5"
                    >
                        {activities.links.map((link, index) => (
                            <Link
                                key={index}
                                href={link.url ?? '#'}
                                preserveScroll
                                className={cn(
                                    'border px-3 py-1.5 font-mono text-[11px] font-bold',
                                    link.active
                                        ? 'border-hq-lime bg-hq-lime text-hq-ink'
                                        : 'border-hq-border text-hq-moss hover:border-hq-border-strong',
                                    !link.url &&
                                        'pointer-events-none opacity-40',
                                )}
                                dangerouslySetInnerHTML={{
                                    __html: link.label,
                                }}
                            />
                        ))}
                    </nav>
                )}
            </div>
        </div>
    );
}

ActivityIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
