import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { compare as playersCompare } from '@/routes/players';
import type { CompareView } from '@/types/models';

/** Most players the comparator takes at once. */
export const COMPARE_MAX = 3;

/** One colour per comparator slot, the same in the tray and every view (mock `--s0/--s1/--s2`). */
export const COMPARE_SLOT_COLORS = [
    'var(--color-hq-paper)',
    'var(--color-hq-azure)',
    'var(--color-hq-ember)',
] as const;

const SELECTION_KEY = 'cmp-ids';
const VIEW_KEY = 'cmp-vista';
const CHANGE_EVENT = 'cmp-selection-change';

/** What the tray needs to draw a chosen player without asking the server. */
export interface CompareEntry {
    id: number;
    name: string;
    image: string;
}

const EMPTY: CompareEntry[] = [];
let current: CompareEntry[] | null = null;

/** Tolerant of anything a previous version, another tab or a user left behind. */
function parse(raw: string | null): CompareEntry[] {
    if (raw === null) {
        return EMPTY;
    }

    try {
        const value: unknown = JSON.parse(raw);

        if (!Array.isArray(value)) {
            return EMPTY;
        }

        const entries: CompareEntry[] = [];

        for (const item of value) {
            if (typeof item !== 'object' || item === null) {
                continue;
            }

            const { id, name, image } = item as Record<string, unknown>;

            if (
                typeof id !== 'number' ||
                !Number.isInteger(id) ||
                id <= 0 ||
                typeof name !== 'string' ||
                typeof image !== 'string' ||
                entries.some((entry) => entry.id === id)
            ) {
                continue;
            }

            entries.push({ id, name, image });
        }

        return entries.slice(0, COMPARE_MAX);
    } catch {
        return EMPTY;
    }
}

function readStorage(key: string): string | null {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeStorage(key: string, value: string): void {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Private mode or blocked storage: the selection lives in memory only.
    }
}

function snapshot(): CompareEntry[] {
    if (current === null) {
        current = parse(readStorage(SELECTION_KEY));
    }

    return current;
}

function commit(next: CompareEntry[]): void {
    current = next.slice(0, COMPARE_MAX);
    writeStorage(SELECTION_KEY, JSON.stringify(current));
    window.dispatchEvent(new Event(CHANGE_EVENT));
}

function subscribe(onChange: () => void): () => void {
    const onStorage = (event: StorageEvent) => {
        if (event.key === SELECTION_KEY) {
            current = parse(event.newValue);
            onChange();
        }
    };

    window.addEventListener(CHANGE_EVENT, onChange);
    window.addEventListener('storage', onStorage);

    return () => {
        window.removeEventListener(CHANGE_EVENT, onChange);
        window.removeEventListener('storage', onStorage);
    };
}

/** The players chosen for the comparator, shared by every row, the tray and the comparator itself. */
export function useCompareSelection(): CompareEntry[] {
    return useSyncExternalStore(subscribe, snapshot, () => EMPTY);
}

export function toggleCompare(entry: CompareEntry): void {
    const entries = snapshot();

    if (entries.some((item) => item.id === entry.id)) {
        commit(entries.filter((item) => item.id !== entry.id));

        return;
    }

    if (entries.length < COMPARE_MAX) {
        commit([...entries, entry]);
    }
}

export function removeFromCompare(id: number): void {
    commit(snapshot().filter((entry) => entry.id !== id));
}

export function clearCompare(): void {
    commit([]);
}

/** Mirrors the comparator's players into the selection — a no-op when nothing changed. */
export function replaceCompare(entries: CompareEntry[]): void {
    const before = snapshot();
    const same =
        before.length === entries.length &&
        before.every(
            (entry, index) =>
                entry.id === entries[index].id &&
                entry.name === entries[index].name &&
                entry.image === entries[index].image,
        );

    if (!same) {
        commit(entries);
    }
}

export function rememberedCompareView(): CompareView | null {
    const view = readStorage(VIEW_KEY);

    return view === 'a' || view === 'b' || view === 'c' ? view : null;
}

export function rememberCompareView(view: CompareView): void {
    writeStorage(VIEW_KEY, view);
}

export function compareUrl(ids: number[], view?: CompareView): string {
    return playersCompare.url({
        query: {
            ids: ids.join(','),
            vista: view ?? rememberedCompareView() ?? undefined,
        },
    });
}

/**
 * The ficha's and the jornada modal's "Comparar": adds the player (taking
 * the last slot when all three are used) and opens the comparator as soon
 * as there are two; otherwise the tray stays up asking for another one.
 */
export function compareWith(entry: CompareEntry): 'opened' | 'waiting' {
    const entries = snapshot();
    const next = entries.some((item) => item.id === entry.id)
        ? entries
        : entries.length >= COMPARE_MAX
          ? [...entries.slice(0, COMPARE_MAX - 1), entry]
          : [...entries, entry];

    commit(next);

    if (next.length >= 2) {
        router.visit(compareUrl(next.map((item) => item.id)));

        return 'opened';
    }

    return 'waiting';
}
