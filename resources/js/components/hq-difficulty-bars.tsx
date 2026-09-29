import type { ReactNode } from 'react';
import { HqTooltip } from '@/components/hq-tooltip';
import {
    RIVAL_DIFFICULTY_BG_CLASSES,
    RIVAL_DIFFICULTY_TEXT_CLASSES,
    formatDifficulty,
    rivalDifficultyBars,
    rivalDifficultyLevel,
} from '@/lib/rival-difficulty';
import { cn } from '@/lib/utils';

/**
 * Every layout puts the number under the bars:
 * - `stack`: full-width 3px rule, small moss number (Próximos 3).
 * - `inline`: full-width 3px rule, small paper number (calendar cell foot).
 * - `gauge`: five 7×9px segments, level-coloured number (ficha lists, calendar average).
 */
export type HqDifficultyBarsLayout = 'stack' | 'inline' | 'gauge';

interface HqDifficultyBarsProps {
    /** 0–10, 10 = hardest. */
    difficulty: number;
    layout: HqDifficultyBarsLayout;
    className?: string;
}

/**
 * The 5-segment difficulty gauge (more lit bars = harder, coloured by level)
 * with the exact 0–10 difficulty in small mono type under it — option B of
 * the difficulty mock. Purely visual: the caller wraps it in the tooltip.
 */
export function HqDifficultyBars({
    difficulty,
    layout,
    className,
}: HqDifficultyBarsProps) {
    const level = rivalDifficultyLevel(difficulty);
    const bars = rivalDifficultyBars(difficulty);

    const segments = (
        <span
            aria-hidden="true"
            className={cn(
                layout === 'gauge'
                    ? 'grid grid-cols-[repeat(5,7px)] gap-0.5'
                    : 'flex h-[3px] gap-px',
                layout !== 'gauge' && 'w-full',
            )}
        >
            {Array.from({ length: 5 }, (_, segment) => (
                <i
                    key={segment}
                    className={cn(
                        'block',
                        layout === 'gauge' ? 'h-[9px]' : 'flex-1',
                        segment < bars
                            ? RIVAL_DIFFICULTY_BG_CLASSES[level]
                            : 'bg-hq-border-strong',
                    )}
                />
            ))}
        </span>
    );

    const number = (
        <span
            aria-hidden="true"
            className={cn(
                'font-mono leading-none font-bold tabular-nums',
                layout === 'stack' && 'mt-[3px] text-[9.5px] text-hq-moss',
                layout === 'inline' && 'mt-[3px] text-[9px] text-hq-paper',
                layout === 'gauge' &&
                    cn('mt-1 text-xs', RIVAL_DIFFICULTY_TEXT_CLASSES[level]),
            )}
        >
            {formatDifficulty(difficulty)}
        </span>
    );

    return (
        <span
            className={cn(
                'flex flex-col items-center',
                layout !== 'gauge' && 'w-full',
                className,
            )}
        >
            {segments}
            {number}
        </span>
    );
}

/** What the shared difficulty tooltip needs from a next-fixture slot or calendar match. */
export interface DifficultyTooltipMatch {
    week_number: number;
    opponent: { main_name: string };
    is_home: boolean;
    rival_position: number | null;
    difficulty: number | null;
    absence_adjusted: boolean | null;
}

/**
 * "Dificultad 7,4 / 10 · rival 14.º · fuera" (+ " · bajas del rival" when the
 * rival's absences eased the match) — the same line everywhere.
 */
export function difficultySummary(match: DifficultyTooltipMatch): ReactNode {
    const venue = match.is_home ? 'casa' : 'fuera';
    const rival =
        match.rival_position === null
            ? ''
            : ` · rival ${match.rival_position}.º`;

    if (match.difficulty === null) {
        return `Sin dificultad${rival} · ${venue}`;
    }

    const level = rivalDifficultyLevel(match.difficulty);

    return (
        <>
            <span className={RIVAL_DIFFICULTY_TEXT_CLASSES[level]}>
                Dificultad{' '}
                <b className="font-bold">
                    {formatDifficulty(match.difficulty)}
                </b>{' '}
                / 10
            </span>
            {rival} · {venue}
            {match.absence_adjusted === true && ' · bajas del rival'}
        </>
    );
}

interface HqDifficultyTooltipProps {
    match: DifficultyTooltipMatch;
    children: ReactNode;
    className?: string;
    focusable?: boolean;
    /** Extra lines between the heading and the difficulty line (date, rescheduled…). */
    details?: ReactNode;
}

/** HqTooltip with the shared "J8 · vs Rival / Dificultad …" label. */
export function HqDifficultyTooltip({
    match,
    children,
    className,
    focusable = true,
    details,
}: HqDifficultyTooltipProps) {
    return (
        <HqTooltip
            focusable={focusable}
            wrap
            className={className}
            label={
                <>
                    <b className="font-bold">
                        J{match.week_number} · vs {match.opponent.main_name}
                    </b>
                    {details}
                    <br />
                    {difficultySummary(match)}
                </>
            }
        >
            {children}
        </HqTooltip>
    );
}
