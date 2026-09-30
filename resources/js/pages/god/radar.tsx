import { Head } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqPageHeader } from '@/components/hq-page-header';
import AppLayout from '@/layouts/app-layout';
import { RadarBalances } from '@/pages/god/radar-balances';
import type { RadarClause, RadarManager } from '@/types/models';

interface GodRadarProps {
    connectedManagerId: number | null;
    managers: RadarManager[];
    clauses: RadarClause[];
    now: string;
}

export default function GodRadar({
    connectedManagerId,
    managers,
    clauses,
}: GodRadarProps) {
    const [payerId, setPayerId] = useState<number | null>(null);

    return (
        <>
            <Head title="Radar">
                <meta name="robots" content="noindex" />
            </Head>
            <HqPageHeader title="Radar" />
            <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1 border-b border-hq-border px-3.5 py-2 text-[12.5px] text-hq-moss">
                <TriangleAlert
                    aria-hidden="true"
                    className="size-3.5 text-hq-amber"
                />
                <span>
                    <b className="text-hq-paper">Balances estimados:</b> rango
                    pesimista – optimista; solo el tuyo es real.
                </span>
            </div>
            {managers.length === 0 ? (
                <HqEmptyState title="Sin managers" />
            ) : (
                <RadarBalances
                    managers={managers}
                    clauses={clauses}
                    connectedManagerId={connectedManagerId}
                    selectedPayerId={payerId}
                    onSelectPayer={setPayerId}
                />
            )}
        </>
    );
}

GodRadar.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
