import type { ReactNode } from 'react';
import { HqLed } from '@/components/hq-led';
import { JornadaBadges, JornadaSpan } from '@/components/prizes/jornada-badges';
import { ManagerCrest, PlayerPortrait } from '@/components/prizes/prize-crest';
import { leaderValue, millions, shortManagerName } from '@/lib/prize-format';
import { cn } from '@/lib/utils';
import type {
    PrizeManager,
    PrizePlayer,
    PrizeRowData,
    PrizeStanding,
} from '@/types/prizes';

interface LeaderProps {
    prize: PrizeStanding;
    managers: Map<number, PrizeManager>;
    players: Record<string, PrizePlayer>;
    viewer: number | null;
}

const SLOT =
    'flex h-full min-w-0 flex-col justify-center gap-[7px] border-hq-border bg-hq-lime/[0.06] px-3.5 py-2.5 sm:border-r md:border-l';

/** Up to three tied leaders keep their own context under their name; four or more show only crest and name. */
const MAX_TIED_WITH_CONTEXT = 3;

/** The «TÚ» tag next to the highlighted manager. */
export function You() {
    return (
        <span className="ml-1 inline-block shrink-0 bg-hq-paper px-[3px] py-[2px] align-middle font-mono text-[10px] leading-none font-bold tracking-[0.08em] text-hq-ink">
            TÚ
        </span>
    );
}

function BigValue({ big, unit }: { big: ReactNode; unit: string }) {
    return (
        <span className="inline-flex items-baseline gap-[5px] whitespace-nowrap">
            <HqLed tone="lime" className="text-[28px] leading-[0.85]">
                {big}
            </HqLed>
            {unit && (
                <small className="font-mono text-[11px] text-hq-moss">
                    {unit}
                </small>
            )}
        </span>
    );
}

/**
 * The leader's own context, one prize at a time (spec "Qué enseña cada
 * premio"). `compact` is the shorter form used in a tied leader's column.
 */
function LeaderContext({
    prize,
    row,
    managers,
    players,
    compact,
}: Omit<LeaderProps, 'viewer'> & { row: PrizeRowData; compact: boolean }) {
    const context = row.context;
    const mono =
        'block max-w-full truncate font-mono text-[11px] text-hq-moss-dim';
    const badgeCap = compact ? 5 : 12;

    switch (prize.key) {
        case 'best_night':
        case 'worst_night':
            return context.week_number ? (
                <JornadaBadges weeks={[context.week_number]} max={1} />
            ) : null;
        case 'sunday_king':
            return (
                <JornadaBadges
                    weeks={context.weeks ?? []}
                    max={badgeCap}
                    className={cn(compact && 'justify-center')}
                />
            );
        case 'worst_weeks':
            return (
                <JornadaBadges
                    weeks={context.weeks ?? []}
                    max={badgeCap}
                    tone="neg"
                    className={cn(compact && 'justify-center')}
                />
            );
        case 'most_buyouts_made':
        case 'most_buyouts_suffered': {
            const made = prize.key === 'most_buyouts_made';
            const other = made ? context.favourite : context.nemesis;
            const manager = other
                ? managers.get(other.season_manager_id)
                : undefined;

            if (!manager || !other) {
                return null;
            }

            if (compact) {
                return (
                    <span
                        title={`${made ? 'Su víctima favorita' : 'Su verdugo'}: ${manager.name} ×${other.count}`}
                        className="inline-flex max-w-full items-center gap-1 font-mono text-[10.5px] text-hq-moss"
                    >
                        {made ? 'a' : 'verdugo'}
                        <ManagerCrest
                            manager={manager}
                            className="size-[14px] p-px"
                        />
                        ×{other.count}
                    </span>
                );
            }

            return (
                <span className={mono}>
                    {made ? 'Su víctima favorita: ' : 'Su verdugo: '}
                    <b className="font-semibold text-hq-paper">
                        {shortManagerName(manager.name)} ×{other.count}
                    </b>
                </span>
            );
        }
        case 'most_overpaid': {
            const player = context.worst
                ? players[context.worst.player_id]
                : undefined;

            if (!player || !context.worst) {
                return null;
            }

            return compact ? (
                <span className="block max-w-full truncate font-mono text-[10.5px] text-hq-moss">
                    <b className="font-semibold text-hq-paper">
                        {player.nickname}
                    </b>{' '}
                    +{millions(context.worst.overpaid)}
                </span>
            ) : (
                <span className={mono}>
                    El peor:{' '}
                    <b className="font-semibold text-hq-paper">
                        {player.nickname}, +{millions(context.worst.overpaid)}{' '}
                        M€
                    </b>
                </span>
            );
        }
        case 'longest_partnership':
            return (
                <span className="inline-flex items-center gap-2">
                    <JornadaSpan
                        from={context.from_week ?? 0}
                        to={context.to_week ?? 0}
                    />
                    {context.alive &&
                        (compact ? (
                            <i
                                title="Sigue"
                                className="block size-[6px] bg-hq-lime"
                            >
                                <span className="sr-only">Sigue</span>
                            </i>
                        ) : (
                            <span className="inline-flex items-center gap-[5px] font-mono text-[10.5px] font-semibold tracking-[0.06em] text-hq-lime uppercase">
                                <i
                                    className="block size-[6px] bg-hq-lime"
                                    aria-hidden="true"
                                />
                                Sigue
                            </span>
                        ))}
                </span>
            );
        default:
            return null;
    }
}

function rowOf(prize: PrizeStanding, id: number): PrizeRowData | undefined {
    return prize.rows.find((row) => row.season_manager_id === id);
}

/** Tied leaders: the shared value once, then equal columns side by side (no «Empate» seal). */
function TiedSlot({
    value,
    count,
    children,
}: {
    value: ReactNode;
    count: number;
    children: ReactNode;
}) {
    return (
        <div className={SLOT}>
            {value}
            <div
                className="grid"
                style={{
                    gridTemplateColumns: `repeat(${count}, minmax(0, 1fr))`,
                }}
            >
                {children}
            </div>
        </div>
    );
}

function TiedColumn({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col items-center gap-1 px-1 py-0.5 text-center [&+&]:shadow-[inset_1px_0_0_var(--color-hq-border-strong)]">
            {children}
        </div>
    );
}

function TiedName({ name, isViewer }: { name: string; isViewer: boolean }) {
    return (
        <span className="flex max-w-full min-w-0 items-center justify-center">
            <span
                title={name}
                className="min-w-0 truncate font-sans text-xs font-extrabold text-hq-paper"
            >
                {name}
            </span>
            {isViewer && <You />}
        </span>
    );
}

/** One leader, laid out as one line on phones: who on the left, value on the right, context below. */
function SoloSlot({
    who,
    value,
    context,
}: {
    who: ReactNode;
    value: ReactNode;
    context: ReactNode;
}) {
    return (
        <div
            className={cn(
                SLOT,
                'max-sm:grid max-sm:grid-cols-[minmax(0,1fr)_auto] max-sm:items-center max-sm:gap-x-2 max-sm:gap-y-1.5',
            )}
        >
            <div className="flex min-w-0 items-center gap-[9px]">{who}</div>
            <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1.5 max-sm:contents">
                {value}
                {context && (
                    <div className="min-w-0 basis-full empty:hidden max-sm:col-span-full">
                        {context}
                    </div>
                )}
            </div>
        </div>
    );
}

function EmptySlot({ children }: { children: ReactNode }) {
    return (
        <div
            className={cn(
                SLOT,
                'bg-transparent font-mono text-xs text-hq-moss-dim',
            )}
        >
            {children}
        </div>
    );
}

/** «Nadie todavía», one leader, or tied leaders side by side sharing the slot. */
export function PrizeLeader({ prize, managers, players, viewer }: LeaderProps) {
    if (prize.key === 'most_owned_player') {
        return (
            <MostOwnedLeader
                prize={prize}
                managers={managers}
                players={players}
                viewer={viewer}
            />
        );
    }

    const leaders = prize.leaders.flatMap((id) => {
        const manager = managers.get(id);
        const row = rowOf(prize, id);

        return manager && row ? [{ id, manager, row }] : [];
    });

    if (leaders.length === 0) {
        return <EmptySlot>Nadie todavía</EmptySlot>;
    }

    const { big, unit } = leaderValue(prize, leaders[0].row);
    const value = <BigValue big={big} unit={unit} />;
    const isPartnership = prize.key === 'longest_partnership';
    const partnerOf = (row: PrizeRowData) =>
        isPartnership && row.context.player_id
            ? players[row.context.player_id]
            : undefined;

    if (leaders.length > 1) {
        const withContext = leaders.length <= MAX_TIED_WITH_CONTEXT;

        return (
            <TiedSlot value={value} count={leaders.length}>
                {leaders.map(({ id, manager, row }) => {
                    const partner = partnerOf(row);

                    return (
                        <TiedColumn key={id}>
                            <span className="flex">
                                <ManagerCrest
                                    manager={manager}
                                    className="size-7"
                                />
                                {partner && (
                                    <PlayerPortrait
                                        player={partner}
                                        className="-ml-2 size-7"
                                    />
                                )}
                            </span>
                            <TiedName
                                name={shortManagerName(manager.name)}
                                isViewer={id === viewer}
                            />
                            {withContext && partner && (
                                <span className="max-w-full truncate font-mono text-[10.5px] text-hq-moss">
                                    {partner.nickname}
                                </span>
                            )}
                            {withContext && (
                                <LeaderContext
                                    prize={prize}
                                    row={row}
                                    managers={managers}
                                    players={players}
                                    compact
                                />
                            )}
                        </TiedColumn>
                    );
                })}
            </TiedSlot>
        );
    }

    const { id, manager, row } = leaders[0];
    const partner = partnerOf(row);

    return (
        <SoloSlot
            who={
                <>
                    <span className="flex shrink-0">
                        <ManagerCrest manager={manager} className="size-9" />
                        {partner && (
                            <PlayerPortrait
                                player={partner}
                                className="-ml-2 size-9"
                            />
                        )}
                    </span>
                    <span className="min-w-0">
                        <span className="flex min-w-0 items-center font-sans text-[13.5px] font-extrabold text-hq-paper">
                            <span className="min-w-0 truncate">
                                {manager.name}
                            </span>
                            {id === viewer && <You />}
                        </span>
                        {partner && (
                            <span className="mt-0.5 block truncate font-mono text-[11px] text-hq-moss">
                                {partner.nickname}
                            </span>
                        )}
                    </span>
                </>
            }
            value={value}
            context={
                <LeaderContext
                    prize={prize}
                    row={row}
                    managers={managers}
                    players={players}
                    compact={false}
                />
            }
        />
    );
}

/**
 * Fichaje del Pueblo: only the player, his winning manager and the owners
 * count. Every (player, winner) pair is a leader, so tied players and tied
 * winners of one player all sit side by side.
 */
function MostOwnedLeader({ prize, managers, players, viewer }: LeaderProps) {
    const pairs = prize.candidates.flatMap((candidate) =>
        candidate.winners.flatMap((winnerId) => {
            const manager = managers.get(winnerId);
            const player = players[candidate.player_id];

            return manager && player ? [{ candidate, manager, player }] : [];
        }),
    );

    if (pairs.length === 0) {
        return <EmptySlot>Nadie todavía</EmptySlot>;
    }

    const value = (
        <BigValue big={pairs[0].candidate.owners.length} unit="dueños" />
    );

    const winner = (manager: PrizeManager, withLabel: boolean) => (
        <span className="inline-flex max-w-full min-w-0 items-center gap-[5px] font-mono text-[11px] text-hq-moss">
            {withLabel && <span className="shrink-0">Se lo lleva</span>}
            <ManagerCrest manager={manager} className="size-[14px] p-px" />
            <b className="min-w-0 truncate font-bold text-hq-lime">
                {shortManagerName(manager.name)}
            </b>
            {manager.id === viewer && <You />}
        </span>
    );

    if (pairs.length > 1) {
        return (
            <TiedSlot value={value} count={pairs.length}>
                {pairs.map(({ candidate, manager, player }) => (
                    <TiedColumn key={`${candidate.player_id}-${manager.id}`}>
                        <PlayerPortrait player={player} className="size-7" />
                        <span
                            title={player.nickname}
                            className="max-w-full truncate font-sans text-xs font-extrabold text-hq-paper"
                        >
                            {player.nickname}
                        </span>
                        {winner(manager, false)}
                    </TiedColumn>
                ))}
            </TiedSlot>
        );
    }

    const { manager, player } = pairs[0];

    return (
        <SoloSlot
            who={
                <>
                    <PlayerPortrait player={player} className="size-9" />
                    <span className="min-w-0">
                        <span className="block truncate font-sans text-[13.5px] font-extrabold text-hq-paper">
                            {player.nickname}
                        </span>
                        <span className="mt-0.5 flex min-w-0">
                            {winner(manager, true)}
                        </span>
                    </span>
                </>
            }
            value={value}
            context={null}
        />
    );
}
