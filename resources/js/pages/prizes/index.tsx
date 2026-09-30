import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactElement } from 'react';
import { useMemo, useState } from 'react';
import { HqPageHeader } from '@/components/hq-page-header';
import { HqChannelHeader } from '@/components/hq-section';
import { ManagerCrest } from '@/components/prizes/prize-crest';
import { PrizeDetailDialog } from '@/components/prizes/prize-detail-dialog';
import { PrizeRow } from '@/components/prizes/prize-row';
import AppLayout from '@/layouts/app-layout';
import { shortManagerName } from '@/lib/prize-format';
import { togglePrizeViewer, usePrizeViewer } from '@/lib/prize-viewer';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import type {
    PrizeManager,
    PrizesPageProps,
    PrizeStanding,
    SeasonPrizeKey,
} from '@/types/prizes';

function TierHeader({
    amount,
    title,
    prizes,
}: {
    amount: number;
    title: string;
    prizes: PrizeStanding[];
}) {
    return (
        <HqChannelHeader
            as="h3"
            title={
                <>
                    <span
                        className={cn(
                            'mr-2',
                            amount === 10 ? 'text-hq-gold' : 'text-hq-khaki',
                        )}
                    >
                        {amount} €
                    </span>
                    {title}
                </>
            }
            action={`${prizes.length} · ${prizes.length * amount} €`}
        />
    );
}

export default function PrizesIndex({
    season,
    lastFinishedWeek,
    managers,
    prizes,
    players,
}: PrizesPageProps) {
    const viewer = usePrizeViewer();
    const [openKey, setOpenKey] = useState<SeasonPrizeKey | null>(null);
    const managersById = useMemo(
        () =>
            new Map<number, PrizeManager>(
                managers.map((manager) => [manager.id, manager]),
            ),
        [managers],
    );
    const openPrize = prizes.find((prize) => prize.key === openKey) ?? null;
    const rowProps = {
        managers: managersById,
        players,
        viewer,
        onOpen: setOpenKey,
    };
    const bigPrizes = prizes.filter((prize) => prize.amount === 10);
    const smallPrizes = prizes.filter((prize) => prize.amount === 5);
    const pot = prizes.reduce((total, prize) => total + prize.amount, 0);

    return (
        <div className="flex-1">
            <Head title="Premios" />
            <Link
                href={home()}
                className="mx-4 mt-3 inline-flex min-h-8 cursor-pointer items-center gap-1.5 font-mono text-[11px] font-semibold tracking-[0.08em] text-hq-moss uppercase transition-colors hover:text-hq-lime max-sm:mx-3.5 max-sm:mt-2.5"
            >
                <ArrowLeft className="size-3.5" aria-hidden="true" />
                Clasificación
            </Link>
            <HqPageHeader
                code="BOTE DE SANCIONES"
                title="Premios"
                className="pt-1.5 sm:pt-1.5"
                lede={
                    lastFinishedWeek > 0
                        ? `El campeón no se lleva nada: esto va de otra cosa. Clasificación tras la J${lastFinishedWeek}; se reparten al acabar la temporada.`
                        : 'El campeón no se lleva nada: esto va de otra cosa. Se reparten al acabar la temporada.'
                }
                meta={[
                    {
                        label: 'Bote',
                        value: <span className="text-hq-gold">{pot} €</span>,
                    },
                    { label: 'Premios', value: String(prizes.length) },
                    {
                        label: 'Jornada',
                        value: `${lastFinishedWeek}/${season.total_weeks}`,
                    },
                ]}
            />
            <div
                role="group"
                aria-label="Resaltar mánager"
                className="hq-no-scrollbar flex items-center gap-2 overflow-x-auto border-b border-hq-border px-4 py-2 max-sm:px-3.5"
            >
                <span className="shrink-0 font-mono text-[11px] tracking-[0.07em] text-hq-moss-dim uppercase">
                    Soy
                </span>
                {managers.map((manager) => {
                    const pressed = manager.id === viewer;

                    return (
                        <button
                            key={manager.id}
                            type="button"
                            aria-pressed={pressed}
                            title={
                                pressed
                                    ? 'Pulsa otra vez para quitar'
                                    : undefined
                            }
                            onClick={() => togglePrizeViewer(manager.id)}
                            className={cn(
                                'inline-flex min-h-8 shrink-0 cursor-pointer items-center gap-1.5 border py-0 pr-2.5 pl-1 font-mono text-[11.5px] font-semibold whitespace-nowrap transition-colors',
                                pressed
                                    ? 'border-hq-paper bg-hq-panel-alt text-hq-paper'
                                    : 'border-hq-border text-hq-moss hover:border-hq-border-bright hover:text-hq-paper',
                            )}
                        >
                            <ManagerCrest
                                manager={manager}
                                className="size-6"
                            />
                            {shortManagerName(manager.name)}
                        </button>
                    );
                })}
            </div>
            <TierHeader
                amount={10}
                title="Premios grandes"
                prizes={bigPrizes}
            />
            {bigPrizes.map((prize) => (
                <PrizeRow key={prize.key} prize={prize} {...rowProps} />
            ))}
            <TierHeader
                amount={5}
                title="Premios pequeños"
                prizes={smallPrizes}
            />
            {smallPrizes.map((prize) => (
                <PrizeRow key={prize.key} prize={prize} {...rowProps} />
            ))}
            <PrizeDetailDialog
                prize={openPrize}
                managers={managersById}
                players={players}
                viewer={viewer}
                onClose={() => setOpenKey(null)}
            />
        </div>
    );
}

PrizesIndex.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
