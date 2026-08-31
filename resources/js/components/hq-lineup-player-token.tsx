import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { EventGlyph } from '@/components/match-event-icons';
import { matchPointsBadgeClass, matchPointsBadgeClassOnPhoto } from '@/lib/points';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { FixtureLineupEntry } from '@/types/models';

interface HqLineupPlayerTokenProps {
    entry: FixtureLineupEntry;
    variant: 'pitch' | 'bench';
    /** Omitted entirely for an unresolved token (no player to show anything for). */
    onSelect?: (entry: FixtureLineupEntry) => void;
}

const AVATAR_SIZE: Record<'pitch' | 'bench', string> = {
    pitch: 'h-13 w-13', // 52px
    bench: 'h-8.5 w-8.5', // 34px
};

// Bench only: the avatar wrapper is taller than the photo itself, leaving
// room below it for the position tag so the tag sits under the photo
// instead of overlapping it.
const AVATAR_WRAP_SIZE: Record<'pitch' | 'bench', string> = {
    pitch: 'h-13 w-13',
    bench: 'h-11 w-8.5',
};

function statCount(stats: FixtureLineupEntry['stats'], key: string): number {
    return stats?.[key]?.[0] ?? 0;
}

export function HqLineupPlayerToken({ entry, variant, onSelect }: HqLineupPlayerTokenProps) {
    const isPitch = variant === 'pitch';
    const clickable = entry.player !== null && onSelect !== undefined;
    const handleClick = clickable ? () => onSelect(entry) : undefined;
    const hasPlayed = entry.starter || entry.subbed_in;
    const subMinute = entry.subbed_in || entry.subbed_out ? entry.sub_minute : null;

    // Everything here reads off fantasy_stats (entry.stats), not worldcup26's
    // own event log — worldcup26 doesn't distinguish an own goal from a
    // regular one, a second yellow from a first, or give penalty
    // won/conceded/saved or clean sheets. Mirrors MatchEventIcons (used on
    // the player ficha's own match timeline) exactly, split into good/bad
    // groups for the two-corner badge layout that component doesn't need.
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

    const hasGoodEvent = goals > 0 || assists > 0 || penaltyWon > 0 || penaltySaved > 0 || cleanSheet;
    const hasBadEvent = ownGoals > 0 || yellow || secondYellow || red || penaltyConceded > 0 || penaltyMissed > 0;

    // Icon content only — positioning/background differ between the pitch
    // (pegged to the avatar corner) and the bench (its own strip below the
    // name, since the bench row has room to spare and the pitch token doesn't).
    const goodIcons = (
        <>
            {goals > 0 && (
                <EventGlyph count={goals} title="Gol">
                    <span className="text-[13px] leading-none">⚽</span>
                </EventGlyph>
            )}
            {assists > 0 && (
                <EventGlyph count={assists} title="Asistencia">
                    <span className="text-[13px] leading-none text-hq-med">➜</span>
                </EventGlyph>
            )}
            {penaltyWon > 0 && (
                <EventGlyph count={penaltyWon} title="Provoca penalti">
                    <span className="border border-hq-gold px-1 py-px font-mono text-[9px] font-bold text-hq-gold">P+</span>
                </EventGlyph>
            )}
            {penaltySaved > 0 && (
                <EventGlyph count={penaltySaved} title="Penalti parado">
                    <span className="border border-hq-lime px-1 py-px font-mono text-[9px] font-bold text-hq-lime">P✓</span>
                </EventGlyph>
            )}
            {cleanSheet && (
                <span title="Portería a cero" className="border border-hq-lime px-1 py-px font-mono text-[9px] font-bold text-hq-lime">0</span>
            )}
        </>
    );
    const badIcons = (
        <>
            {ownGoals > 0 && (
                <EventGlyph count={ownGoals} title="Autogol">
                    <span className="border border-hq-live px-1 py-px font-mono text-[9px] font-bold text-hq-live">PP</span>
                </EventGlyph>
            )}
            {yellow && <span title="Amarilla" className="hq-crest-cut h-3.5 w-2.5 bg-hq-gold" />}
            {secondYellow && (
                <span title="Doble amarilla" className="relative inline-block h-3.5 w-4">
                    <span className="hq-crest-cut absolute top-0.5 left-0 h-3 w-2 bg-hq-gold/60" />
                    <span className="hq-crest-cut absolute top-0 left-1.5 h-3 w-2 bg-hq-gold" />
                </span>
            )}
            {red && <span title="Roja" className="hq-crest-cut h-3.5 w-2.5 bg-hq-live" />}
            {penaltyConceded > 0 && (
                <EventGlyph count={penaltyConceded} title="Comete penalti">
                    <span className="border border-hq-ember px-1 py-px font-mono text-[9px] font-bold text-hq-ember">P−</span>
                </EventGlyph>
            )}
            {penaltyMissed > 0 && (
                <EventGlyph count={penaltyMissed} title="Penalti fallado">
                    <span className="border border-hq-live px-1 py-px font-mono text-[9px] font-bold text-hq-live">P✗</span>
                </EventGlyph>
            )}
        </>
    );

    const avatarImage = entry.player ? (
        <EntityImage
            src={entry.player.image}
            alt={entry.player.nickname}
            fallback={User}
            className={cn(AVATAR_SIZE[variant], !isPitch && 'absolute top-0', 'border-[1.5px] border-hq-border-strong bg-hq-border')}
        />
    ) : (
        <div
            className={cn(
                AVATAR_SIZE[variant],
                !isPitch && 'absolute top-0',
                'flex items-center justify-center rounded-full border-[1.5px] border-dashed border-hq-border-strong font-mono text-hq-moss-dim',
            )}
        >
            ?
        </div>
    );

    const avatar = (
        <div className={cn('relative shrink-0', AVATAR_WRAP_SIZE[variant])}>
            {isPitch && hasBadEvent && (
                <span className="absolute -top-1.5 left-3 z-10 flex -translate-x-full items-center gap-1 whitespace-nowrap px-1 py-px font-mono text-[9px] font-bold text-hq-live">
                    {badIcons}
                </span>
            )}
            {isPitch && hasGoodEvent && (
                <span className="absolute -top-1.5 right-3 z-10 flex translate-x-full items-center gap-1 whitespace-nowrap px-1 py-px font-mono text-[9px] font-bold text-hq-lime">
                    {goodIcons}
                </span>
            )}

            {avatarImage}

            {isPitch && subMinute !== null && (
                <span
                    className={cn(
                        'absolute -bottom-1 left-3 z-10 -translate-x-full whitespace-nowrap border bg-hq-ink px-1 py-px font-mono text-[10px] font-bold',
                        entry.subbed_out ? 'border-hq-live text-hq-live' : 'border-hq-lime text-hq-lime',
                    )}
                >
                    ↳{subMinute}
                </span>
            )}

            {isPitch && entry.player && entry.points !== null && (
                <span
                    className={cn(
                        'absolute right-3 -bottom-1 z-10 translate-x-full rounded-[2px] px-1 py-px font-mono text-[11px] font-bold',
                        matchPointsBadgeClassOnPhoto(entry.points),
                    )}
                >
                    {entry.points}
                </span>
            )}

            {!isPitch && entry.player && (
                <HqPositionTag
                    position={entry.player.position}
                    className="absolute bottom-0 left-1/2 z-10 -translate-x-1/2 bg-hq-ink px-1 py-0.5 text-[7px] whitespace-nowrap"
                />
            )}
        </div>
    );

    const nameLine = (
        <div
            className={cn(
                'truncate font-mono text-hq-paper',
                isPitch ? 'mt-1 text-center text-[11px]' : 'text-[12.5px] font-bold',
            )}
            title={entry.player ? undefined : `match_data_id: ${entry.match_data_id}`}
        >
            <b className="mr-1 text-hq-lime">{entry.jersey}</b>
            {entry.player?.nickname ?? entry.unresolved_name ?? 'No vinculado'}
        </div>
    );

    // Bench only: every event/substitution legend in one row right under the
    // name, instead of splitting cards/goals from the sub badge or pushing
    // them into their own column — the bench row has the width to spare.
    const benchLegendLine = !isPitch && (hasGoodEvent || hasBadEvent || subMinute !== null) && (
        <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
            {subMinute !== null && (
                <span
                    className={cn(
                        'whitespace-nowrap border bg-hq-ink px-1 py-px font-mono text-[10px] font-bold',
                        entry.subbed_out ? 'border-hq-live text-hq-live' : 'border-hq-lime text-hq-lime',
                    )}
                >
                    ↳{subMinute}
                </span>
            )}
            {hasGoodEvent && (
                <span className="flex items-center gap-1 font-mono text-[9px] font-bold text-hq-lime">
                    {goodIcons}
                </span>
            )}
            {hasBadEvent && (
                <span className="flex items-center gap-1 font-mono text-[9px] font-bold text-hq-live">
                    {badIcons}
                </span>
            )}
        </div>
    );

    const managerLine = entry.lineup_manager && (
        <Link
            href={seasonManagersShow(entry.lineup_manager.id).url}
            onClick={(event) => event.stopPropagation()}
            className={cn(
                'inline-flex max-w-full items-center gap-1 truncate text-hq-moss hover:text-hq-paper',
                isPitch
                    ? 'absolute top-full left-1/2 mt-0.5 -translate-x-1/2 font-sans text-[9px]'
                    : 'mt-0.5 font-mono text-[11px] font-bold',
            )}
        >
            <span
                className="h-2 w-2 shrink-0 rounded-[1px]"
                style={{ backgroundColor: managerColor(entry.lineup_manager.primary_color) }}
            />
            <span className="truncate">{entry.lineup_manager.name}</span>
        </Link>
    );

    const benchStatBadges = entry.player && entry.points !== null && (
        <div className="flex shrink-0 flex-col items-end gap-2">
            <span className={cn('hq-tag-cut w-9 py-0.5 text-center font-display text-[18px]', matchPointsBadgeClass(entry.points))}>
                {entry.points}
            </span>
            {hasPlayed && entry.dazn_points !== null && (
                <span className="flex items-center gap-1 font-mono text-[10px] text-hq-moss-dim">
                    <img src="/images/dazn-logo.png" alt="DAZN" className="h-3.5 w-3.5" />
                    {entry.dazn_points}
                </span>
            )}
        </div>
    );

    if (isPitch) {
        return (
            <div
                className={cn('relative flex w-31 flex-col items-center gap-0.5', clickable && 'cursor-pointer')}
                onClick={handleClick}
            >
                {avatar}
                {nameLine}
                {managerLine}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'flex items-center gap-2.5 px-3 py-2 transition-colors hover:bg-hq-panel-alt',
                clickable && 'cursor-pointer',
            )}
            onClick={handleClick}
        >
            {avatar}
            <div className="min-w-0 flex-1">
                {nameLine}
                {benchLegendLine}
                {managerLine}
            </div>
            {benchStatBadges}
        </div>
    );
}
