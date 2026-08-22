import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';

export default function SeasonTeamsIndex() {
    return (
        <>
            <Head title="Equipos" />
            <p className="text-neutral-500">
                Próximamente: clasificación de la jornada seleccionada.
            </p>
        </>
    );
}

SeasonTeamsIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
