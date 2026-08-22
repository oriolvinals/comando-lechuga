import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

interface EntityImageProps {
    src: string;
    alt: string;
    fallback: LucideIcon;
    className?: string;
}

export function EntityImage({
    src,
    alt,
    fallback: Fallback,
    className,
}: EntityImageProps) {
    if (!src) {
        return (
            <span
                className={cn(
                    'inline-flex items-center justify-center rounded-full bg-neutral-100 text-neutral-400',
                    className,
                )}
            >
                <Fallback className="h-1/2 w-1/2" />
            </span>
        );
    }

    return (
        <img
            src={src}
            alt={alt}
            className={cn('rounded-full object-cover', className)}
        />
    );
}
