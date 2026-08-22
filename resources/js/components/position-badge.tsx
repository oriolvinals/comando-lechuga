import {
    POSITION_ABBREVIATIONS,
    POSITION_BADGE_STYLES,
    POSITION_LABELS,
} from '@/lib/player-labels';
import type { PlayerPosition } from '@/types/models';

interface PositionBadgeProps {
    position: PlayerPosition;
}

export function PositionBadge({ position }: PositionBadgeProps) {
    return (
        <span
            title={POSITION_LABELS[position]}
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${POSITION_BADGE_STYLES[position]}`}
        >
            {POSITION_ABBREVIATIONS[position]}
        </span>
    );
}
