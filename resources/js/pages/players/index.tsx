import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';

export default function PlayersIndex() {
    return (
        <>
            <Head title="Jugadores" />
            <p className="text-neutral-500">
                Próximamente: buscador de jugadores con filtros por posición,
                equipo y estado.
            </p>
        </>
    );
}

PlayersIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
