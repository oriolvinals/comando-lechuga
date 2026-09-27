import { Link } from '@inertiajs/react';
import { Home, Plane, Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import {
    RIVAL_DIFFICULTY_BG_CLASSES,
    RIVAL_DIFFICULTY_LABELS,
    rivalDifficultyBars,
    rivalDifficultyLevel,
} from '@/lib/rival-difficulty';
import type { RivalDifficultyLevel } from '@/lib/rival-difficulty';
import { cn } from '@/lib/utils';
import { show as teamsShow } from '@/routes/teams';
import type { NextFixtureSlot } from '@/types/models';

const LEVEL_TEXT_CLASSES: Record<RivalDifficultyLevel, string> = {
    hard: 'text-hq-live',
    mid: 'text-hq-amber',
    easy: 'text-hq-lime',
};

/**
 * The club's next fixtures as a ruled list (mock `.nrs`): jornada, rival
 * crest and name (@ when away), Casa/Fuera, and on the right the rival's
 * table position, a 5-segment difficulty gauge and its level.
 */
export function NextRivalsList({
    fixtures,
}: {
    fixtures: (NextFixtureSlot | null)[];
}) {
    return (
        <div>
            {fixtures.map((slot, index) => {
                if (slot === null) {
                    return (
                        <div
                            key={index}
                            className="border-b border-hq-border px-3.5 py-3 font-mono text-xs text-hq-moss-dim sm:px-4"
                        >
                            Sin partido
                        </div>
                    );
                }

                const level = rivalDifficultyLevel(slot.difficulty);
                const bars = rivalDifficultyBars(slot.difficulty);
                const VenueIcon = slot.is_home ? Home : Plane;

                return (
                    <Link
                        key={index}
                        href={teamsShow(slot.opponent.id).url}
                        className="flex min-h-11 items-center gap-2.5 border-b border-hq-border px-3.5 py-2.5 transition-colors hover:bg-hq-panel sm:px-4"
                    >
                        <span className="min-w-[26px] font-mono text-xs leading-none font-bold text-hq-moss">
                            J{slot.week_number}
                        </span>
                        <EntityImage
                            src={slot.opponent.logo}
                            alt=""
                            fallback={Shield}
                            shape="square"
                            className="size-[26px] shrink-0 rounded-none bg-transparent"
                        />
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm leading-[1.15] font-extrabold text-hq-paper">
                                {slot.is_home ? '' : '@ '}
                                {slot.opponent.main_name}
                            </span>
                            <span className="mt-1 flex items-center gap-[5px] font-mono text-[11px] leading-none text-hq-moss-dim">
                                <VenueIcon
                                    aria-hidden="true"
                                    className="size-[11px] shrink-0"
                                />
                                {slot.is_home ? 'Casa' : 'Fuera'}
                            </span>
                        </span>
                        <span
                            className="flex shrink-0 flex-col items-end gap-1"
                            title={`Dificultad ${slot.difficulty.toFixed(2).replace('.', ',')}`}
                        >
                            <span className="font-mono text-[11px] leading-none text-hq-moss">
                                {slot.rival_position}º
                            </span>
                            <span
                                aria-hidden="true"
                                className="grid grid-cols-[repeat(5,7px)] gap-0.5"
                            >
                                {Array.from({ length: 5 }, (_, segment) => (
                                    <i
                                        key={segment}
                                        className={cn(
                                            'block h-[9px]',
                                            segment < bars
                                                ? RIVAL_DIFFICULTY_BG_CLASSES[
                                                      level
                                                  ]
                                                : 'bg-hq-border',
                                        )}
                                    />
                                ))}
                            </span>
                            <span
                                className={cn(
                                    'font-mono text-[10px] leading-none font-bold tracking-[0.07em] uppercase',
                                    LEVEL_TEXT_CLASSES[level],
                                )}
                            >
                                {RIVAL_DIFFICULTY_LABELS[level]}
                            </span>
                        </span>
                    </Link>
                );
            })}
        </div>
    );
}
