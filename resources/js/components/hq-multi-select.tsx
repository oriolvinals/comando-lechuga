import { ChevronDown } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export interface HqMultiSelectOption {
    value: string;
    label: string;
}

interface HqMultiSelectProps {
    label: string;
    options: HqMultiSelectOption[];
    selected: string[];
    onChange: (next: string[]) => void;
    className?: string;
}

/**
 * Filter-bar multi-select: an uppercase mono trigger ("POSICIÓN Todos ▾",
 * lime once something is picked) opening a checkbox list with "Limpiar".
 * 44px tall on touch layouts, 34px from `sm` up.
 */
export function HqMultiSelect({
    label,
    options,
    selected,
    onChange,
    className,
}: HqMultiSelectProps) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const listId = useId();

    useEffect(() => {
        if (!open) {
            return;
        }

        const handlePointerDown = (event: PointerEvent) => {
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

        window.addEventListener('pointerdown', handlePointerDown);
        window.addEventListener('keydown', handleKeyDown);

        return () => {
            window.removeEventListener('pointerdown', handlePointerDown);
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [open]);

    const toggleValue = (value: string) => {
        onChange(
            selected.includes(value)
                ? selected.filter((item) => item !== value)
                : [...selected, value],
        );
    };

    const hasSelection = selected.length > 0;
    const summary =
        selected.length === 0
            ? 'Todos'
            : selected.length === 1
              ? (options.find((option) => option.value === selected[0])
                    ?.label ?? '1')
              : `${selected.length} sel.`;

    return (
        <div ref={containerRef} className={cn('relative', className)}>
            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                aria-expanded={open}
                aria-controls={open ? listId : undefined}
                className={cn(
                    'flex h-11 w-full cursor-pointer items-center gap-2 border bg-hq-ink px-2.5 font-mono text-[11.5px] leading-none font-bold tracking-[0.06em] uppercase transition-colors sm:h-[34px] sm:w-auto',
                    hasSelection
                        ? 'border-hq-lime text-hq-lime'
                        : 'border-hq-border-strong text-hq-moss hover:border-hq-border-bright hover:text-hq-paper',
                )}
            >
                {label}
                <span
                    className={cn(
                        'min-w-0 truncate font-medium tracking-normal normal-case',
                        hasSelection ? 'text-hq-lime' : 'text-hq-moss-dim',
                    )}
                >
                    {summary}
                </span>
                <ChevronDown
                    aria-hidden="true"
                    className={cn(
                        'ml-auto size-[13px] shrink-0 transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </button>

            {open && (
                <div
                    id={listId}
                    className="absolute top-[calc(100%+4px)] left-0 z-30 max-h-[300px] w-max max-w-[min(320px,calc(100vw-32px))] min-w-[210px] overflow-auto border border-hq-border-bright bg-hq-panel p-1 shadow-[0_12px_30px_rgba(0,0,0,0.5)] sm:min-w-[230px]"
                >
                    {options.map((option) => {
                        const isSelected = selected.includes(option.value);

                        return (
                            <label
                                key={option.value}
                                className={cn(
                                    'flex min-h-11 cursor-pointer items-center gap-[9px] px-[9px] py-[7px] font-mono text-[12.5px] leading-tight hover:bg-hq-panel-alt sm:min-h-0',
                                    isSelected
                                        ? 'text-hq-lime'
                                        : 'text-hq-moss',
                                )}
                            >
                                <input
                                    type="checkbox"
                                    checked={isSelected}
                                    onChange={() => toggleValue(option.value)}
                                    className="accent-hq-lime"
                                />
                                {option.label}
                            </label>
                        );
                    })}
                    {hasSelection && (
                        <button
                            type="button"
                            onClick={() => onChange([])}
                            className="mt-1 block min-h-11 w-full cursor-pointer border-t border-hq-border px-[9px] py-2 text-left font-mono text-[11px] leading-none font-semibold tracking-[0.06em] text-hq-moss-dim uppercase hover:text-hq-paper sm:min-h-0"
                        >
                            Limpiar
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
