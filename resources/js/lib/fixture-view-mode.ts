const FIXTURE_VIEW_MODE_KEY = 'fixtureViewMode';

export type FixtureViewMode = 'pitch' | 'list';

// A single global preference (campo/lista), not scoped per match — whichever
// view you last picked on any fixture ficha is what the next one opens with.
export function setStoredFixtureViewMode(mode: FixtureViewMode) {
    try {
        localStorage.setItem(FIXTURE_VIEW_MODE_KEY, mode);
    } catch {
        // Storage unavailable (private browsing, disabled, etc.) — not worth surfacing.
    }
}

export function getStoredFixtureViewMode(): FixtureViewMode {
    try {
        return localStorage.getItem(FIXTURE_VIEW_MODE_KEY) === 'list' ? 'list' : 'pitch';
    } catch {
        return 'pitch';
    }
}
