import { Link } from '@inertiajs/react';
import { ActivityCard } from '@/components/activity-card';
import { HqSection } from '@/components/hq-section';
import { index as activityIndex } from '@/routes/activity';
import type { SeasonActivity } from '@/types/models';

interface ActivityPanelProps {
    activity: SeasonActivity[];
}

export function ActivityPanel({ activity }: ActivityPanelProps) {
    return (
        <HqSection title="Actividad">
            <div className="mb-4 flex justify-end">
                <Link
                    href={activityIndex().url}
                    className="font-mono text-[11px] font-bold text-hq-lime hover:underline"
                >
                    VER TODO →
                </Link>
            </div>

            {activity.length === 0 ? (
                <p className="text-sm text-hq-moss">
                    Todavía no hay actividad esta temporada.
                </p>
            ) : (
                <div className="grid grid-cols-1 gap-2.5 md:grid-cols-2">
                    {activity.map((entry) => (
                        <ActivityCard key={entry.id} activity={entry} />
                    ))}
                </div>
            )}
        </HqSection>
    );
}
