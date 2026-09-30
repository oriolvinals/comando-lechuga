import { router } from '@inertiajs/react';
import {
    ArrowDownWideNarrow,
    ChevronDown,
    CircleCheck,
    CircleHelp,
    Crosshair,
    List,
    LockOpen,
    Timer,
    User,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqManagerChip } from '@/components/hq-manager-chip';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { formatAverage, formatMillions } from '@/lib/format';
import { POSITION_ABBREVIATIONS } from '@/lib/player-labels';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { ManagerSquare, Segmented } from '@/pages/god/radar-helpers';
import { ClauseStateBadge } from '@/pages/god/radar-unlocks';
import { show as playersShow } from '@/routes/players';
import type { PlayerPosition, RadarClause, RadarManager } from '@/types/models';

type Scope = 'open' | 'soon' | 'all';
type Sort = 'opportunity' | 'clause' | 'average' | 'soon';

const PAGE = 14;
const MAX_PAYERS_SHOWN = 5;
const SEVENTY_TWO_HOURS = 72 * 3600 * 1000;
const POSITIONS: (PlayerPosition | 'all')[] = [
    'all',
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
];
const PAYER_LEVEL_LABELS = {
    sure: 'paga seguro',
    maybe: 'quizá',
    no: 'no llega',
} as const;

/** Under `formatMillions`' 0,01 M€ precision the difference would print as "0 M€". */
const SAME_AS_VALUE_BELOW = 5_000;

/** "+5,35 M€ sobre valor", "−1,2 M€ bajo valor" or "igual al valor". */
function describeOverValue(overValue: number): string {
    if (Math.abs(overValue) < SAME_AS_VALUE_BELOW) {
        return 'igual al valor';
    }

    return overValue > 0
        ? `+${formatMillions(overValue)} sobre valor`
        : `−${formatMillions(-overValue)} bajo valor`;
}

interface RadarClausesProps {
    clauses: RadarClause[];
    managers: RadarManager[];
    connectedManagerId: number | null;
    payerId: number | null;
    onPayerChange: (managerId: number | null) => void;
}

const SELECT_CLASS =
    'min-h-8 min-w-0 flex-1 cursor-pointer border border-hq-border-strong bg-hq-panel px-2 font-mono text-xs font-semibold text-hq-paper hover:border-hq-border-bright sm:flex-none';

/** The clause radar: filters, sorts, and one row per squad player. */
export function RadarClauses({
    clauses,
    managers,
    connectedManagerId,
    payerId,
    onPayerChange,
}: RadarClausesProps) {
    const [ownerId, setOwnerId] = useState<number | null>(null);
    const [position, setPosition] = useState<PlayerPosition | 'all'>('all');
    const [scope, setScope] = useState<Scope>('open');
    const [sort, setSort] = useState<Sort>('opportunity');
    const filterSignature = [payerId, ownerId, position, scope, sort].join('|');
    /** «Ver más» expands only the filters it was pressed for; any change goes back to 14 rows. */
    const [expandedFor, setExpandedFor] = useState<string | null>(null);

    if (expandedFor !== null && expandedFor !== filterSignature) {
        setExpandedFor(null);
    }

    const limit =
        expandedFor === filterSignature ? Number.POSITIVE_INFINITY : PAGE;
    const now = useNow(60_000);
    const byId = new Map(managers.map((manager) => [manager.id, manager]));
    const payer = payerId !== null ? (byId.get(payerId) ?? null) : null;
    const payerLow = payer
        ? payer.cash.is_real
            ? payer.cash.mid
            : payer.cash.low
        : 0;
    const payerHigh = payer
        ? payer.cash.is_real
            ? payer.cash.mid
            : payer.cash.high
        : 0;

    const rows = clauses
        .filter((clause) => {
            if (scope === 'open' && clause.state !== 'open') {
                return false;
            }

            if (
                scope === 'soon' &&
                !(
                    clause.state === 'locked' &&
                    new Date(clause.locked_until).getTime() - now <
                        SEVENTY_TWO_HOURS
                )
            ) {
                return false;
            }

            if (position !== 'all' && clause.player.position !== position) {
                return false;
            }

            if (ownerId !== null && clause.owner_id !== ownerId) {
                return false;
            }

            if (
                payer &&
                (clause.owner_id === payer.id || payerHigh < clause.amount)
            ) {
                return false;
            }

            return true;
        })
        .sort((a, b) => {
            if (sort === 'clause') {
                return a.amount - b.amount;
            }

            if (sort === 'average') {
                return b.player.average_points - a.player.average_points;
            }

            if (sort === 'soon') {
                return a.locked_until.localeCompare(b.locked_until);
            }

            return b.opportunity - a.opportunity || a.amount - b.amount;
        });

    const changeScope = (value: Scope) => {
        setScope(value);

        if (value === 'soon') {
            setSort('soon');
        } else if (sort === 'soon') {
            setSort('opportunity');
        }
    };

    return (
        <section aria-labelledby="radar-clauses" className="min-w-0">
            <h2
                id="radar-clauses"
                className="flex items-center gap-1.5 px-3.5 pt-3 pb-2 text-[15px] font-black uppercase"
            >
                <Crosshair aria-hidden="true" className="size-4 text-hq-moss" />
                Cláusulas
                <small className="font-mono text-xs font-semibold text-hq-moss-dim normal-case">
                    {rows.length}
                    {payer && ` · paga ${payer.name}`}
                </small>
            </h2>
            <div className="flex flex-wrap items-center gap-2 px-3.5 pb-2.5 sm:gap-3.5">
                <label className="flex w-full items-center gap-1.5 sm:w-auto">
                    <Wallet
                        aria-hidden="true"
                        className="size-3.5 shrink-0 text-hq-moss-dim"
                    />
                    <span className="sr-only">Quién paga</span>
                    <select
                        className={SELECT_CLASS}
                        value={payerId ?? ''}
                        onChange={(event) =>
                            onPayerChange(
                                event.target.value
                                    ? Number(event.target.value)
                                    : null,
                            )
                        }
                    >
                        <option value="">Paga: cualquiera</option>
                        {managers.map((manager) => (
                            <option key={manager.id} value={manager.id}>
                                {manager.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex w-full items-center gap-1.5 sm:w-auto">
                    <User
                        aria-hidden="true"
                        className="size-3.5 shrink-0 text-hq-moss-dim"
                    />
                    <span className="sr-only">Dueño</span>
                    <select
                        className={SELECT_CLASS}
                        value={ownerId ?? ''}
                        onChange={(event) =>
                            setOwnerId(
                                event.target.value
                                    ? Number(event.target.value)
                                    : null,
                            )
                        }
                    >
                        <option value="">Dueño: todos</option>
                        {managers.map((manager) => (
                            <option key={manager.id} value={manager.id}>
                                {manager.name}
                            </option>
                        ))}
                    </select>
                </label>
                <div className="w-full sm:w-auto">
                    <Segmented
                        label="Posición"
                        value={position}
                        onChange={setPosition}
                        options={POSITIONS.map((value) => ({
                            value,
                            label:
                                value === 'all'
                                    ? 'Todas'
                                    : POSITION_ABBREVIATIONS[value],
                        }))}
                    />
                </div>
                <div className="w-full sm:w-auto">
                    <Segmented<Scope>
                        label="Estado"
                        value={scope}
                        onChange={changeScope}
                        options={[
                            {
                                value: 'open',
                                label: 'Abiertas',
                                icon: LockOpen,
                            },
                            {
                                value: 'soon',
                                label: '72 h',
                                icon: Timer,
                                title: 'Se abren en menos de 72 h',
                            },
                            { value: 'all', label: 'Todas', icon: List },
                        ]}
                    />
                </div>
                <label className="flex w-full items-center gap-1.5 sm:w-auto">
                    <ArrowDownWideNarrow
                        aria-hidden="true"
                        className="size-3.5 shrink-0 text-hq-moss-dim"
                    />
                    <span className="sr-only">Orden</span>
                    <select
                        className={SELECT_CLASS}
                        value={sort}
                        onChange={(event) =>
                            setSort(event.target.value as Sort)
                        }
                    >
                        <option value="opportunity">Mejor oportunidad</option>
                        <option value="clause">Cláusula más baja</option>
                        <option value="average">Mejor media</option>
                        <option value="soon">Se abre antes</option>
                    </select>
                </label>
            </div>

            {rows.length === 0 ? (
                <HqEmptyState title="Ninguna cláusula con estos filtros." />
            ) : (
                <table className="w-full border-collapse max-[860px]:block">
                    <thead className="max-[860px]:hidden">
                        <tr className="border-y border-hq-border font-mono text-[11px] tracking-[0.07em] text-hq-moss-dim uppercase">
                            <th
                                scope="col"
                                className="py-1.5 pr-2 pl-3.5 text-left font-medium"
                            >
                                Jugador
                            </th>
                            <th
                                scope="col"
                                className="px-2 text-left font-medium"
                            >
                                Dueño
                            </th>
                            <th
                                scope="col"
                                className="px-2 text-right font-medium"
                            >
                                Cláusula
                            </th>
                            <th
                                scope="col"
                                className="px-2 text-right font-medium"
                            >
                                Valor
                            </th>
                            <th
                                scope="col"
                                className="px-2 text-right font-medium"
                            >
                                Media
                            </th>
                            <th
                                scope="col"
                                className="px-2 text-right font-medium"
                            >
                                Oport.
                            </th>
                            <th
                                scope="col"
                                className="px-2 text-left font-medium"
                            >
                                {payer ? 'Le queda' : 'Pueden pagar'}
                            </th>
                            <th
                                scope="col"
                                className="py-1.5 pr-3.5 pl-2 text-left font-medium"
                            >
                                Estado
                            </th>
                        </tr>
                    </thead>
                    <tbody className="max-[860px]:block">
                        {rows.slice(0, limit).map((clause) => {
                            const owner = byId.get(clause.owner_id);
                            const overValue =
                                clause.amount - clause.player.market_value;
                            const rivals = clause.payers.filter(
                                (entry) =>
                                    entry.manager_id !== connectedManagerId,
                            );
                            const sure = rivals.filter(
                                (entry) => entry.level === 'sure',
                            ).length;
                            const maybe = rivals.filter(
                                (entry) => entry.level === 'maybe',
                            ).length;
                            const left = payerLow - clause.amount;
                            const fichaUrl = playersShow(clause.player.id).url;
                            const isDimmed = clause.state !== 'open';

                            return (
                                <tr
                                    key={clause.player.id}
                                    tabIndex={0}
                                    onClick={() => router.visit(fichaUrl)}
                                    onKeyDown={(event) => {
                                        if (
                                            event.key === 'Enter' &&
                                            event.target === event.currentTarget
                                        ) {
                                            router.visit(fichaUrl);
                                        }
                                    }}
                                    title={`Abrir ficha de ${clause.player.nickname}`}
                                    className="cursor-pointer border-b border-hq-border hover:bg-hq-panel max-[860px]:grid max-[860px]:grid-cols-[minmax(0,1fr)_auto] max-[860px]:gap-x-3 max-[860px]:gap-y-2 max-[860px]:px-3.5 max-[860px]:py-2.5"
                                >
                                    <td className="py-1.5 pr-2 pl-3.5 max-[860px]:p-0">
                                        <div className="flex min-w-0 items-center gap-2">
                                            <HqPositionTag
                                                position={
                                                    clause.player.position
                                                }
                                            />
                                            <div className="min-w-0">
                                                <b className="block truncate text-[13.5px] font-extrabold">
                                                    {clause.player.nickname}
                                                </b>
                                                <span className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 font-mono text-[11px] text-hq-moss-dim">
                                                    <span>
                                                        {
                                                            clause.player
                                                                .team_short_name
                                                        }{' '}
                                                        · {clause.player.points}{' '}
                                                        pts
                                                        <span className="min-[861px]:hidden">
                                                            {' '}
                                                            · media{' '}
                                                            {formatAverage(
                                                                clause.player
                                                                    .average_points,
                                                            )}
                                                        </span>
                                                    </span>
                                                    <HqStatusBadge
                                                        status={
                                                            clause.player.status
                                                        }
                                                    />
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td
                                        className={cn(
                                            'px-2 max-[860px]:col-span-2 max-[860px]:p-0',
                                            isDimmed && 'opacity-60',
                                        )}
                                    >
                                        {owner && (
                                            <button
                                                type="button"
                                                title={`Filtrar por ${owner.name}`}
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    setOwnerId(owner.id);
                                                }}
                                                className="max-w-full cursor-pointer hover:underline"
                                            >
                                                <HqManagerChip
                                                    manager={owner}
                                                    link={false}
                                                />
                                            </button>
                                        )}
                                    </td>
                                    <td className="px-2 text-right max-[860px]:p-0 max-[860px]:text-left">
                                        <span className="flex flex-col items-end gap-0.5 max-[860px]:items-start">
                                            <b className="font-mono text-[13px] whitespace-nowrap tabular-nums">
                                                {formatMillions(clause.amount)}
                                            </b>
                                            <span
                                                className={cn(
                                                    'font-mono text-[11.5px] whitespace-nowrap tabular-nums',
                                                    overValue <
                                                        SAME_AS_VALUE_BELOW
                                                        ? 'text-hq-lime'
                                                        : 'text-hq-moss-dim',
                                                )}
                                            >
                                                {describeOverValue(overValue)}
                                            </span>
                                        </span>
                                    </td>
                                    <td className="px-2 text-right max-[860px]:p-0">
                                        <span className="flex flex-col items-end gap-0.5">
                                            <span className="font-mono text-xs whitespace-nowrap text-hq-moss tabular-nums">
                                                {formatMillions(
                                                    clause.player.market_value,
                                                )}
                                            </span>
                                            <HqMarketValueDifference
                                                difference={
                                                    clause.player
                                                        .market_value_difference
                                                }
                                                trend={
                                                    clause.player.market_trend
                                                }
                                            />
                                        </span>
                                    </td>
                                    <td
                                        className={cn(
                                            'px-2 text-right font-mono text-[13px] font-bold tabular-nums max-[860px]:hidden',
                                            isDimmed && 'opacity-60',
                                        )}
                                    >
                                        {formatAverage(
                                            clause.player.average_points,
                                        )}
                                    </td>
                                    <td className="px-2 text-right max-[860px]:p-0 max-[860px]:text-left">
                                        <span
                                            className="inline-flex items-center gap-1.5"
                                            title="Oportunidad: valor/cláusula × forma"
                                        >
                                            <span className="relative h-[5px] w-[46px] bg-hq-border">
                                                <i
                                                    className="absolute inset-y-0 left-0 bg-hq-lime"
                                                    style={{
                                                        width: `${clause.opportunity}%`,
                                                    }}
                                                />
                                            </span>
                                            <b className="font-mono text-[12.5px] tabular-nums">
                                                {clause.opportunity}
                                            </b>
                                        </span>
                                    </td>
                                    <td
                                        className={cn(
                                            'px-2 max-[860px]:p-0 max-[860px]:text-right',
                                            isDimmed && 'opacity-60',
                                        )}
                                    >
                                        {payer ? (
                                            <b
                                                className={cn(
                                                    'font-mono text-[13px] whitespace-nowrap tabular-nums',
                                                    left < 0 && 'text-hq-neg',
                                                )}
                                            >
                                                {formatMillions(left)}
                                            </b>
                                        ) : (
                                            <span
                                                className="inline-flex items-center gap-1"
                                                aria-label={`${sure} pagan seguro, ${maybe} quizá`}
                                            >
                                                {rivals
                                                    .slice(0, MAX_PAYERS_SHOWN)
                                                    .map((entry) => {
                                                        const manager =
                                                            byId.get(
                                                                entry.manager_id,
                                                            );

                                                        if (!manager) {
                                                            return null;
                                                        }

                                                        return (
                                                            <span
                                                                key={
                                                                    entry.manager_id
                                                                }
                                                                title={`${manager.name}: ${PAYER_LEVEL_LABELS[entry.level]}`}
                                                                className="inline-flex"
                                                            >
                                                                <ManagerSquare
                                                                    manager={
                                                                        manager
                                                                    }
                                                                    variant={
                                                                        entry.level
                                                                    }
                                                                />
                                                            </span>
                                                        );
                                                    })}
                                                <span className="ml-1 inline-flex items-center gap-0.5 font-mono text-[11.5px] font-bold text-hq-lime">
                                                    <CircleCheck
                                                        aria-hidden="true"
                                                        className="size-3"
                                                    />
                                                    {sure}
                                                </span>
                                                {maybe > 0 && (
                                                    <span className="inline-flex items-center gap-0.5 font-mono text-[11.5px] font-bold text-hq-khaki">
                                                        <CircleHelp
                                                            aria-hidden="true"
                                                            className="size-3"
                                                        />
                                                        {maybe}
                                                    </span>
                                                )}
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-1.5 pr-3.5 pl-2 max-[860px]:col-start-2 max-[860px]:row-start-1 max-[860px]:p-0 max-[860px]:text-right">
                                        <ClauseStateBadge clause={clause} />
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            )}
            {rows.length > limit && (
                <div className="flex justify-center p-2.5">
                    <button
                        type="button"
                        onClick={() => setExpandedFor(filterSignature)}
                        className="inline-flex min-h-[34px] cursor-pointer items-center gap-1.5 border border-hq-border-strong px-3 font-mono text-[11.5px] font-bold tracking-[0.05em] text-hq-moss uppercase hover:border-hq-lime hover:text-hq-lime"
                    >
                        <ChevronDown aria-hidden="true" className="size-3" />
                        Ver {rows.length - limit} más
                    </button>
                </div>
            )}
            {!payer && rows.length > 0 && (
                <div
                    aria-label="Leyenda"
                    className="flex flex-wrap gap-x-3.5 gap-y-1 border-t border-hq-border px-3.5 py-2 font-mono text-[11.5px] text-hq-moss-dim"
                >
                    {(['sure', 'maybe', 'no'] as const).map((level) => (
                        <span
                            key={level}
                            className="inline-flex items-center gap-1.5"
                        >
                            <ManagerSquare
                                manager={{ name: ' ', primary_color: null }}
                                variant={level}
                            />
                            {PAYER_LEVEL_LABELS[level]}
                        </span>
                    ))}
                </div>
            )}
        </section>
    );
}
