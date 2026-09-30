import { useSyncExternalStore } from 'react';

/** The «Soy» picker: a per-viewer convenience only, never shared. */
const STORAGE_KEY = 'premios-me';
const NOBODY = 'none';
const CHANGE_EVENT = 'premios-me-change';

/** Not read yet: storage is only touched in the browser, on first use. */
let current: number | null | undefined;

function parse(raw: string | null): number | null {
    if (raw === null || raw === NOBODY) {
        return null;
    }

    const id = Number(raw);

    return Number.isInteger(id) && id > 0 ? id : null;
}

function snapshot(): number | null {
    if (current === undefined) {
        try {
            current = parse(window.localStorage.getItem(STORAGE_KEY));
        } catch {
            current = null;
        }
    }

    return current;
}

function subscribe(onChange: () => void): () => void {
    const onStorage = (event: StorageEvent) => {
        if (event.key === STORAGE_KEY) {
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

/** The highlighted manager id, or null for the neutral state. */
export function usePrizeViewer(): number | null {
    return useSyncExternalStore(subscribe, snapshot, () => null);
}

/** Selects a manager; selecting the highlighted one again clears it. */
export function togglePrizeViewer(id: number): void {
    current = snapshot() === id ? null : id;

    try {
        window.localStorage.setItem(
            STORAGE_KEY,
            current === null ? NOBODY : String(current),
        );
    } catch {
        // Private mode or blocked storage: the choice holds for this visit only.
    }

    window.dispatchEvent(new Event(CHANGE_EVENT));
}
