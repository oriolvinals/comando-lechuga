import { BadgeCheck, CircleCheck, Users, Wallet } from 'lucide-react';
import { useState } from 'react';
import { HqLed } from '@/components/hq-led';
import { HqShieldCount } from '@/components/hq-shield-count';
import { cn } from '@/lib/utils';
import { formatM, ManagerSquare } from '@/pages/god/radar-helpers';
import type { RadarClause, RadarManager } from '@/types/models';

type Axis = 'total' | 'cash';

interface RadarBalancesProps {
    managers: RadarManager[];
    clauses: RadarClause[];
    connectedManagerId: number | null;
    selectedPayerId: number | null;
    onSelectPayer: (managerId: number | null) => void;
}

function range(manager: RadarManager, axis: Axis) {
    return axis === 'total' ? manager.total : manager.cash;
}

/** Shared axis: every manager's range on one scale, with "tu total / tu caja" as reference. */
function SharedAxis({
    managers,
    connectedManagerId,
    onSelectPayer,
}: Pick<RadarBalancesProps, 'managers' | 'connectedManagerId'> & {
    onSelectPayer: (managerId: number) => void;
}) {
    const [axis, setAxis] = useState<Axis>('total');
    const sorted = [...managers].sort(
        (a, b) => range(b, axis).mid - range(a, axis).mid,
    );
    const step = 50_000_000;
    const min =
        Math.floor(
            Math.min(...managers.map((m) => range(m, axis).low)) / step,
        ) * step;
    const max =
        Math.ceil(
            Math.max(...managers.map((m) => range(m, axis).high)) / step,
        ) * step;
    const x = (value: number) => (100 * (value - min)) / Math.max(1, max - min);
    const me = managers.find((m) => m.id === connectedManagerId) ?? null;
    const reference = me ? range(me, axis).mid : null;
    const ticks: number[] = [];

    for (
        let value = min;
        value <= max;
        value += max - min > 300_000_000 ? 100_000_000 : step
    ) {
        ticks.push(value);
    }

    return (
        <div className="border-t border-hq-border px-3.5 pt-2 pb-3">
            <div
                role="group"
                aria-label="Qué comparar"
                className="mb-2 flex w-max border border-hq-border-strong"
            >
                {(['total', 'cash'] as const).map((option) => (
                    <button
                        key={option}
                        type="button"
                        aria-pressed={axis === option}
                        onClick={() => setAxis(option)}
                        className={cn(
                            'min-h-7 cursor-pointer border-r border-hq-border-strong px-2.5 font-mono text-[11px] font-bold tracking-[0.04em] uppercase last:border-r-0',
                            axis === option
                                ? 'bg-hq-lime text-hq-ink'
                                : 'text-hq-moss hover:bg-hq-panel hover:text-hq-paper',
                        )}
                    >
                        {option === 'total' ? 'Total' : 'Caja'}
                    </button>
                ))}
            </div>
            <div className="grid grid-cols-[92px_minmax(0,1fr)_78px] gap-2 sm:grid-cols-[150px_minmax(0,1fr)_104px] sm:gap-3">
                <span />
                <span className="relative h-3">
                    {ticks.map((tick) => (
                        <span
                            key={tick}
                            className="absolute -translate-x-1/2 font-mono text-[11px] text-hq-moss-dim"
                            style={{ left: `${x(tick)}%` }}
                        >
                            {formatM(tick)}
                        </span>
                    ))}
                </span>
                <span />
            </div>
            {sorted.map((manager) => {
                const r = range(manager, axis);
                const isMe = manager.id === connectedManagerId;

                return (
                    <button
                        key={manager.id}
                        type="button"
                        onClick={() => onSelectPayer(manager.id)}
                        title={`Ver qué puede pagar ${manager.name}`}
                        className="grid w-full cursor-pointer grid-cols-[92px_minmax(0,1fr)_78px] items-center gap-2 py-[3px] text-left hover:bg-hq-panel sm:grid-cols-[150px_minmax(0,1fr)_104px] sm:gap-3"
                    >
                        <span className="flex min-w-0 items-center gap-1.5 font-mono text-xs font-semibold text-hq-moss">
                            <ManagerSquare manager={manager} />
                            <span className="truncate">{manager.name}</span>
                        </span>
                        <span className="relative h-3.5 before:absolute before:inset-x-0 before:top-[6.5px] before:h-px before:bg-hq-border">
                            {isMe ? (
                                <b
                                    className="absolute inset-y-0 -ml-[5px] w-2.5 bg-hq-azure"
                                    style={{ left: `${x(r.mid)}%` }}
                                />
                            ) : (
                                <i
                                    className="absolute inset-y-0.5 border border-hq-khaki/75 bg-hq-khaki/30"
                                    style={{
                                        left: `${x(r.low)}%`,
                                        width: `${Math.max(0.6, x(r.high) - x(r.low))}%`,
                                    }}
                                />
                            )}
                            {reference !== null && (
                                <s
                                    className="absolute -inset-y-1 border-l border-dashed border-hq-azure"
                                    style={{ left: `${x(reference)}%` }}
                                />
                            )}
                        </span>
                        <span
                            className={cn(
                                'flex items-center justify-end gap-1 font-mono text-xs font-bold whitespace-nowrap tabular-nums',
                                isMe ? 'text-hq-azure' : 'text-hq-khaki',
                            )}
                        >
                            {isMe
                                ? formatM(r.mid)
                                : `${formatM(r.low)}–${formatM(r.high)}`}
                            {isMe && (
                                <BadgeCheck
                                    aria-label="Dato real"
                                    className="size-3"
                                />
                            )}
                        </span>
                    </button>
                );
            })}
            <p className="mt-1.5 flex flex-wrap items-center gap-x-3.5 gap-y-1 font-mono text-[11px] text-hq-moss-dim">
                {reference !== null && (
                    <span className="inline-flex items-center gap-1.5">
                        <s
                            aria-hidden="true"
                            className="inline-block h-3 border-l border-dashed border-hq-azure"
                        />
                        {axis === 'total' ? 'tu total' : 'tu caja'}{' '}
                        {formatM(reference)} M€
                    </span>
                )}
                <span className="inline-flex items-center gap-1.5">
                    <i
                        aria-hidden="true"
                        className="inline-block h-2 w-4 border border-hq-khaki/75 bg-hq-khaki/30"
                    />
                    {axis === 'total'
                        ? 'caja (pesimista–optimista) + plantilla'
                        : 'caja pesimista–optimista'}
                </span>
            </p>
        </div>
    );
}

/** The balances block: shared axis on top, one aligned card per manager below. */
export function RadarBalances({
    managers,
    clauses,
    connectedManagerId,
    selectedPayerId,
    onSelectPayer,
}: RadarBalancesProps) {
    const select = (managerId: number) =>
        onSelectPayer(selectedPayerId === managerId ? null : managerId);

    return (
        <section aria-labelledby="radar-balances">
            <h2
                id="radar-balances"
                className="flex items-center gap-1.5 px-3.5 pt-3 pb-2 text-[15px] font-black uppercase"
            >
                <Wallet aria-hidden="true" className="size-4 text-hq-moss" />
                Balances
            </h2>
            <SharedAxis
                managers={managers}
                connectedManagerId={connectedManagerId}
                onSelectPayer={select}
            />
            <div className="grid grid-cols-2 border-y border-hq-border md:grid-cols-4 xl:grid-cols-7">
                {managers.map((manager) => {
                    const isReal = manager.cash.is_real;
                    const openOthers = clauses.filter(
                        (c) => c.owner_id !== manager.id && c.state === 'open',
                    );
                    /** Mirrors ClauseRadar::payerLevel's "sure" rule (low >= amount) — keep them in sync. */
                    const paysSure = openOthers.filter(
                        (c) => manager.cash.low >= c.amount,
                    ).length;

                    return (
                        <button
                            key={manager.id}
                            type="button"
                            aria-pressed={selectedPayerId === manager.id}
                            onClick={() => select(manager.id)}
                            title={`Ver qué puede pagar ${manager.name}`}
                            className="flex min-w-0 cursor-pointer flex-col gap-1.5 border-r border-b border-hq-border p-3 text-left hover:bg-hq-panel aria-pressed:bg-hq-panel-alt aria-pressed:outline aria-pressed:-outline-offset-1 aria-pressed:outline-hq-lime"
                        >
                            <span className="flex min-w-0 items-center gap-2">
                                <img
                                    src={manager.logo}
                                    alt=""
                                    loading="lazy"
                                    className="size-7 shrink-0 border border-hq-border-strong bg-hq-panel-alt object-contain p-0.5"
                                />
                                <b className="truncate text-[12.5px] font-extrabold uppercase">
                                    {manager.name}
                                </b>
                            </span>
                            <span className="-mb-1 hq-label">Total</span>
                            <span className="flex items-baseline gap-1">
                                {isReal ? (
                                    <span className="font-dot text-[26px] leading-[0.9] font-black text-hq-azure tabular-nums">
                                        {formatM(manager.total.mid)}
                                    </span>
                                ) : (
                                    <HqLed tone="lime" className="text-[26px]">
                                        <small className="text-[13px]">~</small>
                                        {formatM(manager.total.mid)}
                                    </HqLed>
                                )}
                                <small className="font-mono text-[11px] font-bold text-hq-moss">
                                    M€
                                </small>
                                {isReal && (
                                    <BadgeCheck
                                        aria-label="Dato real"
                                        className="size-3 text-hq-azure"
                                    />
                                )}
                            </span>
                            <span className="grid grid-cols-1 gap-1 border-t border-hq-border pt-1.5 sm:grid-cols-2">
                                <span className="flex items-baseline justify-between gap-1 sm:flex-col sm:justify-start">
                                    <em className="flex items-center gap-1 font-mono text-[11px] tracking-[0.06em] text-hq-moss-dim uppercase not-italic">
                                        <Wallet
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                        Caja
                                    </em>
                                    <b
                                        className={cn(
                                            'truncate font-mono text-xs tabular-nums',
                                            isReal
                                                ? 'text-hq-azure'
                                                : 'text-hq-khaki',
                                        )}
                                    >
                                        {isReal
                                            ? formatM(manager.cash.mid)
                                            : `${formatM(manager.cash.low)}–${formatM(manager.cash.high)}`}
                                    </b>
                                </span>
                                <span className="flex items-baseline justify-between gap-1 sm:flex-col sm:justify-start">
                                    <em className="flex items-center gap-1 font-mono text-[11px] tracking-[0.06em] text-hq-moss-dim uppercase not-italic">
                                        <Users
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                        Plantilla
                                    </em>
                                    <b className="font-mono text-xs text-hq-paper tabular-nums">
                                        {formatM(manager.squad_value)}
                                    </b>
                                </span>
                            </span>
                            <span className="mt-auto flex items-center justify-between pt-1 font-mono text-[11.5px] font-semibold">
                                <span
                                    className="flex items-center gap-1 text-hq-lime"
                                    title="Cláusulas abiertas que paga seguro"
                                >
                                    <CircleCheck
                                        aria-hidden="true"
                                        className="size-3"
                                    />
                                    {paysSure}/{openOthers.length}
                                </span>
                                <HqShieldCount
                                    used={manager.shields.used}
                                    focusable={false}
                                />
                            </span>
                        </button>
                    );
                })}
            </div>
        </section>
    );
}
