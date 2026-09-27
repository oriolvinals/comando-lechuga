import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

type RefreshStatus = 'idle' | 'loading' | 'refreshed';

// Local reloads can resolve in a few ms, which lets React batch the
// 'loading' and result updates into one paint and skip the spin entirely.
const MIN_LOADING_MS = 450;
const RESULT_FLASH_MS = 1600;

export function HqFixtureRefreshButton({ only }: { only: string[] }) {
    const [status, setStatus] = useState<RefreshStatus>('idle');
    const pendingTimeout = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(pendingTimeout.current), []);

    const handleRefresh = () => {
        if (status === 'loading') {
            return;
        }

        const startedAt = Date.now();
        setStatus('loading');

        const settle = (next: RefreshStatus) => {
            clearTimeout(pendingTimeout.current);
            const delay = Math.max(
                0,
                MIN_LOADING_MS - (Date.now() - startedAt),
            );
            pendingTimeout.current = setTimeout(() => {
                setStatus(next);

                if (next !== 'idle') {
                    pendingTimeout.current = setTimeout(
                        () => setStatus('idle'),
                        RESULT_FLASH_MS,
                    );
                }
            }, delay);
        };

        router.reload({
            only,
            onSuccess: () => settle('refreshed'),
            onError: () => settle('idle'),
        });
    };

    return (
        <button
            type="button"
            onClick={handleRefresh}
            disabled={status === 'loading'}
            title="Actualizar partido"
            aria-label="Actualizar partido"
            className={cn(
                'inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center border bg-hq-ink transition-colors sm:h-[30px] sm:w-8',
                status === 'loading' &&
                    'cursor-not-allowed border-hq-border-strong text-hq-moss',
                status === 'refreshed' &&
                    'border-hq-lime bg-hq-lime/10 text-hq-lime',
                status === 'idle' &&
                    'border-hq-border-strong text-hq-moss hover:border-hq-lime hover:text-hq-lime',
            )}
        >
            <RefreshCw
                aria-hidden="true"
                className={cn(
                    'h-3.5 w-3.5',
                    status === 'loading' && 'animate-spin',
                )}
            />
        </button>
    );
}
