import { useEffect, useState } from 'react';

/**
 * Ticking "now" (ms), safe to read during render — reading `Date.now()`
 * directly in a component body is impure and fails the React Compiler's
 * purity check.
 */
export function useNow(intervalMs = 1000): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const interval = setInterval(() => setNow(Date.now()), intervalMs);

        return () => clearInterval(interval);
    }, [intervalMs]);

    return now;
}
