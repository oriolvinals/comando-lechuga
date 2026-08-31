import { Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import {
    COUNTDOWN_THRESHOLD_MS,
    FIXTURE_STATE_LABELS,
    formatFixtureSecondaryText,
    isLiveFixtureState,
} from '@/lib/fixture-state';
import { formatMatchDateShort, formatMatchDateTime } from '@/lib/format';
import { useCountdown } from '@/lib/use-countdown';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type { Fixture } from '@/types/models';

export function HqFixtureCard({ fixture }: { fixture: Fixture }) {
    const countdown = useCountdown(fixture.date);
    const now = useNow();
    const isLive = isLiveFixtureState(fixture.state);
    const isFinished = fixture.state === 'finished';
    const hasScore = isLive || isFinished;
    const isScheduled = fixture.state === 'scheduled';
    const remainingMs = new Date(fixture.date).getTime() - now;
    const startsSoon =
        isScheduled && remainingMs > 0 && remainingMs < COUNTDOWN_THRESHOLD_MS;
    const secondaryText = startsSoon
        ? formatMatchDateShort(fixture.date)
        : formatFixtureSecondaryText(
              fixture.state,
              fixture.date,
              fixture.display_clock,
              formatMatchDateShort,
          );

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
            <div className="flex items-center justify-center gap-2 sm:gap-4">
                <div className="shrink-0">
                    <EntityImage
                        src={fixture.local_team.logo}
                        alt={fixture.local_team.main_name}
                        fallback={Shield}
                        shape="square"
                        className="h-12 w-12 border border-hq-border-strong bg-hq-border-strong/40 p-1.5"
                    />
                    <p className="mt-1.5 font-mono text-[10px] font-bold text-hq-moss">
                        {fixture.local_team.short_name}
                    </p>
                </div>
                {hasScore ? (
                    <div className="flex shrink-0 items-center gap-1 font-display text-xl text-hq-paper sm:gap-2 sm:text-2xl">
                        <span>{fixture.local_score}</span>
                        <span className="text-hq-moss-dim">–</span>
                        <span>{fixture.guest_score}</span>
                    </div>
                ) : (
                    <span className="shrink-0 font-display text-lg text-hq-moss">
                        VS
                    </span>
                )}
                <div className="shrink-0">
                    <EntityImage
                        src={fixture.guest_team.logo}
                        alt={fixture.guest_team.main_name}
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
            {secondaryText && (
                <p
                    className={cn(
                        'mt-0.5 font-mono text-[9px] uppercase',
                        isLive ? 'text-hq-live' : 'text-hq-moss-dim',
                    )}
                >
                    {secondaryText}
                </p>
            )}
        </Link>
    );
}
