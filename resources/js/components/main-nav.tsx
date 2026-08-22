import { Link, usePage } from '@inertiajs/react';
import { home } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import { index as playersIndex } from '@/routes/players';
import { index as seasonTeamsIndex } from '@/routes/season-teams';
import { cn } from '@/lib/utils';

const navItems = [
    { label: 'Inicio', href: home().url },
    { label: 'Equipos', href: seasonTeamsIndex().url },
    { label: 'Jugadores', href: playersIndex().url },
    { label: 'Actividad', href: activityIndex().url },
];

export function MainNav() {
    const { url } = usePage();

    return (
        <nav aria-label="Principal" className="flex gap-1">
            {navItems.map((item) => {
                const isActive = item.href === '/' ? url === '/' : url.startsWith(item.href);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={isActive ? 'page' : undefined}
                        className={cn(
                            'rounded-md px-3 py-2 text-sm font-medium transition-colors',
                            isActive ? 'bg-neutral-900 text-white' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900',
                        )}
                    >
                        {item.label}
                    </Link>
                );
            })}
        </nav>
    );
}
