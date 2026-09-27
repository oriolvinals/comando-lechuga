import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface HqPageHeaderMetaItem {
    label: ReactNode;
    value: ReactNode;
}

interface HqPageHeaderProps {
    /** Lime mono code above the title, e.g. "CH·J · BASE DE DATOS". */
    code?: string;
    title: ReactNode;
    /** Right-aligned label/value pairs (wraps under the title on phones). */
    meta?: HqPageHeaderMetaItem[];
    className?: string;
}

/** A page's title block: code line, Chivo black uppercase h1, meta readouts on the right. */
export function HqPageHeader({
    code,
    title,
    meta = [],
    className,
}: HqPageHeaderProps) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-end gap-4 border-b border-hq-border px-3.5 pt-4 pb-3 sm:px-5 sm:pt-[22px] sm:pb-4',
                className,
            )}
        >
            <div className="min-w-0">
                {code && (
                    <span className="mb-2 block font-mono text-[11px] leading-none font-semibold tracking-[0.14em] text-hq-lime">
                        {code}
                    </span>
                )}
                <h1 className="font-display text-[26px] leading-[0.95] text-hq-paper uppercase sm:text-[34px]">
                    {title}
                </h1>
            </div>
            {meta.length > 0 && (
                <dl className="flex w-full flex-wrap items-end justify-between gap-x-[18px] gap-y-2 font-mono text-xs leading-tight text-hq-moss sm:ml-auto sm:w-auto sm:justify-end">
                    {meta.map((item, index) => (
                        <div key={index}>
                            <dt>{item.label}</dt>
                            <dd className="mt-1 text-lg leading-none font-semibold text-hq-paper tabular-nums">
                                {item.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}
