import type { ReactNode } from 'react';
import type { JornadaStats, PlayerPosition } from '@/types/models';

interface MatchEventIconsProps {
    stats: JornadaStats;
    position: PlayerPosition;
}

function statCount(stats: JornadaStats, key: string): number {
    return stats[key]?.[0] ?? 0;
}

/**
 * Wraps a repeatable event glyph (goal, assist, penalty outcome...) so a
 * count > 1 renders once with a small corner badge instead of repeating
 * the glyph N times.
 */
export function EventGlyph({
    count,
    title,
    children,
}: {
    count: number;
    title: string;
    children: ReactNode;
}) {
    return (
        <span className="relative inline-flex" title={title}>
            {children}
            {count > 1 && (
                <span className="absolute -right-1.5 -bottom-1.5 flex h-2.5 min-w-2.5 items-center justify-center rounded-[2px] border border-hq-border-strong bg-hq-ink px-0.5 font-mono text-[8px] font-extrabold text-hq-paper">
                    {count}
                </span>
            )}
        </span>
    );
}

export function MatchEventIcons({ stats, position }: MatchEventIconsProps) {
    const goals = statCount(stats, 'goals');
    const ownGoals = statCount(stats, 'own_goals');
    const assists = statCount(stats, 'goal_assist');
    const secondYellow = statCount(stats, 'second_yellow_card') > 0;
    const yellow = statCount(stats, 'yellow_card') > 0 && !secondYellow;
    const red = statCount(stats, 'red_card') > 0 && !secondYellow;
    const penaltyWon = statCount(stats, 'penalty_won');
    const penaltyConceded = statCount(stats, 'penalty_conceded');
    const penaltyMissed = statCount(stats, 'penalty_failed');
    const penaltySaved = statCount(stats, 'penalty_save');
    const cleanSheet =
        position === 'goalkeeper' &&
        statCount(stats, 'goals_conceded') === 0 &&
        statCount(stats, 'mins_played') >= 60;

    const hasAnyEvent =
        goals > 0 ||
        ownGoals > 0 ||
        assists > 0 ||
        yellow ||
        secondYellow ||
        red ||
        penaltyWon > 0 ||
        penaltyConceded > 0 ||
        penaltyMissed > 0 ||
        penaltySaved > 0 ||
        cleanSheet;

    if (!hasAnyEvent) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {goals > 0 && (
                <EventGlyph count={goals} title="Gol">
                    <span className="text-[13px] leading-none">⚽</span>
                </EventGlyph>
            )}
            {ownGoals > 0 && (
                <EventGlyph count={ownGoals} title="Autogol">
                    <span className="border border-hq-live px-1 py-px font-mono text-[9px] font-bold text-hq-live">
                        PP
                    </span>
                </EventGlyph>
            )}
            {assists > 0 && (
                <EventGlyph count={assists} title="Asistencia">
                    <span className="text-[13px] leading-none text-hq-med">➜</span>
                </EventGlyph>
            )}
            {yellow && (
                <span title="Amarilla" className="hq-crest-cut h-3.5 w-2.5 bg-hq-gold" />
            )}
            {secondYellow && (
                <span title="Doble amarilla" className="relative inline-block h-3.5 w-4">
                    <span className="hq-crest-cut absolute top-0.5 left-0 h-3 w-2 bg-hq-gold/60" />
                    <span className="hq-crest-cut absolute top-0 left-1.5 h-3 w-2 bg-hq-gold" />
                </span>
            )}
            {red && <span title="Roja" className="hq-crest-cut h-3.5 w-2.5 bg-hq-live" />}
            {penaltyWon > 0 && (
                <EventGlyph count={penaltyWon} title="Provoca penalti">
                    <span className="border border-hq-gold px-1 py-px font-mono text-[9px] font-bold text-hq-gold">
                        P+
                    </span>
                </EventGlyph>
            )}
            {penaltyConceded > 0 && (
                <EventGlyph count={penaltyConceded} title="Comete penalti">
                    <span className="border border-hq-ember px-1 py-px font-mono text-[9px] font-bold text-hq-ember">
                        P−
                    </span>
                </EventGlyph>
            )}
            {penaltyMissed > 0 && (
                <EventGlyph count={penaltyMissed} title="Penalti fallado">
                    <span className="border border-hq-live px-1 py-px font-mono text-[9px] font-bold text-hq-live">
                        P✗
                    </span>
                </EventGlyph>
            )}
            {penaltySaved > 0 && (
                <EventGlyph count={penaltySaved} title="Penalti parado">
                    <span className="border border-hq-lime px-1 py-px font-mono text-[9px] font-bold text-hq-lime">
                        P✓
                    </span>
                </EventGlyph>
            )}
            {cleanSheet && (
                <span title="Portería a cero" className="border border-hq-lime px-1 py-px font-mono text-[9px] font-bold text-hq-lime">
                    0
                </span>
            )}
        </div>
    );
}
