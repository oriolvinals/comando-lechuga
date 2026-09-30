import { Fragment } from 'react';
import { ManagerCrest, PlayerPortrait } from '@/components/prizes/prize-crest';
import { cn } from '@/lib/utils';
import type {
    OwnedPlayerCandidate,
    PrizeManager,
    PrizePlayer,
} from '@/types/prizes';

const TILT = [-4, 3, -2, 4, -3, 2, -1];

function jornadas(count: number): string {
    return `${count} ${count === 1 ? 'jornada' : 'jornadas'}`;
}

/** One stamp per distinct owner, in order, ×N for repeat spells, jornadas held under it; the winner in lime. */
export function PrizePassport({
    candidate,
    player,
    managers,
}: {
    candidate: OwnedPlayerCandidate;
    player: PrizePlayer | undefined;
    managers: Map<number, PrizeManager>;
}) {
    const winners = candidate.winners.flatMap((id) => {
        const manager = managers.get(id);

        return manager ? [manager] : [];
    });

    return (
        <div className="min-w-0 bg-hq-panel px-4 py-3">
            <div className="flex items-center gap-[9px]">
                {player && (
                    <PlayerPortrait player={player} className="size-[30px]" />
                )}
                <span className="min-w-0">
                    <b className="block truncate font-sans text-[13.5px] font-extrabold">
                        {player?.nickname}
                    </b>
                    <small className="mt-[3px] block font-mono text-[11px] text-hq-moss">
                        {candidate.owners.length} dueños · {candidate.transfers}{' '}
                        {candidate.transfers === 1 ? 'traspaso' : 'traspasos'}
                        {candidate.on_market && ' · hoy en el mercado'}
                    </small>
                </span>
            </div>
            <div className="mt-3 flex flex-wrap gap-x-2 gap-y-2.5">
                {candidate.owners.map((id, index) => {
                    const manager = managers.get(id);
                    const spells = candidate.chain.filter(
                        (owner) => owner === id,
                    ).length;
                    const isWinner = candidate.winners.includes(id);
                    const held = candidate.weeks_held[id] ?? 0;

                    return manager ? (
                        <span
                            key={id}
                            className="flex flex-col items-center gap-[5px]"
                            title={`${manager.name} · ${jornadas(held)}`}
                        >
                            <span
                                className={cn(
                                    'relative grid place-items-center p-1',
                                    isWinner
                                        ? 'border border-hq-lime bg-hq-lime/[0.12]'
                                        : 'border border-dashed border-hq-border-bright',
                                )}
                                style={{
                                    transform: `rotate(${TILT[index % TILT.length]}deg)`,
                                }}
                            >
                                <ManagerCrest
                                    manager={manager}
                                    className="size-[22px] border-0 bg-transparent p-px"
                                />
                                {spells > 1 && (
                                    <sup className="absolute -top-[7px] -right-[7px] bg-hq-paper px-[2px] py-px font-mono text-[9px] leading-none font-bold text-hq-ink">
                                        ×{spells}
                                    </sup>
                                )}
                            </span>
                            <small
                                className={cn(
                                    'font-mono text-[10px] leading-none font-semibold whitespace-nowrap',
                                    isWinner
                                        ? 'text-hq-lime'
                                        : 'text-hq-moss-dim',
                                )}
                            >
                                {held} jor.
                            </small>
                        </span>
                    ) : null;
                })}
            </div>
            {winners.length > 0 && (
                <p className="mt-3 font-mono text-[11.5px] leading-relaxed text-hq-moss">
                    Se lo lleva{winners.length > 1 ? 'n' : ''}{' '}
                    {winners.map((winner, index) => (
                        <Fragment key={winner.id}>
                            {index > 0 && ' y '}
                            <ManagerCrest
                                manager={winner}
                                className="mx-1 inline-block size-4 p-px align-[-3px]"
                            />
                            <b className="font-bold text-hq-lime">
                                {winner.name}
                            </b>
                        </Fragment>
                    ))}
                    {winners.length > 1
                        ? ', los que más tiempo lo tuvieron'
                        : ', el que más tiempo lo tuvo'}{' '}
                    ({jornadas(candidate.weeks_held[winners[0].id] ?? 0)}).
                </p>
            )}
        </div>
    );
}
