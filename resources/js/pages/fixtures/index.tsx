import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { HqFixtureCard } from '@/components/hq-fixture-card';
import { HqWeekScrollPicker } from '@/components/hq-week-scroll-picker';
import AppLayout from '@/layouts/app-layout';
import type { Fixture, Season, WeekProgressMap } from '@/types/models';

interface FixturesIndexProps {
    season: Season;
    fixtures: Fixture[];
    weekProgress: WeekProgressMap;
    [key: string]: unknown;
}

function groupByWeek(fixtures: Fixture[]): Map<number, Fixture[]> {
    const groups = new Map<number, Fixture[]>();

    for (const fixture of fixtures) {
        const existing = groups.get(fixture.week_number) ?? [];
        existing.push(fixture);
        groups.set(fixture.week_number, existing);
    }

    return groups;
}

export default function FixturesIndex({
    season,
    fixtures,
    weekProgress,
}: FixturesIndexProps) {
    const [selectedWeek, setSelectedWeek] = useState(season.current_week);
    const fixturesByWeek = groupByWeek(fixtures);

    const goToWeek = (week: number) => {
        setSelectedWeek(week);
        document
            .getElementById(`jornada-${week}`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto max-w-7xl px-6 py-9">
                <Head title="Partidos" />

                <h1 className="mb-6 font-display text-3xl text-hq-paper uppercase">
                    Partidos
                </h1>

                <div className="sticky top-12 z-40 -mx-6 border-b border-hq-border bg-hq-ink px-6 pt-2 pb-4">
                    <HqWeekScrollPicker
                        week={selectedWeek}
                        maxWeek={season.total_weeks}
                        playedThroughWeek={season.current_week}
                        weekProgress={weekProgress}
                        onChange={goToWeek}
                    />
                </div>

                <div className="flex flex-col gap-12 pt-8">
                    {Array.from(
                        { length: season.total_weeks },
                        (_, index) => index + 1,
                    ).map((week) => {
                        const weekFixtures = fixturesByWeek.get(week) ?? [];

                        if (weekFixtures.length === 0) {
                            return null;
                        }

                        return (
                            <section
                                key={week}
                                id={`jornada-${week}`}
                                className="scroll-mt-24"
                            >
                                <h2 className="mb-3 border-b border-hq-border pb-2 font-display text-xl text-hq-paper uppercase">
                                    Jornada {week}
                                </h2>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                                    {weekFixtures.map((fixture) => (
                                        <HqFixtureCard
                                            key={fixture.id}
                                            fixture={fixture}
                                        />
                                    ))}
                                </div>
                            </section>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

FixturesIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
