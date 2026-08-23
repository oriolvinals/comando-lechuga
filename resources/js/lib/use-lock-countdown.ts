import { useEffect, useState } from 'react';
import { formatRelativeTime } from '@/lib/format';

/**
 * Relative day text ("dentro de 8 días") while 24h+ remain, switching to a
 * live-ticking HH:MM:SS countdown once inside the final day — same rule for
 * both a locked buyout clause and a shield, since both key off
 * `buyout_clause_locked_until`.
 */
export function useLockCountdown(targetIso: string): string {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const interval = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(interval);
    }, []);

    const diffMs = new Date(targetIso).getTime() - now;

    if (diffMs <= 0) {
        return 'Disponible';
    }

    if (diffMs >= 86_400_000) {
        return formatRelativeTime(targetIso);
    }

    const totalSeconds = Math.floor(diffMs / 1000);
    const pad = (value: number) => String(value).padStart(2, '0');
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
}
