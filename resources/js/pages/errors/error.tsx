import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { home } from '@/routes';

interface ErrorPageProps {
    status: number;
    [key: string]: unknown;
}

interface StatusCopy {
    accentText: string;
    accentBorder: string;
    tag: string;
    headline: string;
    sub: string;
}

const STATUS_COPY: Record<number, StatusCopy> = {
    404: {
        accentText: 'text-hq-lime',
        accentBorder: 'border-hq-lime',
        tag: 'Fuera de juego',
        headline: 'No hay nadie en esta posición',
        sub: 'Esta jugada no existe o se movió de sitio. Revisa la convocatoria o vuelve al inicio.',
    },
    403: {
        accentText: 'text-hq-gold',
        accentBorder: 'border-hq-gold',
        tag: 'Tarjeta roja',
        headline: 'Expulsado del terreno de juego',
        sub: 'No tienes acceso a esta parte del campo.',
    },
    419: {
        accentText: 'text-hq-khaki',
        accentBorder: 'border-hq-khaki',
        tag: 'Tiempo cumplido',
        headline: 'La sesión ha caducado',
        sub: 'Ha pasado demasiado tiempo. Recarga la página e inténtalo de nuevo.',
    },
    429: {
        accentText: 'text-hq-ember',
        accentBorder: 'border-hq-ember',
        tag: 'Fuera de forma',
        headline: 'Vas demasiado rápido',
        sub: 'Estás pidiendo balón más rápido de lo que damos abasto. Espera un momento.',
    },
    500: {
        accentText: 'text-hq-live',
        accentBorder: 'border-hq-live',
        tag: 'Fallo en el VAR',
        headline: 'Algo se ha roto en el sistema',
        sub: 'Ya lo estamos revisando en la sala VAR. Inténtalo de nuevo en un momento.',
    },
    503: {
        accentText: 'text-hq-live',
        accentBorder: 'border-hq-live',
        tag: 'Partido suspendido',
        headline: 'Estamos en mantenimiento',
        sub: 'Volvemos a saltar al campo en unos minutos.',
    },
};

const FALLBACK_COPY: StatusCopy = {
    accentText: 'text-hq-moss',
    accentBorder: 'border-hq-moss',
    tag: 'Error',
    headline: 'Algo ha ido mal',
    sub: 'Ha ocurrido un error inesperado.',
};

export default function ErrorPage({ status }: ErrorPageProps) {
    const copy = STATUS_COPY[status] ?? FALLBACK_COPY;

    return (
        <>
            <Head title={`Error ${status}`} />
            <div className="hq-texture hq-bleed flex flex-1 flex-col items-center justify-center border-y border-hq-border px-6 py-16 text-center">
                <p className={cn('font-display text-8xl', copy.accentText)}>
                    {status}
                </p>
                <span
                    className={cn(
                        'mt-1.5 border px-2 py-0.5 font-mono text-[10px] font-bold tracking-wider uppercase',
                        copy.accentBorder,
                        copy.accentText,
                    )}
                >
                    {copy.tag}
                </span>
                <h1 className="mt-4 font-display text-2xl text-hq-paper uppercase">
                    {copy.headline}
                </h1>
                <p className="mt-2.5 max-w-sm font-mono text-[12.5px] text-hq-moss">
                    {copy.sub}
                </p>
                <Link
                    href={home().url}
                    className="mt-6 inline-flex items-center gap-1 border border-hq-lime px-3 py-1.5 font-mono text-[11px] font-bold text-hq-lime uppercase hover:bg-hq-lime/10"
                >
                    Volver a la página principal
                    <ArrowUpRight className="h-3 w-3" />
                </Link>
            </div>
        </>
    );
}

ErrorPage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
