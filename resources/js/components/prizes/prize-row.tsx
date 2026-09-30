import type { KeyboardEvent } from 'react';
import { PrizeLeader } from '@/components/prizes/prize-leader';
import { PrizeRestList } from '@/components/prizes/prize-rest-list';
import { cn } from '@/lib/utils';
import type {
    PrizeManager,
    PrizePlayer,
    PrizeStanding,
    SeasonPrizeKey,
} from '@/types/prizes';

/** One prize: name, € and rule | the leader | the others. The whole row opens the detail. */
export function PrizeRow({
    prize,
    managers,
    players,
    viewer,
    onOpen,
}: {
    prize: PrizeStanding;
    managers: Map<number, PrizeManager>;
    players: Record<string, PrizePlayer>;
    viewer: number | null;
    onOpen: (key: SeasonPrizeKey) => void;
}) {
    const isBig = prize.amount === 10;
    const open = () => onOpen(prize.key);
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.target !== event.currentTarget) {
            return;
        }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            open();
        }
    };

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={open}
            onKeyDown={onKeyDown}
            aria-label={`${prize.name}, ${prize.amount} euros. Ver clasificación`}
            className="group grid cursor-pointer grid-cols-[minmax(0,1fr)_280px_minmax(0,1.3fr)] border-b border-hq-border transition-colors hover:bg-hq-panel focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-hq-lime max-md:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] max-sm:grid-cols-1"
        >
            <div className="min-w-0 px-4 py-3 max-md:col-span-2 max-md:px-3.5 max-md:pt-[11px] max-md:pb-2 max-sm:col-span-1 max-sm:pt-2.5 max-sm:pb-[7px]">
                <div className="flex flex-wrap items-baseline gap-2">
                    <h4
                        className={cn(
                            'font-display leading-none text-hq-paper uppercase transition-colors group-hover:text-hq-lime max-sm:text-base',
                            isBig ? 'text-[19px]' : 'text-[17px]',
                        )}
                    >
                        {prize.name}
                    </h4>
                    <span
                        className={cn(
                            'font-mono text-[11.5px] leading-none font-bold whitespace-nowrap tabular-nums',
                            isBig ? 'text-hq-gold' : 'text-hq-khaki',
                        )}
                    >
                        {prize.amount} €
                    </span>
                </div>
                <p className="mt-1.5 text-xs leading-snug text-hq-moss max-md:mt-1 max-sm:text-[11.5px]">
                    {prize.rule}
                </p>
            </div>
            <div className="min-w-0 max-md:border-t max-md:border-hq-border">
                <PrizeLeader
                    prize={prize}
                    managers={managers}
                    players={players}
                    viewer={viewer}
                />
            </div>
            <div className="min-w-0 max-md:border-t max-md:border-hq-border">
                <PrizeRestList
                    prize={prize}
                    managers={managers}
                    players={players}
                    viewer={viewer}
                />
            </div>
        </div>
    );
}
