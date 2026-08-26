import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface HqScrollRowProps {
    children: ReactNode;
    className?: string;
    contentClassName?: string;
}

export function HqScrollRow({
    children,
    className,
    contentClassName,
}: HqScrollRowProps) {
    const scrollerRef = useRef<HTMLDivElement>(null);
    const [scrollStart, setScrollStart] = useState(0);
    const [visibleFraction, setVisibleFraction] = useState(1);

    const updateProgress = useCallback(() => {
        const el = scrollerRef.current;

        if (!el) {
            return;
        }

        const max = el.scrollWidth - el.clientWidth;
        setScrollStart(max > 0 ? el.scrollLeft / max : 0);
        setVisibleFraction(
            el.scrollWidth > 0 ? el.clientWidth / el.scrollWidth : 1,
        );
    }, []);

    useEffect(() => {
        updateProgress();

        const el = scrollerRef.current;

        if (!el) {
            return;
        }

        el.addEventListener('scroll', updateProgress, { passive: true });
        window.addEventListener('resize', updateProgress);

        return () => {
            el.removeEventListener('scroll', updateProgress);
            window.removeEventListener('resize', updateProgress);
        };
    }, [updateProgress]);

    const scrollByCard = (direction: 1 | -1) => {
        const el = scrollerRef.current;

        if (!el) {
            return;
        }

        const firstCard = el.firstElementChild as HTMLElement | null;
        const gap = parseFloat(getComputedStyle(el).columnGap || '0') || 0;
        const step = (firstCard?.offsetWidth ?? el.clientWidth * 0.8) + gap;

        el.scrollBy({ left: direction * step, behavior: 'smooth' });
    };

    const showControls = visibleFraction < 1;

    return (
        <div className={cn('relative', className)}>
            {showControls && (
                <button
                    type="button"
                    onClick={() => scrollByCard(-1)}
                    aria-label="Anterior"
                    className="absolute top-1/2 -left-3.5 z-10 hidden h-8 w-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full border border-hq-border-strong bg-hq-ink text-hq-paper shadow-lg transition-colors hover:border-hq-lime hover:text-hq-lime sm:flex"
                >
                    <ChevronLeft className="h-4 w-4" />
                </button>
            )}

            <div
                ref={scrollerRef}
                className={cn(
                    'flex [scrollbar-width:none] gap-2 overflow-x-auto scroll-smooth [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden',
                    contentClassName,
                )}
            >
                {children}
            </div>

            {showControls && (
                <button
                    type="button"
                    onClick={() => scrollByCard(1)}
                    aria-label="Siguiente"
                    className="absolute top-1/2 -right-3.5 z-10 hidden h-8 w-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full border border-hq-border-strong bg-hq-ink text-hq-paper shadow-lg transition-colors hover:border-hq-lime hover:text-hq-lime sm:flex"
                >
                    <ChevronRight className="h-4 w-4" />
                </button>
            )}

            {showControls && (
                <div className="relative mt-2.5 h-0.5 overflow-hidden bg-hq-border">
                    <div
                        className="absolute top-0 h-full bg-hq-lime"
                        style={{
                            width: `${visibleFraction * 100}%`,
                            left: `${scrollStart * (1 - visibleFraction) * 100}%`,
                        }}
                    />
                </div>
            )}
        </div>
    );
}
