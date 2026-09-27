import { Home, Plane } from 'lucide-react';
import { HqTooltip } from '@/components/hq-tooltip';
import {
    RIVAL_DIFFICULTY_BG_CLASSES,
    RIVAL_DIFFICULTY_LABELS,
    rivalDifficultyBars,
    rivalDifficultyLevel,
} from '@/lib/rival-difficulty';
import { cn } from '@/lib/utils';
import type { NextFixtureSlot } from '@/types/models';

type HqNextFixturesSize = 'md' | 'sm';

interface HqNextFixturesProps {
    fixtures: (NextFixtureSlot | null)[];
    className?: string;
    size?: HqNextFixturesSize;
    /** Off inside a row whose tooltips shouldn't add tab stops. */
    focusable?: boolean;
}

const SLOT_WIDTH: Record<HqNextFixturesSize, string> = {
    md: 'w-[34px]',
    sm: 'w-[26px]',
};

const BOX_SIZE: Record<HqNextFixturesSize, string> = {
    md: 'h-8 w-[34px]',
    sm: 'h-6 w-[26px]',
};

const CREST_SIZE: Record<HqNextFixturesSize, string> = {
    md: 'h-6 w-6',
    sm: 'h-[18px] w-[18px]',
};

/**
 * The next 3 upcoming (not yet started) fixtures for a player's team, soonest
 * first — a mirror of HqRecentScores looking forward: the rival's crest in a
 * ruled box, a home/away glyph on its bottom edge, and a 3px rule under it
 * coloured by how hard the rival is (red = top of the table, amber = mid,
 * lime = bottom). The tooltip spells out jornada, rival, venue, the rival's
 * position and the difficulty score.
 */
export function HqNextFixtures({
    fixtures,
    className,
    size = 'md',
    focusable = true,
}: HqNextFixturesProps) {
    return (
        <div className={cn('flex shrink-0 gap-1', className)}>
            {fixtures.map((slot, index) => {
                if (!slot) {
                    return (
                        <span
                            key={index}
                            className={cn(
                                'flex shrink-0 flex-col items-center gap-0.5',
                                SLOT_WIDTH[size],
                            )}
                        >
                            <span
                                aria-label="Sin partido"
                                className={cn(
                                    'flex items-center justify-center border border-dashed border-hq-border-strong font-mono text-[11px] text-hq-moss-dim',
                                    BOX_SIZE[size],
                                )}
                            >
                                –
                            </span>
                        </span>
                    );
                }

                const level = rivalDifficultyLevel(slot.difficulty);
                const bars = rivalDifficultyBars(slot.difficulty);
                const VenueIcon = slot.is_home ? Home : Plane;
                const venue = slot.is_home ? 'Casa' : 'Fuera';

                return (
                    <HqTooltip
                        key={index}
                        focusable={focusable}
                        className={cn(
                            'shrink-0 flex-col items-center',
                            SLOT_WIDTH[size],
                        )}
                        label={
                            <>
                                <b className="font-bold">
                                    J{slot.week_number} · vs{' '}
                                    {slot.opponent.main_name}
                                </b>
                                <br />
                                {venue}
                                <br />
                                <span className="text-hq-moss-dim">
                                    Rival
                                </span>{' '}
                                {slot.rival_position}º · dificultad{' '}
                                {slot.difficulty.toFixed(2).replace('.', ',')} (
                                {RIVAL_DIFFICULTY_LABELS[level]})
                            </>
                        }
                    >
                        <span
                            className={cn(
                                'relative flex items-center justify-center border border-hq-border-strong bg-hq-panel-alt',
                                BOX_SIZE[size],
                            )}
                        >
                            <img
                                src={slot.opponent.logo}
                                alt={`vs ${slot.opponent.main_name} (${venue})`}
                                className={cn(
                                    'object-contain',
                                    CREST_SIZE[size],
                                )}
                            />
                            <span className="absolute -bottom-[5px] left-1/2 flex h-[11px] w-[11px] -translate-x-1/2 items-center justify-center bg-hq-ink text-hq-moss">
                                <VenueIcon
                                    aria-hidden="true"
                                    className="h-[9px] w-[9px]"
                                    strokeWidth={2.2}
                                />
                            </span>
                        </span>
                        <span
                            aria-hidden="true"
                            className="mt-[5px] flex h-[3px] w-full gap-px"
                        >
                            {Array.from({ length: 5 }, (_, segment) => (
                                <span
                                    key={segment}
                                    className={cn(
                                        'flex-1',
                                        segment < bars
                                            ? RIVAL_DIFFICULTY_BG_CLASSES[level]
                                            : 'bg-hq-border',
                                    )}
                                />
                            ))}
                        </span>
                    </HqTooltip>
                );
            })}
        </div>
    );
}
