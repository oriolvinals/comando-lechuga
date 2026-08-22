interface WeekSelectorProps {
    week: number;
    totalWeeks: number;
    onChange: (week: number) => void;
    label: string;
}

export function WeekSelector({
    week,
    totalWeeks,
    onChange,
    label,
}: WeekSelectorProps) {
    return (
        <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold">{label}</h2>
            <div className="flex gap-2">
                <button
                    type="button"
                    onClick={() => onChange(week - 1)}
                    disabled={week <= 1}
                    className="rounded-md px-3 py-1 text-sm text-neutral-600 hover:bg-neutral-100 disabled:opacity-40"
                >
                    Anterior
                </button>
                <button
                    type="button"
                    onClick={() => onChange(week + 1)}
                    disabled={week >= totalWeeks}
                    className="rounded-md px-3 py-1 text-sm text-neutral-600 hover:bg-neutral-100 disabled:opacity-40"
                >
                    Siguiente
                </button>
            </div>
        </div>
    );
}
