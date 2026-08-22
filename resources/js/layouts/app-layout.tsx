import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { MainNav } from '@/components/main-nav';

export default function AppLayout({ children }: PropsWithChildren) {
    const { season } = usePage().props;

    return (
        <div className="min-h-screen bg-white text-neutral-900">
            <header className="bg-hq-ink">
                <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-3 font-mono text-[11px] tracking-widest text-hq-moss uppercase">
                    <span>Temporada {season.name}</span>
                    <span className="flex items-center gap-1.5 font-bold text-hq-lime">
                        <span className="h-1.5 w-1.5 rounded-full bg-hq-lime" />
                        En directo
                    </span>
                </div>
                <div className="mx-auto flex max-w-7xl items-center justify-between border-t border-hq-border px-6 py-3">
                    <span className="font-display text-xl tracking-wide text-hq-paper uppercase">
                        Comando <span className="text-hq-lime">Lechuga</span>
                    </span>
                    <MainNav />
                </div>
            </header>
            <main className="mx-auto max-w-7xl px-6">{children}</main>
        </div>
    );
}
