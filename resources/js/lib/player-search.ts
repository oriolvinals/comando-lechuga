import { router } from '@inertiajs/react';
import { index as playersIndex } from '@/routes/players';

/** DOM id of the Jugadores search box — the shell's "Buscar jugador…" focuses it. */
export const PLAYER_SEARCH_INPUT_ID = 'player-search';

function focusPlayerSearchInput(): boolean {
    const input = document.getElementById(PLAYER_SEARCH_INPUT_ID);

    if (!(input instanceof HTMLInputElement)) {
        return false;
    }

    input.focus();
    input.select();

    return true;
}

/** Focuses the Jugadores search box, visiting the Jugadores page first when needed. */
export function openPlayerSearch(): void {
    if (focusPlayerSearchInput()) {
        return;
    }

    router.visit(playersIndex().url, {
        onSuccess: () => {
            requestAnimationFrame(() => focusPlayerSearchInput());
        },
    });
}
