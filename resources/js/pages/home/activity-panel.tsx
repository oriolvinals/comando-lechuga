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
            code="CH·04"
            title="Actividad"
            action={
                <Link
                    href={activityIndex().url}
                    className="inline-flex min-h-11 items-center font-bold text-hq-lime hover:underline sm:min-h-0"
                >
                    VER TODO →
                </Link>
            }
            flush
        >
            {activity.length === 0 ? (
                <p className="p-4 text-sm text-hq-moss">
                    Todavía no hay actividad esta temporada.
                </p>
            ) : (
                <div className="-mb-px grid grid-cols-1 md:grid-cols-2 md:[&>*:nth-child(odd)]:border-r md:[&>*:nth-child(odd)]:border-hq-border">
                    {activity.map((entry) => (
                        <HqActivityTimelineEntry
                            key={entry.id}
                            activity={entry}
                        />
                    ))}
                </div>
            )}
        </HqSection>
    );
}
