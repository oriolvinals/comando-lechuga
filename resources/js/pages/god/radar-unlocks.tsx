import { Link, router } from '@inertiajs/react';
import {
    ArrowDownWideNarrow,
    ArrowUp,
    ChevronDown,
    Lock,
    LockOpen,
    Shield,
    ShieldCheck,
    Tag,
    Timer,
    User,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqCountdown } from '@/components/hq-countdown';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqPositionTag } from '@/components/hq-position-tag';
import { formatMatchDateTime, formatMillions } from '@/lib/format';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import {
    ManagerSquare,
    OverValue,
    PayerSquares,
    Segmented,
} from '@/pages/god/radar-helpers';
import { show as playersShow } from '@/routes/players';
import type { RadarClause, RadarManager } from '@/types/models';

const RELOAD_COOLDOWN_MS = 5_000;
const reloadedMoments = new Set<string>();
let lastReloadAt = 0;
let trailingReload: ReturnType<typeof setTimeout> | null = null;

function reloadRadarProps(): void {
    lastReloadAt = Date.now();
    router.reload({ only: ['clauses', 'market', 'managers', 'now'] });
}

/**
 * Reload the radar once per elapsed moment: countdowns of the same moment
 * (the table and the sidebar, or clauses that open together) share one
 * reload. A different moment inside the cooldown schedules one trailing
 * reload instead of being swallowed; it is dropped if the page changed.
 */
function reloadRadarFor(moment: string): void {
    if (reloadedMoments.has(moment)) {
        return;
    }

    reloadedMoments.add(moment);
    const sinceLastReload = Date.now() - lastReloadAt;

    if (sinceLastReload >= RELOAD_COOLDOWN_MS) {
        reloadRadarProps();

        return;
    }

    if (trailingReload !== null) {
        return;
    }

    const pathname = window.location.pathname;

    trailingReload = setTimeout(() => {
        trailingReload = null;

        if (window.location.pathname === pathname) {
            reloadRadarProps();
        }
    }, RELOAD_COOLDOWN_MS - sinceLastReload);
}

/**
 * A live countdown that reloads the radar props when it reaches zero — but
 * only when the moment was still ahead on mount. A target already past (the
 * browser clock ahead of the server's) would otherwise fire on every reload
 * and loop. Key it by `target` so a new moment gets a fresh check.
 */
export function ReloadingCountdown({ target }: { target: string }) {
    const [isAheadOnMount] = useState(
        () => new Date(target).getTime() > Date.now(),
    );
    const reload = useCallback(() => reloadRadarFor(target), [target]);

    return (
        <HqCountdown
            target={target}
            onElapsed={isAheadOnMount ? reload : undefined}
        />
    );
}

const BADGE_CLASS =
    'inline-flex h-[22px] items-center gap-1.5 border px-1.5 font-mono text-[11px] leading-none font-bold whitespace-nowrap';

/** Clause state as in the comparator: LockOpen lime, Lock gold + countdown, ShieldCheck azure + countdown, Tag lime. */
export function ClauseStateBadge({ clause }: { clause: RadarClause }) {
    if (clause.state === 'open') {
        return (
            <span
                className={cn(
                    BADGE_CLASS,
                    'border-hq-lime/40 bg-hq-lime/[0.07] text-hq-lime',
                )}
            >
                <LockOpen aria-hidden="true" className="size-3" />
                Abierta
            </span>
        );
    }

    if (clause.state === 'listed') {
        return (
            <span
                className={cn(BADGE_CLASS, 'border-hq-lime/40 text-hq-lime')}
                title="En venta: no se puede pagar la cláusula"
            >
                <Tag aria-hidden="true" className="size-3" />
                En venta
            </span>
        );
    }

    if (clause.state === 'shielded' && clause.shielded_until) {
        return (
            <span
                className={cn(BADGE_CLASS, 'border-hq-azure/40 text-hq-azure')}
                title="Blindado"
            >
                <ShieldCheck aria-hidden="true" className="size-3" />
                <ReloadingCountdown
                    key={clause.shielded_until}
                    target={clause.shielded_until}
                />
            </span>
        );
    }

    return (
        <span
            className={cn(BADGE_CLASS, 'border-hq-gold/40 text-hq-gold')}
            title="Bloqueada"
        >
            <Lock aria-hidden="true" className="size-3" />
            <ReloadingCountdown
                key={clause.locked_until}
                target={clause.locked_until}
            />
        </span>
    );
}

const SHIELD_ROW_CLASS =
    'grid cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-2 border-t border-hq-border px-3.5 py-1.5 hover:bg-hq-panel';

const HOUR_MS = 3600 * 1000;
const DAY_MS = 24 * HOUR_MS;
const UNLOCKS_PAGE = 12;
/** A projection this close to the clause isn't worth a line. */
const PROJECTION_SHOWN_ABOVE = 50_000;

type Bucket = 'now' | 'day' | 'three_days' | 'week' | 'later';
type UnlockSort = 'time' | 'gap' | 'rise' | 'clause';

/** Groups by time left to open; each one holds what opens before `before`. */
const BUCKETS: { key: Bucket; label: string; before: number }[] = [
    { key: 'now', label: 'Ahora', before: HOUR_MS },
    { key: 'day', label: '24 h', before: DAY_MS },
    { key: 'three_days', label: '72 h', before: 3 * DAY_MS },
    { key: 'week', label: '7 días', before: 7 * DAY_MS },
    { key: 'later', label: 'Después', before: Number.POSITIVE_INFINITY },
];

const BUCKET_DOT_CLASS: Record<Bucket, string> = {
    now: 'bg-hq-amber animate-hq-pulse motion-reduce:animate-none',
    day: 'bg-hq-gold',
    three_days: 'bg-hq-gold',
    week: 'bg-hq-moss-dim',
    later: 'bg-hq-moss-dim',
};

const COUNTDOWN_CLASS: Record<Bucket, string> = {
    now: 'text-hq-amber',
    day: 'text-hq-gold',
    three_days: 'text-hq-gold',
    week: 'text-hq-moss',
    later: 'text-hq-moss',
};

function unlocksAt(clause: RadarClause): number {
    return new Date(clause.locked_until).getTime();
}

function bucketOf(clause: RadarClause, now: number): Bucket {
    const remaining = unlocksAt(clause) - now;

    return BUCKETS.find((bucket) => remaining < bucket.before)?.key ?? 'later';
}

/** Clause minus value, relative to the value. */
function gapRatio(clause: RadarClause): number {
    return (
        (clause.amount - clause.player.market_value) /
        Math.max(1, clause.player.market_value)
    );
}

/** Today's value change, relative to the value. */
function riseRatio(clause: RadarClause): number {
    return (
        clause.player.market_value_difference /
        Math.max(1, clause.player.market_value)
    );
}

const UNLOCK_SORTS: Record<
    UnlockSort,
    (a: RadarClause, b: RadarClause) => number
> = {
    time: (a, b) => a.locked_until.localeCompare(b.locked_until),
    gap: (a, b) =>
        gapRatio(a) - gapRatio(b) ||
        a.locked_until.localeCompare(b.locked_until),
    rise: (a, b) => riseRatio(b) - riseRatio(a),
    clause: (a, b) => a.amount - b.amount,
};

/**
 * The clause when it opens if the value keeps today's pace: the clause
 * follows the value once the value passes it. Null when not worth showing.
 */
function projectedClause(clause: RadarClause, now: number): number | null {
    const dailyChange = clause.player.market_value_difference;

    if (dailyChange <= 0) {
        return null;
    }

    const days = Math.max(0, (unlocksAt(clause) - now) / DAY_MS);
    const projected = Math.round(
        clause.player.market_value + dailyChange * days,
    );

    return projected > clause.amount + PROJECTION_SHOWN_ABOVE
        ? projected
        : null;
}

const FIGURE_LABEL_CLASS =
    'font-mono text-[11px] leading-none tracking-[0.07em] text-hq-moss-dim uppercase not-italic';

/** One clause about to open: who, when, the numbers, and who can pay it. */
function UnlockRow({
    clause,
    bucket,
    owner,
    byId,
    isMine,
    connectedManagerId,
    myCash,
    now,
}: {
    clause: RadarClause;
    bucket: Bucket;
    owner: RadarManager | undefined;
    byId: Map<number, RadarManager>;
    isMine: boolean;
    connectedManagerId: number | null;
    myCash: number | null;
    now: number;
}) {
    const { player } = clause;
    const overValue = clause.amount - player.market_value;
    const overPercent = Math.round(
        (100 * overValue) / Math.max(1, player.market_value),
    );
    const projected = projectedClause(clause, now);
    const rivals = clause.payers.filter(
        (entry) => entry.manager_id !== connectedManagerId,
    );
    const left = myCash !== null ? myCash - clause.amount : null;

    return (
        <Link
            href={playersShow(player.id)}
            title={`Abrir ficha de ${player.nickname}`}
            className="grid cursor-pointer gap-[7px] border-t border-hq-border px-3.5 pt-[9px] pb-2.5 hover:bg-hq-panel"
        >
            <span className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-[9px]">
                <EntityImage
                    src={player.image}
                    alt={player.nickname}
                    fallback={User}
                    shape="square"
                    className="size-9 shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt object-cover object-top text-hq-moss-dim"
                />
                <span className="flex min-w-0 flex-col gap-1">
                    <b className="flex min-w-0 items-center gap-1.5 text-[13.5px] leading-tight font-extrabold">
                        <HqPositionTag position={player.position} />
                        <span className="truncate">{player.nickname}</span>
                    </b>
                    {owner && (
                        <span className="flex min-w-0 items-center gap-1.5 font-mono text-[11.5px] text-hq-moss">
                            <ManagerSquare manager={owner} />
                            <span className="truncate">{owner.name}</span>
                        </span>
                    )}
                </span>
                <span className="flex flex-col items-end gap-1">
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 text-[12.5px] font-bold',
                            COUNTDOWN_CLASS[bucket],
                        )}
                    >
                        <Lock aria-hidden="true" className="size-3" />
                        <ReloadingCountdown
                            key={clause.locked_until}
                            target={clause.locked_until}
                        />
                    </span>
                    <span className="font-mono text-[11px] whitespace-nowrap text-hq-moss-dim">
                        {formatMatchDateTime(clause.locked_until)}
                    </span>
                </span>
            </span>
            <span className="grid grid-cols-3 border border-hq-border">
                <span className="flex min-w-0 flex-col gap-1 border-r border-hq-border px-[7px] py-[5px]">
                    <em className={FIGURE_LABEL_CLASS}>Cláusula</em>
                    <b className="font-mono text-[12.5px] whitespace-nowrap tabular-nums">
                        {formatMillions(clause.amount)}
                    </b>
                    {projected !== null && (
                        <span
                            className="inline-flex items-center gap-0.5 font-mono text-[11px] font-semibold whitespace-nowrap text-hq-khaki tabular-nums"
                            title={`~${formatMillions(projected)} al abrir si el valor sigue subiendo igual`}
                        >
                            <ArrowUp aria-hidden="true" className="size-3" />~
                            {formatMillions(projected)}
                        </span>
                    )}
                </span>
                <span className="flex min-w-0 flex-col gap-1 border-r border-hq-border px-[7px] py-[5px]">
                    <em className={FIGURE_LABEL_CLASS}>Valor</em>
                    <span className="font-mono text-xs whitespace-nowrap text-hq-moss tabular-nums">
                        {formatMillions(player.market_value)}
                    </span>
                    <HqMarketValueDifference
                        difference={player.market_value_difference}
                        trend={player.market_trend}
                        className="text-[11px]"
                    />
                </span>
                <span className="flex min-w-0 flex-col gap-1 px-[7px] py-[5px]">
                    <em className={FIGURE_LABEL_CLASS}>Diferencia</em>
                    <OverValue overValue={overValue} compact />
                    {overPercent > 0 && (
                        <span className="font-mono text-[11px] text-hq-moss-dim tabular-nums">
                            +{overPercent} %
                        </span>
                    )}
                </span>
            </span>
            <span className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                {isMine ? (
                    <span className="inline-flex h-5 items-center gap-1 border border-hq-azure/40 px-1.5 font-mono text-[11px] font-bold tracking-[0.05em] text-hq-azure uppercase">
                        <User aria-hidden="true" className="size-3" />
                        Tuya
                    </span>
                ) : (
                    <PayerSquares payers={rivals} byId={byId} />
                )}
                {!isMine && left !== null && (
                    <span className="font-mono text-[11.5px] whitespace-nowrap text-hq-moss">
                        te queda{' '}
                        <b
                            className={cn(
                                'tabular-nums',
                                left < 0 ? 'text-hq-neg' : 'text-hq-paper',
                            )}
                        >
                            {formatMillions(left)}
                        </b>
                    </span>
                )}
            </span>
        </Link>
    );
}

/** Sidebar: locked clauses grouped by when they open, and the shielded ones, with live countdowns. */
export function RadarUnlocks({
    clauses,
    managers,
    connectedManagerId,
}: {
    clauses: RadarClause[];
    managers: RadarManager[];
    connectedManagerId: number | null;
}) {
    const [sort, setSort] = useState<UnlockSort>('time');
    /** «Ver más» expands only the order it was pressed for. */
    const [expandedFor, setExpandedFor] = useState<UnlockSort | null>(null);
    const now = useNow(60_000);
    const byId = new Map(managers.map((manager) => [manager.id, manager]));
    const connected =
        connectedManagerId !== null ? byId.get(connectedManagerId) : undefined;
    const myCash = connected?.cash.is_real ? connected.cash.mid : null;
    const locked = clauses
        .filter((clause) => clause.state === 'locked')
        .sort(UNLOCK_SORTS[sort]);
    const shielded = clauses.filter((clause) => clause.state === 'shielded');
    const limit =
        expandedFor === sort ? Number.POSITIVE_INFINITY : UNLOCKS_PAGE;
    const groups: ((typeof BUCKETS)[number] & {
        rows: RadarClause[];
        shown: RadarClause[];
    })[] = [];
    let remaining = limit;

    for (const bucket of BUCKETS) {
        const rows = locked.filter(
            (clause) => bucketOf(clause, now) === bucket.key,
        );

        if (rows.length === 0 || remaining <= 0) {
            continue;
        }

        const shown = rows.slice(0, remaining);
        remaining -= shown.length;
        groups.push({ ...bucket, rows, shown });
    }

    return (
        <aside
            aria-labelledby="radar-unlocks"
            className="min-w-0 border-t border-hq-border-strong xl:border-t-0 xl:border-l"
        >
            <h2
                id="radar-unlocks"
                className="flex items-center gap-1.5 px-3.5 pt-3 pb-2 text-[15px] font-black uppercase"
            >
                <Timer aria-hidden="true" className="size-4 text-hq-moss" />
                Se abren
                <small className="font-mono text-xs font-semibold text-hq-moss-dim normal-case">
                    {locked.length}
                </small>
            </h2>
            {locked.length === 0 ? (
                <p className="px-3.5 pb-3.5 text-[13px] text-hq-moss">
                    Ninguna bloqueada.
                </p>
            ) : (
                <div className="flex items-center gap-2 px-3.5 pb-2.5">
                    <ArrowDownWideNarrow
                        aria-hidden="true"
                        className="size-3.5 shrink-0 text-hq-moss-dim"
                    />
                    <div className="flex-1">
                        <Segmented<UnlockSort>
                            label="Orden"
                            value={sort}
                            onChange={setSort}
                            options={[
                                {
                                    value: 'time',
                                    label: 'Hora',
                                    title: 'Se abre antes',
                                },
                                {
                                    value: 'gap',
                                    label: 'Dif.',
                                    title: 'Cláusula más cerca del valor',
                                },
                                {
                                    value: 'rise',
                                    label: 'Sube',
                                    title: 'El valor sube más hoy',
                                },
                                {
                                    value: 'clause',
                                    label: 'Cláus.',
                                    title: 'Cláusula más baja',
                                },
                            ]}
                        />
                    </div>
                </div>
            )}
            {groups.map((group) => (
                <section key={group.key} aria-label={group.label}>
                    <h3 className="flex items-center justify-between gap-2 border-t border-hq-border-strong bg-hq-well px-3.5 pt-[7px] pb-1.5 font-mono text-[11px] leading-none font-bold tracking-[0.08em] text-hq-moss uppercase">
                        <span className="inline-flex items-center gap-1.5 text-hq-paper">
                            <i
                                aria-hidden="true"
                                className={cn(
                                    'block size-1.5',
                                    BUCKET_DOT_CLASS[group.key],
                                )}
                            />
                            {group.label}
                        </span>
                        {group.rows.length}
                    </h3>
                    {group.shown.map((clause) => (
                        <UnlockRow
                            key={clause.player.id}
                            clause={clause}
                            bucket={group.key}
                            owner={byId.get(clause.owner_id)}
                            byId={byId}
                            isMine={clause.owner_id === connectedManagerId}
                            connectedManagerId={connectedManagerId}
                            myCash={myCash}
                            now={now}
                        />
                    ))}
                </section>
            ))}
            {locked.length > limit && (
                <div className="flex justify-center border-t border-hq-border p-2.5">
                    <button
                        type="button"
                        onClick={() => setExpandedFor(sort)}
                        className="inline-flex min-h-[34px] cursor-pointer items-center gap-1.5 border border-hq-border-strong px-3 font-mono text-[11.5px] font-bold tracking-[0.05em] text-hq-moss uppercase hover:border-hq-lime hover:text-hq-lime"
                    >
                        <ChevronDown aria-hidden="true" className="size-3" />
                        Ver {locked.length - limit} más
                    </button>
                </div>
            )}
            <h2 className="mt-1.5 flex items-center gap-1.5 border-t border-hq-border-strong px-3.5 pt-3 pb-2 text-[15px] font-black uppercase">
                <ShieldCheck
                    aria-hidden="true"
                    className="size-4 text-hq-moss"
                />
                Blindados
            </h2>
            {shielded.length === 0 ? (
                <p className="flex items-center gap-1.5 px-3.5 pb-3.5 text-[13px] text-hq-moss">
                    <Shield
                        aria-hidden="true"
                        className="size-3.5 text-hq-moss-dim"
                    />
                    Ninguno ahora.
                </p>
            ) : (
                shielded.map((clause) => (
                    <Link
                        key={clause.player.id}
                        href={playersShow(clause.player.id)}
                        title={`Abrir ficha de ${clause.player.nickname}`}
                        className={SHIELD_ROW_CLASS}
                    >
                        <HqPositionTag position={clause.player.position} />
                        <b className="truncate text-[13px] font-extrabold">
                            {clause.player.nickname}
                        </b>
                        <ClauseStateBadge clause={clause} />
                    </Link>
                ))
            )}
        </aside>
    );
}
