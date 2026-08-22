import { router } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqSection } from '@/components/hq-section';
import { formatMatchDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
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
        <HqSection number="01" title="Operaciones de la jornada">
            <div className="mb-4 flex flex-wrap gap-1.5">
                {Array.from(
                    { length: season.total_weeks },
                    (_, index) => index + 1,
                ).map((weekNumber) => (
                    <button
                        key={weekNumber}
                        type="button"
                        onClick={() => goToWeek(weekNumber)}
                        className={cn(
                            'border px-2.5 py-1 font-mono text-[11px] font-bold',
                            weekNumber === week
                                ? 'border-hq-lime bg-hq-lime text-hq-ink'
                                : 'border-hq-border text-hq-moss hover:border-hq-border-strong',
                        )}
                    >
                        J{weekNumber}
                    </button>
                ))}
            </div>

            {fixtures.length === 0 ? (
                <p className="text-sm text-hq-moss">
                    No hay partidos programados para esta jornada.
                </p>
            ) : (
                <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:grid-cols-5">
                    {fixtures.map((fixture) => (
                        <div
                            key={fixture.id}
                            className="relative border border-hq-border bg-hq-panel px-2 py-2.5 text-center"
                        >
                            <span
                                className={cn(
                                    'absolute top-1.5 right-1.5 h-1.5 w-1.5 rounded-full',
                                    fixture.state === 'finished'
                                        ? 'bg-hq-lime'
                                        : 'bg-hq-moss-dim',
                                )}
                            />
                            <div className="mt-1 flex items-center justify-center gap-1.5">
                                <EntityImage
                                    src={fixture.local_team.logo}
                                    alt={fixture.local_team.name}
                                    fallback={Shield}
                                    shape="square"
                                    className="h-6 w-6 bg-hq-panel-alt p-0.5"
                                />
                                <span className="font-display text-sm text-hq-paper">
                                    {fixture.state === 'finished'
                                        ? `${fixture.local_score}–${fixture.guest_score}`
                                        : 'vs'}
                                </span>
                                <EntityImage
                                    src={fixture.guest_team.logo}
                                    alt={fixture.guest_team.name}
                                    fallback={Shield}
                                    shape="square"
                                    className="h-6 w-6 bg-hq-panel-alt p-0.5"
                                />
                            </div>
                            <p className="mt-1.5 font-mono text-[9px] text-hq-moss-dim">
                                {fixture.state === 'finished'
                                    ? 'FINAL'
                                    : formatMatchDateTime(fixture.date)}
                            </p>
                        </div>
                    ))}
                </div>
            )}
        </HqSection>
    );
}
