/*
 * es-ES leaves 4-digit numbers ungrouped by default ("1570 €");
 * `useGrouping: 'always'` keeps them consistent with larger ones ("1.570 €").
 */
const CURRENCY_FORMAT = new Intl.NumberFormat('es-ES', {
    style: 'currency',
    currency: 'EUR',
    maximumFractionDigits: 0,
    useGrouping: 'always',
});

const NUMBER_FORMAT = new Intl.NumberFormat('es-ES', {
    maximumFractionDigits: 1,
    useGrouping: 'always',
});

export function formatCurrency(amount: number): string {
    return CURRENCY_FORMAT.format(amount);
}

const MILLIONS_FORMAT = new Intl.NumberFormat('es-ES', {
    maximumFractionDigits: 2,
});

/** An amount in millions for tight spots (`12,35 M€`). */
export function formatMillions(amount: number): string {
    return `${MILLIONS_FORMAT.format(amount / 1_000_000)} M€`;
}

/** A count with Spanish thousands dots, 4-digit ones included (`1.234`). */
export function formatNumber(value: number): string {
    return NUMBER_FORMAT.format(value);
}

/** One decimal with a Spanish comma, dropped when it rounds to a whole number (`11,5`, `4` not `4,0`). */
export function formatAverage(value: number): string {
    return NUMBER_FORMAT.format(Math.round(value * 10) / 10);
}

const DECIMAL_FORMAT = new Intl.NumberFormat('es-ES', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
    useGrouping: 'always',
});

/** Always two decimals with a Spanish comma, for rates and ratios (`2,29`, `1,00`). */
export function formatDecimal(value: number): string {
    return DECIMAL_FORMAT.format(value);
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

/** "sáb, 4 oct" — a kickoff's day without the time. */
export function formatMatchDay(isoDate: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(new Date(isoDate));
}

/** "10 sept" — a day without weekday or time. */
export function formatShortDay(isoDate: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        day: 'numeric',
        month: 'short',
    }).format(new Date(isoDate));
}

export function formatTime(isoDate: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(isoDate));
}

export function formatFullDateTime(isoDate: string): string {
    const date = new Date(isoDate);

    const datePart = new Intl.DateTimeFormat('es-ES', {
        day: '2-digit',
        month: '2-digit',
        year: '2-digit',
    }).format(date);
    const timePart = new Intl.DateTimeFormat('es-ES', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);

    return `${datePart} ${timePart}`;
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

/** Time left to unlock: "2 d 14 h 03 min", "5 h 07 min", and with seconds in the last hour: "42 min 07 s". */
export function formatUnlockCountdown(ms: number): string {
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const pad = (value: number) => String(value).padStart(2, '0');

    if (days > 0) {
        return `${days} d ${pad(hours)} h ${pad(minutes)} min`;
    }

    if (hours > 0) {
        return `${hours} h ${pad(minutes)} min`;
    }

    return `${minutes} min ${pad(seconds)} s`;
}
