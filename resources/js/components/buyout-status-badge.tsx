import { Lock, ShieldCheck, Unlock } from 'lucide-react';
import { formatRelativeTime, isFutureDate } from '@/lib/format';
import { cn } from '@/lib/utils';

interface BuyoutStatusBadgeProps {
    shielded: boolean;
    buyoutClauseLockedUntil: string;
}

export function BuyoutStatusBadge({
    shielded,
    buyoutClauseLockedUntil,
}: BuyoutStatusBadgeProps) {
    if (shielded) {
        return (
            <span className="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600">
                <ShieldCheck className="h-4 w-4" />
                Blindado
            </span>
        );
    }

    const isLocked = isFutureDate(buyoutClauseLockedUntil);

    if (isLocked) {
        return (
            <span
                className={cn(
                    'inline-flex items-center gap-1.5 text-sm font-medium text-amber-600',
                )}
            >
                <Lock className="h-4 w-4" />
                No disponible ({formatRelativeTime(buyoutClauseLockedUntil)})
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600">
            <Unlock className="h-4 w-4" />
            Disponible
        </span>
    );
}
