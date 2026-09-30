import type { ReactNode } from 'react';
import { COMPARE_SLOT_COLORS } from '@/lib/compare-selection';
import { cn } from '@/lib/utils';

/**
 * The page's column grid: a label column and one column per slot (`--cols`,
 * set on the page root). The player strip, the veredicto and every body row
 * share it, so each player's figures sit under his card. On phones the label
 * spans the row and only the slot columns remain.
 */
export const COMPARE_GRID =
    'grid grid-cols-[repeat(var(--cols),minmax(0,1fr))] sm:grid-cols-[minmax(128px,180px)_repeat(var(--cols),minmax(0,1fr))]';

/** Spans every slot column (the chart, the league tracks, the legends). */
export const COMPARE_SPAN = 'col-span-full sm:col-[2/-1]';

export const BIG_NUMBER =
    'font-mono text-[15px] font-bold text-hq-paper tabular-nums max-sm:text-[13.5px]';
export const SMALL_NOTE = 'font-mono text-[11px] text-hq-moss-dim';

/** A row's name and hint in the label column; on phones a line above the cells. */
export function RowLabel({
    label,
    hint,
    children,
    className,
}: {
    label: string;
    hint?: string;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'col-span-full flex flex-wrap items-baseline gap-x-2 gap-y-[3px] px-3.5 pt-3 sm:col-span-1 sm:flex-col sm:flex-nowrap sm:px-4 sm:py-3.5',
                className,
            )}
        >
            <b className="text-xs font-extrabold text-hq-paper uppercase">
                {label}
            </b>
            {hint && <small className={SMALL_NOTE}>{hint}</small>}
            {children}
        </div>
    );
}

/** A section's title bar, with an optional right side (tally, toggle). */
export function CompareSectionHeader({
    id,
    title,
    children,
}: {
    id: string;
    title: string;
    children?: ReactNode;
}) {
    return (
        <div
            id={id}
            className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 border-b border-hq-border-strong bg-linear-to-r from-hq-panel to-transparent to-70% px-3.5 py-2.5 sm:px-4"
        >
            <h2 id={`${id}-title`} className="hq-label text-hq-paper">
                {title}
            </h2>
            {children}
        </div>
    );
}

/** The player's name in his slot colour, where the columns no longer say whose a cell is (stacked rows on phones). */
export function SlotName({
    slot,
    name,
    className,
}: {
    slot: number;
    name: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'flex items-center gap-1.5 text-[11px] leading-none font-black text-hq-moss uppercase',
                className,
            )}
        >
            <i
                aria-hidden="true"
                className="size-2 shrink-0"
                style={{ background: COMPARE_SLOT_COLORS[slot] }}
            />
            {name}
        </span>
    );
}

/** A segmented toggle (`aria-pressed` buttons in a group), lime when on. */
export function CompareToggleGroup<T extends string>({
    label,
    options,
    value,
    onChange,
    className,
}: {
    label: string;
    options: { value: T; label: string }[];
    value: T;
    onChange: (value: T) => void;
    className?: string;
}) {
    return (
        <span
            role="group"
            aria-label={label}
            className={cn(
                'inline-flex self-start border border-hq-border-strong',
                className,
            )}
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'h-10 cursor-pointer px-2.5 font-mono text-[10.5px] font-bold tracking-[0.06em] uppercase transition-colors not-last:border-r not-last:border-hq-border-strong sm:h-7',
                        value === option.value
                            ? 'bg-hq-lime text-hq-ink'
                            : 'text-hq-moss hover:bg-hq-panel-alt hover:text-hq-paper',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </span>
    );
}
