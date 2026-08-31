import { Home, Plane } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { NextFixtureSlot } from '@/types/models';

interface HqNextFixturesProps {
    fixtures: (NextFixtureSlot | null)[];
    className?: string;
    size?: 'md' | 'sm';
}

const SIZE_CLASSES: Record<'md' | 'sm', string> = {
    md: 'h-8 w-8',
    sm: 'h-[22px] w-[22px]',
};

const VENUE_ICON_SIZE: Record<'md' | 'sm', string> = {
    md: 'h-3.5 w-3.5',
    sm: 'h-2.5 w-2.5',
};

/**
 * The next 3 upcoming (not yet started) fixtures for a player's team, soonest
 * first — a mirror of HqRecentScores, but looking forward: the opponent's
 * crest instead of a points value, with a home/away icon floating at the
 * bottom center instead of the "used" dot.
 */
export function HqNextFixtures({
    fixtures,
    className,
    size = 'md',
}: HqNextFixturesProps) {
    const VenueIcon = (isHome: boolean) => (isHome ? Home : Plane);

    return (
        <div className={cn('flex shrink-0 gap-1', className)}>
            {fixtures.map((slot, index) => {
                const Icon = slot ? VenueIcon(slot.is_home) : null;

                return (
                    <span
                        key={index}
                        title={
                            slot
                                ? `Jornada ${slot.week_number} · vs ${slot.opponent.main_name} · ${slot.is_home ? 'Casa' : 'Fuera'}`
                                : undefined
                        }
                        className={cn(
                            'relative flex shrink-0 items-center justify-center overflow-visible border font-mono font-bold',
                            SIZE_CLASSES[size],
                            slot
                                ? 'border-hq-border-strong bg-hq-border-strong'
                                : 'border-dashed border-hq-border-strong bg-hq-border-strong/40',
                        )}
                    >
                        {slot && Icon ? (
                            <>
                                <img
                                    src={slot.opponent.logo}
                                    alt={slot.opponent.main_name}
                                    className="h-full w-full object-contain p-0.5"
                                />
                                <Icon
                                    className={cn(
                                        'absolute -bottom-2 left-1/2 shrink-0 -translate-x-1/2 text-hq-paper drop-shadow-[0_1px_2px_rgba(0,0,0,0.9)]',
                                        VENUE_ICON_SIZE[size],
                                    )}
                                    strokeWidth={2}
                                />
                            </>
                        ) : (
                            <span className="text-[11px] text-hq-moss-dim">
                                –
                            </span>
                        )}
                    </span>
                );
            })}
        </div>
    );
}
