import { Link, usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import { index as playersIndex } from '@/routes/players';
import { index as seasonTeamsIndex } from '@/routes/season-teams';

const navItems = [
    { label: 'Inicio', href: home().url },
    { label: 'Equipos', href: seasonTeamsIndex().url },
    { label: 'Jugadores', href: playersIndex().url },
    { label: 'Actividad', href: activityIndex().url },
];

function pillClassName(isActive: boolean) {
    return cn(
        'border px-3.5 py-1.5 font-mono text-xs font-bold tracking-wider uppercase transition-colors',
        isActive
            ? 'border-hq-lime bg-hq-lime text-hq-ink'
            : 'border-hq-border text-hq-moss hover:border-hq-border-strong hover:text-hq-paper',
    );
}

export function MainNav() {
    const { url } = usePage();
    const [open, setOpen] = useState(false);

    const path = url.split('?')[0].split('#')[0];
    const items = navItems.map((item) => ({
        ...item,
        isActive: path === item.href || path.startsWith(`${item.href}/`),
    }));

    return (
        <div className="relative">
            <nav aria-label="Principal" className="hidden gap-1.5 sm:flex">
                {items.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={item.isActive ? 'page' : undefined}
                        className={pillClassName(item.isActive)}
                    >
                        {item.label}
                    </Link>
                ))}
            </nav>

            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                aria-label={open ? 'Cerrar menú' : 'Abrir menú'}
                className="cursor-pointer border border-hq-border p-2 text-hq-paper sm:hidden"
            >
                {open ? (
                    <X className="h-4 w-4" />
                ) : (
                    <Menu className="h-4 w-4" />
                )}
            </button>

            {open && (
                <nav
                    aria-label="Principal"
                    className="absolute top-full right-0 z-50 mt-2 flex w-48 flex-col gap-1.5 border border-hq-border bg-hq-panel p-2 sm:hidden"
                >
                    {items.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            aria-current={item.isActive ? 'page' : undefined}
                            onClick={() => setOpen(false)}
                            className={cn(
                                pillClassName(item.isActive),
                                'text-center',
                            )}
                        >
                            {item.label}
                        </Link>
                    ))}
                </nav>
            )}
        </div>
    );
}
