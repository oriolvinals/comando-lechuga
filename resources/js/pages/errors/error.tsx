import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { CSSProperties, ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';
import { home } from '@/routes';

interface ErrorPageProps {
    status: number;
    [key: string]: unknown;
}

interface StatusCopy {
    /** CSS colour of the scoreboard number and tag. */
    accent: string;
    tag: string;
    headline: string;
    sub: string;
}

const STATUS_COPY: Record<number, StatusCopy> = {
    404: {
        accent: 'var(--color-hq-lime)',
        tag: 'Fuera de juego',
        headline: 'No hay nadie en esta posición',
        sub: 'Esta jugada no existe o se movió de sitio. Revisa la convocatoria o vuelve al inicio.',
    },
    403: {
        accent: 'var(--color-hq-gold)',
        tag: 'Tarjeta roja',
        headline: 'Expulsado del terreno de juego',
        sub: 'No tienes acceso a esta parte del campo.',
    },
    419: {
        accent: 'var(--color-hq-khaki)',
        tag: 'Tiempo cumplido',
        headline: 'La sesión ha caducado',
        sub: 'Ha pasado demasiado tiempo. Recarga la página e inténtalo de nuevo.',
    },
    429: {
        accent: 'var(--color-hq-ember)',
        tag: 'Fuera de forma',
        headline: 'Vas demasiado rápido',
        sub: 'Estás pidiendo balón más rápido de lo que damos abasto. Espera un momento.',
    },
    500: {
        accent: 'var(--color-hq-live)',
        tag: 'Fallo en el VAR',
        headline: 'Algo se ha roto en el sistema',
        sub: 'Ya lo estamos revisando en la sala VAR. Inténtalo de nuevo en un momento.',
    },
    503: {
        accent: 'var(--color-hq-live)',
        tag: 'Partido suspendido',
        headline: 'Estamos en mantenimiento',
        sub: 'Volvemos a saltar al campo en unos minutos.',
    },
};

const FALLBACK_COPY: StatusCopy = {
    accent: 'var(--color-hq-olive)',
    tag: 'Error',
    headline: 'Algo ha ido mal',
    sub: 'Ha ocurrido un error inesperado.',
};

export default function ErrorPage({ status }: ErrorPageProps) {
    const copy = STATUS_COPY[status] ?? FALLBACK_COPY;

    return (
        <>
            <Head title={`Error ${status}`} />
            <div
                style={{ '--ec': copy.accent } as CSSProperties}
                className="flex flex-1 flex-col items-center px-4 pt-12 pb-16 text-center"
            >
                <div className="hq-scanlines flex flex-col items-center overflow-hidden border border-hq-border-strong bg-hq-well px-[26px] pt-3.5 pb-[18px] sm:px-[42px] sm:pt-[18px] sm:pb-[22px]">
                    <span className="font-mono text-[11px] leading-tight font-medium tracking-[0.07em] text-hq-moss uppercase">
                        Marcador · incidencia
                    </span>
                    <p className="relative z-[1] mt-2.5 mb-2 font-dot text-[96px] leading-[0.9] font-black text-(--ec) [text-shadow:0_0_30px_color-mix(in_srgb,var(--ec)_45%,transparent)] sm:text-[150px]">
                        {status}
                    </p>
                    <span className="relative z-[1] border border-(--ec) px-2 py-[5px] font-mono text-[11px] leading-none font-bold tracking-[0.14em] text-(--ec) uppercase">
                        {copy.tag}
                    </span>
                </div>
                <h1 className="mt-[26px] mb-2 font-display text-[30px] leading-none text-hq-paper uppercase">
                    {copy.headline}
                </h1>
                <p className="mb-6 max-w-[420px] font-mono text-[13px] leading-normal text-hq-moss">
                    {copy.sub}
                </p>
                <Link
                    href={home().url}
                    className="inline-flex min-h-11 items-center gap-1.5 border border-hq-lime px-2.5 font-mono text-[11.5px] leading-none font-bold tracking-[0.06em] text-hq-lime uppercase hover:bg-hq-lime/10 sm:min-h-0 sm:py-[7px]"
                >
                    Volver a la página principal
                    <ArrowUpRight aria-hidden="true" className="size-[13px]" />
                </Link>
            </div>
        </>
    );
}

ErrorPage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
