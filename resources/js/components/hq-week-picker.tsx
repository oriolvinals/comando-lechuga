import { ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

interface HqWeekPickerProps {
    week: number;
    maxWeek: number;
    playedThroughWeek: number;
    onChange: (week: number) => void;
}

export function HqWeekPicker({
    week,
    maxWeek,
    playedThroughWeek,
    onChange,
}: HqWeekPickerProps) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const handlePointerDown = (event: MouseEvent) => {
            if (
                containerRef.current &&
                !containerRef.current.contains(event.target as Node)
            ) {
                setOpen(false);
            }
        };
        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        window.addEventListener('mousedown', handlePointerDown);
        window.addEventListener('keydown', handleKeyDown);

        return () => {
            window.removeEventListener('mousedown', handlePointerDown);
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [open]);

    return (
        <div ref={containerRef} className="relative inline-block">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                className="flex items-center gap-2 border border-hq-lime bg-hq-lime px-3.5 py-2 font-mono text-xs font-bold text-hq-ink"
            >
                JORNADA {String(week).padStart(2, '0')}
                <ChevronDown
                    className={cn(
                        'h-3.5 w-3.5 transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </button>

            {open && (
                <div className="absolute left-0 z-20 mt-1.5 grid w-[min(90vw,22rem)] grid-cols-6 gap-1.5 border border-hq-border bg-hq-panel p-3 shadow-xl sm:grid-cols-8">
                    {Array.from(
                        { length: maxWeek },
                        (_, index) => index + 1,
                    ).map((weekNumber) => (
                        <button
                            key={weekNumber}
                            type="button"
                            onClick={() => {
                                onChange(weekNumber);
                                setOpen(false);
                            }}
                            className={cn(
                                'py-1.5 text-center font-mono text-[11px] font-bold',
                                weekNumber === week
                                    ? 'bg-hq-lime text-hq-ink'
                                    : weekNumber <= playedThroughWeek
                                      ? 'text-hq-lime hover:bg-hq-panel-alt'
                                      : 'text-hq-moss hover:bg-hq-panel-alt',
                            )}
                        >
                            {weekNumber}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
