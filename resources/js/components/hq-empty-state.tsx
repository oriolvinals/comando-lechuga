import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface HqEmptyStateProps {
    /** A short glyph shown in dot-matrix above the title ("∅", "⚽", "▦"). */
    glyph?: ReactNode;
    title: ReactNode;
    children?: ReactNode;
    className?: string;
}

/** Dashed-frame empty state: dot-matrix glyph, uppercase title, mono explanation. */
export function HqEmptyState({
    glyph = '∅',
    title,
    children,
    className,
}: HqEmptyStateProps) {
    return (
        <div
            className={cn(
                'm-3.5 border border-dashed border-hq-border-bright px-[18px] py-7 text-center sm:m-4',
                className,
            )}
        >
            <div
                aria-hidden="true"
                className="mb-2.5 font-dot text-[34px] leading-none font-black text-hq-border-bright"
            >
                {glyph}
            </div>
            <h3 className="text-base leading-tight font-extrabold tracking-[0.02em] text-hq-paper uppercase">
                {title}
            </h3>
            {children && (
                <p className="mt-1.5 font-mono text-[12.5px] leading-[1.45] text-hq-moss">
                    {children}
                </p>
            )}
        </div>
    );
}
