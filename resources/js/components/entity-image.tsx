import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

interface EntityImageProps {
    src: string;
    alt: string;
    fallback: LucideIcon;
    className?: string;
    shape?: 'circle' | 'square';
}

export function EntityImage({
    src,
    alt,
    fallback: Fallback,
    className,
    shape = 'circle',
}: EntityImageProps) {
    if (!src) {
        return (
            <span
                className={cn(
                    'inline-flex items-center justify-center bg-neutral-100 text-neutral-400',
                    shape === 'circle' ? 'rounded-full' : 'rounded-md',
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
            className={cn(
                shape === 'circle'
                    ? 'rounded-full object-cover'
                    : 'rounded-md object-contain',
                className,
            )}
        />
    );
}
