import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { MainNav } from '@/components/main-nav';
import { cn } from '@/lib/utils';
import { home } from '@/routes';

export default function AppLayout({ children }: PropsWithChildren) {
    const { season, liveMatchday } = usePage().props;

    return (
        <div className="min-h-screen bg-white text-neutral-900">
            <header className="bg-hq-ink">
                <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-3 font-mono text-[11px] tracking-widest text-hq-moss uppercase">
                    <span>Temporada {season.name}</span>
                    <span
                        className={cn(
                            'flex items-center gap-1.5 font-bold',
                            liveMatchday
                                ? 'text-hq-live'
                                : 'text-hq-moss-dim',
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
                            Jornada{' '}
                            {String(season.current_week).padStart(2, '0')}
                        </span>
                        <span className="text-hq-border-strong">·</span>
                        {liveMatchday ? 'Online' : 'Offline'}
                    </span>
                </div>
                <div className="mx-auto flex max-w-7xl items-center justify-between border-t border-hq-border px-6 py-3">
                    <Link
                        href={home().url}
                        className="font-display text-xl tracking-wide text-hq-paper uppercase transition-opacity hover:opacity-80"
                    >
                        Comando <span className="text-hq-lime">Lechuga</span>
                    </Link>
                    <MainNav />
                </div>
            </header>
            <main className="mx-auto max-w-7xl px-6">{children}</main>
        </div>
    );
}
