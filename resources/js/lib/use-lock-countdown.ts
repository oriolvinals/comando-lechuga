import { formatRelativeTime } from '@/lib/format';

/**
 * Relative day text ("dentro de 8 días") while 24h+ remain, switching to a
 * live-ticking HH:MM:SS countdown once inside the final day. Used for both
 * a locked buyout clause (`buyout_clause_locked_until`) and a shield
 * (`shielded_until`) — pass whichever deadline applies; `null` (e.g. a
 * shielded row synced before `shielded_until` existed) reads as expired.
 */
export function useLockCountdown(
    targetIso: string | null,
    now: number,
): string {
    if (targetIso === null) {
        return 'Disponible';
    }

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
