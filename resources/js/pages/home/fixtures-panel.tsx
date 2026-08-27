import { router } from '@inertiajs/react';
import { HqFixtureCard } from '@/components/hq-fixture-card';
import { HqSection } from '@/components/hq-section';
import { HqWeekScrollPicker } from '@/components/hq-week-scroll-picker';
import { home } from '@/routes';
import type { Fixture, Season, WeekProgressMap } from '@/types/models';

interface FixturesPanelProps {
    fixtures: Fixture[];
    season: Season;
    week: number;
    weekProgress: WeekProgressMap;
}

export function FixturesPanel({
    fixtures,
    season,
    week,
    weekProgress,
}: FixturesPanelProps) {
    const goToWeek = (nextWeek: number) => {
        router.get(
            home().url,
            { week: nextWeek },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <HqSection title="Jornadas">
            <div className="mb-4">
                <HqWeekScrollPicker
                    week={week}
                    maxWeek={season.total_weeks}
                    playedThroughWeek={season.current_week}
                    weekProgress={weekProgress}
                    onChange={goToWeek}
                />
            </div>

            {fixtures.length === 0 ? (
                <p className="text-sm text-hq-moss">
                    No hay partidos programados para esta jornada.
                </p>
            ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {fixtures.map((fixture) => (
                        <HqFixtureCard key={fixture.id} fixture={fixture} />
                    ))}
                </div>
            )}
        </HqSection>
    );
}
