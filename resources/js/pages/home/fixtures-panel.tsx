import { Link, router } from '@inertiajs/react';
import { HqFixtureCard } from '@/components/hq-fixture-card';
import { HqSection } from '@/components/hq-section';
import { HqWeekScrollPicker } from '@/components/hq-week-scroll-picker';
import { home } from '@/routes';
import { index as fixturesIndex } from '@/routes/fixtures';
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
        <HqSection
            code="CH·03"
            title="Jornadas"
            action={
                <>
                    <span>
                        Jornada {week} de {season.total_weeks}
                    </span>
                    <span aria-hidden="true">·</span>
                    <Link
                        href={fixturesIndex().url}
                        className="font-bold text-hq-lime hover:underline"
                    >
                        Calendario →
                    </Link>
                </>
            }
            flush
        >
            <div className="relative border-b border-hq-border px-3 py-2 sm:px-4">
                <HqWeekScrollPicker
                    week={week}
                    maxWeek={season.total_weeks}
                    playedThroughWeek={season.current_week}
                    weekProgress={weekProgress}
                    onChange={goToWeek}
                />
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-y-0 left-0 w-7 bg-linear-to-r from-hq-ink to-transparent"
                />
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-y-0 right-0 w-7 bg-linear-to-l from-hq-ink to-transparent"
                />
            </div>

            {fixtures.length === 0 ? (
                <p className="p-4 text-sm text-hq-moss">
                    No hay partidos programados para esta jornada.
                </p>
            ) : (
                <div className="-mb-px grid grid-cols-2 md:grid-cols-3 min-[73.75rem]:grid-cols-5 [&>*]:border-r [&>*]:border-b [&>*]:border-hq-border max-md:[&>*:nth-child(2n)]:border-r-0 md:max-[73.75rem]:[&>*:nth-child(3n)]:border-r-0 min-[73.75rem]:[&>*:nth-child(5n)]:border-r-0">
                    {fixtures.map((fixture) => (
                        <HqFixtureCard key={fixture.id} fixture={fixture} />
                    ))}
                </div>
            )}
        </HqSection>
    );
}
