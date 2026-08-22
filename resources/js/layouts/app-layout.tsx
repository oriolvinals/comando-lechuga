import type { PropsWithChildren } from 'react';
import { MainNav } from '@/components/main-nav';

export default function AppLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-screen bg-white text-neutral-900">
            <header className="border-b border-neutral-200">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                    <span className="text-lg font-semibold">Comando Lechuga</span>
                    <MainNav />
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-6 py-10">{children}</main>
        </div>
    );
}
