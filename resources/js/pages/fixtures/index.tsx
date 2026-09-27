import { Head } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactElement } from 'react';
import { useRef, useState } from 'react';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqLed } from '@/components/hq-led';
import { HqPageHeader } from '@/components/hq-page-header';
import { HqChannelHeader } from '@/components/hq-section';
import { HqWeekPickerBand } from '@/components/hq-week-scroll-picker';
import AppLayout from '@/layouts/app-layout';
import { isLiveFixtureState } from '@/lib/fixture-state';
import { FixtureRow } from '@/pages/fixtures/fixture-row';
import type {
    Fixture,
    Season,
    WeekProgress,
    WeekProgressMap,
} from '@/types/models';

interface FixturesIndexProps {
    season: Season;
    fixtures: Fixture[];
    weekProgress: WeekProgressMap;
    [key: string]: unknown;
}

const WEEK_HASH_PATTERN = /^#jornada-(\d+)$/;

const DAY_HEADING_FORMAT = new Intl.DateTimeFormat('es-ES', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
});

const DAY_MONTH_FORMAT = new Intl.DateTimeFormat('es-ES', {
    day: 'numeric',
    month: 'short',
});

const PROGRESS_LABELS: Record<WeekProgress, string> = {
    all: 'completa',
    partial: 'en curso',
    none: 'pendiente',
};

const PROGRESS_CLASSES: Record<WeekProgress, string> = {
    all: 'text-hq-lime',
    partial: 'text-hq-gold',
    none: '',
};

/**
 * The jornada to open on: a `#jornada-N` anchor (old per-week links and
 * this page's own history) wins, otherwise the season's current one.
 */
function initialWeek(season: Season): number {
    if (typeof window === 'undefined') {
        return season.current_week;
    }

    const match = WEEK_HASH_PATTERN.exec(window.location.hash);
    const week = match ? Number(match[1]) : NaN;

    return week >= 1 && week <= season.total_weeks ? week : season.current_week;
}

/** Fixtures of one jornada grouped by calendar day, in kick-off order. */
function groupByDay(fixtures: Fixture[]): [string, Fixture[]][] {
    const groups = new Map<string, Fixture[]>();

    for (const fixture of fixtures) {
        const day = DAY_HEADING_FORMAT.format(new Date(fixture.date));
        const existing = groups.get(day) ?? [];
        existing.push(fixture);
        groups.set(day, existing);
    }

    return Array.from(groups.entries());
}

function WeekHeader({
    week,
    fixtures,
    progress,
}: {
    week: number;
    fixtures: Fixture[];
    progress: WeekProgress;
}) {
    const finishedCount = fixtures.filter(
        (fixture) => fixture.state === 'finished',
    ).length;
    const liveCount = fixtures.filter((fixture) =>
        isLiveFixtureState(fixture.state),
    ).length;
    const postponedCount = fixtures.filter(
        (fixture) => fixture.state === 'postponed',
    ).length;
    const firstDate = DAY_MONTH_FORMAT.format(new Date(fixtures[0].date));
    const lastDate = DAY_MONTH_FORMAT.format(
        new Date(fixtures[fixtures.length - 1].date),
    );

    return (
        <HqChannelHeader
            code={`J${String(week).padStart(2, '0')}`}
            title={`Jornada ${week}`}
            action={
                <span className="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[10.5px] sm:text-xs">
                    <span>
                        {firstDate === lastDate
                            ? firstDate
                            : `${firstDate} – ${lastDate}`}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span className={PROGRESS_CLASSES[progress]}>
                        {PROGRESS_LABELS[progress]}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span>
                        {finishedCount}/{fixtures.length} jugados
                    </span>
                    {liveCount > 0 && (
                        <>
                            <span aria-hidden="true">·</span>
                            <span className="text-hq-live">
                                {liveCount} en directo
                            </span>
                        </>
                    )}
                    {postponedCount > 0 && (
                        <>
                            <span aria-hidden="true">·</span>
                            <span>
                                {postponedCount} aplazado
                                {postponedCount > 1 ? 's' : ''}
                            </span>
                        </>
                    )}
                </span>
            }
        />
    );
}

function WeekStepButton({
    direction,
    week,
    disabled,
    onClick,
}: {
    direction: 'previous' | 'next';
    week: number;
    disabled: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={
                direction === 'previous'
                    ? 'Jornada anterior'
                    : 'Jornada siguiente'
            }
            className="inline-flex min-h-11 items-center gap-1.5 border border-hq-border-strong px-3 font-mono text-xs font-bold tracking-[0.06em] text-hq-moss uppercase transition-colors hover:border-hq-border-bright hover:text-hq-paper disabled:cursor-default disabled:opacity-35 disabled:hover:border-hq-border-strong disabled:hover:text-hq-moss sm:min-h-9"
        >
            {direction === 'previous' && (
                <ChevronLeft aria-hidden="true" className="h-4 w-4" />
            )}
            J{week}
            {direction === 'next' && (
                <ChevronRight aria-hidden="true" className="h-4 w-4" />
            )}
        </button>
    );
}

export default function FixturesIndex({
    season,
    fixtures,
    weekProgress,
}: FixturesIndexProps) {
    const [selectedWeek, setSelectedWeek] = useState(() => initialWeek(season));
    const weekNavRef = useRef<HTMLDivElement>(null);
    const weekFixtures = fixtures.filter(
        (fixture) => fixture.week_number === selectedWeek,
    );
    const days = groupByDay(weekFixtures);

    const goToWeek = (week: number) => {
        if (week < 1 || week > season.total_weeks) {
            return;
        }

        setSelectedWeek(week);
        // Keep the jornada in the URL (without a new history entry) so a
        // reload or a shared link reopens it. Inertia's page state lives in
        // history.state, so pass it through untouched.
        window.history.replaceState(
            window.history.state,
            '',
            `#jornada-${week}`,
        );

        // Picking from the sticky band while scrolled down: bring the new
        // jornada's header back into view instead of leaving the reader
        // mid-list of a different week.
        const nav = weekNavRef.current;

        if (nav && nav.getBoundingClientRect().top < 0) {
            nav.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    return (
        <div className="flex-1">
            <Head title="Partidos" />

            <HqPageHeader
                code="CH·P · CALENDARIO"
                title="Partidos"
                meta={[
                    { label: 'Temporada', value: season.name },
                    { label: 'Jornada actual', value: season.current_week },
                ]}
            />

            <div className="sticky top-(--hq-header-h) z-20 bg-hq-ink">
                <HqWeekPickerBand
                    week={selectedWeek}
                    maxWeek={season.total_weeks}
                    playedThroughWeek={season.current_week}
                    weekProgress={weekProgress}
                    onChange={goToWeek}
                    className="border-hq-border-strong"
                />
            </div>

            <div
                ref={weekNavRef}
                className="flex scroll-mt-[calc(var(--hq-header-h)+70px)] items-center justify-between gap-3 border-b border-hq-border px-3.5 py-3 sm:px-5 sm:py-[18px]"
            >
                <WeekStepButton
                    direction="previous"
                    week={selectedWeek - 1}
                    disabled={selectedWeek <= 1}
                    onClick={() => goToWeek(selectedWeek - 1)}
                />
                <div className="flex items-baseline gap-2.5">
                    <span className="hq-label">Jornada</span>
                    <HqLed tone="lime" glow className="text-4xl sm:text-5xl">
                        {String(selectedWeek).padStart(2, '0')}
                    </HqLed>
                    <span className="hq-label">de {season.total_weeks}</span>
                </div>
                <WeekStepButton
                    direction="next"
                    week={selectedWeek + 1}
                    disabled={selectedWeek >= season.total_weeks}
                    onClick={() => goToWeek(selectedWeek + 1)}
                />
            </div>

            {weekFixtures.length === 0 ? (
                <HqEmptyState title="Sin partidos">
                    No hay partidos programados para esta jornada.
                </HqEmptyState>
            ) : (
                <section aria-label={`Jornada ${selectedWeek}`}>
                    <WeekHeader
                        week={selectedWeek}
                        fixtures={weekFixtures}
                        progress={weekProgress[String(selectedWeek)] ?? 'none'}
                    />
                    {days.map(([day, dayFixtures]) => (
                        <div key={day}>
                            <h3 className="flex justify-between gap-3 border-b border-hq-border-strong bg-hq-ink px-3.5 pt-3.5 pb-2 font-mono text-[11px] leading-none font-bold tracking-[0.14em] text-hq-moss uppercase sm:px-4">
                                <span>{day}</span>
                                <span className="font-medium text-hq-moss-dim">
                                    {dayFixtures.length}{' '}
                                    {dayFixtures.length === 1
                                        ? 'partido'
                                        : 'partidos'}
                                </span>
                            </h3>
                            <div className="flex flex-col">
                                {dayFixtures.map((fixture) => (
                                    <FixtureRow
                                        key={fixture.id}
                                        fixture={fixture}
                                    />
                                ))}
                            </div>
                        </div>
                    ))}
                </section>
            )}
        </div>
    );
}

FixturesIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
