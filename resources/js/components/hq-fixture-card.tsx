import { Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
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
import type { Fixture, Team } from '@/types/models';

/**
 * The crests/score/state content shared by HqFixtureCard and any other
 * layout that wants to embed it under its own Link/hover (e.g. a modal
 * section where the whole block — including a label above this content —
 * should hover as one unit, which isn't possible with a Link nested inside
 * another Link).
 */
export function HqFixtureCardContent({ fixture }: { fixture: Fixture }) {
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
        ? null
        : formatFixtureSecondaryText(
              fixture.state,
              fixture.date,
              fixture.display_clock,
              formatMatchDateShort,
          );

    return (
        <>
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
                    'mt-1 font-mono text-[10px] uppercase',
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
        </>
    );
}

function ScoreboardRow({
    team,
    score,
    isLoser,
}: {
    team: Team;
    score: number | null;
    isLoser: boolean;
}) {
    return (
        <div className="grid grid-cols-[22px_minmax(0,1fr)_auto] items-center gap-2 py-[3px]">
            <EntityImage
                src={team.logo}
                alt={team.main_name}
                fallback={Shield}
                shape="square"
                className="h-[22px] w-[22px] rounded-none text-hq-moss-dim"
            />
            <span
                className={cn(
                    'truncate text-xs leading-none font-bold tracking-[0.02em] uppercase md:text-[13px]',
                    isLoser ? 'text-hq-moss' : 'text-hq-paper',
                )}
            >
                {team.short_name}
            </span>
            <HqLed
                tone={isLoser ? 'off' : 'paper'}
                className="min-w-[18px] text-right text-[21px] md:text-2xl"
            >
                {score ?? ''}
            </HqLed>
        </div>
    );
}

/**
 * A fixture as a mini scoreboard: one row per team (crest, short name,
 * dot-matrix score — the loser dimmed), then a dashed foot with the state
 * dot and label (kickoff date, countdown under 2h, live half, FINALIZADO…)
 * and the secondary line (finished date or live clock). Borderless so a
 * parent grid can rule it; the live state draws its own red frame.
 */
export function HqFixtureCard({
    fixture,
    className,
}: {
    fixture: Fixture;
    className?: string;
}) {
    const countdown = useCountdown(fixture.date);
    const now = useNow();
    const isLive = isLiveFixtureState(fixture.state);
    const isFinished = fixture.state === 'finished';
    const isScheduled = fixture.state === 'scheduled';
    const hasScore = isLive || isFinished;
    const remainingMs = new Date(fixture.date).getTime() - now;
    const startsSoon =
        isScheduled && remainingMs > 0 && remainingMs < COUNTDOWN_THRESHOLD_MS;
    const secondaryText = startsSoon
        ? null
        : formatFixtureSecondaryText(
              fixture.state,
              fixture.date,
              fixture.display_clock,
              formatMatchDateShort,
          );
    const localScore = hasScore ? fixture.local_score : null;
    const guestScore = hasScore ? fixture.guest_score : null;
    const localLoses =
        localScore !== null && guestScore !== null && localScore < guestScore;
    const guestLoses =
        localScore !== null && guestScore !== null && guestScore < localScore;

    return (
        <Link
            href={fixturesShow(fixture.id).url}
            className={cn(
                'block px-[11px] pt-2.5 pb-[11px] transition-colors hover:bg-hq-panel md:px-3.5 md:pt-3',
                isLive && 'shadow-[inset_0_0_0_1px_var(--color-hq-live)]',
                className,
            )}
        >
            <ScoreboardRow
                team={fixture.local_team}
                score={localScore}
                isLoser={localLoses}
            />
            <ScoreboardRow
                team={fixture.guest_team}
                score={guestScore}
                isLoser={guestLoses}
            />
            <div className="mt-2 flex items-center justify-between gap-1.5 border-t border-dashed border-hq-border pt-[7px] font-mono text-[10.5px] leading-[1.2] font-semibold tracking-[0.05em] text-hq-moss-dim uppercase">
                <span
                    className={cn(
                        'inline-flex min-w-0 items-center gap-1.5',
                        isLive && 'text-hq-live',
                        startsSoon && 'text-hq-gold',
                        fixture.state === 'postponed' && 'text-hq-moss',
                    )}
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'h-1.5 w-1.5 shrink-0 rounded-full bg-hq-led-off',
                            isFinished && 'bg-hq-lime',
                            isLive && 'animate-hq-pulse bg-hq-live',
                        )}
                    />
                    {isScheduled ? (
                        startsSoon ? (
                            <HqLed tone="gold" className="text-sm">
                                {countdown}
                            </HqLed>
                        ) : (
                            <span className="truncate">
                                {formatMatchDateTime(fixture.date)}
                            </span>
                        )
                    ) : (
                        FIXTURE_STATE_LABELS[fixture.state]
                    )}
                </span>
                {secondaryText && (
                    <span className={cn('shrink-0', isLive && 'text-hq-live')}>
                        {secondaryText}
                    </span>
                )}
            </div>
        </Link>
    );
}
