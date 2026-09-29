import { X } from 'lucide-react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { Fragment, useEffect, useId, useRef, useState } from 'react';
import { HqLed } from '@/components/hq-led';
import { HqPositionTag } from '@/components/hq-position-tag';
import {
    SCORING_POSITIONS,
    SCORING_RULE_GROUPS,
    scoringFrequencyForPosition,
    scoringPointsForPosition,
} from '@/lib/fantasy-scoring';
import type { ScoringPosition } from '@/lib/fantasy-scoring';
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
 * "¿Cómo se puntúa?" (mock option B): the official LaLiga Fantasy scoring
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
                className="flex max-h-[90vh] w-full cursor-default flex-col overflow-hidden border border-hq-border-bright bg-hq-ink shadow-[0_30px_80px_rgba(0,0,0,0.6)] md:max-h-[86vh] md:max-w-[480px]"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex items-center justify-between gap-2.5 border-b border-hq-border py-2 pr-2.5 pl-4">
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
                        className="flex h-11 w-11 cursor-pointer items-center justify-center border border-hq-border-strong text-hq-moss hover:border-hq-border-bright hover:text-hq-paper md:h-8 md:w-8"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div
                    role="tablist"
                    aria-label="Posición"
                    onKeyDown={handleTablistKeyDown}
                    className="grid grid-cols-4 border-b border-hq-border"
                >
                    {SCORING_POSITIONS.map((tabPosition, index) => {
                        const selected = tabPosition === position;

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
                                    'flex min-h-12 cursor-pointer flex-col items-center justify-center gap-1.5 border-b-2 border-transparent py-1.5 transition-colors',
                                    selected
                                        ? 'bg-hq-panel'
                                        : 'hover:bg-hq-panel/50',
                                    selected &&
                                        POSITION_TAB_BORDER_CLASSES[
                                            tabPosition
                                        ],
                                )}
                            >
                                <HqPositionTag
                                    position={tabPosition}
                                    className={cn(
                                        !selected &&
                                            'border-hq-border-strong bg-transparent text-hq-moss-dim',
                                    )}
                                />
                                <small
                                    className={cn(
                                        'font-mono text-[9.5px] font-semibold tracking-[0.04em] uppercase',
                                        selected
                                            ? 'text-hq-paper'
                                            : 'text-hq-moss-dim',
                                    )}
                                >
                                    {POSITION_LABELS[tabPosition]}
                                </small>
                            </button>
                        );
                    })}
                </div>

                <div
                    id={`${tablistId}-panel`}
                    role="tabpanel"
                    aria-labelledby={`${tablistId}-${position}`}
                    tabIndex={0}
                    className="overflow-y-auto overscroll-contain"
                >
                    <div className="grid grid-cols-1 min-[400px]:grid-cols-2">
                        {SCORING_RULE_GROUPS.map((group) => (
                            <Fragment key={group.group}>
                                <p className="col-span-full border-b border-hq-border bg-hq-well px-4 pt-3 pb-1.5 font-mono text-[10.5px] leading-none font-bold tracking-[0.1em] text-hq-moss uppercase">
                                    {group.group}
                                </p>
                                {group.rules.map((rule) => {
                                    const value = scoringPointsForPosition(
                                        rule,
                                        position,
                                    );
                                    const frequency =
                                        scoringFrequencyForPosition(
                                            rule,
                                            position,
                                        );

                                    return (
                                        <div
                                            key={rule.label}
                                            className="flex items-center gap-3 border-b border-hq-border px-4 py-[9px]"
                                        >
                                            <span className="min-w-0 flex-1 text-[13px] leading-[1.3] font-semibold text-hq-paper">
                                                {rule.label === 'Nota DAZN' ? (
                                                    <img
                                                        src="/images/dazn-logo.png"
                                                        alt="DAZN"
                                                        className="mr-1.5 inline-block h-3.5 w-3.5 align-text-bottom"
                                                    />
                                                ) : null}
                                                {rule.label}
                                                {rule.sub && (
                                                    <small className="block font-mono text-[11px] leading-[1.3] font-normal text-hq-moss-dim">
                                                        {rule.sub}
                                                    </small>
                                                )}
                                            </span>
                                            {rule.range ? (
                                                <HqLed
                                                    tone="lime"
                                                    className="shrink-0 text-base whitespace-nowrap"
                                                >
                                                    {rule.range}
                                                </HqLed>
                                            ) : (
                                                value !== undefined && (
                                                    <span className="flex shrink-0 items-baseline gap-1 whitespace-nowrap">
                                                        <HqLed
                                                            className={cn(
                                                                'text-base',
                                                                pointsToneClass(
                                                                    value,
                                                                ),
                                                            )}
                                                        >
                                                            {formatSignedPoints(
                                                                value,
                                                            )}
                                                        </HqLed>
                                                        {frequency !==
                                                            undefined && (
                                                            <span className="font-mono text-[10.5px] font-medium text-hq-moss">
                                                                cada {frequency}
                                                            </span>
                                                        )}
                                                    </span>
                                                )
                                            )}
                                        </div>
                                    );
                                })}
                            </Fragment>
                        ))}
                        <p className="col-span-full p-4 font-mono text-[11px] leading-[1.5] text-hq-moss-dim">
                            Si el logo DAZN parpadea, la nota es nuestra
                            estimación provisional: aún no cuenta.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}

const POSITION_TAB_BORDER_CLASSES: Record<ScoringPosition, string> = {
    goalkeeper: 'border-hq-por',
    defender: 'border-hq-def',
    midfield: 'border-hq-med',
    striker: 'border-hq-del',
};
