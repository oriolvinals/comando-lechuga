import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';

interface WeekSelectorProps {
    week: number;
    maxWeek: number;
    onChange: (week: number) => void;
    label: string;
}

export function WeekSelector({
    week,
    maxWeek,
    onChange,
    label,
}: WeekSelectorProps) {
    const activeRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        activeRef.current?.scrollIntoView({
            block: 'nearest',
            inline: 'center',
            behavior: 'smooth',
        });
    }, [week]);

    return (
        <div>
            <h2 className="text-lg font-semibold">{label}</h2>
            <div className="mt-3 flex gap-1.5 overflow-x-auto pb-1">
                {Array.from({ length: maxWeek }, (_, index) => index + 1).map(
                    (weekNumber) => (
                        <button
                            key={weekNumber}
                            ref={weekNumber === week ? activeRef : undefined}
                            type="button"
                            onClick={() => onChange(weekNumber)}
                            aria-current={
                                weekNumber === week ? 'true' : undefined
                            }
                            className={cn(
                                'shrink-0 rounded-full px-3 py-1 text-sm font-medium transition-colors',
                                weekNumber === week
                                    ? 'bg-neutral-900 text-white'
                                    : 'text-neutral-600 hover:bg-neutral-100',
                            )}
                        >
                            J{weekNumber}
                        </button>
                    ),
                )}
            </div>
        </div>
    );
}
