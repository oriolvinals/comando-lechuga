import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { MAIN_NAV_ITEMS } from '@/components/main-nav';
import { openPlayerSearch } from '@/lib/player-search';

function isTypingTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.isContentEditable ||
        ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName)
    );
}

/**
 * Global console shortcuts: 1–5 jump to a section, "/" opens the player
 * search. Ignored while typing in a field or with a modifier key held.
 */
export function useShellShortcuts(): void {
    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            if (
                event.defaultPrevented ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                isTypingTarget(event.target)
            ) {
                return;
            }

            if (event.key === '/') {
                event.preventDefault();
                openPlayerSearch();

                return;
            }

            const item = MAIN_NAV_ITEMS.find(
                (navItem) => navItem.shortcut === event.key,
            );

            if (item) {
                router.visit(item.href);
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, []);
}
