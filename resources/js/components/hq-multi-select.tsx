import { ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
}

export function HqMultiSelect({
    label,
    options,
    selected,
    onChange,
}: HqMultiSelectProps) {
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
        <div ref={containerRef} className="relative inline-block">
            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                aria-expanded={open}
                className={cn(
                    'flex items-center gap-1.5 border px-3 py-2 font-mono text-[11px] font-bold tracking-wide uppercase transition-colors',
                    hasSelection
                        ? 'border-hq-lime text-hq-lime'
                        : 'border-hq-border bg-hq-panel text-hq-moss hover:border-hq-border-strong',
                )}
            >
                {label}
                <span
                    className={cn(
                        'normal-case',
                        hasSelection ? 'text-hq-lime' : 'text-hq-moss-dim',
                    )}
                >
                    {summary}
                </span>
                <ChevronDown
                    className={cn(
                        'h-3.5 w-3.5 transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </button>

            {open && (
                <div className="absolute z-20 mt-1.5 max-h-64 w-56 overflow-auto border border-hq-border bg-hq-panel p-1 shadow-xl">
                    {options.map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                'flex cursor-pointer items-center gap-2 px-2.5 py-1.5 font-mono text-xs hover:bg-hq-panel-alt',
                                selected.includes(option.value)
                                    ? 'text-hq-lime'
                                    : 'text-hq-moss',
                            )}
                        >
                            <input
                                type="checkbox"
                                checked={selected.includes(option.value)}
                                onChange={() => toggleValue(option.value)}
                                className="accent-hq-lime"
                            />
                            {option.label}
                        </label>
                    ))}
                </div>
            )}
        </div>
    );
}
