import { Link } from '@inertiajs/react';
import type { MouseEvent as ReactMouseEvent } from 'react';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import { show as seasonManagersShow } from '@/routes/season-managers';

interface HqManagerChipManager {
    id: number;
    name: string;
    primary_color: string | null;
}

interface HqManagerChipProps {
    /** `null` renders the free-market "Libre" chip. */
    manager: HqManagerChipManager | null;
    /** Link to the manager's ficha (default). Off inside another link or button. */
    link?: boolean;
    className?: string;
}

/**
 * A fantasy manager as the mock's `.mgr` chip: their colour square and name
 * (mono, truncating), linking to their ficha. `null` is "Libre" in dim moss.
 */
export function HqManagerChip({
    manager,
    link = true,
    className,
}: HqManagerChipProps) {
    const classes = cn(
        'inline-flex max-w-full min-w-0 items-center gap-1.5 font-mono text-xs leading-none font-medium text-hq-moss',
        className,
    );

    if (manager === null) {
        return (
            <span className={classes}>
                <i
                    aria-hidden="true"
                    className="block size-[9px] shrink-0 bg-hq-moss-dim shadow-[0_0_0_1px_rgba(255,255,255,0.12)]"
                />
                <span className="truncate text-hq-moss-dim">Libre</span>
            </span>
        );
    }

    const content = (
        <>
            <i
                aria-hidden="true"
                className="block size-[9px] shrink-0 shadow-[0_0_0_1px_rgba(255,255,255,0.12)]"
                style={{ backgroundColor: managerColor(manager.primary_color) }}
            />
            <span className="truncate">{manager.name}</span>
        </>
    );

    if (!link) {
        return <span className={classes}>{content}</span>;
    }

    return (
        <Link
            href={seasonManagersShow(manager.id).url}
            onClick={(event: ReactMouseEvent) => event.stopPropagation()}
            className={cn(classes, 'hover:text-hq-paper')}
        >
            {content}
        </Link>
    );
}
