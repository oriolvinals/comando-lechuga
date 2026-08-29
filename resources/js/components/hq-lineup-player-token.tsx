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

export function HqLineupPlayerToken({ entry, variant, onSelect }: HqLineupPlayerTokenProps) {
    const isPitch = variant === 'pitch';
    const clickable = entry.player !== null && onSelect !== undefined;
    const handleClick = clickable ? () => onSelect(entry) : undefined;
    const hasGoodEvent = entry.goals > 0 || entry.assists > 0;
    const hasBadEvent = entry.yellow_cards > 0 || entry.red_cards > 0;
    const hasPlayed = entry.starter || entry.subbed_in;
    const subMinute = entry.subbed_in || entry.subbed_out ? entry.sub_minute : null;

    // Icon content only — positioning/background differ between the pitch
    // (pegged to the avatar corner) and the bench (its own strip below the
    // name, since the bench row has room to spare and the pitch token doesn't).
    const goodIcons = (
        <>
            {Array.from({ length: entry.goals }, (_, i) => (
                <span key={`g-${i}`}>⚽</span>
            ))}
            {Array.from({ length: entry.assists }, (_, i) => (
                <span key={`a-${i}`}>➜</span>
            ))}
        </>
    );
    const badIcons = (
        <>
            {Array.from({ length: entry.yellow_cards }, (_, i) => (
                <span key={`y-${i}`} className="inline-block h-2.5 w-[7px] rounded-[1px] bg-hq-gold" />
            ))}
            {Array.from({ length: entry.red_cards }, (_, i) => (
                <span key={`r-${i}`} className="inline-block h-2.5 w-[7px] rounded-[1px] bg-hq-live" />
            ))}
        </>
    );

    const avatar = (
        <div className={cn('relative shrink-0', AVATAR_SIZE[variant])}>
            {isPitch && hasBadEvent && (
                <span className="absolute -top-1.5 right-8 z-10 flex items-center gap-0.5 whitespace-nowrap border border-hq-live bg-hq-bad-corner px-1 py-px font-mono text-[9px] font-bold text-hq-live">
                    {badIcons}
                </span>
            )}
            {isPitch && hasGoodEvent && (
                <span className="absolute -top-1.5 left-8 z-10 flex items-center gap-0.5 whitespace-nowrap border border-hq-lime bg-hq-good-corner px-1 py-px font-mono text-[9px] font-bold text-hq-lime">
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

            {entry.player && (
                <HqPositionTag
                    position={entry.player.position}
                    className={cn(
                        'absolute -bottom-1 -left-2 z-10 bg-hq-ink',
                        isPitch && 'flex h-[15px] min-w-[20px] items-center justify-center border-[1.5px] px-[3px] py-0 text-[7.5px]',
                    )}
                />
            )}

            {subMinute !== null && (
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
        <div className={cn('truncate font-mono text-hq-paper', isPitch ? 'text-center text-[11px]' : 'text-[12.5px]')}>
            <b className="mr-1 text-hq-lime">{entry.jersey}</b>
            {entry.player?.nickname ?? 'No vinculado'}
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

    // Bench only: good/bad events get their own strip below the name instead
    // of overlaying the photo — the bench row has the horizontal room the
    // tight pitch token doesn't, so there's no need to cram icons onto the avatar.
    const benchEventStrip = !isPitch && (hasGoodEvent || hasBadEvent) && (
        <div className="mt-0.5 flex items-center gap-1.5">
            {hasGoodEvent && (
                <span className="flex items-center gap-0.5 border border-hq-lime bg-hq-good-corner px-1.5 py-px font-mono text-[9px] font-bold text-hq-lime">
                    {goodIcons}
                </span>
            )}
            {hasBadEvent && (
                <span className="flex items-center gap-0.5 border border-hq-live bg-hq-bad-corner px-1.5 py-px font-mono text-[9px] font-bold text-hq-live">
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
                {managerLine}
                {!entry.player && <div className="font-mono text-[9.5px] text-hq-moss-dim">no vinculado</div>}
                {benchEventStrip}
                {statBadges}
            </div>
        </div>
    );
}
