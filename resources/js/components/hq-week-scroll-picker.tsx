import { usePage } from '@inertiajs/react';
import { useLayoutEffect, useRef } from 'react';
import { HqScrollRow } from '@/components/hq-scroll-row';
import { cn } from '@/lib/utils';
import type { WeekProgressMap } from '@/types/models';

interface HqWeekScrollPickerProps {
    week: number;
    maxWeek: number;
    playedThroughWeek: number;
    weekProgress: WeekProgressMap;
    onChange: (week: number) => void;
}

/**
 * Horizontal jornada picker for the home page. Color reflects how far along
 * that jornada actually is (none/partial/all fixtures finished), and the
 * live jornada (if any) pulses red, like the navbar's live indicator.
 */
export function HqWeekScrollPicker({
    week,
    maxWeek,
    playedThroughWeek,
    weekProgress,
    onChange,
}: HqWeekScrollPickerProps) {
    const { liveMatchday } = usePage().props;
    const selectedRef = useRef<HTMLButtonElement>(null);
    const isFirstRender = useRef(true);

    useLayoutEffect(() => {
        // First mount jumps to the selected week instantly — otherwise it
        // visibly animates from the start on every page load. A later change
        // (the picker stays mounted across `preserveState` navigations) scrolls
        // smoothly instead, since that's a deliberate interaction.
        selectedRef.current?.scrollIntoView({
            block: 'nearest',
            inline: 'center',
            behavior: isFirstRender.current ? 'instant' : 'smooth',
        });
        isFirstRender.current = false;
    }, [week]);

    return (
        <HqScrollRow contentClassName="px-1 py-1" showProgress={false}>
            {Array.from({ length: maxWeek }, (_, index) => index + 1).map(
                (weekNumber) => {
                    const isLive =
                        liveMatchday && weekNumber === playedThroughWeek;
                    const progress = weekProgress[String(weekNumber)] ?? 'none';

                    return (
                        <button
                            key={weekNumber}
                            ref={weekNumber === week ? selectedRef : undefined}
                            type="button"
                            onClick={() => onChange(weekNumber)}
                            className={cn(
                                'relative flex h-14 w-14 shrink-0 cursor-pointer flex-col items-center justify-center border font-mono',
                                isLive
                                    ? 'border-hq-live'
                                    : weekNumber === week
                                      ? 'border-hq-paper'
                                      : 'border-hq-border hover:border-hq-border-strong',
                                progress === 'all'
                                    ? 'bg-hq-lime/15 text-hq-lime'
                                    : progress === 'partial'
                                      ? 'bg-hq-gold/15 text-hq-gold'
                                      : 'text-hq-paper/30',
                            )}
                        >
                            <span className="text-[10px] font-bold opacity-80">
                                J
                            </span>
                            <span className="font-display text-lg leading-none">
                                {weekNumber}
                            </span>
                            {isLive && (
                                <span className="absolute -top-1 -right-1 h-2 w-2 animate-pulse rounded-full bg-hq-live" />
                            )}
                        </button>
                    );
                },
            )}
        </HqScrollRow>
    );
}
