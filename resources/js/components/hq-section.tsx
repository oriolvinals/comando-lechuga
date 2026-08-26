import type { PropsWithChildren, ReactNode } from 'react';

interface HqSectionProps extends PropsWithChildren {
    title: string;
    action?: ReactNode;
}

export function HqSection({ title, action, children }: HqSectionProps) {
    return (
        <div className="border-t border-hq-border/70 py-6">
            <div className="mb-4 flex items-center justify-between gap-4">
                <h2 className="font-display text-xl tracking-wide text-hq-paper uppercase">
                    {title}
                </h2>
                {action}
            </div>
            {children}
        </div>
    );
}
