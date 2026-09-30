import { usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { JornadaSheetProvider } from '@/components/hq-jornada-sheet';
import { HqLiveSignal } from '@/components/hq-live-signal';
import { HqTicker } from '@/components/hq-ticker';
import { HqWordmark } from '@/components/hq-wordmark';
import { MainNav, MobileBottomNav } from '@/components/main-nav';
import { openPlayerSearch } from '@/lib/player-search';
import { useShellShortcuts } from '@/lib/use-shell-shortcuts';
import { cn } from '@/lib/utils';

function GodChip({ compact = false }: { compact?: boolean }) {
    return (
        <span
            className={cn(
                'flex items-center gap-1.5 font-mono leading-none font-bold tracking-[0.1em] text-hq-amber hq-hazard',
                compact
                    ? 'h-6 border border-hq-amber/50 px-[7px] text-[10px]'
                    : 'h-full border-l border-hq-border px-3 text-[11px]',
            )}
        >
            <i aria-hidden="true" className="block size-[7px] bg-hq-amber" />
            GOD
        </span>
    );
}

/**
 * The "Sala de mando" console shell: the teletipo strip, a sticky ruled top
 * bar (wordmark, live signal, section nav, player search, god-mode chip),
 * the page inside a
 * 1440px ruled frame, a status line on desktop, and a bottom bar with a
 * "Más" sheet on phones and tablets. The page sits inside the app-wide
 * jornada sheet (JornadaSheetProvider), so any jornada score can open it.
 */
export default function AppLayout({ children }: PropsWithChildren) {
    const { season, godMode } = usePage().props;

    useShellShortcuts();

    return (
        <div className="flex min-h-screen flex-col bg-hq-ink text-hq-paper">
            <HqTicker />

            <header className="sticky top-0 z-40 hidden h-(--hq-header-h) border-b border-hq-border-strong bg-hq-ink/94 backdrop-blur-[6px] lg:block">
                <div className="mx-auto flex h-full w-full max-w-[1440px] items-stretch min-[1441px]:border-x min-[1441px]:border-hq-border">
                    <HqWordmark className="border-r border-hq-border px-5 text-[21px]" />
                    <HqLiveSignal className="border-r border-hq-border px-4" />
                    <MainNav />
                    <div className="ml-auto flex items-stretch">
                        <button
                            type="button"
                            onClick={openPlayerSearch}
                            className="flex cursor-text items-center gap-2 border-l border-hq-border px-3.5 font-mono text-[13px] leading-none font-medium text-hq-moss hover:text-hq-paper 2xl:min-w-60"
                        >
                            <Search
                                aria-hidden="true"
                                className="size-[15px]"
                            />
                            <span className="hidden 2xl:inline">
                                Buscar jugador…
                            </span>
                            <span className="sr-only 2xl:hidden">
                                Buscar jugador
                            </span>
                            <kbd className="ml-auto rounded-[2px] border border-hq-border-strong px-1 py-0.5 text-[10.5px] font-semibold text-hq-moss-dim">
                                /
                            </kbd>
                        </button>
                        {godMode && <GodChip />}
                    </div>
                </div>
            </header>

            <header className="sticky top-0 z-40 flex h-(--hq-header-h) items-center justify-between gap-2.5 border-b border-hq-border-strong bg-hq-ink/96 px-3.5 backdrop-blur-[6px] lg:hidden">
                <HqWordmark className="text-[19px]" />
                <div className="flex items-center gap-2">
                    <HqLiveSignal compact />
                    {godMode && <GodChip compact />}
                    <button
                        type="button"
                        onClick={openPlayerSearch}
                        aria-label="Buscar jugador"
                        className="flex size-11 cursor-pointer items-center justify-center border border-hq-border-strong text-hq-moss hover:border-hq-lime hover:text-hq-lime"
                    >
                        <Search aria-hidden="true" className="size-[15px]" />
                    </button>
                </div>
            </header>

            <main className="mx-auto flex w-full max-w-[1440px] min-w-0 flex-1 flex-col min-[1441px]:border-x min-[1441px]:border-hq-border">
                <JornadaSheetProvider>{children}</JornadaSheetProvider>
            </main>

            <footer className="hidden h-7 border-t border-hq-border bg-hq-well lg:block">
                <div className="mx-auto flex h-full w-full max-w-[1440px] items-center gap-[18px] overflow-hidden px-4 font-mono text-[11px] leading-none font-medium tracking-[0.04em] whitespace-nowrap text-hq-moss-dim min-[1441px]:border-x min-[1441px]:border-hq-border">
                    <span>SALA DE MANDO</span>
                    <span>
                        Temporada{' '}
                        <b className="font-semibold text-hq-moss">
                            {season.name}
                        </b>
                    </span>
                    <span>
                        Jornada{' '}
                        <b className="font-semibold text-hq-moss">
                            {season.current_week}/{season.total_weeks}
                        </b>
                    </span>
                    <span className="ml-auto">
                        atajos <b className="font-semibold text-hq-moss">1–5</b>{' '}
                        navegar ·{' '}
                        <b className="font-semibold text-hq-moss">/</b> buscar
                    </span>
                </div>
            </footer>

            <MobileBottomNav />
        </div>
    );
}
