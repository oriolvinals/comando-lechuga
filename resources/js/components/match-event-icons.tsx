import type { JornadaStats, PlayerPosition } from '@/types/models';

interface MatchEventIconsProps {
    stats: JornadaStats;
    position: PlayerPosition;
}

function statCount(stats: JornadaStats, key: string): number {
    return stats[key]?.[0] ?? 0;
}

export function MatchEventIcons({ stats, position }: MatchEventIconsProps) {
    const goals = statCount(stats, 'goals');
    const ownGoals = statCount(stats, 'own_goals');
    const assists = statCount(stats, 'goal_assist');
    const secondYellow = statCount(stats, 'second_yellow_card') > 0;
    const yellow = statCount(stats, 'yellow_card') > 0 && !secondYellow;
    const red = statCount(stats, 'red_card') > 0 && !secondYellow;
    const penaltyWon = statCount(stats, 'penalty_won') > 0;
    const penaltyConceded = statCount(stats, 'penalty_conceded') > 0;
    const penaltyMissed = statCount(stats, 'penalty_failed') > 0;
    const penaltySaved = statCount(stats, 'penalty_save') > 0;
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
        penaltyWon ||
        penaltyConceded ||
        penaltyMissed ||
        penaltySaved ||
        cleanSheet;

    if (!hasAnyEvent) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {Array.from({ length: goals }, (_, i) => (
                <span key={`goal-${i}`} className="text-[13px] leading-none">
                    ⚽
                </span>
            ))}
            {Array.from({ length: ownGoals }, (_, i) => (
                <span
                    key={`own-goal-${i}`}
                    className="border border-hq-live px-1 py-px font-mono text-[9px] font-bold text-hq-live"
                >
                    PP
                </span>
            ))}
            {Array.from({ length: assists }, (_, i) => (
                <span
                    key={`assist-${i}`}
                    className="text-[13px] leading-none text-hq-med"
                >
                    ➜
                </span>
            ))}
            {yellow && (
                <span className="hq-crest-cut h-3.5 w-2.5 bg-hq-gold" />
            )}
            {secondYellow && (
                <span className="relative inline-block h-3.5 w-4">
                    <span className="hq-crest-cut absolute top-0.5 left-0 h-3 w-2 bg-hq-gold/60" />
                    <span className="hq-crest-cut absolute top-0 left-1.5 h-3 w-2 bg-hq-gold" />
                </span>
            )}
            {red && <span className="hq-crest-cut h-3.5 w-2.5 bg-hq-live" />}
            {penaltyWon && (
                <span className="border border-hq-gold px-1 py-px font-mono text-[9px] font-bold text-hq-gold">
                    P+
                </span>
            )}
            {penaltyConceded && (
                <span className="border border-hq-ember px-1 py-px font-mono text-[9px] font-bold text-hq-ember">
                    P−
                </span>
            )}
            {penaltyMissed && (
                <span className="border border-hq-live px-1 py-px font-mono text-[9px] font-bold text-hq-live">
                    P✗
                </span>
            )}
            {penaltySaved && (
                <span className="border border-hq-lime px-1 py-px font-mono text-[9px] font-bold text-hq-lime">
                    P✓
                </span>
            )}
            {cleanSheet && (
                <span className="border border-hq-lime px-1 py-px font-mono text-[9px] font-bold text-hq-lime">
                    0
                </span>
            )}
        </div>
    );
}
