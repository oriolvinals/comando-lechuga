import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { HqPageHeader } from '@/components/hq-page-header';
import AppLayout from '@/layouts/app-layout';

export default function GodRadar() {
    return (
        <>
            <Head title="Radar">
                <meta name="robots" content="noindex" />
            </Head>
            <HqPageHeader title="Radar" />
        </>
    );
}

GodRadar.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
