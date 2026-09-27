import { Link } from '@inertiajs/react';
import { ChevronRight, Shield } from 'lucide-react';
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import {
    COUNTDOWN_THRESHOLD_MS,
    FIXTURE_STATE_LABELS,
    isLiveFixtureState,
} from '@/lib/fixture-state';
import { formatTime } from '@/lib/format';
import { useCountdown } from '@/lib/use-countdown';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type { Fixture, Team } from '@/types/models';

type RowState = 'live' | 'soon' | 'scheduled' | 'postponed' | 'finished';

const STATE_TONE: Record<RowState, string> = {
    live: 'text-hq-live',
    soon: 'text-hq-gold',
    scheduled: 'text-hq-moss-dim',
    postponed: 'text-hq-moss',
    finished: 'text-hq-moss',
};

const STATE_DOT: Record<RowState, string> = {
    live: 'animate-hq-pulse bg-hq-live',
    soon: 'bg-hq-gold',
    scheduled: 'bg-hq-led-off',
    postponed: 'border border-hq-moss-dim',
    finished: 'bg-hq-lime',
};

function rowStateOf(fixture: Fixture, now: number): RowState {
    if (isLiveFixtureState(fixture.state)) {
        return 'live';
    }

    if (fixture.state === 'finished') {
        return 'finished';
    }

    if (fixture.state === 'postponed') {
        return 'postponed';
    }

    const remainingMs = new Date(fixture.date).getTime() - now;

    return remainingMs > 0 && remainingMs < COUNTDOWN_THRESHOLD_MS
        ? 'soon'
        : 'scheduled';
}

function TeamCell({
    team,
    side,
    isLoser,
}: {
    team: Team;
    side: 'local' | 'guest';
    isLoser: boolean;
}) {
    return (
        <span
            className={cn(
                'flex min-w-0 items-center gap-2.5 md:gap-3',
                side === 'local'
                    ? 'flex-row-reverse justify-end [grid-area:l] md:flex-row md:justify-end md:text-right md:[grid-area:auto]'
                    : '[grid-area:g] md:[grid-area:auto]',
            )}
        >
            {side === 'guest' && <Crest team={team} />}
            <span
                className={cn(
                    'truncate text-sm leading-[1.1] font-extrabold tracking-[-0.005em] uppercase min-[73.75rem]:text-[17px]',
                    isLoser ? 'text-hq-moss' : 'text-hq-paper',
                )}
            >
                {team.main_name}
            </span>
            {side === 'local' && <Crest team={team} />}
        </span>
    );
}

function Crest({ team }: { team: Team }) {
    return (
        <EntityImage
            src={team.logo}
            alt=""
            fallback={Shield}
            shape="square"
            className="h-[26px] w-[26px] shrink-0 rounded-none bg-transparent text-hq-moss-dim md:h-9 md:w-9"
        />
    );
}

/** The state column: dot + label on top, the clock / countdown / kick-off / final clock below. */
function StateCell({ fixture, state }: { fixture: Fixture; state: RowState }) {
    const countdown = useCountdown(fixture.date);
    let label: string;
    let detail: ReactNode = null;

    switch (state) {
        case 'live':
            label = FIXTURE_STATE_LABELS[fixture.state];
            detail =
                fixture.state !== 'half_time' && fixture.display_clock ? (
                    <HqLed tone="amber" className="text-lg">
                        {fixture.display_clock}
                    </HqLed>
                ) : null;
            break;
        case 'soon':
            label = 'Empieza en';
            detail = (
                <HqLed tone="gold" className="text-lg">
                    {countdown}
                </HqLed>
            );
            break;
        case 'scheduled':
            label = 'Programado';
            detail = (
                <span className="hidden text-hq-moss md:inline">
                    {formatTime(fixture.date)}
                </span>
            );
            break;
        case 'postponed':
            label = FIXTURE_STATE_LABELS.postponed;
            detail = <span className="text-hq-moss-dim">sin fecha</span>;
            break;
        case 'finished':
            label = FIXTURE_STATE_LABELS.finished;
            detail = fixture.display_clock ? (
                <span className="text-hq-moss-dim">
                    {fixture.display_clock}
                </span>
            ) : null;
            break;
    }

    return (
        <span className="flex items-center justify-end gap-2 self-center font-mono text-[11px] leading-none font-bold tracking-[0.08em] uppercase [grid-area:st] md:flex-col md:items-start md:justify-center md:gap-1.5 md:[grid-area:auto]">
            <span
                className={cn(
                    'inline-flex items-center gap-[7px] whitespace-nowrap',
                    STATE_TONE[state],
                )}
            >
                <i
                    aria-hidden="true"
                    className={cn(
                        'block h-[7px] w-[7px] shrink-0 rounded-full',
                        STATE_DOT[state],
                    )}
                />
                {label}
            </span>
            {detail && (
                <span className="font-medium tracking-[0.02em] whitespace-nowrap">
                    {detail}
                </span>
            )}
        </span>
    );
}

/**
 * One fixture as a generous console row (mock `.fxr`): kick-off time, both
 * teams with 36px crests, the dot-matrix score with the loser dimmed (VS
 * before kick-off), and the state column — FINALIZADO + final clock, the
 * pulsing live half + amber clock, the gold "Empieza en" countdown under
 * 2h, Programado + time, or APLAZADO. Live and starting-soon rows get a
 * coloured left edge. Below `md` each row folds into two team lines with
 * the score stacked on the right and time · state underneath.
 */
export function FixtureRow({ fixture }: { fixture: Fixture }) {
    const now = useNow();
    const state = rowStateOf(fixture, now);
    const hasScore = state === 'live' || state === 'finished';
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
                'group grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 border-b border-hq-border px-3.5 py-[13px] transition-colors [grid-template-areas:"l_sc""g_sc""w_st"] hover:bg-hq-panel',
                'md:min-h-[76px] md:grid-cols-[92px_minmax(0,1fr)_120px_minmax(0,1fr)_120px_18px] md:gap-3 md:px-5 md:py-4 md:[grid-template-areas:none]',
                'min-[73.75rem]:grid-cols-[120px_minmax(0,1fr)_150px_minmax(0,1fr)_150px_24px] min-[73.75rem]:gap-[18px]',
                state === 'live' &&
                    'bg-hq-live/4 shadow-[inset_3px_0_0_var(--color-hq-live)]',
                state === 'soon' &&
                    'shadow-[inset_3px_0_0_var(--color-hq-gold)]',
                state === 'postponed' && 'opacity-70',
            )}
        >
            <span className="self-center font-mono text-[11px] leading-none font-medium tracking-[0.04em] text-hq-moss uppercase [grid-area:w] md:text-xs md:[grid-area:auto]">
                {formatTime(fixture.date)}
            </span>
            <TeamCell
                team={fixture.local_team}
                side="local"
                isLoser={localLoses}
            />
            <span className="flex flex-col items-center justify-center gap-0.5 [grid-area:sc] md:flex-row md:gap-2.5 md:[grid-area:auto]">
                {hasScore ? (
                    <>
                        <HqLed
                            tone="lime"
                            className={cn(
                                'text-[30px] md:text-[40px]',
                                localLoses && 'text-[#6f7a55]',
                            )}
                        >
                            {localScore}
                        </HqLed>
                        <span
                            aria-hidden="true"
                            className="hidden font-dot text-[22px] leading-none font-black text-hq-border-bright md:inline"
                        >
                            –
                        </span>
                        <HqLed
                            tone="lime"
                            className={cn(
                                'text-[30px] md:text-[40px]',
                                guestLoses && 'text-[#6f7a55]',
                            )}
                        >
                            {guestScore}
                        </HqLed>
                    </>
                ) : (
                    <span className="font-dot text-2xl leading-none font-black text-hq-moss-dim">
                        VS
                    </span>
                )}
            </span>
            <TeamCell
                team={fixture.guest_team}
                side="guest"
                isLoser={guestLoses}
            />
            <StateCell fixture={fixture} state={state} />
            <ChevronRight
                aria-hidden="true"
                className="hidden h-4 w-4 text-hq-moss-dim group-hover:text-hq-lime md:block"
            />
        </Link>
    );
}
