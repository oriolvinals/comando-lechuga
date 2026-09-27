import type { PropsWithChildren, ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface HqChannelHeaderProps {
    /** Channel code after the ▮ marker, e.g. "CH·01" or "J08". */
    code?: string;
    title: ReactNode;
    /** Right-aligned meta / controls (a "ver todo →" link, a countdown, a refresh button). */
    action?: ReactNode;
    /** Heading level of the title; section headers are h2 by default. */
    as?: 'h2' | 'h3';
    className?: string;
}

/**
 * The numbered console channel header ("▮ CH·01 CLASIFICACIÓN ··· meta"):
 * a 40px mono uppercase bar on a 1px rule, fading from panel to ink.
 */
export function HqChannelHeader({
    code,
    title,
    action,
    as: Heading = 'h2',
    className,
}: HqChannelHeaderProps) {
    return (
        <div
            className={cn(
                'flex min-h-10 flex-wrap items-center gap-x-3 gap-y-1 border-b border-hq-border bg-linear-to-r from-hq-panel to-transparent to-70% px-3.5 py-2 font-mono text-[11.5px] font-bold tracking-[0.09em] text-hq-paper uppercase sm:px-4 sm:text-xs',
                className,
            )}
        >
            <Heading className="flex min-w-0 items-center gap-3">
                <span className="shrink-0 font-semibold text-hq-lime">
                    ▮{code ? ` ${code}` : ''}
                </span>
                <span className="min-w-0">{title}</span>
            </Heading>
            {action && (
                <div className="ml-auto flex items-center gap-2.5 font-medium tracking-[0.06em] text-hq-moss-dim">
                    {action}
                </div>
            )}
        </div>
    );
}

interface HqSectionProps extends PropsWithChildren, HqChannelHeaderProps {
    /** Drop the default 16px body padding — for tables, lists and grids that run edge to edge. */
    flush?: boolean;
    bodyClassName?: string;
    id?: string;
}

/**
 * A console section: channel header, then the body, closed by a 1px rule.
 * No cards — sections stack on rules.
 */
export function HqSection({
    code,
    title,
    action,
    as,
    flush = false,
    className,
    bodyClassName,
    id,
    children,
}: HqSectionProps) {
    return (
        <section id={id} className={cn('border-b border-hq-border', className)}>
            <HqChannelHeader
                code={code}
                title={title}
                action={action}
                as={as}
            />
            <div className={cn(!flush && 'p-3.5 sm:p-4', bodyClassName)}>
                {children}
            </div>
        </section>
    );
}
