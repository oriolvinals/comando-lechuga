import { Head, Link, router } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { TYPE_LABELS } from '@/components/activity-helpers';
import { HqActivityTimelineEntry } from '@/components/hq-activity-timeline-entry';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqMultiSelect } from '@/components/hq-multi-select';
import { HqPageHeader } from '@/components/hq-page-header';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as activityIndex } from '@/routes/activity';
import type { Paginated, Activity, SeasonActivityType } from '@/types/models';

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

function groupByDay(activities: Activity[]): [string, Activity[]][] {
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
        <div className="flex-1">
            <Head title="Actividad" />

            <HqPageHeader
                code="REGISTRO DE MERCADO"
                title="Actividad"
                meta={[
                    {
                        label: 'Movimientos',
                        value: formatNumber(activities.total),
                    },
                ]}
            />

            <div className="flex flex-wrap items-center gap-1.5 border-b border-hq-border bg-hq-panel px-3.5 py-2.5 sm:gap-2 sm:px-4 sm:py-3">
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
                <HqEmptyState glyph="∅" title="Sin actividad">
                    No hay actividad que coincida con estos filtros.
                </HqEmptyState>
            ) : (
                <>
                    <p className="border-b border-hq-border px-3.5 py-2.5 hq-label sm:px-4">
                        {formatNumber(activities.total)} movimientos · página{' '}
                        {activities.current_page} de {activities.last_page}
                    </p>

                    {groups.map(([day, entries]) => (
                        <section key={day}>
                            <h2 className="flex items-center justify-between border-b border-hq-border-strong bg-hq-ink px-3.5 py-2.5 font-mono text-[11px] font-bold tracking-[0.14em] text-hq-moss-dim uppercase sm:px-4">
                                <span>{day}</span>
                                <span>{entries.length}</span>
                            </h2>
                            <div className="-mb-px grid grid-cols-1 md:grid-cols-2 md:[&>*:nth-child(odd)]:border-r md:[&>*:nth-child(odd)]:border-hq-border">
                                {entries.map((entry) => (
                                    <HqActivityTimelineEntry
                                        key={entry.id}
                                        activity={entry}
                                    />
                                ))}
                            </div>
                        </section>
                    ))}
                </>
            )}

            {activities.last_page > 1 && (
                <nav
                    aria-label="Paginación"
                    className="flex flex-wrap items-center gap-1 px-3.5 py-3.5 sm:px-4"
                >
                    {activities.links.map((link, index) => (
                        <Link
                            key={index}
                            href={link.url ?? '#'}
                            preserveScroll
                            aria-current={link.active ? 'page' : undefined}
                            className={cn(
                                'inline-flex h-11 min-w-11 items-center justify-center border px-2 font-mono text-xs font-bold sm:h-8 sm:min-w-[34px]',
                                link.active
                                    ? 'border-hq-lime bg-hq-lime text-hq-ink'
                                    : 'border-hq-border-strong text-hq-moss hover:border-hq-border-bright hover:text-hq-paper',
                                !link.url && 'pointer-events-none opacity-35',
                            )}
                            dangerouslySetInnerHTML={{
                                __html: link.label,
                            }}
                        />
                    ))}
                    <span className="ml-auto font-mono text-xs text-hq-moss-dim">
                        {activities.per_page} por página
                    </span>
                </nav>
            )}
        </div>
    );
}

ActivityIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
