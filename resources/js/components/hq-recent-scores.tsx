import type { MouseEvent } from 'react';
import { useJornadaSheet } from '@/components/hq-jornada-sheet';
import { HqTooltip } from '@/components/hq-tooltip';
import { formatSignedPoints, matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { RecentScoreFixture, Team } from '@/types/models';

interface HqRecentScoresProps {
    scores: (number | null)[];
    /** Per-slot: whether a real finished fixture exists there — lets a null slot render as "not called up" instead of "no match history yet". */
    finished?: boolean[];
    /** Per-slot: was the player in this team's lineup that week? Omit entirely outside the team ficha, where the question doesn't apply. */
    used?: (boolean | null)[];
    /** Per-slot: the rival the player's team faced in that match — shows a small crest floating at the bottom center when provided. */
    opponents?: (Team | null)[];
    /** Per-slot: that match's id and jornada. With `playerId`, a played slot is a button that opens its jornada sheet. */
    fixtures?: RecentScoreFixture[];
    /** Whose scores these are — with `fixtures`, makes each played slot open that match's jornada sheet. Omit for team-level totals. */
    playerId?: number;
    className?: string;
    size?: 'md' | 'sm' | 'xs';
    /** Makes each slot's tooltip keyboard-reachable — off by default, since most rows are already one link. */
    focusable?: boolean;
    /** Color tier for a slot's value — defaults to the per-player scale; pass {@link teamFormBadgeClass} for team-level totals. */
    badgeClass?: (points: number) => string;
    /** Appends one more slot for the jornada currently in progress — its points are still counting, unlike the rest. Omit (or null) when there's no live jornada. */
    live?: number | null;
}

const SIZE_CLASSES: Record<'md' | 'sm' | 'xs', string> = {
    md: 'h-8 w-8 text-[13px]',
    sm: 'h-6 w-6 text-[11px]',
    xs: 'h-[18px] min-w-[22px] px-[3px] text-[11px]',
};

/**
 * Points for the last 3 played matches. A null slot is either "no match
 * history yet" (a dash) or, when `finished` says a real fixture already
 * happened there, "not called up" (a dashed-red "NC"). An optional trailing
 * `live` slot marks the jornada currently in progress with a pulsing dot,
 * distinct from the finished ones — when present, it takes the place of the
 * oldest finished slot so the row stays at the same 3 total, rather than
 * growing to 4.
 * With `playerId` and `fixtures`, a played slot is a button that opens that
 * match's jornada sheet (and never triggers the row or card around it); NC
 * and empty slots stay plain.
 */
export function HqRecentScores({
    scores,
    finished,
    used,
    opponents,
    className,
    size = 'md',
    badgeClass = matchPointsBadgeClass,
    live,
    focusable = false,
    fixtures,
    playerId,
}: HqRecentScoresProps) {
    const sheet = useJornadaSheet();
    const hasLive = live !== undefined && live !== null;
    // Nulls only ever pad the end (see docblock below), so when there's
    // already a gap, drop that trailing null to make room for the live slot
    // instead of an oldest real value — only trim the oldest real entry once
    // the row is already full of real data.
    const trimStart = hasLive && scores[scores.length - 1] !== null;
    function visible<T>(slots: T[]): T[];
    function visible<T>(slots: T[] | undefined): T[] | undefined;
    function visible<T>(slots: T[] | undefined): T[] | undefined {
        if (!hasLive) {
            return slots;
        }

        return trimStart ? slots?.slice(1) : slots?.slice(0, -1);
    }
    const visibleScores = visible(scores);
    const visibleFinished = visible(finished);
    const visibleUsed = visible(used);
    const visibleOpponents = visible(opponents);
    const visibleFixtures = visible(fixtures);

    return (
        <div className={cn('flex shrink-0 gap-1', className)}>
            {visibleScores.map((points, index) => {
                const wasUsed = visibleUsed?.[index];
                const notCalledUp = points === null && visibleFinished?.[index];
                const opponent = visibleOpponents?.[index];
                const fixture = visibleFixtures?.[index] ?? null;
                // A played slot opens that match's jornada sheet; NC and
                // empty slots are only described.
                const opensSheet =
                    sheet !== null &&
                    playerId !== undefined &&
                    fixture !== null &&
                    points !== null;
                const loading =
                    opensSheet && sheet.isLoading(playerId, fixture.id);
                const Slot = opensSheet ? 'button' : 'span';
                const label = [
                    opponent ? `vs ${opponent.main_name}` : null,
                    points !== null
                        ? `${points} pts`
                        : notCalledUp
                          ? 'No convocado'
                          : 'Sin partido todavía',
                    wasUsed === true
                        ? 'En la alineación del manager'
                        : wasUsed === false
                          ? 'Fuera de la alineación'
                          : null,
                ]
                    .filter(Boolean)
                    .join(' · ');

                return (
                    <HqTooltip
                        key={index}
                        label={label}
                        focusable={focusable && !opensSheet}
                    >
                        <Slot
                            {...(opensSheet
                                ? {
                                      type: 'button' as const,
                                      'aria-label': `Ver ficha de la jornada J${fixture.week_number} · ${label}`,
                                      'aria-busy': loading || undefined,
                                      onClick: (event: MouseEvent) => {
                                          // The row or card around it has its own click target.
                                          event.preventDefault();
                                          event.stopPropagation();
                                          sheet.openMatch(playerId, fixture.id);
                                      },
                                  }
                                : {})}
                            className={cn(
                                'relative flex shrink-0 items-center justify-center border font-mono font-bold',
                                SIZE_CLASSES[size],
                                opensSheet &&
                                    'cursor-pointer transition-[filter] hover:brightness-125 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-hq-lime',
                                loading && 'animate-pulse',
                                points !== null
                                    ? badgeClass(points)
                                    : notCalledUp
                                      ? 'border-dashed border-hq-live bg-hq-border-strong text-hq-live'
                                      : 'border-dashed border-hq-border-strong bg-hq-border-strong/40 text-hq-moss-dim',
                            )}
                        >
                            {points ?? (notCalledUp ? 'NC' : '–')}
                            {wasUsed !== undefined && wasUsed !== null && (
                                <span
                                    className={cn(
                                        'absolute -bottom-2 left-1/2 h-[5px] w-[5px] -translate-x-1/2 rounded-full border',
                                        wasUsed
                                            ? 'border-hq-lime bg-hq-lime'
                                            : 'border-hq-border-bright',
                                    )}
                                />
                            )}
                            {opponent && (
                                <img
                                    src={opponent.logo}
                                    alt={opponent.main_name}
                                    title={opponent.main_name}
                                    className="absolute -bottom-1.5 left-1/2 h-3 w-3 -translate-x-1/2 object-contain drop-shadow-[0_1px_2px_rgba(0,0,0,0.9)]"
                                />
                            )}
                        </Slot>
                    </HqTooltip>
                );
            })}
            {hasLive && (
                <HqTooltip
                    label="Jornada en curso: los puntos siguen contando"
                    focusable={focusable}
                >
                    <span
                        className={cn(
                            'relative flex shrink-0 items-center justify-center border font-mono font-bold',
                            SIZE_CLASSES[size],
                            badgeClass(live),
                        )}
                    >
                        {formatSignedPoints(live)}
                        <span className="absolute -top-1.5 -right-1 h-1.5 w-1.5 animate-pulse rounded-full bg-hq-live" />
                    </span>
                </HqTooltip>
            )}
        </div>
    );
}
