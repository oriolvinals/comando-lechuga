import { Link } from '@inertiajs/react';
import { ArrowRightLeft, User } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqStartMeter } from '@/components/hq-start-probability';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type {
    PlayerPosition,
    StartProbabilityAlternative,
    StartProbabilityEntry,
} from '@/types/models';

/** The photo frame of an alternative, in his position's colour — so he reads as another player. */
const POSITION_BORDER_CLASSES: Record<PlayerPosition, string> = {
    goalkeeper: 'border-hq-por',
    defender: 'border-hq-def',
    midfield: 'border-hq-med',
    striker: 'border-hq-del',
    coach: 'border-hq-ent',
};

/** Keeps the card this far from the viewport edges. */
const VIEWPORT_MARGIN = 8;
/** Gap between the indicator and the card. */
const TRIGGER_GAP = 6;
/** How long the card waits before closing once the pointer leaves, so it can travel from the indicator onto the card. */
const CLOSE_DELAY_MS = 120;

interface Anchor {
    left: number;
    right: number;
    top: number;
    bottom: number;
}

/**
 * One alternative: his photo framed in his position's colour, his name
 * (linked to his ficha when he's one of ours, FF's name otherwise), his
 * position and his own %, read off his row of the same block.
 */
function AlternativeLine({
    alternative,
    entry,
    className,
}: {
    alternative: StartProbabilityAlternative;
    entry: StartProbabilityEntry | undefined;
    className?: string;
}) {
    const player = alternative.player;

    return (
        <div
            className={cn(
                'relative grid min-h-11 items-center gap-x-2 transition-colors',
                player !== null && 'group/alt cursor-pointer',
                className,
            )}
        >
            <EntityImage
                src={player?.image ?? ''}
                alt=""
                fallback={User}
                shape="square"
                className={cn(
                    'h-7 w-7 rounded-none border bg-hq-well object-cover text-hq-moss-dim',
                    player === null
                        ? 'border-hq-border-strong'
                        : POSITION_BORDER_CLASSES[player.position],
                )}
                style={{ objectPosition: 'center 20%' }}
            />
            <span className="flex min-w-0 items-center gap-1.5">
                {player === null ? (
                    <span className="min-w-0 truncate text-[13px] font-bold text-hq-moss">
                        {alternative.name}
                    </span>
                ) : (
                    <Link
                        href={playersShow(player.id).url}
                        className="min-w-0 truncate text-[13px] font-bold text-hq-paper outline-none group-hover/alt:text-hq-lime after:absolute after:inset-0 after:content-[''] focus-visible:text-hq-lime focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-hq-lime"
                    >
                        {player.nickname}
                    </Link>
                )}
                {player !== null && (
                    <HqPositionTag
                        position={player.position}
                        className="px-1 py-0.5"
                    />
                )}
            </span>
            {player !== null && (
                <HqStartMeter
                    probability={entry?.probability ?? null}
                    status={player.status}
                    size="sm"
                    className="relative z-10"
                />
            )}
        </div>
    );
}

/**
 * The swap indicator on a probable starter's photo corner (a «2» beside it
 * when FútbolFantasy lists two) and the «Puede salir en su lugar» card with
 * the players who could start instead, in FF's order. Hover opens it on a
 * mouse, a tap on a touch screen; a tap outside or Esc closes it. The card
 * is portalled into document.body and clamped to the viewport, so a pitch
 * that clips its overflow never cuts it. Place it inside the token's
 * positioned slot; `size` matches the token's photo.
 */
export function HqStartAlternatives({
    alternatives,
    entriesById,
    size = 'lg',
}: {
    alternatives: StartProbabilityAlternative[];
    entriesById: Map<number, StartProbabilityEntry>;
    size?: 'lg' | 'sm';
}) {
    const [anchor, setAnchor] = useState<Anchor | null>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const cardRef = useRef<HTMLDivElement | null>(null);
    const closeTimer = useRef<ReturnType<typeof setTimeout>>(undefined);

    const open = () => {
        clearTimeout(closeTimer.current);
        const rect = triggerRef.current?.getBoundingClientRect();

        if (rect) {
            setAnchor({
                left: rect.left,
                right: rect.right,
                top: rect.top,
                bottom: rect.bottom,
            });
        }
    };
    const close = () => {
        clearTimeout(closeTimer.current);
        setAnchor(null);
    };
    const closeSoon = () => {
        clearTimeout(closeTimer.current);
        closeTimer.current = setTimeout(() => setAnchor(null), CLOSE_DELAY_MS);
    };
    const hoverable = () =>
        window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    useEffect(() => () => clearTimeout(closeTimer.current), []);

    useEffect(() => {
        if (!anchor) {
            return;
        }

        const closeOnOutside = (event: PointerEvent) => {
            const target = event.target as Node;

            if (
                !triggerRef.current?.contains(target) &&
                !cardRef.current?.contains(target)
            ) {
                setAnchor(null);
            }
        };
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setAnchor(null);
                triggerRef.current?.focus();
            }
        };
        const closeOnScroll = () => setAnchor(null);

        document.addEventListener('pointerdown', closeOnOutside);
        document.addEventListener('keydown', closeOnEscape);
        window.addEventListener('scroll', closeOnScroll, {
            capture: true,
            passive: true,
        });

        return () => {
            document.removeEventListener('pointerdown', closeOnOutside);
            document.removeEventListener('keydown', closeOnEscape);
            window.removeEventListener('scroll', closeOnScroll, {
                capture: true,
            });
        };
    }, [anchor]);

    /** Places the card under the indicator (above it when there's no room), clamped inside the viewport. */
    const placeCard = (card: HTMLDivElement | null) => {
        cardRef.current = card;

        if (!card || !anchor) {
            return;
        }

        const centerX = (anchor.left + anchor.right) / 2;
        const left = Math.max(
            VIEWPORT_MARGIN,
            Math.min(
                window.innerWidth - VIEWPORT_MARGIN - card.offsetWidth,
                centerX - card.offsetWidth / 2,
            ),
        );
        const fitsBelow =
            anchor.bottom + TRIGGER_GAP + card.offsetHeight <=
            window.innerHeight - VIEWPORT_MARGIN;

        card.style.left = `${left}px`;
        card.style.top = fitsBelow
            ? `${anchor.bottom + TRIGGER_GAP}px`
            : `${Math.max(VIEWPORT_MARGIN, anchor.top - TRIGGER_GAP - card.offsetHeight)}px`;
        card.style.visibility = 'visible';
    };

    if (alternatives.length === 0) {
        return null;
    }

    const names = alternatives.map(
        (alternative) => alternative.player?.nickname ?? alternative.name,
    );
    const label = `${alternatives.length === 1 ? 'Alternativa' : `${alternatives.length} alternativas`}: ${joinNames(names)}`;

    return (
        <>
            <button
                ref={triggerRef}
                type="button"
                aria-label={label}
                aria-expanded={anchor !== null}
                aria-haspopup="dialog"
                onClick={() => (anchor && !hoverable() ? close() : open())}
                onMouseEnter={() => hoverable() && open()}
                onMouseLeave={() => hoverable() && closeSoon()}
                className={cn(
                    'absolute -top-2 z-[12] inline-flex h-[18px] min-w-[18px] cursor-pointer items-center justify-center gap-0.5 border border-hq-khaki px-[3px] font-mono text-[11px] leading-none font-bold transition-colors outline-none hover:bg-hq-khaki hover:text-hq-ink focus-visible:bg-hq-khaki focus-visible:text-hq-ink',
                    size === 'lg'
                        ? 'left-[calc(50%+17px)]'
                        : 'left-[calc(50%+15px)]',
                    anchor
                        ? 'bg-hq-khaki text-hq-ink'
                        : 'bg-hq-ink text-hq-khaki',
                )}
            >
                <ArrowRightLeft
                    aria-hidden="true"
                    className="h-[11px] w-[11px]"
                    strokeWidth={2}
                />
                {alternatives.length > 1 && <span>{alternatives.length}</span>}
            </button>
            {anchor &&
                createPortal(
                    <div
                        ref={placeCard}
                        role="dialog"
                        aria-label="Puede salir en su lugar"
                        onMouseEnter={() => hoverable() && open()}
                        onMouseLeave={() => hoverable() && closeSoon()}
                        className="invisible fixed top-0 left-0 z-[999] w-[264px] max-w-[calc(100vw-16px)] border border-hq-border-bright bg-hq-panel text-left shadow-[0_10px_28px_rgba(0,0,0,0.6)]"
                    >
                        <div className="border-b border-hq-border px-2.5 pt-2 pb-[7px] font-mono text-[11px] leading-none font-bold tracking-[0.06em] text-hq-khaki">
                            Puede salir en su lugar
                        </div>
                        {alternatives.map((alternative) => (
                            <AlternativeLine
                                key={alternative.position}
                                alternative={alternative}
                                entry={
                                    alternative.player
                                        ? entriesById.get(alternative.player.id)
                                        : undefined
                                }
                                className="grid-cols-[28px_minmax(0,1fr)_auto] border-hq-border px-2.5 py-[7px] transition-colors hover:bg-hq-panel-alt [&+&]:border-t"
                            />
                        ))}
                    </div>,
                    document.body,
                )}
        </>
    );
}

/**
 * The list view's sub-rows under a probable starter, one per alternative in
 * FútbolFantasy's order: indented, joined to the starter by a dashed elbow,
 * each one his own link.
 */
export function HqStartAlternativeRows({
    alternatives,
    entriesById,
}: {
    alternatives: StartProbabilityAlternative[];
    entriesById: Map<number, StartProbabilityEntry>;
}) {
    return alternatives.map((alternative, index) => (
        <div
            key={alternative.position}
            className="relative grid min-h-[50px] grid-cols-[14px_minmax(0,1fr)] border-b border-hq-border bg-hq-well/55 py-[7px] pr-3.5 pl-[31px] transition-colors hover:bg-hq-panel sm:pr-4"
        >
            <span
                aria-hidden="true"
                className={cn(
                    'relative -my-[7px] self-stretch before:absolute before:top-0 before:left-0 before:border-l before:border-dashed before:border-hq-khaki after:absolute after:top-1/2 after:left-0 after:w-full after:border-t after:border-dashed after:border-hq-khaki',
                    index < alternatives.length - 1
                        ? 'before:h-full'
                        : 'before:h-1/2',
                )}
            />
            <AlternativeLine
                alternative={alternative}
                entry={
                    alternative.player
                        ? entriesById.get(alternative.player.id)
                        : undefined
                }
                className="ml-2 h-9 min-h-0 grid-cols-[28px_minmax(0,1fr)_auto]"
            />
        </div>
    ));
}

function joinNames(names: string[]): string {
    return names.length <= 1
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} y ${names[names.length - 1]}`;
}

/** Each block entry by player id, to read an alternative's own % off his row. */
export function entriesByPlayerId(
    entries: StartProbabilityEntry[],
): Map<number, StartProbabilityEntry> {
    return new Map(entries.map((entry) => [entry.player.id, entry]));
}

/**
 * For each player id, the probable starters FútbolFantasy lists him under as
 * an alternative — the bench's «Alternativa a …» — in the block's order.
 */
export function alternativeOfByPlayerId(
    entries: StartProbabilityEntry[],
): Map<number, string[]> {
    const starters = new Map<number, string[]>();

    entries.forEach((entry) => {
        entry.alternatives.forEach((alternative) => {
            if (alternative.player === null) {
                return;
            }

            starters.set(alternative.player.id, [
                ...(starters.get(alternative.player.id) ?? []),
                entry.player.nickname,
            ]);
        });
    });

    return starters;
}
