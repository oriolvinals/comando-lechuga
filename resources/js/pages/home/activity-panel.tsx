import { Link } from '@inertiajs/react';
import { HqActivityTimelineEntry } from '@/components/hq-activity-timeline-entry';
import { HqSection } from '@/components/hq-section';
import { index as activityIndex } from '@/routes/activity';
import type { Activity } from '@/types/models';

interface ActivityPanelProps {
    activity: Activity[];
}

export function ActivityPanel({ activity }: ActivityPanelProps) {
    return (
        <HqSection
            title="Actividad"
            action={
                <Link
                    href={activityIndex().url}
                    className="shrink-0 font-mono text-[11px] font-bold text-hq-lime hover:underline"
                >
                    VER TODO →
                </Link>
            }
        >
            {activity.length === 0 ? (
                <p className="text-sm text-hq-moss">
                    Todavía no hay actividad esta temporada.
                </p>
            ) : (
                <div className="grid grid-cols-1 gap-2.5 md:grid-cols-2 lg:grid-cols-3">
                    {activity.map((entry) => (
                        <div key={entry.id} className="hq-card-cut px-4 py-1">
                            <HqActivityTimelineEntry activity={entry} />
                        </div>
                    ))}
                </div>
            )}
        </HqSection>
    );
}
