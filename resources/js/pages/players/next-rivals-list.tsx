import { Link } from '@inertiajs/react';
import { Home, Plane, Shield, UserX } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
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
import { show as teamsShow } from '@/routes/teams';
import type { NextFixtureSlot } from '@/types/models';

/**
 * The club's next fixtures as a ruled list (mock `.nrs`): jornada, rival
 * crest and name, Casa/Fuera (+ "Bajas del rival" when its
 * absences eased the match), and on the right the rival's table position,
 * the 5-segment 0–10 difficulty gauge with its number under it, and its level.
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

                const VenueIcon = slot.is_home ? Home : Plane;

                return (
                    <Link
                        key={index}
                        href={teamsShow(slot.opponent.id).url}
                        className="flex min-h-11 cursor-pointer items-center gap-2.5 border-b border-hq-border px-3.5 py-2.5 transition-colors hover:bg-hq-panel sm:px-4"
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
                                {slot.opponent.main_name}
                            </span>
                            <span className="mt-1 flex items-center gap-[5px] font-mono text-[11px] leading-none text-hq-moss-dim">
                                <VenueIcon
                                    aria-hidden="true"
                                    className="size-[11px] shrink-0"
                                />
                                {slot.is_home ? 'Casa' : 'Fuera'}
                                {slot.absence_adjusted === true && (
                                    <>
                                        <UserX
                                            aria-hidden="true"
                                            className="ml-1 size-[11px] shrink-0 text-hq-moss"
                                        />
                                        <span className="truncate text-hq-moss">
                                            Bajas del rival
                                        </span>
                                    </>
                                )}
                            </span>
                        </span>
                        <HqDifficultyTooltip
                            match={slot}
                            focusable={false}
                            className="flex shrink-0 flex-col items-end gap-1"
                        >
                            {slot.rival_position !== null && (
                                <span className="font-mono text-[11px] leading-none text-hq-moss">
                                    {slot.rival_position}.º
                                </span>
                            )}
                            {slot.difficulty === null ? (
                                <span className="font-mono text-[11px] leading-none text-hq-moss-dim">
                                    –
                                </span>
                            ) : (
                                <>
                                    <HqDifficultyBars
                                        difficulty={slot.difficulty}
                                        layout="gauge"
                                    />
                                    <span
                                        className={cn(
                                            'font-mono text-[10px] leading-none font-bold tracking-[0.07em] uppercase',
                                            RIVAL_DIFFICULTY_TEXT_CLASSES[
                                                rivalDifficultyLevel(
                                                    slot.difficulty,
                                                )
                                            ],
                                        )}
                                    >
                                        <span className="sr-only">
                                            Dificultad{' '}
                                            {formatDifficulty(slot.difficulty)}{' '}
                                            sobre 10,{' '}
                                        </span>
                                        {
                                            RIVAL_DIFFICULTY_LABELS[
                                                rivalDifficultyLevel(
                                                    slot.difficulty,
                                                )
                                            ]
                                        }
                                    </span>
                                </>
                            )}
                        </HqDifficultyTooltip>
                    </Link>
                );
            })}
        </div>
    );
}
