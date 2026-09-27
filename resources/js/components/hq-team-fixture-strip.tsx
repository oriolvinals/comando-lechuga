import { Shield } from 'lucide-react';
import { useLayoutEffect, useRef } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqTooltip } from '@/components/hq-tooltip';
import { isLiveFixtureState } from '@/lib/fixture-state';
import { formatMatchDateTime } from '@/lib/format';
import { opponentOf, resultFor } from '@/lib/team-fixture-result';
import { cn } from '@/lib/utils';
import type { Fixture } from '@/types/models';

interface HqTeamFixtureStripProps {
    fixtures: Fixture[];
    teamId: number;
    selectedWeek: number;
    onSelectWeek: (week: number) => void;
    className?: string;
}

/** Mock `.wk.res-*`: a 2px frame in the result colour; dashed when the match has no result yet. */
const RESULT_TILE_CLASSES = {
    win: 'border-2 border-hq-lime text-hq-lime',
    draw: 'border-2 border-hq-gold text-hq-gold',
    loss: 'border-2 border-hq-live text-hq-live',
    none: 'border border-dashed border-hq-border-bright text-hq-paper/35',
} as const;

/**
 * The team ficha's single jornada selector (mock `.wks.tiles-scores` band):
 * every fixture this season, played and upcoming, each tile showing the
 * actual score (not a V/E/D letter) framed in the result colour, the
 * opponent's crest on its bottom edge and a pulse while live — so it doubles
 * as the season's results-at-a-glance calendar. Selecting a tile drives the
 * pitch below it; the tooltip names the rival and the score or kick-off.
 */
export function HqTeamFixtureStrip({
    fixtures,
    teamId,
    selectedWeek,
    onSelectWeek,
    className,
}: HqTeamFixtureStripProps) {
    const selectedRef = useRef<HTMLButtonElement>(null);
    const scrollerRef = useRef<HTMLDivElement>(null);
    const isFirstRender = useRef(true);

    useLayoutEffect(() => {
        const button = selectedRef.current;
        const scroller = scrollerRef.current;

        if (!button || !scroller) {
            return;
        }

        // Only the row scrolls — `scrollIntoView` would also scroll the page.
        const buttonLeft =
            button.getBoundingClientRect().left -
            scroller.getBoundingClientRect().left +
            scroller.scrollLeft;

        scroller.scrollTo({
            left:
                buttonLeft - scroller.clientWidth / 2 + button.offsetWidth / 2,
            behavior: isFirstRender.current ? 'instant' : 'smooth',
        });
        isFirstRender.current = false;
    }, [selectedWeek]);

    return (
        <div className={cn('relative border-b border-hq-border', className)}>
            <div
                ref={scrollerRef}
                className="hq-no-scrollbar flex gap-1 overflow-x-auto px-3.5 pt-3 pb-[18px] sm:px-4"
            >
                {fixtures.map((fixture) => {
                    const opponent = opponentOf(fixture, teamId);
                    const result = resultFor(fixture, teamId);
                    const isSelected = fixture.week_number === selectedWeek;
                    const isLocal = fixture.local_team.id === teamId;
                    const ownScore = isLocal
                        ? fixture.local_score
                        : fixture.guest_score;
                    const rivalScore = isLocal
                        ? fixture.guest_score
                        : fixture.local_score;
                    const hasScore =
                        ownScore !== null &&
                        rivalScore !== null &&
                        fixture.state !== 'scheduled';
                    const isLive = isLiveFixtureState(fixture.state);
                    const scoreline = hasScore
                        ? `${ownScore}-${rivalScore}`
                        : null;
                    const rivalLabel = `J${fixture.week_number} · ${isLocal ? 'vs' : '@'} ${opponent.main_name}`;

                    return (
                        <HqTooltip
                            key={fixture.id}
                            className="shrink-0"
                            borderClassName={
                                result === 'win'
                                    ? 'border-hq-lime'
                                    : result === 'draw'
                                      ? 'border-hq-gold'
                                      : result === 'loss'
                                        ? 'border-hq-live'
                                        : 'border-hq-border-bright'
                            }
                            label={
                                <div className="text-center">
                                    <div>{rivalLabel}</div>
                                    <div className="font-bold">
                                        {isLive && (
                                            <span className="text-hq-live">
                                                EN DIRECTO ·{' '}
                                            </span>
                                        )}
                                        {scoreline ??
                                            formatMatchDateTime(fixture.date)}
                                    </div>
                                </div>
                            }
                        >
                            <button
                                ref={isSelected ? selectedRef : undefined}
                                type="button"
                                aria-pressed={isSelected}
                                aria-label={`${rivalLabel}${scoreline ? ` · ${scoreline}` : ''}`}
                                onClick={() =>
                                    onSelectWeek(fixture.week_number)
                                }
                                className={cn(
                                    'relative flex size-[52px] cursor-pointer flex-col items-center justify-center gap-[3px] transition-colors hover:brightness-125',
                                    RESULT_TILE_CLASSES[result ?? 'none'],
                                    isLive && 'animate-hq-pulse',
                                    isSelected &&
                                        'shadow-[0_0_0_2px_var(--color-hq-paper)]',
                                )}
                            >
                                <span className="font-mono text-[9.5px] leading-none font-semibold tracking-[0.06em] opacity-85">
                                    J{fixture.week_number}
                                </span>
                                <span className="font-dot text-[15px] leading-none font-black">
                                    {scoreline ?? '—'}
                                </span>
                                <EntityImage
                                    src={opponent.logo}
                                    alt=""
                                    fallback={Shield}
                                    shape="square"
                                    className="absolute -bottom-[7px] left-1/2 size-[15px] -translate-x-1/2 rounded-none bg-transparent object-contain drop-shadow-[0_1px_2px_#000]"
                                />
                            </button>
                        </HqTooltip>
                    );
                })}
            </div>
            <span
                aria-hidden="true"
                className="pointer-events-none absolute inset-y-0 left-0 w-7 bg-linear-to-r from-hq-ink to-transparent"
            />
            <span
                aria-hidden="true"
                className="pointer-events-none absolute inset-y-0 right-0 w-7 bg-linear-to-l from-hq-ink to-transparent"
            />
        </div>
    );
}
