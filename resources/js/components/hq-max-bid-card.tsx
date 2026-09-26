import type { CSSProperties } from 'react';
import { formatCurrency } from '@/lib/format';
import { STATUS_LABELS } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import type { MaxBidEstimate, PlayerStatus } from '@/types/models';

const OFFER_SPREAD = 0.1;

function formatMillions(amount: number): string {
    return `${(amount / 1_000_000).toLocaleString('es-ES', { maximumFractionDigits: 2 })} M€`;
}

function formatDaily(amount: number): string {
    const sign = amount >= 0 ? '+' : '−';
    const absolute = Math.abs(amount);
    const text =
        absolute >= 1_000_000
            ? `${(absolute / 1_000_000).toLocaleString('es-ES', { maximumFractionDigits: 2 })} M`
            : `${Math.round(absolute / 1_000).toLocaleString('es-ES')} k`;

    return `${sign}${text}/día`;
}

function formatSigned(value: number): string {
    return value.toLocaleString('es-ES', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        signDisplay: 'always',
    });
}

function toneClass(value: number): string {
    if (value > 0) {
        return 'text-hq-lime';
    }

    return value < 0 ? 'text-hq-live' : 'text-hq-paper';
}

function ProjectionChart({ estimate }: { estimate: MaxBidEstimate }) {
    const projection = estimate.projection ?? [];
    const width = 224;
    const height = 80;
    const pad = 4;
    const profitable = estimate.status === 'profitable';
    const low = Math.min(...projection) * (1 - OFFER_SPREAD);
    const high =
        Math.max(...projection, estimate.bid ?? 0) * (1 + OFFER_SPREAD);
    const x = (day: number) => pad + (day / 14) * (width - 2 * pad);
    const y = (amount: number) =>
        height - pad - ((amount - low) / (high - low)) * (height - 2 * pad);
    const line = projection.map((v, day) => `${x(day)},${y(v)}`).join(' ');
    const band = [
        ...projection.map((v, day) => `${x(day)},${y(v * (1 + OFFER_SPREAD))}`),
        ...projection
            .map((v, day) => `${x(day)},${y(v * (1 - OFFER_SPREAD))}`)
            .reverse(),
    ].join(' ');
    const stroke = profitable ? 'var(--color-hq-lime)' : 'var(--color-hq-live)';

    return (
        <svg
            viewBox={`0 0 ${width} ${height + 16}`}
            className="mt-3 block w-full"
            role="img"
            aria-label={`Proyección: ${formatMillions(projection[14])} en 14 días`}
        >
            <polygon points={band} fill={stroke} opacity={0.08} />
            <line
                x1={pad}
                x2={width - pad}
                y1={y(estimate.value)}
                y2={y(estimate.value)}
                stroke="var(--color-hq-border-strong)"
            />
            {estimate.bid !== null && (
                <>
                    <line
                        x1={pad}
                        x2={width - pad}
                        y1={y(estimate.bid)}
                        y2={y(estimate.bid)}
                        stroke="var(--color-hq-gold)"
                        strokeDasharray="4 3"
                    />
                    <text
                        x={width - pad}
                        y={y(estimate.bid) - 3}
                        textAnchor="end"
                        className="fill-hq-gold font-mono text-[11px]"
                    >
                        puja
                    </text>
                </>
            )}
            <polyline
                points={line}
                fill="none"
                stroke={stroke}
                strokeWidth={1.8}
            />
            <circle
                cx={x(0)}
                cy={y(estimate.value)}
                r={2.5}
                className="fill-hq-paper"
            />
            <text
                x={pad}
                y={height + 13}
                className="fill-hq-moss-dim font-mono text-[11px]"
            >
                hoy
            </text>
            <text
                x={width - pad}
                y={height + 13}
                textAnchor="end"
                className="fill-hq-moss-dim font-mono text-[11px]"
            >
                día 14 · {formatMillions(projection[14])}
            </text>
        </svg>
    );
}

function BreakdownRow({
    label,
    value,
    valueClass,
    nested = false,
}: {
    label: string;
    value: string;
    valueClass: string;
    nested?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-center justify-between gap-2 border-t border-hq-border py-1.5 font-mono text-[11px]',
                nested && 'pl-2.5',
            )}
        >
            <span className={nested ? 'text-hq-moss-dim' : 'text-hq-moss'}>
                {label}
            </span>
            <span className={cn('text-right font-bold', valueClass)}>
                {value}
            </span>
        </div>
    );
}

function Headline({
    estimate,
    playerStatus,
}: {
    estimate: MaxBidEstimate;
    playerStatus: PlayerStatus;
}) {
    if (estimate.status === 'profitable' && estimate.bid !== null) {
        return (
            <>
                <p className="mt-2 font-mono text-2xl font-bold text-hq-lime">
                    {formatCurrency(estimate.bid)}
                </p>
                <p className="font-mono text-[11px] text-hq-moss">
                    <span className="font-bold text-hq-lime">
                        {((estimate.bid_premium ?? 0) * 100).toLocaleString(
                            'es-ES',
                            { maximumFractionDigits: 1, signDisplay: 'always' },
                        )}{' '}
                        %
                    </span>{' '}
                    sobre su valor ({formatMillions(estimate.value)})
                </p>
            </>
        );
    }

    if (estimate.status === 'no_data') {
        return (
            <p className="mt-2 font-mono text-sm font-bold text-hq-moss uppercase">
                Sin datos suficientes
            </p>
        );
    }

    const reason =
        estimate.status === 'unavailable'
            ? STATUS_LABELS[playerStatus]
            : `Proyección a la baja: ${formatMillions((estimate.projected_day14 ?? estimate.value) - estimate.value)} en 14 días`;

    return (
        <>
            <p className="mt-2 font-mono text-lg font-bold text-hq-live uppercase">
                Sin rentabilidad
            </p>
            <p className="font-mono text-[11px] text-hq-moss">{reason}</p>
        </>
    );
}

interface HqMaxBidCardProps {
    estimate: MaxBidEstimate;
    playerStatus: PlayerStatus;
}

/**
 * Hidden "puja máxima rentable" card (only rendered with ?puja): the bid the
 * best daily league offer beats with 75 % probability during the 14-day
 * clause lock, the projected value, and the factors behind it.
 */
export function HqMaxBidCard({ estimate, playerStatus }: HqMaxBidCardProps) {
    const hasProjection = estimate.projection !== null;
    const profitable = estimate.status === 'profitable';

    return (
        <div
            className="hq-card-cut p-4"
            style={
                {
                    '--hq-card-tint': profitable
                        ? 'rgb(196 255 61 / 0.05)'
                        : 'rgb(255 61 90 / 0.05)',
                } as CSSProperties
            }
        >
            <div className="flex items-center justify-between font-mono text-[11px] font-bold tracking-wide text-hq-moss uppercase">
                <span>Puja máx. rentable</span>
                <span className="border border-dashed border-hq-border-strong px-1.5 text-hq-moss-dim">
                    ?puja
                </span>
            </div>

            <Headline estimate={estimate} playerStatus={playerStatus} />

            {hasProjection && (
                <>
                    <ProjectionChart estimate={estimate} />

                    <div className="mt-3">
                        <BreakdownRow
                            label="Momentum (3 días)"
                            value={formatDaily(
                                estimate.momentum_increment ?? 0,
                            )}
                            valueClass={toneClass(
                                estimate.momentum_increment ?? 0,
                            )}
                        />
                        <BreakdownRow
                            label="Mercado general"
                            value={formatDaily(estimate.market_adjustment ?? 0)}
                            valueClass={toneClass(
                                estimate.market_adjustment ?? 0,
                            )}
                        />
                        <BreakdownRow
                            label={`Deportivo (S ${formatSigned(estimate.sport_score ?? 0)})`}
                            value={formatDaily(estimate.sport_adjustment ?? 0)}
                            valueClass={toneClass(
                                estimate.sport_adjustment ?? 0,
                            )}
                        />
                        <BreakdownRow
                            nested
                            label="Forma"
                            value={formatSigned(estimate.form ?? 0)}
                            valueClass={toneClass(estimate.form ?? 0)}
                        />
                        <BreakdownRow
                            nested
                            label={`Participación ${estimate.recent_participation
                                .map((match) => `${match.minutes}'`)
                                .join(' · ')}`}
                            value={(estimate.participation ?? 0).toFixed(2)}
                            valueClass="text-hq-paper"
                        />
                        <BreakdownRow
                            nested
                            label="Rivales"
                            value={formatSigned(estimate.rivals_effect ?? 0)}
                            valueClass={toneClass(estimate.rivals_effect ?? 0)}
                        />
                        {estimate.upcoming_rivals.map((rival) => (
                            <div
                                key={`${rival.team.id}-${rival.days_until}`}
                                className="flex items-center justify-between gap-2 py-0.5 pl-5 font-mono text-[11px] text-hq-moss-dim"
                            >
                                <span className="flex min-w-0 items-center gap-1.5">
                                    <img
                                        src={rival.team.logo}
                                        alt=""
                                        className="h-4 w-4 shrink-0 object-contain"
                                    />
                                    <span className="truncate">
                                        {rival.team.short_name} (
                                        {rival.position}º) · {rival.days_until}{' '}
                                        d
                                    </span>
                                </span>
                                <span
                                    className={cn(
                                        'shrink-0',
                                        toneClass(rival.difficulty),
                                    )}
                                >
                                    {formatSigned(rival.difficulty)} ×{' '}
                                    {rival.weight.toFixed(2)}
                                </span>
                            </div>
                        ))}
                        <BreakdownRow
                            label="Proyección día 7 / 14"
                            value={`${formatMillions(estimate.projected_day7 ?? 0)} / ${formatMillions(estimate.projected_day14 ?? 0)}`}
                            valueClass="text-hq-paper"
                        />
                    </div>
                </>
            )}

            <p className="mt-3 border-t border-hq-border pt-2 font-mono text-[11px] leading-snug text-hq-moss-dim">
                Mejor oferta esperada durante los 14 días de blindaje · 75 % de
                confianza
            </p>
        </div>
    );
}
