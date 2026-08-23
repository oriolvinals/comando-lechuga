import type { LucideIcon } from 'lucide-react';
import type { CSSProperties } from 'react';
import { cn } from '@/lib/utils';

interface EntityImageProps {
    src: string;
    alt: string;
    fallback: LucideIcon;
    className?: string;
    shape?: 'circle' | 'square';
    style?: CSSProperties;
}

export function EntityImage({
    src,
    alt,
    fallback: Fallback,
    className,
    shape = 'circle',
    style,
}: EntityImageProps) {
    if (!src) {
        return (
            <span
                style={style}
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

    if (shape === 'circle') {
        return (
            <span
                style={style}
                className={cn(
                    'inline-block overflow-hidden rounded-full',
                    className,
                )}
            >
                <img
                    src={src}
                    alt={alt}
                    className="h-full w-full scale-125 object-cover object-bottom"
                />
            </span>
        );
    }

    return (
        <img
            src={src}
            alt={alt}
            style={style}
            className={cn('rounded-md object-contain', className)}
        />
    );
}
