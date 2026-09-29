import type { ReactNode } from 'react';
import { useRef } from 'react';
import { HqTooltip } from '@/components/hq-tooltip';
import { useMediaQuery } from '@/lib/use-media-query';
import { cn } from '@/lib/utils';
import type { CompareView } from '@/types/models';

const ICON = 'size-[18px]';

export const COMPARE_VIEWS: {
    key: CompareView;
    name: string;
    description: string;
    icon: ReactNode;
}[] = [
    {
        key: 'a',
        name: 'Cara a cara',
        description: 'Fila a fila, quién gana cada dato',
        icon: (
            <svg
                className={ICON}
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth={2}
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <rect x="3" y="4" width="6" height="16" rx="1" />
                <rect x="15" y="4" width="6" height="16" rx="1" />
                <path d="M11 8h2" />
                <path d="M11 12h2" />
                <path d="M11 16h2" />
            </svg>
        ),
    },
    {
        key: 'b',
        name: 'Pistas',
        description: 'Dónde está cada uno en la liga',
        icon: (
            <svg
                className={ICON}
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth={2}
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <path d="M3 6h.01M6.5 6h.01M10 6h.01" />
                <circle cx="17" cy="6" r="2.5" />
                <path d="M14 12h.01M17.5 12h.01M21 12h.01" />
                <circle cx="7" cy="12" r="2.5" />
                <path d="M3 18h.01M8 18h.01M20.5 18h.01" />
                <circle cx="14" cy="18" r="2.5" />
            </svg>
        ),
    },
    {
        key: 'c',
        name: 'Carriles',
        description: 'Jornada a jornada, pasado y futuro',
        icon: (
            <svg
                className={ICON}
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth={2}
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <path d="M3 7h2.5M8 7h2.5M3 17h2.5M8 17h2.5" />
                <path d="M14.5 3v18" strokeDasharray="2 2.5" />
                <path d="M18 7h3M18 17h3" />
            </svg>
        ),
    },
];

/** Segmented control with three drawn icons; names from 1100 px, below that the icon + tooltip and the active name beside it. */
export function CompareViewSwitch({
    view,
    onChange,
}: {
    view: CompareView;
    onChange: (view: CompareView) => void;
}) {
    const wide = useMediaQuery('(min-width: 1100px)');
    const tabs = useRef<Record<CompareView, HTMLButtonElement | null>>({
        a: null,
        b: null,
        c: null,
    });
    const index = COMPARE_VIEWS.findIndex((item) => item.key === view);
    const active = COMPARE_VIEWS[index];

    const move = (next: number) => {
        const target =
            COMPARE_VIEWS[(next + COMPARE_VIEWS.length) % COMPARE_VIEWS.length]
                .key;
        onChange(target);
        tabs.current[target]?.focus();
    };

    return (
        <div className="flex min-w-0 flex-1 items-center gap-3 sm:flex-none">
            <div
                role="tablist"
                aria-label="Vista del comparador"
                className="inline-flex border border-hq-border-strong bg-hq-well"
                onKeyDown={(event) => {
                    const keys: Record<string, number> = {
                        ArrowRight: index + 1,
                        ArrowLeft: index - 1,
                        Home: 0,
                        End: COMPARE_VIEWS.length - 1,
                    };

                    if (event.key in keys) {
                        event.preventDefault();
                        move(keys[event.key]);
                    }
                }}
            >
                {COMPARE_VIEWS.map((item) => {
                    const selected = item.key === view;
                    const tab = (
                        <button
                            ref={(element) => {
                                tabs.current[item.key] = element;
                            }}
                            role="tab"
                            type="button"
                            id={`cmp-tab-${item.key}`}
                            aria-controls="cmp-panel"
                            aria-selected={selected}
                            aria-label={item.name}
                            aria-describedby={`cmp-desc-${item.key}`}
                            tabIndex={selected ? 0 : -1}
                            onClick={() => onChange(item.key)}
                            className={cn(
                                'inline-flex h-10 min-w-11 cursor-pointer items-center justify-center gap-2 border-r border-hq-border-strong px-3 font-mono text-[11px] font-bold tracking-[0.06em] uppercase transition-colors last:border-r-0 max-[1099px]:w-12 max-[1099px]:px-0',
                                selected
                                    ? 'bg-hq-lime text-hq-ink'
                                    : 'text-hq-moss hover:bg-hq-panel-alt hover:text-hq-paper',
                            )}
                        >
                            {item.icon}
                            {wide && <span>{item.name}</span>}
                        </button>
                    );

                    return wide ? (
                        <span key={item.key} className="contents">
                            {tab}
                        </span>
                    ) : (
                        <HqTooltip key={item.key} label={item.name}>
                            {tab}
                        </HqTooltip>
                    );
                })}
            </div>
            {!wide && (
                <p aria-hidden="true" className="m-0 min-w-0">
                    <b className="block font-sans text-sm leading-none font-black text-hq-paper uppercase">
                        {active.name}
                    </b>
                    <span className="mt-[5px] block truncate font-mono text-[11px] leading-[1.3] text-hq-moss-dim">
                        {active.description}
                    </span>
                </p>
            )}
            {COMPARE_VIEWS.map((item) => (
                <span
                    key={item.key}
                    id={`cmp-desc-${item.key}`}
                    className="sr-only"
                >
                    {item.description}
                </span>
            ))}
        </div>
    );
}
