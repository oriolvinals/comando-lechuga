import { Link, router } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqSection } from '@/components/hq-section';
import { HqWeekPicker } from '@/components/hq-week-picker';
import { FIXTURE_STATE_LABELS, isLiveFixtureState } from '@/lib/fixture-state';
import { formatMatchDateTime } from '@/lib/format';
import { useCountdown } from '@/lib/use-countdown';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { show as fixturesShow } from '@/routes/fixtures';
import type { Fixture, Season } from '@/types/models';

interface FixturesPanelProps {
    fixtures: Fixture[];
    season: Season;
    week: number;
}

const COUNTDOWN_THRESHOLD_MS = 2 * 60 * 60 * 1000;

function FixtureCard({ fixture }: { fixture: Fixture }) {
    const countdown = useCountdown(fixture.date);
    const isLive = isLiveFixtureState(fixture.state);
    const isFinished = fixture.state === 'finished';
    const hasScore = isLive || isFinished;
    const isScheduled = fixture.state === 'scheduled';
    const remainingMs = new Date(fixture.date).getTime() - Date.now();
    const startsSoon =
        isScheduled && remainingMs > 0 && remainingMs < COUNTDOWN_THRESHOLD_MS;

    return (
        <Link
            href={fixturesShow(fixture.id).url}
            className={cn(
                'relative rounded-md border bg-hq-panel px-4 py-3.5 text-center transition-colors hover:bg-hq-panel-alt',
                isLive
                    ? 'border-hq-live'
                    : 'border-hq-border hover:border-hq-border-strong',
            )}
        >
            <span
                className={cn(
                    'absolute top-2.5 right-2.5 h-2 w-2 rounded-full',
                    isFinished && 'bg-hq-lime',
                    isLive && 'animate-pulse bg-hq-live',
                    fixture.state === 'scheduled' && 'bg-hq-moss-dim',
                )}
            />
            <div className="flex items-center justify-center gap-4">
                <div>
                    <EntityImage
                        src={fixture.local_team.logo}
                        alt={fixture.local_team.name}
                        fallback={Shield}
                        shape="square"
                        className="h-12 w-12 border border-hq-border-strong bg-hq-border-strong/40 p-1.5"
                    />
                    <p className="mt-1.5 font-mono text-[10px] font-bold text-hq-moss">
                        {fixture.local_team.short_name}
                    </p>
                </div>
                {hasScore ? (
                    <div className="flex items-center gap-2 font-display text-2xl text-hq-paper">
                        <span>{fixture.local_score}</span>
                        <span className="text-hq-moss-dim">–</span>
                        <span>{fixture.guest_score}</span>
                    </div>
                ) : (
                    <span className="font-display text-lg text-hq-moss">
                        VS
                    </span>
                )}
                <div>
                    <EntityImage
                        src={fixture.guest_team.logo}
                        alt={fixture.guest_team.name}
                        fallback={Shield}
                        shape="square"
                        className="h-12 w-12 border border-hq-border-strong bg-hq-border-strong/40 p-1.5"
                    />
                    <p className="mt-1.5 font-mono text-[10px] font-bold text-hq-moss">
                        {fixture.guest_team.short_name}
                    </p>
                </div>
            </div>
            <p
                className={cn(
                    'mt-3 font-mono text-[10px] uppercase',
                    isLive && 'font-bold text-hq-live',
                    startsSoon && 'font-bold text-hq-gold',
                    !isLive && !startsSoon && 'text-hq-moss-dim',
                )}
            >
                {isScheduled
                    ? startsSoon
                        ? countdown
                        : formatMatchDateTime(fixture.date)
                    : FIXTURE_STATE_LABELS[fixture.state]}
            </p>
        </Link>
    );
}

export function FixturesPanel({ fixtures, season, week }: FixturesPanelProps) {
    const goToWeek = (nextWeek: number) => {
        router.get(home().url, { week: nextWeek }, { preserveScroll: true });
    };

    return (
        <HqSection title="Jornadas">
            <div className="mb-4">
                <HqWeekPicker
                    week={week}
                    maxWeek={season.total_weeks}
                    playedThroughWeek={season.current_week}
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
                        <FixtureCard key={fixture.id} fixture={fixture} />
                    ))}
                </div>
            )}
        </HqSection>
    );
}
