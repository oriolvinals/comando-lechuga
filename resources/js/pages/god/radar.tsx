import { Head } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    CircleHelp,
    Lock,
    LockOpen,
    ShieldCheck,
    Tag,
    TriangleAlert,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqPageHeader } from '@/components/hq-page-header';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { RadarBalances } from '@/pages/god/radar-balances';
import { RadarClauses } from '@/pages/god/radar-clauses';
import { RadarManualRaises } from '@/pages/god/radar-manual-raises';
import { RadarUnlocks } from '@/pages/god/radar-unlocks';
import type {
    ClauseState,
    RadarClause,
    RadarManager,
    RadarManualRaise,
    RadarMarketListing,
    RadarRaiseCandidate,
} from '@/types/models';

interface GodRadarProps {
    connectedManagerId: number | null;
    managers: RadarManager[];
    clauses: RadarClause[];
    market: RadarMarketListing[];
    manualRaises: RadarManualRaise[];
    raiseCandidates: RadarRaiseCandidate[];
    now: string;
}

/** Header counts, each with its comparator state icon and colour. */
const STATE_COUNTS: {
    state: ClauseState;
    label: string;
    icon: LucideIcon;
    className: string;
}[] = [
    {
        state: 'open',
        label: 'Abiertas',
        icon: LockOpen,
        className: 'text-hq-lime',
    },
    {
        state: 'locked',
        label: 'Bloqueadas',
        icon: Lock,
        className: 'text-hq-gold',
    },
    {
        state: 'shielded',
        label: 'Blindadas',
        icon: ShieldCheck,
        className: 'text-hq-azure',
    },
    {
        state: 'listed',
        label: 'En venta',
        icon: Tag,
        className: 'text-hq-lime',
    },
];

export default function GodRadar({
    connectedManagerId,
    managers,
    clauses,
    market,
    manualRaises,
    raiseCandidates,
}: GodRadarProps) {
    const [payerId, setPayerId] = useState<number | null>(null);

    return (
        <>
            <Head title="Radar">
                <meta name="robots" content="noindex" />
            </Head>
            <HqPageHeader
                title="Radar"
                meta={STATE_COUNTS.map(
                    ({ state, label, icon: Icon, className }) => ({
                        label: (
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1',
                                    className,
                                )}
                            >
                                <Icon aria-hidden="true" className="size-3" />
                                {label}
                            </span>
                        ),
                        value: clauses.filter(
                            (clause) => clause.state === state,
                        ).length,
                    }),
                )}
            />
            <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1 border-b border-hq-border px-3.5 py-2 text-[12.5px] text-hq-moss">
                <TriangleAlert
                    aria-hidden="true"
                    className="size-3.5 text-hq-amber"
                />
                <span>
                    <b className="text-hq-paper">Balances estimados:</b> rango
                    pesimista – optimista; solo el tuyo es real.
                </span>
                <details className="sm:ml-auto">
                    <summary className="inline-flex min-h-7 cursor-pointer list-none items-center gap-1.5 border border-hq-border-strong px-2 font-mono text-[11.5px] font-semibold text-hq-khaki hover:border-hq-khaki">
                        <CircleHelp aria-hidden="true" className="size-3" />
                        Qué no sabemos
                    </summary>
                    <ul className="mt-2 grid gap-1 text-[12.5px]">
                        <li>
                            Qué subidas de cláusula son reales: se infieren
                            (coste = subida/2), salvo las subidas conocidas.
                        </li>
                        <li>
                            Qué días reclama cada uno el premio diario: se
                            cuenta el 67 % (100.000 €; 200.000 € en parón).
                        </li>
                        <li>
                            Qué es la parte de tu saldo real que no explica la
                            actividad: no se suma a los rivales.
                        </li>
                    </ul>
                </details>
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
            <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="min-w-0">
                    <RadarClauses
                        clauses={clauses}
                        market={market}
                        managers={managers}
                        connectedManagerId={connectedManagerId}
                        payerId={payerId}
                        onPayerChange={setPayerId}
                    />
                    <RadarManualRaises
                        entries={manualRaises}
                        managers={managers}
                        candidates={raiseCandidates}
                    />
                </div>
                <RadarUnlocks
                    clauses={clauses}
                    managers={managers}
                    connectedManagerId={connectedManagerId}
                />
            </div>
        </>
    );
}

GodRadar.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
