import { User } from 'lucide-react';
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqDaznBadge } from '@/components/hq-dazn-badge';
import { HqManagerChip } from '@/components/hq-manager-chip';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqTooltip } from '@/components/hq-tooltip';
import { EventGlyph } from '@/components/match-event-icons';
import { matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { FixtureLineupEntry } from '@/types/models';

interface HqLineupPlayerTokenProps {
    entry: FixtureLineupEntry;
    variant: 'pitch' | 'bench';
    /** Omitted entirely for an unresolved token (no player to show anything for). */
    onSelect?: (entry: FixtureLineupEntry) => void;
}

function statCount(stats: FixtureLineupEntry['stats'], key: string): number {
    return stats?.[key]?.[0] ?? 0;
}

const TEXT_GLYPH_CLASS =
    'border border-current px-[3px] py-0.5 font-mono text-[9.5px] leading-none font-bold';

const CARD_CLASS = 'inline-block h-[13px] w-[9px] rounded-[1px]';

/**
 * Everything here reads off fantasy_stats (entry.stats), not worldcup26's
 * own event log — worldcup26 doesn't distinguish an own goal from a regular
 * one, a second yellow from a first, or give penalty won/conceded/saved or
 * clean sheets. Mirrors MatchEventIcons (the player ficha's match log)
 * exactly, split into good/bad groups for the two-corner pitch layout.
 */
function eventIcons(entry: FixtureLineupEntry): {
    good: ReactNode;
    bad: ReactNode;
    hasGood: boolean;
    hasBad: boolean;
} {
    const goals = statCount(entry.stats, 'goals');
    const ownGoals = statCount(entry.stats, 'own_goals');
    const assists = statCount(entry.stats, 'goal_assist');
    const secondYellow = statCount(entry.stats, 'second_yellow_card') > 0;
    const yellow = statCount(entry.stats, 'yellow_card') > 0 && !secondYellow;
    const red = statCount(entry.stats, 'red_card') > 0 && !secondYellow;
    const penaltyWon = statCount(entry.stats, 'penalty_won');
    const penaltyConceded = statCount(entry.stats, 'penalty_conceded');
    const penaltyMissed = statCount(entry.stats, 'penalty_failed');
    const penaltySaved = statCount(entry.stats, 'penalty_save');
    const cleanSheet =
        entry.player?.position === 'goalkeeper' &&
        statCount(entry.stats, 'goals_conceded') === 0 &&
        statCount(entry.stats, 'mins_played') >= 60;

    const hasGood =
        goals > 0 ||
        assists > 0 ||
        penaltyWon > 0 ||
        penaltySaved > 0 ||
        cleanSheet;
    const hasBad =
        ownGoals > 0 ||
        yellow ||
        secondYellow ||
        red ||
        penaltyConceded > 0 ||
        penaltyMissed > 0;

    const good = (
        <>
            {goals > 0 && (
                <EventGlyph count={goals} title="Gol">
                    <span className="text-[13px] leading-none">⚽</span>
                </EventGlyph>
            )}
            {assists > 0 && (
                <EventGlyph count={assists} title="Asistencia">
                    <span className="font-mono text-[13px] leading-none font-extrabold text-hq-med">
                        ➜
                    </span>
                </EventGlyph>
            )}
            {penaltyWon > 0 && (
                <EventGlyph count={penaltyWon} title="Provoca penalti">
                    <span className={cn(TEXT_GLYPH_CLASS, 'text-hq-gold')}>
                        P+
                    </span>
                </EventGlyph>
            )}
            {penaltySaved > 0 && (
                <EventGlyph count={penaltySaved} title="Penalti parado">
                    <span className={cn(TEXT_GLYPH_CLASS, 'text-hq-lime')}>
                        P✓
                    </span>
                </EventGlyph>
            )}
            {cleanSheet && (
                <span
                    title="Portería a cero"
                    className={cn(TEXT_GLYPH_CLASS, 'text-hq-lime')}
                >
                    0
                </span>
            )}
        </>
    );
    const bad = (
        <>
            {ownGoals > 0 && (
                <EventGlyph count={ownGoals} title="Autogol">
                    <span className={cn(TEXT_GLYPH_CLASS, 'text-hq-live')}>
                        PP
                    </span>
                </EventGlyph>
            )}
            {yellow && (
                <span
                    title="Amarilla"
                    className={cn(CARD_CLASS, 'bg-hq-gold')}
                />
            )}
            {secondYellow && (
                <span
                    title="Doble amarilla"
                    className="relative inline-block h-[13px] w-[15px]"
                >
                    <span
                        className={cn(
                            CARD_CLASS,
                            'absolute top-0.5 left-0 bg-hq-gold/60',
                        )}
                    />
                    <span
                        className={cn(
                            CARD_CLASS,
                            'absolute top-0 left-1.5 bg-hq-gold',
                        )}
                    />
                </span>
            )}
            {red && (
                <span title="Roja" className={cn(CARD_CLASS, 'bg-hq-live')} />
            )}
            {penaltyConceded > 0 && (
                <EventGlyph count={penaltyConceded} title="Comete penalti">
                    <span className={cn(TEXT_GLYPH_CLASS, 'text-hq-ember')}>
                        P−
                    </span>
                </EventGlyph>
            )}
            {penaltyMissed > 0 && (
                <EventGlyph count={penaltyMissed} title="Penalti fallado">
                    <span className={cn(TEXT_GLYPH_CLASS, 'text-hq-live')}>
                        P✗
                    </span>
                </EventGlyph>
            )}
        </>
    );

    return { good, bad, hasGood, hasBad };
}

function SubMinuteBadge({
    entry,
    minute,
    className,
}: {
    entry: FixtureLineupEntry;
    minute: number;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'border border-current bg-hq-ink px-1 py-0.5 font-mono text-[10px] leading-none font-bold whitespace-nowrap',
                entry.subbed_out ? 'text-hq-live' : 'text-hq-lime',
                className,
            )}
        >
            ↳{minute}'
        </span>
    );
}

function playerName(entry: FixtureLineupEntry): string {
    return entry.player?.nickname ?? entry.unresolved_name ?? 'No vinculado';
}

function accessibleLabel(entry: FixtureLineupEntry): string {
    const points =
        entry.points === null ? 'sin puntos' : `${entry.points} puntos`;

    return `${entry.jersey} ${playerName(entry)} · ${points}`;
}

/**
 * A player of a real match lineup. `pitch` is the horizontal match pitch
 * token (mock `.mtok`): 52px framed photo, good events top-right and bad
 * ones top-left, the sub minute bottom-left (red out / lime in), the points
 * tier chip bottom-right, then jersey + name and the manager who fielded
 * him. `bench` is the ruled list row (mock `.lrow`) used for starters in the
 * list view and for Suplentes: photo with the position tag, jersey + name,
 * sub minute and who he swapped with, events, the manager, and the points
 * chip with DAZN underneath. Clicking (or Enter/Space on the name button)
 * opens the player's jornada modal.
 */
export function HqLineupPlayerToken({
    entry,
    variant,
    onSelect,
}: HqLineupPlayerTokenProps) {
    const clickable = entry.player !== null && onSelect !== undefined;
    const handleClick = clickable ? () => onSelect(entry) : undefined;
    const hasPlayed = entry.starter || entry.subbed_in;
    const subMinute =
        entry.subbed_in || entry.subbed_out ? entry.sub_minute : null;
    const events = eventIcons(entry);
    const unresolvedTitle = entry.player
        ? undefined
        : `wc26: sin vincular (${entry.wc26_id})`;

    if (variant === 'pitch') {
        return (
            <div
                onClick={handleClick}
                className={cn(
                    'group relative flex w-32 flex-col items-center',
                    clickable && 'cursor-pointer',
                )}
            >
                <button
                    type="button"
                    disabled={!clickable}
                    aria-label={accessibleLabel(entry)}
                    className="flex max-w-full flex-col items-center outline-none disabled:cursor-default"
                >
                    <span className="relative block h-13 w-13">
                        <span className="absolute inset-0 overflow-hidden border-[1.5px] border-hq-paper/80 bg-hq-well transition-colors group-hover:border-hq-lime group-has-focus-visible:border-hq-lime">
                            {entry.player ? (
                                <EntityImage
                                    src={entry.player.image}
                                    alt=""
                                    fallback={User}
                                    shape="square"
                                    className="h-full w-full rounded-none bg-transparent object-cover"
                                    style={{ objectPosition: 'center 25%' }}
                                />
                            ) : (
                                <span className="flex h-full w-full items-center justify-center font-mono text-hq-moss-dim">
                                    ?
                                </span>
                            )}
                        </span>
                        {events.hasBad && (
                            <span className="absolute -top-[9px] right-[calc(100%-8px)] z-10 flex items-center gap-1 bg-[rgba(6,7,5,0.85)] px-[3px] py-px whitespace-nowrap">
                                {events.bad}
                            </span>
                        )}
                        {events.hasGood && (
                            <span className="absolute -top-[9px] left-[calc(100%-8px)] z-10 flex items-center gap-1 bg-[rgba(6,7,5,0.85)] px-[3px] py-px whitespace-nowrap">
                                {events.good}
                            </span>
                        )}
                        {subMinute !== null && (
                            <SubMinuteBadge
                                entry={entry}
                                minute={subMinute}
                                className="absolute -bottom-[5px] -left-3 z-10"
                            />
                        )}
                        {entry.player && entry.points !== null && (
                            // Ink backing under the translucent tier fill, so
                            // the chip stays legible over the photo.
                            <span className="absolute -right-3 -bottom-[5px] z-10 flex bg-hq-ink">
                                <span
                                    className={cn(
                                        'inline-flex h-[18px] min-w-[22px] items-center justify-center px-[3px] font-mono text-[11px] leading-none font-bold tabular-nums',
                                        matchPointsBadgeClass(entry.points),
                                    )}
                                >
                                    {entry.points}
                                </span>
                            </span>
                        )}
                    </span>
                    <span
                        title={unresolvedTitle}
                        className="mt-1.5 block max-w-32 truncate bg-[rgba(6,7,5,0.8)] px-1 py-0.5 font-mono text-[11px] leading-[1.1] font-medium text-hq-paper"
                    >
                        <b className="mr-1 text-hq-lime">{entry.jersey}</b>
                        {playerName(entry)}
                    </span>
                </button>
                {hasPlayed && (
                    <span className="mt-0.5 flex empty:hidden">
                        <HqDaznBadge entry={entry} size="xs" plate />
                    </span>
                )}
                {entry.lineup_manager && (
                    <HqManagerChip
                        manager={entry.lineup_manager}
                        className="mt-0.5 max-w-32 bg-[rgba(6,7,5,0.7)] px-1 py-px text-[9.5px]"
                    />
                )}
            </div>
        );
    }

    return (
        <div
            onClick={handleClick}
            className={cn(
                'group flex h-16 items-center gap-2.5 border-b border-hq-border px-3.5 transition-colors hover:bg-hq-panel',
                clickable && 'cursor-pointer',
                !hasPlayed && 'opacity-55',
            )}
        >
            <span className="relative shrink-0 pb-2.5">
                {entry.player ? (
                    <EntityImage
                        src={entry.player.image}
                        alt=""
                        fallback={User}
                        shape="square"
                        className="h-10 w-10 rounded-none border border-hq-border-strong bg-hq-well object-cover"
                        style={{ objectPosition: 'center 25%' }}
                    />
                ) : (
                    <span className="flex h-10 w-10 items-center justify-center border border-dashed border-hq-border-bright font-mono text-hq-moss-dim">
                        ?
                    </span>
                )}
                {entry.player && (
                    <HqPositionTag
                        position={entry.player.position}
                        className="absolute bottom-0 left-1/2 -translate-x-1/2 bg-hq-ink px-[3px] py-0.5 text-[8.5px]"
                    />
                )}
            </span>
            <div className="min-w-0 flex-1 pl-1">
                <button
                    type="button"
                    disabled={!clickable}
                    aria-label={accessibleLabel(entry)}
                    className="flex max-w-full items-baseline gap-1.5 text-left outline-none focus-visible:underline disabled:cursor-default"
                >
                    <span className="min-w-[18px] text-right font-mono text-xs leading-none font-extrabold text-hq-lime">
                        {entry.jersey}
                    </span>
                    <span
                        title={unresolvedTitle}
                        className="truncate text-[13.5px] leading-[1.2] font-bold text-hq-paper group-hover:text-hq-lime"
                    >
                        {playerName(entry)}
                    </span>
                </button>
                {/* Always reserved, even when empty, so every row keeps the same height. */}
                <div className="mt-1 flex h-4 min-w-0 items-center gap-1.5 overflow-hidden text-[11px] leading-none whitespace-nowrap">
                    {subMinute !== null && (
                        <SubMinuteBadge
                            entry={entry}
                            minute={subMinute}
                            className="shrink-0"
                        />
                    )}
                    {subMinute !== null && entry.counterpart_player && (
                        <HqTooltip
                            label="Cambio (dato de la alineación)"
                            className="shrink-0 font-mono text-[11px] text-hq-moss-dim"
                        >
                            {entry.subbed_out ? 'sale por' : 'entra por'}{' '}
                            {entry.counterpart_player.nickname}
                        </HqTooltip>
                    )}
                    {events.hasGood && (
                        <span className="inline-flex shrink-0 items-center gap-1.5">
                            {events.good}
                        </span>
                    )}
                    {events.hasBad && (
                        <span className="inline-flex shrink-0 items-center gap-1.5">
                            {events.bad}
                        </span>
                    )}
                    {entry.lineup_manager && (
                        <HqManagerChip
                            manager={entry.lineup_manager}
                            className="min-w-0"
                        />
                    )}
                </div>
            </div>
            {/*
             * A fixed-height slot for the points chip + DAZN badge stack,
             * reserved even when a row has neither (unresolved player,
             * unplayed sub), so every row stays the same height and both
             * team columns keep their rows level.
             */}
            <div className="flex h-[54px] shrink-0 flex-col items-end justify-center gap-1.5">
                {entry.player && entry.points !== null && (
                    <span
                        className={cn(
                            'inline-flex h-8 min-w-10 items-center justify-center px-[5px] font-mono text-base leading-none font-bold tabular-nums',
                            matchPointsBadgeClass(entry.points),
                        )}
                    >
                        {entry.points}
                    </span>
                )}
                {entry.player && hasPlayed && (
                    <HqDaznBadge entry={entry} size="sm" />
                )}
            </div>
        </div>
    );
}
