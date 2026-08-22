import { ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export interface MultiSelectOption {
    value: string;
    label: string;
}

interface MultiSelectProps {
    label: string;
    options: MultiSelectOption[];
    selected: string[];
    onChange: (next: string[]) => void;
}

export function MultiSelect({
    label,
    options,
    selected,
    onChange,
}: MultiSelectProps) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        function handleClickOutside(event: MouseEvent) {
            if (
                containerRef.current &&
                !containerRef.current.contains(event.target as Node)
            ) {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', handleClickOutside);

        return () =>
            document.removeEventListener('mousedown', handleClickOutside);
    }, [open]);

    const toggleValue = (value: string) => {
        onChange(
            selected.includes(value)
                ? selected.filter((item) => item !== value)
                : [...selected, value],
        );
    };

    const summary =
        selected.length === 0
            ? 'Todos'
            : selected.length === 1
              ? (options.find((option) => option.value === selected[0])
                    ?.label ?? '1 seleccionado')
              : `${selected.length} seleccionados`;

    return (
        <div ref={containerRef} className="relative inline-block text-left">
            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                aria-expanded={open}
                className="flex items-center gap-1.5 rounded-md border border-neutral-300 px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50"
            >
                <span className="text-neutral-500">{label}:</span>
                <span className="font-medium">{summary}</span>
                <ChevronDown className="h-3.5 w-3.5 text-neutral-400" />
            </button>

            {open && (
                <div className="absolute z-10 mt-1 max-h-64 w-56 overflow-auto rounded-md border border-neutral-200 bg-white py-1 shadow-lg">
                    {options.map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                'flex cursor-pointer items-center gap-2 px-3 py-1.5 text-sm hover:bg-neutral-50',
                                selected.includes(option.value) &&
                                    'bg-neutral-50',
                            )}
                        >
                            <input
                                type="checkbox"
                                checked={selected.includes(option.value)}
                                onChange={() => toggleValue(option.value)}
                                className="rounded border-neutral-300"
                            />
                            {option.label}
                        </label>
                    ))}
                </div>
            )}
        </div>
    );
}
