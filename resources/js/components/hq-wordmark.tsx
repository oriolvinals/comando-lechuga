import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { home } from '@/routes';

interface HqWordmarkProps {
    className?: string;
}

/** "COMANDO LECHUGA" in Anton, "Lechuga" in lime — links home. */
export function HqWordmark({ className }: HqWordmarkProps) {
    return (
        <Link
            href={home().url}
            className={cn(
                'flex items-center gap-[0.28em] font-wordmark leading-none tracking-[0.035em] whitespace-nowrap text-hq-paper uppercase transition-opacity hover:opacity-80',
                className,
            )}
        >
            Comando <span className="text-hq-lime">Lechuga</span>
        </Link>
    );
}
