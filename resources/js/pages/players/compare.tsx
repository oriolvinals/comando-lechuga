import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';

export default function PlayersCompare() {
    return (
        <div className="flex-1">
            <Head title="Comparador" />
        </div>
    );
}

PlayersCompare.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
