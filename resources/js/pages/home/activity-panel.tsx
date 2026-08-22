import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { describeActivity } from '@/components/activity-entry';
import { EntityImage } from '@/components/entity-image';
import { HqSection } from '@/components/hq-section';
import { formatRelativeTime } from '@/lib/format';
import { index as activityIndex } from '@/routes/activity';
import type { SeasonActivity } from '@/types/models';

interface ActivityPanelProps {
    activity: SeasonActivity[];
}

export function ActivityPanel({ activity }: ActivityPanelProps) {
    return (
        <HqSection title="Transmisiones">
            <div className="mb-4 flex items-center justify-between">
                <p className="font-mono text-[11px] text-hq-moss-dim">
                    Registro de actividad de la liga
                </p>
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
                <div className="flex flex-col">
                    {activity.map((entry) => (
                        <div
                            key={entry.id}
                            className="flex items-center gap-3 border-b border-dashed border-hq-border py-2.5 font-mono text-xs last:border-b-0"
                        >
                            {entry.player ? (
                                <EntityImage
                                    src={entry.player.image}
                                    alt={entry.player.nickname}
                                    fallback={User}
                                    className="h-6 w-6 shrink-0 border border-hq-border-strong bg-hq-panel-alt"
                                />
                            ) : (
                                <span className="w-6 shrink-0" />
                            )}
                            <span className="shrink-0 text-hq-moss-dim">
                                [{formatRelativeTime(entry.occurred_at)}]
                            </span>
                            <span className="text-hq-paper/80">
                                {describeActivity(entry)}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </HqSection>
    );
}
