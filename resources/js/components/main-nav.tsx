import { Link, usePage } from '@inertiajs/react';
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

export function MainNav() {
    const { url } = usePage();

    return (
        <nav aria-label="Principal" className="flex gap-1.5">
            {navItems.map((item) => {
                const path = url.split('?')[0].split('#')[0];
                const isActive =
                    path === item.href || path.startsWith(`${item.href}/`);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={isActive ? 'page' : undefined}
                        className={cn(
                            'border px-3.5 py-1.5 font-mono text-xs font-bold tracking-wider uppercase transition-colors',
                            isActive
                                ? 'border-hq-lime bg-hq-lime text-hq-ink'
                                : 'border-hq-border text-hq-moss hover:border-hq-border-strong hover:text-hq-paper',
                        )}
                    >
                        {item.label}
                    </Link>
                );
            })}
        </nav>
    );
}
