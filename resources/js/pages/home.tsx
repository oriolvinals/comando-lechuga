import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';

export default function Home() {
    return (
        <>
            <Head title="Inicio" />
            <p className="text-neutral-500">Próximamente: clasificación general y partidos de la jornada.</p>
        </>
    );
}

Home.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
