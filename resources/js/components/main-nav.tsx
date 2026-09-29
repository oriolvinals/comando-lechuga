import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowLeftRight,
    CalendarDays,
    Ellipsis,
    House,
    Shield,
    Trophy,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import { index as fixturesIndex } from '@/routes/fixtures';
import { index as playersIndex } from '@/routes/players';
import { index as seasonManagersIndex } from '@/routes/season-managers';
import { index as teamsIndex } from '@/routes/teams';

export interface MainNavItem {
    label: string;
    href: string;
    /** Keyboard shortcut (1–5) shown as a <kbd> on desktop. */
    shortcut: string;
    icon: LucideIcon;
}

export const MAIN_NAV_ITEMS: MainNavItem[] = [
    {
        label: 'Managers',
        href: seasonManagersIndex().url,
        shortcut: '1',
        icon: Trophy,
    },
    { label: 'Equipos', href: teamsIndex().url, shortcut: '2', icon: Shield },
    {
        label: 'Jugadores',
        href: playersIndex().url,
        shortcut: '3',
        icon: Users,
    },
    {
        label: 'Partidos',
        href: fixturesIndex().url,
        shortcut: '4',
        icon: CalendarDays,
    },
    {
        label: 'Actividad',
        href: activityIndex().url,
        shortcut: '5',
        icon: ArrowLeftRight,
    },
];

/** Sections that live behind "Más" in the phone bottom bar. */
const SHEET_HREFS = [teamsIndex().url, activityIndex().url];

function useCurrentPath(): string {
    const { url } = usePage();

    return url.split('?')[0].split('#')[0];
}

function isSectionActive(path: string, href: string): boolean {
    return path === href || path.startsWith(`${href}/`);
}

/** Desktop console nav: ruled cells sharing the bar's free width, <kbd> shortcut, active cell filled lime. */
export function MainNav() {
    const path = useCurrentPath();

    return (
        <nav aria-label="Principal" className="flex min-w-0 flex-1">
            {MAIN_NAV_ITEMS.map((item) => {
                const isActive = isSectionActive(path, item.href);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={isActive ? 'page' : undefined}
                        className={cn(
                            'group flex flex-1 items-center justify-center gap-2 border-r border-hq-border px-[11px] text-[12.5px] leading-none font-bold tracking-[0.06em] uppercase xl:px-3.5 2xl:px-4',
                            isActive
                                ? 'bg-hq-lime text-hq-ink'
                                : 'text-hq-moss hover:bg-hq-panel hover:text-hq-paper',
                        )}
                    >
                        <kbd
                            className={cn(
                                'hidden rounded-[2px] border px-1 py-0.5 font-mono text-[10.5px] leading-none font-semibold xl:inline',
                                isActive
                                    ? 'border-hq-ink/50 text-hq-ink'
                                    : 'border-hq-border-strong text-hq-moss-dim',
                            )}
                        >
                            {item.shortcut}
                        </kbd>
                        {item.label}
                    </Link>
                );
            })}
        </nav>
    );
}

const BOTTOM_NAV_ITEMS: Pick<MainNavItem, 'label' | 'href' | 'icon'>[] = [
    { label: 'Inicio', href: home().url, icon: House },
    MAIN_NAV_ITEMS[0],
    MAIN_NAV_ITEMS[2],
    MAIN_NAV_ITEMS[3],
];

/**
 * Phone / tablet bottom bar: Inicio, Managers, Jugadores, Partidos and a
 * "Más" sheet with Equipos and Actividad. Sticky to the viewport bottom.
 */
export function MobileBottomNav() {
    const path = useCurrentPath();
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const isSheetSectionActive = SHEET_HREFS.some((href) =>
        isSectionActive(path, href),
    );

    useEffect(() => {
        if (!isSheetOpen) {
            return;
        }

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setIsSheetOpen(false);
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [isSheetOpen]);

    const cellClassName = (isActive: boolean) =>
        cn(
            'flex min-h-[52px] cursor-pointer flex-col items-center justify-center gap-1 border-r border-hq-border font-mono text-[10px] leading-none font-bold tracking-[0.04em] uppercase last:border-r-0',
            isActive ? 'bg-hq-lime text-hq-ink' : 'text-hq-moss',
        );

    return (
        <>
            {isSheetOpen && (
                <div
                    className="fixed inset-0 z-[150] cursor-pointer bg-black/60 lg:hidden"
                    onClick={() => setIsSheetOpen(false)}
                >
                    <nav
                        id="hq-more-sheet"
                        aria-label="Más secciones"
                        className="absolute inset-x-0 bottom-0 border-t border-hq-border-bright bg-hq-ink pt-1.5 pb-[calc(64px+env(safe-area-inset-bottom))]"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="px-[18px] py-2.5 font-mono text-[11px] font-medium tracking-[0.07em] text-hq-moss-dim uppercase">
                            Más secciones
                        </div>
                        {MAIN_NAV_ITEMS.filter((item) =>
                            SHEET_HREFS.includes(item.href),
                        ).map((item) => {
                            const isActive = isSectionActive(path, item.href);
                            const Icon = item.icon;

                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    aria-current={isActive ? 'page' : undefined}
                                    onClick={() => setIsSheetOpen(false)}
                                    className={cn(
                                        'flex items-center gap-3 border-b border-hq-border px-[18px] py-[15px] text-[15px] leading-none font-extrabold tracking-[0.03em] uppercase',
                                        isActive
                                            ? 'text-hq-lime'
                                            : 'text-hq-paper',
                                    )}
                                >
                                    <Icon
                                        aria-hidden="true"
                                        className="size-[18px] text-hq-lime"
                                    />
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>
                </div>
            )}

            <nav
                aria-label="Principal"
                className="sticky bottom-0 z-[160] grid grid-cols-5 border-t border-hq-border-strong bg-hq-well pb-[env(safe-area-inset-bottom)] lg:hidden"
            >
                {BOTTOM_NAV_ITEMS.map((item) => {
                    const isActive =
                        item.href === home().url
                            ? path === item.href
                            : isSectionActive(path, item.href);
                    const Icon = item.icon;

                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            aria-current={isActive ? 'page' : undefined}
                            onClick={() => setIsSheetOpen(false)}
                            className={cellClassName(isActive)}
                        >
                            <Icon aria-hidden="true" className="size-[19px]" />
                            {item.label}
                        </Link>
                    );
                })}
                <button
                    type="button"
                    aria-expanded={isSheetOpen}
                    aria-controls="hq-more-sheet"
                    onClick={() => setIsSheetOpen((open) => !open)}
                    className={cellClassName(
                        isSheetOpen || isSheetSectionActive,
                    )}
                >
                    <Ellipsis aria-hidden="true" className="size-[19px]" />
                    Más
                </button>
            </nav>
        </>
    );
}
