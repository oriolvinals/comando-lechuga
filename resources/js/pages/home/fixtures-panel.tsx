import { router } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqSection } from '@/components/hq-section';
import { HqWeekPicker } from '@/components/hq-week-picker';
import { formatMatchDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import type { Fixture, FixtureState, Season } from '@/types/models';

interface FixturesPanelProps {
    fixtures: Fixture[];
    season: Season;
    week: number;
}

const LIVE_STATES: FixtureState[] = ['first_half', 'half_time', 'second_half'];

const STATE_LABELS: Record<FixtureState, string> = {
    scheduled: '',
    first_half: '1ª PARTE',
    half_time: 'DESCANSO',
    second_half: '2ª PARTE',
    finished: 'FINAL',
};

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
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-5">
                    {fixtures.map((fixture) => {
                        const isLive = LIVE_STATES.includes(fixture.state);
                        const isFinished = fixture.state === 'finished';
                        const hasScore = isLive || isFinished;

                        return (
                            <div
                                key={fixture.id}
                                className="relative border border-hq-border bg-hq-panel px-3 py-3.5 text-center"
                            >
                                <span
                                    className={cn(
                                        'absolute top-2 right-2 h-2 w-2 rounded-full',
                                        isFinished && 'bg-hq-lime',
                                        isLive && 'animate-pulse bg-hq-live',
                                        fixture.state === 'scheduled' &&
                                            'bg-hq-moss-dim',
                                    )}
                                />
                                <div className="flex items-center justify-center gap-2">
                                    <EntityImage
                                        src={fixture.local_team.logo}
                                        alt={fixture.local_team.name}
                                        fallback={Shield}
                                        shape="square"
                                        className="h-8 w-8 bg-hq-panel-alt p-1"
                                    />
                                    <span className="font-display text-lg text-hq-paper">
                                        {hasScore
                                            ? `${fixture.local_score}–${fixture.guest_score}`
                                            : 'vs'}
                                    </span>
                                    <EntityImage
                                        src={fixture.guest_team.logo}
                                        alt={fixture.guest_team.name}
                                        fallback={Shield}
                                        shape="square"
                                        className="h-8 w-8 bg-hq-panel-alt p-1"
                                    />
                                </div>
                                <p
                                    className={cn(
                                        'mt-2 font-mono text-[10px]',
                                        isLive
                                            ? 'font-bold text-hq-live'
                                            : 'text-hq-moss-dim',
                                    )}
                                >
                                    {fixture.state === 'scheduled'
                                        ? formatMatchDateTime(fixture.date)
                                        : STATE_LABELS[fixture.state]}
                                </p>
                            </div>
                        );
                    })}
                </div>
            )}
        </HqSection>
    );
}
