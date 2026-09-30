import { cn } from '@/lib/utils';

const BADGE =
    'inline-block border px-1 py-[3px] font-mono text-[10.5px] leading-none font-semibold tracking-[.02em] whitespace-nowrap not-italic';

const LIME = 'border-hq-lime/40 text-hq-lime';

/**
 * Jornada tags in chronological order. Past `max` tags, the most recent
 * `max - 1` are shown after a "+N" tag holding the older ones (listed in
 * its title); the detail shows them all.
 */
export function JornadaBadges({
    weeks,
    max,
    tone = 'lime',
    className,
}: {
    weeks: number[];
    max: number;
    tone?: 'lime' | 'neg';
    className?: string;
}) {
    if (weeks.length === 0) {
        return null;
    }

    const shown =
        weeks.length > max ? weeks.slice(weeks.length - (max - 1)) : weeks;
    const older = weeks.slice(0, weeks.length - shown.length);

    return (
        <span
            className={cn(
                'inline-flex min-w-0 flex-wrap items-center gap-[3px]',
                className,
            )}
        >
            {older.length > 0 && (
                <i
                    title={older.map((week) => `J${week}`).join(' · ')}
                    className={cn(
                        BADGE,
                        'border-hq-border-bright bg-hq-panel-alt text-hq-paper',
                    )}
                >
                    +{older.length}
                </i>
            )}
            {shown.map((week) => (
                <i
                    key={week}
                    className={cn(
                        BADGE,
                        tone === 'neg' ? 'border-hq-neg/40 text-hq-neg' : LIME,
                    )}
                >
                    J{week}
                </i>
            ))}
        </span>
    );
}

/** A streak as its first and last jornada: "J9 – J31". */
export function JornadaSpan({ from, to }: { from: number; to: number }) {
    return (
        <span className="inline-flex items-center gap-[3px]">
            <i className={cn(BADGE, LIME)}>J{from}</i>
            <i className="font-mono text-[10px] leading-none font-medium text-hq-moss-dim not-italic">
                –
            </i>
            <i className={cn(BADGE, LIME)}>J{to}</i>
        </span>
    );
}
