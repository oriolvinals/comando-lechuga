import { ActivityEntry } from '@/components/activity-entry';
import type { SeasonActivity } from '@/types/models';

interface ActivityPanelProps {
    activity: SeasonActivity[];
}

export function ActivityPanel({ activity }: ActivityPanelProps) {
    return (
        <section aria-labelledby="activity-heading">
            <h2 id="activity-heading" className="text-lg font-semibold">
                Actividad reciente
            </h2>

            {activity.length === 0 ? (
                <p className="mt-4 text-neutral-500">
                    Todavía no hay actividad esta temporada.
                </p>
            ) : (
                <ul className="mt-4 divide-y divide-neutral-200">
                    {activity.map((entry) => (
                        <ActivityEntry key={entry.id} activity={entry} />
                    ))}
                </ul>
            )}
        </section>
    );
}
