export function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('es-ES', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: 0,
    }).format(amount);
}

/** One decimal, dropped entirely when it rounds to a whole number (e.g. `11.5` stays, `4` not `4.0`). */
export function formatAverage(value: number): string {
    const rounded = Math.round(value * 10) / 10;

    return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
}

export function isFutureDate(isoDate: string): boolean {
    return new Date(isoDate).getTime() > Date.now();
}

export function formatMatchDateTime(isoDate: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(isoDate));
}

export function formatMatchDateShort(isoDate: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(isoDate));
}

export function formatTime(isoDate: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(isoDate));
}

export function formatFullDateTime(isoDate: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(new Date(isoDate));
}

const RELATIVE_TIME_UNITS: { unit: Intl.RelativeTimeFormatUnit; ms: number }[] =
    [
        { unit: 'year', ms: 1000 * 60 * 60 * 24 * 365 },
        { unit: 'month', ms: 1000 * 60 * 60 * 24 * 30 },
        { unit: 'day', ms: 1000 * 60 * 60 * 24 },
        { unit: 'hour', ms: 1000 * 60 * 60 },
        { unit: 'minute', ms: 1000 * 60 },
    ];

export function formatRelativeTime(isoDate: string): string {
    const diffMs = new Date(isoDate).getTime() - Date.now();
    const formatter = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });

    for (const { unit, ms } of RELATIVE_TIME_UNITS) {
        if (Math.abs(diffMs) >= ms) {
            return formatter.format(Math.round(diffMs / ms), unit);
        }
    }

    return formatter.format(Math.round(diffMs / 1000), 'second');
}
