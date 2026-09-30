import type { CSSProperties } from 'react';
import { HqLed } from '@/components/hq-led';
import { ManagerCrest } from '@/components/prizes/prize-crest';
import { restValue, shortManagerName } from '@/lib/prize-format';
import { cn } from '@/lib/utils';
import type { PrizeManager, PrizePlayer, PrizeStanding } from '@/types/prizes';

/** Everyone but the leaders, in two columns (one on mid widths), filled top to bottom. */
export function PrizeRestList({
    prize,
    managers,
    players,
    viewer,
}: {
    prize: PrizeStanding;
    managers: Map<number, PrizeManager>;
    players: Record<string, PrizePlayer>;
    viewer: number | null;
}) {
    if (!prize.decided) {
        return (
            <p className="flex h-full items-center px-3.5 py-2 font-mono text-xs text-hq-moss-dim">
                Cuando se apruebe, sale aquí con los 7 ordenados.
            </p>
        );
    }

    const tiedPlayers =
        prize.key === 'most_owned_player' && prize.candidates.length > 1;
    const rows = prize.rows.filter(
        (row) => !prize.leaders.includes(row.season_manager_id),
    );
    const style = {
        '--rest-rows': Math.max(1, Math.ceil(rows.length / 2)),
    } as CSSProperties;

    return (
        <ol
            style={style}
            className="grid h-full min-w-0 grid-flow-col grid-cols-2 grid-rows-[repeat(var(--rest-rows),auto)] content-center gap-x-4 gap-y-0.5 px-3.5 py-2 max-md:grid-flow-row max-md:grid-cols-1 max-md:grid-rows-none max-sm:grid-flow-col max-sm:grid-cols-2 max-sm:grid-rows-[repeat(var(--rest-rows),auto)] max-sm:gap-x-3 max-sm:gap-y-0 max-sm:pt-1.5"
        >
            {rows.map((row) => {
                const manager = managers.get(row.season_manager_id);

                if (!manager) {
                    return null;
                }

                const value = restValue(prize, row);
                const player = row.context.player_id
                    ? players[row.context.player_id]
                    : undefined;
                const sub =
                    prize.key === 'longest_partnership' || tiedPlayers
                        ? player?.nickname
                        : undefined;
                const isViewer = row.season_manager_id === viewer;
                const isOut = row.value === null;

                return (
                    <li
                        key={row.season_manager_id}
                        className={cn(
                            'grid min-h-[26px] min-w-0 grid-cols-[14px_20px_minmax(0,1fr)_auto] items-center gap-[7px] max-sm:min-h-[25px] max-sm:grid-cols-[12px_18px_minmax(0,1fr)_auto] max-sm:gap-1.5',
                            isViewer &&
                                '-ml-[5px] pl-[3px] shadow-[inset_2px_0_0_var(--color-hq-paper)]',
                        )}
                    >
                        <HqLed tone="off" className="text-right text-xs">
                            {row.place ?? '–'}
                        </HqLed>
                        <ManagerCrest
                            manager={manager}
                            className={cn(
                                'size-5 max-sm:size-[18px]',
                                isOut && 'opacity-45',
                            )}
                        />
                        <span
                            title={manager.name}
                            className={cn(
                                'min-w-0 truncate font-mono text-xs leading-tight text-hq-moss max-sm:text-[11.5px]',
                                isViewer && 'font-bold text-hq-paper',
                                isOut && 'text-hq-led-off',
                            )}
                        >
                            {shortManagerName(manager.name)}
                            {sub && (
                                <small className="block truncate text-[10.5px] font-normal text-hq-moss-dim">
                                    {sub}
                                </small>
                            )}
                        </span>
                        <span
                            className={cn(
                                'font-mono text-xs font-semibold whitespace-nowrap text-hq-paper tabular-nums',
                                value.muted && 'text-hq-led-off',
                            )}
                        >
                            {value.text}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
