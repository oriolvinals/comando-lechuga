import type { PropsWithChildren } from 'react';

interface HqSectionProps extends PropsWithChildren {
    number: string;
    title: string;
}

export function HqSection({ number, title, children }: HqSectionProps) {
    return (
        <div className="border-t border-hq-border/70 py-6">
            <div className="mb-4 flex items-center gap-2.5">
                <span className="rounded border border-hq-border-strong px-2 py-0.5 font-mono text-[11px] text-hq-moss-dim">
                    {number}
                </span>
                <h2 className="font-display text-xl tracking-wide text-hq-paper uppercase">
                    {title}
                </h2>
            </div>
            {children}
        </div>
    );
}
