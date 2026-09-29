import { Home, Plane, UserX } from 'lucide-react';
import {
    HqDifficultyBars,
    HqDifficultyTooltip,
} from '@/components/hq-difficulty-bars';
import {
    RIVAL_DIFFICULTY_LABELS,
    RIVAL_DIFFICULTY_TEXT_CLASSES,
    formatDifficulty,
    rivalDifficultyLevel,
} from '@/lib/rival-difficulty';
import { cn } from '@/lib/utils';
import type { NextFixtureSlot } from '@/types/models';

/**
 * - `sm` / `md`: the thin 3px rule with the number under it (player rows).
 * - `lg`: the ficha-list format — rival position, the 5-segment gauge with
 *   the number under it and the fácil/media/difícil label (team ficha).
 */
type HqNextFixturesSize = 'lg' | 'md' | 'sm';

interface HqNextFixturesProps {
    fixtures: (NextFixtureSlot | null)[];
    className?: string;
    size?: HqNextFixturesSize;
    /** Off inside a row whose tooltips shouldn't add tab stops. */
    focusable?: boolean;
}

const SLOT_WIDTH: Record<HqNextFixturesSize, string> = {
    lg: 'w-[52px]',
    md: 'w-[34px]',
    sm: 'w-[26px]',
};

const BOX_SIZE: Record<HqNextFixturesSize, string> = {
    lg: 'h-9 w-[44px]',
    md: 'h-8 w-[34px]',
    sm: 'h-6 w-[26px]',
};

const CREST_SIZE: Record<HqNextFixturesSize, string> = {
    lg: 'h-7 w-7',
    md: 'h-6 w-6',
    sm: 'h-[18px] w-[18px]',
};

/** The difficulty under the crest box in the `lg` size: rival position, gauge + number, level label. */
function LargeDifficulty({ slot }: { slot: NextFixtureSlot }) {
    return (
        <span className="mt-2 flex flex-col items-center font-mono leading-none">
            <span className="text-[10.5px] text-hq-moss">
                {slot.rival_position === null
                    ? '–'
                    : `${slot.rival_position}.º`}
            </span>
            {slot.difficulty === null ? (
                <span className="mt-1.5 text-[11px] text-hq-moss-dim">–</span>
            ) : (
                <>
                    <HqDifficultyBars
                        difficulty={slot.difficulty}
                        layout="gauge"
                        className="mt-1.5"
                    />
                    <span
                        className={cn(
                            'mt-1 text-[9.5px] font-bold tracking-[0.06em] uppercase',
                            RIVAL_DIFFICULTY_TEXT_CLASSES[
                                rivalDifficultyLevel(slot.difficulty)
                            ],
                        )}
                    >
                        <span className="sr-only">
                            Dificultad {formatDifficulty(slot.difficulty)}{' '}
                            sobre 10,{' '}
                        </span>
                        {
                            RIVAL_DIFFICULTY_LABELS[
                                rivalDifficultyLevel(slot.difficulty)
                            ]
                        }
                    </span>
                </>
            )}
        </span>
    );
}

/**
 * The next 3 upcoming (not yet started) fixtures for a player's team, soonest
 * first — a mirror of HqRecentScores looking forward: the rival's crest in a
 * ruled box, a home/away glyph on its bottom edge, a crossed-out person when
 * the rival's absences eased the match, and under it the 5-bar 0–10
 * difficulty gauge with its number under the bars (more bars = harder; lime
 * easy, amber mid, red hard). The tooltip spells out jornada, rival,
 * difficulty, the rival's position and venue.
 */
export function HqNextFixtures({
    fixtures,
    className,
    size = 'md',
    focusable = true,
}: HqNextFixturesProps) {
    return (
        <div
            className={cn(
                'flex shrink-0',
                size === 'lg' ? 'gap-2.5' : 'gap-1',
                className,
            )}
        >
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

                const VenueIcon = slot.is_home ? Home : Plane;
                const venue = slot.is_home ? 'Casa' : 'Fuera';

                return (
                    <HqDifficultyTooltip
                        key={index}
                        match={slot}
                        focusable={focusable}
                        className={cn(
                            'shrink-0 flex-col items-center',
                            SLOT_WIDTH[size],
                        )}
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
                            {slot.absence_adjusted === true && (
                                <UserX
                                    role="img"
                                    aria-label="Bajas del rival"
                                    className={cn(
                                        'absolute top-px right-px text-hq-moss',
                                        size === 'lg' ? 'size-2.5' : 'size-2',
                                    )}
                                    strokeWidth={2.4}
                                />
                            )}
                            <span className="absolute -bottom-[5px] left-1/2 flex h-[11px] w-[11px] -translate-x-1/2 items-center justify-center bg-hq-ink text-hq-moss">
                                <VenueIcon
                                    aria-hidden="true"
                                    className="h-[9px] w-[9px]"
                                    strokeWidth={2.2}
                                />
                            </span>
                        </span>
                        {size === 'lg' ? (
                            <LargeDifficulty slot={slot} />
                        ) : slot.difficulty === null ? (
                            <span
                                aria-hidden="true"
                                className="mt-[5px] h-[3px] w-full bg-hq-border"
                            />
                        ) : (
                            <HqDifficultyBars
                                difficulty={slot.difficulty}
                                layout="stack"
                                className="mt-[5px]"
                            />
                        )}
                    </HqDifficultyTooltip>
                );
            })}
        </div>
    );
}
