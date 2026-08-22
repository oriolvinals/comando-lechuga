import type { PropsWithChildren } from 'react';

interface HqSectionProps extends PropsWithChildren {
    title: string;
}

export function HqSection({ title, children }: HqSectionProps) {
    return (
        <div className="border-t border-hq-border/70 py-6">
            <h2 className="mb-4 font-display text-xl tracking-wide text-hq-paper uppercase">
                {title}
            </h2>
            {children}
        </div>
    );
}
