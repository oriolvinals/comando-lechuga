import { Ban, CircleCheck, CircleHelp, Cross, UserX } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { STATUS_COLORS, STATUS_LABELS } from '@/lib/player-labels';
import type { PlayerStatus } from '@/types/models';

const STATUS_ICONS: Record<PlayerStatus, LucideIcon> = {
    ok: CircleCheck,
    injured: Cross,
    suspended: Ban,
    doubtful: CircleHelp,
    out_of_league: UserX,
};

interface StatusBadgeProps {
    status: PlayerStatus;
}

export function StatusBadge({ status }: StatusBadgeProps) {
    const Icon = STATUS_ICONS[status];

    return (
        <span title={STATUS_LABELS[status]} className="inline-flex">
            <Icon className={`h-4 w-4 ${STATUS_COLORS[status]}`} />
        </span>
    );
}
