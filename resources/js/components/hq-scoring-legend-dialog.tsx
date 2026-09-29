import { X } from 'lucide-react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { Fragment, useEffect, useId, useRef, useState } from 'react';
import { HqPositionTag } from '@/components/hq-position-tag';
import {
    SCORING_POSITIONS,
    SCORING_RULE_GROUPS,
    scoringFrequencyForPosition,
    scoringPointsForPosition,
} from '@/lib/fantasy-scoring';
import type { ScoringPosition, ScoringRule } from '@/lib/fantasy-scoring';
import { POSITION_LABELS } from '@/lib/player-labels';
import { formatSignedPoints, pointsToneClass } from '@/lib/points';
import { cn } from '@/lib/utils';

interface HqScoringLegendDialogProps {
    open: boolean;
    onClose: () => void;
}

function getFocusableElements(container: HTMLElement): HTMLElement[] {
    return Array.from(
        container.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ).filter((element) => element.offsetParent !== null);
}

/**
 * "¿Cómo se puntúa?" : the official LaLiga Fantasy scoring
 * table, one position at a time behind POR/DEF/MED/DEL tabs — a centred
 * dialog on desktop, a bottom sheet on phones. Esc, the backdrop and the
 * close button all dismiss it; focus is trapped inside while open and
 * returned to whatever opened it on close.
 */
export function HqScoringLegendDialog({
    open,
    onClose,
}: HqScoringLegendDialogProps) {
    const titleId = useId();
    const tablistId = useId();
    const panelRef = useRef<HTMLDivElement>(null);
    const firstTabRef = useRef<HTMLButtonElement>(null);
    const onCloseRef = useRef(onClose);
    const [position, setPosition] = useState<ScoringPosition>(
        SCORING_POSITIONS[0],
    );

    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const previouslyFocused = document.activeElement as HTMLElement | null;
        firstTabRef.current?.focus();

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onCloseRef.current();

                return;
            }

            if (event.key !== 'Tab' || !panelRef.current) {
                return;
            }

            const focusable = getFocusableElements(panelRef.current);

            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => {
            window.removeEventListener('keydown', handleKeyDown);
            previouslyFocused?.focus?.();
        };
    }, [open]);

    if (!open) {
        return null;
    }

    const selectTab = (next: ScoringPosition, focus: boolean) => {
        setPosition(next);

        if (focus) {
            panelRef.current
                ?.querySelector<HTMLButtonElement>(`#${tablistId}-${next}`)
                ?.focus();
        }
    };

    const handleTablistKeyDown = (
        event: ReactKeyboardEvent<HTMLDivElement>,
    ) => {
        const index = SCORING_POSITIONS.indexOf(position);
        let nextIndex: number | null = null;

        if (event.key === 'ArrowRight') {
            nextIndex = (index + 1) % SCORING_POSITIONS.length;
        } else if (event.key === 'ArrowLeft') {
            nextIndex =
                (index - 1 + SCORING_POSITIONS.length) %
                SCORING_POSITIONS.length;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = SCORING_POSITIONS.length - 1;
        }

        if (nextIndex === null) {
            return;
        }

        event.preventDefault();
        selectTab(SCORING_POSITIONS[nextIndex], true);
    };

    return (
        <div
            className="fixed inset-0 z-[200] flex cursor-pointer items-end justify-center bg-black/65 md:items-center md:p-4"
            onClick={onClose}
        >
            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                className="flex max-h-[90vh] w-full cursor-default flex-col overflow-hidden border border-hq-border-bright bg-hq-ink shadow-[0_30px_80px_rgba(0,0,0,0.6)] md:max-h-[88vh] md:max-w-[440px]"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex shrink-0 items-center justify-between gap-3 py-2 pr-2.5 pl-4">
                    <h2
                        id={titleId}
                        className="text-xl leading-none font-black text-hq-paper uppercase"
                    >
                        ¿Cómo se puntúa?
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center border border-hq-border-strong text-hq-moss transition-colors hover:border-hq-border-bright hover:text-hq-paper md:h-[30px] md:w-[30px]"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div
                    role="tablist"
                    aria-label="Posición"
                    onKeyDown={handleTablistKeyDown}
                    className="grid shrink-0 grid-cols-4 gap-px border-y border-hq-border bg-hq-border"
                >
                    {SCORING_POSITIONS.map((tabPosition, index) => {
                        const selected = tabPosition === position;
                        const theme = POSITION_THEME[tabPosition];

                        return (
                            <button
                                key={tabPosition}
                                ref={index === 0 ? firstTabRef : undefined}
                                type="button"
                                id={`${tablistId}-${tabPosition}`}
                                role="tab"
                                aria-selected={selected}
                                aria-controls={`${tablistId}-panel`}
                                tabIndex={selected ? 0 : -1}
                                onClick={() => selectTab(tabPosition, false)}
                                className={cn(
                                    'group relative flex min-h-11 cursor-pointer items-center justify-center transition-colors focus-visible:outline-offset-[-2px] md:min-h-10',
                                    selected
                                        ? theme.tint
                                        : 'bg-hq-ink hover:bg-hq-panel',
                                )}
                            >
                                <HqPositionTag
                                    position={tabPosition}
                                    className={cn(
                                        !selected &&
                                            'border-hq-border-strong bg-transparent text-hq-moss-dim group-hover:border-hq-border-bright group-hover:text-hq-moss',
                                    )}
                                />
                                <span className="sr-only">
                                    {POSITION_LABELS[tabPosition]}
                                </span>
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'absolute inset-x-0 bottom-0 h-0.5 transition-opacity',
                                        theme.bar,
                                        selected
                                            ? 'opacity-100'
                                            : 'opacity-0 group-hover:opacity-40',
                                    )}
                                />
                            </button>
                        );
                    })}
                </div>

                <div
                    id={`${tablistId}-panel`}
                    role="tabpanel"
                    aria-labelledby={`${tablistId}-${position}`}
                    tabIndex={0}
                    className="[scrollbar-width:thin] [scrollbar-color:var(--color-hq-border-bright)_transparent] overflow-y-auto overscroll-contain focus-visible:outline-offset-[-2px]"
                >
                    <div className="grid grid-cols-2 gap-px bg-hq-border">
                        {SCORING_RULE_GROUPS.map((group) => (
                            <Fragment key={group.group}>
                                <h3 className="col-span-full bg-hq-well px-4 pt-[9px] pb-[7px] font-mono text-[10px] leading-none font-bold tracking-[0.12em] text-hq-moss-dim uppercase">
                                    {group.group}
                                </h3>
                                {group.rules.map((rule) => (
                                    <ScoringRuleCell
                                        key={rule.label}
                                        rule={rule}
                                        position={position}
                                        fullWidth={group.rules.length === 1}
                                    />
                                ))}
                                {group.rules.length > 1 &&
                                    group.rules.length % 2 === 1 && (
                                        <div
                                            aria-hidden="true"
                                            className="bg-hq-ink"
                                        />
                                    )}
                            </Fragment>
                        ))}
                    </div>
                    <p className="flex items-start gap-2 border-t border-hq-border px-4 py-2 font-mono text-[11px] leading-snug text-hq-moss-dim">
                        <img
                            src="/images/dazn-logo.png"
                            alt=""
                            className="h-3.5 w-3.5 shrink-0 animate-hq-est motion-reduce:animate-none"
                        />
                        Si el logo DAZN parpadea, la nota es nuestra estimación
                        provisional: aún no cuenta.
                    </p>
                </div>
            </div>
        </div>
    );
}

interface ScoringRuleCellProps {
    rule: ScoringRule;
    position: ScoringPosition;
    fullWidth: boolean;
}

/**
 * One scoring rule, value first: a tinted mono readout (lime for points
 * earned, red for points lost), then the action it pays for and, when it
 * has one, its "cada N" frequency.
 */
function ScoringRuleCell({ rule, position, fullWidth }: ScoringRuleCellProps) {
    const value = scoringPointsForPosition(rule, position);
    const frequency = scoringFrequencyForPosition(rule, position);

    return (
        <div
            className={cn(
                'flex min-h-[34px] items-stretch bg-hq-ink',
                fullWidth && 'col-span-full',
            )}
        >
            <div
                className={cn(
                    'flex shrink-0 flex-col items-center justify-center gap-[3px] border-r border-hq-border px-1 font-mono text-[15px] leading-none font-bold whitespace-nowrap tabular-nums',
                    rule.range ? 'min-w-[68px] text-hq-lime' : 'w-12',
                    value !== undefined && pointsToneClass(value),
                    valueTintClass(rule.range ? 1 : (value ?? 0)),
                )}
            >
                {rule.range ??
                    (value !== undefined && formatSignedPoints(value))}
                {frequency !== undefined && (
                    <span className="text-[9px] font-medium tracking-[0.02em] text-hq-moss uppercase">
                        cada {frequency}
                    </span>
                )}
            </div>
            <div className="flex min-w-0 flex-1 flex-col justify-center gap-0.5 px-2.5 py-1">
                <span className="flex flex-wrap items-baseline gap-x-1.5 text-[13px] leading-[1.25] font-semibold text-hq-paper">
                    <span className="inline-flex items-center gap-1.5">
                        {rule.label === 'Nota DAZN' && (
                            <img
                                src="/images/dazn-logo.png"
                                alt="DAZN"
                                className="h-3.5 w-3.5 shrink-0"
                            />
                        )}
                        {rule.label}
                    </span>
                </span>
                {rule.sub && (
                    <small className="font-mono text-[10px] leading-[1.2] text-hq-moss-dim">
                        {rule.sub}
                    </small>
                )}
            </div>
        </div>
    );
}

/** Background wash behind a rule's value: lime for gains, red for losses. */
function valueTintClass(points: number): string {
    if (points > 0) {
        return 'bg-hq-lime/[0.07]';
    }

    return points < 0 ? 'bg-hq-live/10' : 'bg-hq-well';
}

/** Per-position accents: the active tab's underline and wash. */
const POSITION_THEME: Record<ScoringPosition, { bar: string; tint: string }> = {
    goalkeeper: {
        bar: 'bg-hq-por',
        tint: 'bg-hq-por/[0.08]',
    },
    defender: {
        bar: 'bg-hq-def',
        tint: 'bg-hq-def/[0.08]',
    },
    midfield: {
        bar: 'bg-hq-med',
        tint: 'bg-hq-med/[0.08]',
    },
    striker: {
        bar: 'bg-hq-del',
        tint: 'bg-hq-del/[0.08]',
    },
};
