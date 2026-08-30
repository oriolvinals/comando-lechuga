import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { daznPointsBadgeClass, matchPointsBadgeClass } from '@/lib/points';
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
    const penaltyWon = statCount(entry.stats, 'penalty_won') > 0;
    const penaltyConceded = statCount(entry.stats, 'penalty_conceded') > 0;
    const penaltyMissed = statCount(entry.stats, 'penalty_failed') > 0;
    const penaltySaved = statCount(entry.stats, 'penalty_save') > 0;
    const cleanSheet =
        entry.player?.position === 'goalkeeper' &&
        statCount(entry.stats, 'goals_conceded') === 0 &&
        statCount(entry.stats, 'mins_played') >= 60;

    const hasGoodEvent = goals > 0 || assists > 0 || penaltyWon || penaltySaved || cleanSheet;
    const hasBadEvent = ownGoals > 0 || yellow || secondYellow || red || penaltyConceded || penaltyMissed;

    // Icon content only — positioning/background differ between the pitch
    // (pegged to the avatar corner) and the bench (its own strip below the
    // name, since the bench row has room to spare and the pitch token doesn't).
    const goodIcons = (
        <>
            {Array.from({ length: goals }, (_, i) => (
                <span key={`g-${i}`} className="text-[13px] leading-none">⚽</span>
            ))}
            {Array.from({ length: assists }, (_, i) => (
                <span key={`a-${i}`} className="text-[13px] leading-none text-hq-med">➜</span>
            ))}
            {penaltyWon && (
                <span className="border border-hq-gold px-1 py-px font-mono text-[9px] font-bold text-hq-gold">P+</span>
            )}
            {penaltySaved && (
                <span className="border border-hq-lime px-1 py-px font-mono text-[9px] font-bold text-hq-lime">P✓</span>
            )}
            {cleanSheet && (
                <span className="border border-hq-lime px-1 py-px font-mono text-[9px] font-bold text-hq-lime">0</span>
            )}
        </>
    );
    const badIcons = (
        <>
            {Array.from({ length: ownGoals }, (_, i) => (
                <span key={`og-${i}`} className="border border-hq-live px-1 py-px font-mono text-[9px] font-bold text-hq-live">PP</span>
            ))}
            {yellow && <span className="hq-crest-cut h-3.5 w-2.5 bg-hq-gold" />}
            {secondYellow && (
                <span className="relative inline-block h-3.5 w-4">
                    <span className="hq-crest-cut absolute top-0.5 left-0 h-3 w-2 bg-hq-gold/60" />
                    <span className="hq-crest-cut absolute top-0 left-1.5 h-3 w-2 bg-hq-gold" />
                </span>
            )}
            {red && <span className="hq-crest-cut h-3.5 w-2.5 bg-hq-live" />}
            {penaltyConceded && (
                <span className="border border-hq-ember px-1 py-px font-mono text-[9px] font-bold text-hq-ember">P−</span>
            )}
            {penaltyMissed && (
                <span className="border border-hq-live px-1 py-px font-mono text-[9px] font-bold text-hq-live">P✗</span>
            )}
        </>
    );

    const avatar = (
        <div className={cn('relative shrink-0', AVATAR_SIZE[variant])}>
            {isPitch && hasBadEvent && (
                <span className="absolute -top-1.5 right-8 z-10 flex items-center gap-1 whitespace-nowrap px-1 py-px font-mono text-[9px] font-bold text-hq-live">
                    {badIcons}
                </span>
            )}
            {isPitch && hasGoodEvent && (
                <span className="absolute -top-1.5 left-8 z-10 flex items-center gap-1 whitespace-nowrap px-1 py-px font-mono text-[9px] font-bold text-hq-lime">
                    {goodIcons}
                </span>
            )}

            {entry.player ? (
                <EntityImage
                    src={entry.player.image}
                    alt={entry.player.nickname}
                    fallback={User}
                    className={cn(AVATAR_SIZE[variant], 'border-[1.5px] border-hq-border-strong bg-hq-border')}
                />
            ) : (
                <div
                    className={cn(
                        AVATAR_SIZE[variant],
                        'flex items-center justify-center rounded-full border-[1.5px] border-dashed border-hq-border-strong font-mono text-hq-moss-dim',
                    )}
                >
                    ?
                </div>
            )}

            {isPitch && entry.player && (
                <HqPositionTag
                    position={entry.player.position}
                    className="absolute -bottom-1 -left-2 z-10 flex h-[15px] min-w-[20px] items-center justify-center border-[1.5px] bg-hq-ink px-[3px] py-0 text-[7.5px]"
                />
            )}

            {isPitch && subMinute !== null && (
                <span
                    className={cn(
                        'absolute -right-2 -bottom-1 z-10 whitespace-nowrap border bg-hq-ink px-1 py-px font-mono text-[8px] font-bold',
                        entry.subbed_out ? 'border-hq-live text-hq-live' : 'border-hq-lime text-hq-lime',
                    )}
                >
                    ↳{subMinute}
                </span>
            )}
        </div>
    );

    const nameLine = (
        <div
            className={cn('truncate font-mono text-hq-paper', isPitch ? 'text-center text-[11px]' : 'text-[12.5px]')}
            title={entry.player ? undefined : `match_data_id: ${entry.match_data_id}`}
        >
            <b className="mr-1 text-hq-lime">{entry.jersey}</b>
            {entry.player?.nickname ?? entry.unresolved_name ?? 'No vinculado'}
        </div>
    );

    const benchMetaLine = !isPitch && entry.player && (
        <div className="mt-0.5 flex items-center gap-1.5">
            <HqPositionTag position={entry.player.position} />
            {subMinute !== null && (
                <span
                    className={cn(
                        'whitespace-nowrap border bg-hq-ink px-1 py-px font-mono text-[8px] font-bold',
                        entry.subbed_out ? 'border-hq-live text-hq-live' : 'border-hq-lime text-hq-lime',
                    )}
                >
                    ↳{subMinute}
                </span>
            )}
        </div>
    );

    const managerLine = entry.lineup_manager && (
        <Link
            href={seasonManagersShow(entry.lineup_manager.id).url}
            onClick={(event) => event.stopPropagation()}
            className={cn(
                'flex items-center gap-1 truncate font-mono text-hq-moss hover:text-hq-paper',
                isPitch ? 'justify-center text-[9px]' : 'text-[10.5px]',
            )}
        >
            <span
                className="h-2 w-2 shrink-0 rounded-[1px]"
                style={{ backgroundColor: managerColor(entry.lineup_manager.primary_color) }}
            />
            <span className="truncate">{entry.lineup_manager.name}</span>
        </Link>
    );

    const statBadges = entry.player && entry.points !== null && (
        <div className={cn('flex items-center gap-1.5', isPitch && 'justify-center')}>
            <span className={cn('rounded-[2px] px-1.5 py-0.5 font-mono text-[11px] font-bold', matchPointsBadgeClass(entry.points))}>
                {entry.points}
            </span>
            {hasPlayed && entry.dazn_points !== null && (
                <span className={cn('flex items-center gap-1 rounded-[2px] bg-hq-border px-1.5 py-0.5 font-mono text-[11px]', daznPointsBadgeClass(entry.dazn_points))}>
                    <img src="/images/dazn-logo.png" alt="DAZN" className="h-3.5 w-3.5" />
                    {entry.dazn_points}
                </span>
            )}
        </div>
    );

    const benchStatBadges = entry.player && entry.points !== null && (
        <div className="flex shrink-0 flex-col items-end gap-0.5">
            <span className={cn('rounded-[2px] px-1.5 py-0.5 font-mono text-[11px] font-bold', matchPointsBadgeClass(entry.points))}>
                {entry.points}
            </span>
            {hasPlayed && entry.dazn_points !== null && (
                <span className={cn('flex items-center gap-1 rounded-[2px] bg-hq-border px-1 py-px font-mono text-[9px]', daznPointsBadgeClass(entry.dazn_points))}>
                    <img src="/images/dazn-logo.png" alt="DAZN" className="h-2.5 w-2.5" />
                    {entry.dazn_points}
                </span>
            )}
        </div>
    );

    // Bench only: good/bad events get their own strip below the name instead
    // of overlaying the photo — the bench row has the horizontal room the
    // tight pitch token doesn't, so there's no need to cram icons onto the avatar.
    const benchEventStrip = !isPitch && (hasGoodEvent || hasBadEvent) && (
        <div className="flex shrink-0 items-center gap-1.5">
            {hasGoodEvent && (
                <span className="flex items-center gap-1 px-1.5 py-px font-mono text-[9px] font-bold text-hq-lime">
                    {goodIcons}
                </span>
            )}
            {hasBadEvent && (
                <span className="flex items-center gap-1 px-1.5 py-px font-mono text-[9px] font-bold text-hq-live">
                    {badIcons}
                </span>
            )}
        </div>
    );

    if (isPitch) {
        return (
            <div
                className={cn('flex w-31 flex-col items-center gap-0.5', clickable && 'cursor-pointer')}
                onClick={handleClick}
            >
                {avatar}
                {nameLine}
                {managerLine}
                {statBadges}
            </div>
        );
    }

    return (
        <div className={cn('flex items-center gap-2.5 px-3 py-1.5', clickable && 'cursor-pointer')} onClick={handleClick}>
            {avatar}
            <div className="min-w-0 flex-1">
                {nameLine}
                {benchMetaLine}
                {managerLine}
            </div>
            {benchEventStrip}
            {benchStatBadges}
        </div>
    );
}
