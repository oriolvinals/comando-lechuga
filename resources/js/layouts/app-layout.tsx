import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { MainNav } from '@/components/main-nav';
import { cn } from '@/lib/utils';
import { home } from '@/routes';

export default function AppLayout({ children }: PropsWithChildren) {
    const { season, liveMatchday } = usePage().props;

    return (
        <div className="min-h-screen bg-white text-neutral-900">
            <header className="sticky top-0 z-50 border-b border-hq-border bg-hq-ink">
                <div className="mx-auto flex max-w-7xl items-center gap-4 px-6 py-2.5">
                    <Link
                        href={home().url}
                        className="shrink-0 font-display text-lg tracking-wide text-hq-paper uppercase transition-opacity hover:opacity-80"
                    >
                        Comando <span className="text-hq-lime">Lechuga</span>
                    </Link>
                    <span
                        className={cn(
                            'hidden shrink-0 items-center gap-1.5 border-l border-hq-border-strong pl-4 font-mono text-[10px] font-bold tracking-widest uppercase sm:flex',
                            liveMatchday ? 'text-hq-live' : 'text-hq-moss-dim',
                        )}
                    >
                        <span
                            className={cn(
                                'h-1.5 w-1.5 rounded-full',
                                liveMatchday
                                    ? 'animate-pulse bg-hq-live'
                                    : 'bg-hq-moss-dim',
                            )}
                        />
                        <span className="text-hq-khaki">
                            J{String(season.current_week).padStart(2, '0')}
                        </span>
                        <span className="text-hq-border-strong">·</span>
                        {liveMatchday ? 'Online' : 'Offline'}
                    </span>
                    <div className="ml-auto">
                        <MainNav />
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-7xl px-6">{children}</main>
        </div>
    );
}
