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
                'flex h-6 w-7 items-center justify-center border border-hq-border-strong bg-hq-panel transition-colors',
                status === 'loading' && 'cursor-not-allowed text-hq-moss',
                status === 'refreshed' && 'border-hq-lime text-hq-lime',
                status === 'idle' && 'text-hq-moss hover:text-hq-paper',
            )}
        >
            <RefreshCw
                className={cn(
                    'h-3.5 w-3.5',
                    status === 'loading' && 'animate-spin',
                )}
            />
        </button>
    );
}
