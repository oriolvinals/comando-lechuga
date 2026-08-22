import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';

export default function ActivityIndex() {
    return (
        <>
            <Head title="Actividad" />
            <p className="text-neutral-500">
                Próximamente: feed global de fichajes, ventas, blindajes y
                premios.
            </p>
        </>
    );
}

ActivityIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
