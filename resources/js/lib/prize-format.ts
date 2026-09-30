import type { PrizeRowData, PrizeStanding } from '@/types/prizes';

const MILLIONS_FORMAT = new Intl.NumberFormat('es-ES', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
    useGrouping: 'always',
});

/** "Cruza FC" → "Cruza", "CID F.C" → "CID": the club suffix adds nothing in tight lists. */
export function shortManagerName(name: string): string {
    return name.replace(/\s+F\.?\s?C\.?$/i, '').trim() || name;
}

function times(count: number, one: string, many: string): string {
    return count === 1 ? one : many;
}

/** Euros → "181,7" (millions, one decimal). */
export function millions(euros: number): string {
    return MILLIONS_FORMAT.format(euros / 1_000_000);
}

/** The leader's big LED value and its small unit. */
export function leaderValue(
    prize: PrizeStanding,
    row: PrizeRowData,
): { big: string; unit: string } {
    const value = row.value ?? 0;

    switch (prize.key) {
        case 'best_night':
            return { big: String(value), unit: 'pts' };
        case 'most_buyouts_made':
        case 'most_buyouts_suffered':
            return {
                big: String(value),
                unit: times(value, 'cláusula', 'cláusulas'),
            };
        case 'sunday_king':
        case 'worst_weeks':
            return { big: String(value), unit: times(value, 'vez', 'veces') };
        case 'bench_points':
            return { big: String(value), unit: 'pts sin alinear' };
        case 'most_overpaid':
            return { big: millions(value), unit: 'M€ de más' };
        case 'longest_partnership':
            return { big: String(value), unit: 'jornadas seguidas' };
        default:
            return { big: String(value), unit: '' };
    }
}

/** The short value in the list of the others ("—" when a count is 0, "no lo tuvo" without a value). */
export function restValue(
    prize: PrizeStanding,
    row: PrizeRowData,
): { text: string; muted: boolean } {
    if (row.value === null) {
        return {
            text: prize.key === 'most_owned_player' ? 'no lo tuvo' : '—',
            muted: true,
        };
    }

    switch (prize.key) {
        case 'best_night':
            return {
                text: `${row.value} · J${row.context.week_number}`,
                muted: false,
            };
        case 'most_buyouts_made':
        case 'most_buyouts_suffered':
        case 'sunday_king':
        case 'worst_weeks':
            return row.value === 0
                ? { text: '—', muted: true }
                : { text: String(row.value), muted: false };
        case 'most_overpaid':
            return { text: `${millions(row.value)} M€`, muted: false };
        case 'most_owned_player':
            return { text: `${row.value} jor.`, muted: false };
        default:
            return { text: String(row.value), muted: false };
    }
}
