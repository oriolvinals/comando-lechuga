import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { startBadgeTierClass } from '@/components/hq-lineup-pitch';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { HqTooltip } from '@/components/hq-tooltip';
import { formatMatchDateShort } from '@/lib/format';
import {
    START_TONE_BG_CLASSES,
    START_TONE_BORDER_CLASSES,
    START_TONE_COLOR_VARS,
    START_TONE_TEXT_CLASSES,
    dataAgeDays,
    dataAgeTooltipLabel,
    isUnavailable,
    meterFill,
    startOutcome,
    startTone,
} from '@/lib/start-probability';
import type {
    StartFacts,
    StartOutcome,
    StartTone,
} from '@/lib/start-probability';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { PlayerStatus, StartProbabilityEntry } from '@/types/models';

/**
 * FútbolFantasy's % as a 10-cell bar plus the figure (mock `.t-meter` +
 * `.t-pct`) — the one bar used by the match list, the team aside and the
 * manager roster. Cells are lit at half-cell (5 %) precision — e.g. 75 % is
 * 7 full cells plus one half. Muted (desaturated) while the data is stale.
 * With `fetchedAt`, hovering or focusing the bar shows a tooltip with how
 * old FútbolFantasy's data is.
 */
export function HqStartMeter({
    probability,
    status,
    size = 'md',
    muted = false,
    fetchedAt = null,
    className,
}: {
    probability: number | null;
    status: PlayerStatus;
    size?: 'md' | 'sm';
    muted?: boolean;
    fetchedAt?: string | null;
    className?: string;
}) {
    const now = useNow(60_000);
    const tone = startTone(probability, status);
    const { full, half } = meterFill(probability);
    const label =
        probability === null
            ? 'Sin probabilidad de FútbolFantasy'
            : `${probability} % de ser titular`;

    const bar = (
        <span
            title={fetchedAt === null ? label : undefined}
            className={cn(
                'inline-flex shrink-0 items-center',
                size === 'sm' ? 'gap-1.5' : 'gap-2',
                muted && 'opacity-60 saturate-[.15]',
                fetchedAt === null && className,
            )}
        >
            <span aria-hidden="true" className="inline-flex gap-0.5">
                {Array.from({ length: 10 }, (_, cell) => {
                    const isHalf = cell === full && half;

                    return (
                        <i
                            key={cell}
                            className={cn(
                                'block',
                                size === 'sm'
                                    ? 'h-[9px] w-[3px]'
                                    : 'h-[11px] w-[5px]',
                                cell < full && START_TONE_BG_CLASSES[tone],
                                cell >= full &&
                                    !isHalf &&
                                    'bg-hq-border-strong',
                            )}
                            style={
                                isHalf
                                    ? {
                                          background: `linear-gradient(to right, ${START_TONE_COLOR_VARS[tone]} 50%, var(--color-hq-border-strong) 50%)`,
                                      }
                                    : undefined
                            }
                        />
                    );
                })}
            </span>
            <b
                className={cn(
                    'text-right font-mono leading-none font-bold whitespace-nowrap tabular-nums',
                    size === 'sm' ? 'text-[11px]' : 'min-w-[34px] text-xs',
                    START_TONE_TEXT_CLASSES[tone],
                )}
            >
                <span className="sr-only">{label}</span>
                <span aria-hidden="true">
                    {probability === null ? '—' : `${probability}%`}
                </span>
            </b>
        </span>
    );

    if (fetchedAt === null) {
        return bar;
    }

    return (
        <HqTooltip
            label={dataAgeTooltipLabel(fetchedAt, now)}
            focusable
            className={className}
        >
            {bar}
        </HqTooltip>
    );
}

const OUTCOME_CLASSES: Record<StartOutcome, string> = {
    starter: 'bg-hq-lime/8 text-hq-lime',
    bench: 'text-hq-moss-dim',
    surprise: 'bg-hq-gold/8 text-hq-gold',
    dropped: 'bg-hq-ember/8 text-hq-ember',
};

const OUTCOME_TITLES: Record<StartOutcome, string> = {
    starter: 'Titular confirmado',
    bench: 'Suplente confirmado',
    surprise: 'No estaba en el XI probable de FútbolFantasy',
    dropped: 'Estaba en el XI probable de FútbolFantasy',
};

function outcomeLabel(
    outcome: StartOutcome,
    probability: number | null,
): string {
    const was = probability === null ? '' : ` · era ${probability} %`;

    switch (outcome) {
        case 'starter':
            return 'Titular';
        case 'bench':
            return 'Suplente';
        case 'surprise':
            return `Sorpresa${was}`;
        case 'dropped':
            return `Se cae${was}`;
    }
}

/**
 * Once the lineup is confirmed: lime "Titular", dim "Suplente", gold
 * "Sorpresa · era N %" and ember "Se cae · era N %" against FútbolFantasy's
 * probable XI. Nothing while unconfirmed.
 */
export function HqStartOutcomeChip({
    facts,
    className,
}: {
    facts: StartFacts;
    className?: string;
}) {
    const outcome = startOutcome(facts);

    if (outcome === null) {
        return null;
    }

    return (
        <span
            title={OUTCOME_TITLES[outcome]}
            className={cn(
                'inline-flex h-[17px] shrink-0 items-center border border-current px-[5px] font-mono text-[9.5px] leading-none font-bold tracking-[0.06em] whitespace-nowrap uppercase',
                OUTCOME_CLASSES[outcome],
                className,
            )}
        >
            {outcomeLabel(outcome, facts.probability)}
        </span>
    );
}

/**
 * One player row (mock `.t-lrow`): photo with the position tag, the name
 * and our status badge inline, then the 10-cell bar/% — or the outcome chip
 * once confirmed. Same row the match ficha's start-probability lists use,
 * shared here for the team ficha's full non-XI roster (bench, doubts and
 * bajas) below its probable-XI pitch.
 */
export function HqStartRosterRow({
    entry,
    confirmed,
    dim = false,
    muted = false,
    fetchedAt = null,
}: {
    entry: StartProbabilityEntry;
    confirmed: boolean;
    dim?: boolean;
    muted?: boolean;
    fetchedAt?: string | null;
}) {
    const unavailable = isUnavailable(entry.player.status);

    return (
        <div
            className={cn(
                'relative grid min-h-[50px] cursor-pointer grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-x-2.5 border-b border-hq-border px-3.5 py-[7px] transition-colors hover:bg-hq-panel sm:px-4',
                dim && 'opacity-60',
            )}
        >
            <span className="relative block h-9 w-9">
                <EntityImage
                    src={entry.player.image}
                    alt=""
                    fallback={User}
                    shape="square"
                    className="h-9 w-9 rounded-none border border-hq-border-strong bg-hq-well object-cover"
                    style={{ objectPosition: 'center 20%' }}
                />
                <HqPositionTag
                    position={entry.player.position}
                    className="absolute -bottom-1.5 left-1/2 -translate-x-1/2 bg-hq-ink px-[3px] py-0.5 text-[8.5px]"
                />
            </span>
            <div className="min-w-0">
                <div className="flex min-w-0 items-center gap-1.5">
                    <Link
                        href={playersShow(entry.player.id).url}
                        className="min-w-0 flex-1 truncate text-[13.5px] leading-[1.2] font-bold text-hq-paper outline-none after:absolute after:inset-0 after:content-[''] hover:text-hq-lime focus-visible:text-hq-lime focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-hq-lime"
                    >
                        {entry.player.nickname}
                    </Link>
                    {entry.player.status !== 'ok' && (
                        <HqStatusBadge
                            status={entry.player.status}
                            className="shrink-0"
                        />
                    )}
                </div>
                {unavailable && (entry.probability ?? 0) > 0 && (
                    <span className="mt-1 block font-mono text-[11px] leading-[1.2] text-hq-moss-dim">
                        FF aún le da {entry.probability} %
                    </span>
                )}
            </div>
            <div className="relative z-10 flex items-center justify-end">
                {confirmed ? (
                    <HqStartOutcomeChip facts={entry} />
                ) : (
                    <HqStartMeter
                        probability={entry.probability}
                        status={entry.player.status}
                        muted={muted}
                        fetchedAt={fetchedAt}
                    />
                )}
            </div>
        </div>
    );
}

/** "● Probable" / "● Probable · antigua" / "● Confirmada". */
export function HqStartStateLabel({
    confirmed,
    stale,
}: {
    confirmed: boolean;
    stale: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 font-bold',
                confirmed
                    ? 'text-hq-lime'
                    : stale
                      ? 'text-hq-gold'
                      : 'text-hq-khaki',
            )}
        >
            <i
                aria-hidden="true"
                className="block h-[7px] w-[7px] rounded-full bg-current"
            />
            {confirmed
                ? 'Confirmada'
                : stale
                  ? 'Probable · antigua'
                  : 'Probable'}
        </span>
    );
}

/** Striped gold banner for data older than 48 h (mock `.t-stale`). */
export function HqStartStaleBanner({
    fetchedAt,
    now,
}: {
    fetchedAt: string;
    now: number;
}) {
    const days = Math.max(2, dataAgeDays(fetchedAt, now));

    return (
        <div
            role="status"
            className="flex flex-col gap-1 border-b border-hq-gold/35 bg-[repeating-linear-gradient(135deg,rgb(232_193_74/0.07)_0_8px,transparent_8px_16px)] px-3.5 py-2.5 font-mono text-xs leading-[1.45] text-hq-khaki sm:flex-row sm:items-start sm:gap-2.5 sm:px-4"
        >
            <b className="whitespace-nowrap text-hq-gold">
                ▲ Datos de hace {days} días
            </b>
            <span>
                No se ha podido leer FútbolFantasy desde el{' '}
                {formatMatchDateShort(fetchedAt)}. Los % pueden no reflejar la
                última rueda de prensa; las lesiones sí están al día.
            </span>
        </div>
    );
}

export interface StartSource {
    /** The team's short name, shown as the link text. */
    label: string;
    /** Its FútbolFantasy team page. */
    url: string;
}

/**
 * "Probabilidades: FútbolFantasy" with a link to each team page — on every
 * surface that shows a % — plus the lineup source once worldcup26 confirmed
 * one, and where injuries come from.
 */
export function HqStartAttribution({
    sources,
    confirmedByWorldcup26 = false,
    className,
}: {
    sources: StartSource[];
    confirmedByWorldcup26?: boolean;
    className?: string;
}) {
    const links = sources.filter(
        (source, index) =>
            source.url !== '' &&
            sources.findIndex((other) => other.url === source.url) === index,
    );

    return (
        <p
            className={cn(
                'flex flex-wrap items-center gap-x-2.5 gap-y-1 border-t border-hq-border px-3.5 py-2.5 font-mono text-[11px] leading-[1.4] text-hq-moss-dim sm:px-4',
                className,
            )}
        >
            {confirmedByWorldcup26 && (
                <>
                    <span>Alineación: worldcup26</span>
                    <span aria-hidden="true" className="text-hq-border-bright">
                        ·
                    </span>
                </>
            )}
            <span>
                Probabilidades: FútbolFantasy{' '}
                {links.map((source) => (
                    <a
                        key={source.url}
                        href={source.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mr-1.5 whitespace-nowrap text-hq-moss underline underline-offset-2 hover:text-hq-lime"
                    >
                        {source.label} ↗
                    </a>
                ))}
            </span>
            <span aria-hidden="true" className="text-hq-border-bright">
                ·
            </span>
            <span>Estado físico: LaLiga Fantasy</span>
        </p>
    );
}

/**
 * A probable (or confirmed) starter on a pitch (mock `.t-tok`): framed
 * photo with the % badge where the points chip usually sits — the tone
 * scale below, lilac from 90 %. Once confirmed the badge is ✓, or a gold
 * "!" for a surprise starter. Links to the player ficha. While unconfirmed,
 * `fetchedAt` shows a tooltip with how old FútbolFantasy's % is on hover or
 * keyboard focus of the token.
 *
 * `size="sm"` is the team ficha's probable-XI pitch only: pixel-identical
 * to `HqLineupPitch`'s own `PlayerToken` (48 px photo, row-dependent
 * width via `className`, the same solid corner badge and name pill) so
 * switching between a played jornada's real lineup and the next one's
 * probable XI looks like the same pitch. `size="lg"` (the match ficha's
 * landscape pitch) is unchanged.
 */
export function HqStartPitchToken({
    entry,
    confirmed,
    size = 'lg',
    muted = false,
    fetchedAt = null,
    className,
}: {
    entry: StartProbabilityEntry;
    confirmed: boolean;
    size?: 'lg' | 'sm';
    muted?: boolean;
    fetchedAt?: string | null;
    /** Overrides the size-based width — e.g. the team ficha pitch's row-dependent `tokenWidthForRowCount`. */
    className?: string;
}) {
    const now = useNow(60_000);
    const surprise = confirmed && startOutcome(entry) === 'surprise';
    const tone: StartTone = confirmed
        ? surprise
            ? 'low'
            : 'high'
        : startTone(entry.probability, entry.player.status);
    const badge = confirmed
        ? surprise
            ? '!'
            : '✓'
        : entry.probability === null
          ? '—'
          : `${entry.probability}%`;
    const label = confirmed
        ? `${entry.player.nickname} · ${surprise ? 'titular sorpresa' : 'titular'}`
        : `${entry.player.nickname} · ${entry.probability === null ? 'sin dato' : `${entry.probability} % titular`}`;
    const showAgeTooltip = !confirmed && fetchedAt !== null;

    const token = (
        <Link
            href={playersShow(entry.player.id).url}
            aria-label={label}
            title={showAgeTooltip ? undefined : label}
            className={cn(
                'group flex flex-col items-center outline-none',
                size === 'lg' ? 'w-32' : 'w-[72px]',
                className,
            )}
        >
            <span
                className={cn(
                    'relative block',
                    size === 'lg' ? 'h-13 w-13' : 'h-12 w-12',
                )}
            >
                <span
                    className={cn(
                        'absolute inset-0 overflow-hidden border-[1.5px] bg-hq-well transition-colors group-hover:border-hq-lime group-focus-visible:border-hq-lime',
                        surprise ? 'border-hq-gold' : 'border-hq-paper/80',
                    )}
                >
                    <EntityImage
                        src={entry.player.image}
                        alt=""
                        fallback={User}
                        shape="square"
                        className="h-full w-full rounded-none bg-transparent object-cover"
                        style={{ objectPosition: 'center 25%' }}
                    />
                </span>
                {entry.player.status !== 'ok' && (
                    <HqStatusBadge
                        status={entry.player.status}
                        className="absolute -top-2 -left-3 z-10 bg-hq-ink px-[3px] py-0.5 text-[8.5px]"
                    />
                )}
                <span
                    className={
                        size === 'sm'
                            ? cn(
                                  'absolute -bottom-[5px] -left-[7px] z-10 inline-flex h-4 min-w-5 items-center justify-center px-[3px] font-mono text-[10.5px] leading-none font-extrabold tabular-nums',
                                  startBadgeTierClass(tone),
                                  muted && 'opacity-60 saturate-[.15]',
                              )
                            : cn(
                                  'absolute -right-3.5 -bottom-[5px] z-10 inline-flex h-[18px] min-w-[30px] items-center justify-center border bg-hq-ink px-[3px] font-mono text-[11px] leading-none font-bold tabular-nums',
                                  START_TONE_TEXT_CLASSES[tone],
                                  START_TONE_BORDER_CLASSES[tone],
                                  muted && 'opacity-60 saturate-[.15]',
                              )
                    }
                >
                    {badge}
                </span>
            </span>
            <span
                className={
                    size === 'sm'
                        ? 'mt-[5px] block max-w-full truncate bg-[rgba(6,7,5,0.86)] px-1 py-0.5 font-mono text-[10px] leading-[1.1] font-bold text-hq-paper'
                        : 'mt-1.5 block max-w-full truncate bg-[rgba(6,7,5,0.82)] px-1 py-0.5 font-mono text-[11px] leading-[1.1] font-medium text-hq-paper'
                }
            >
                {entry.player.nickname}
            </span>
        </Link>
    );

    if (!showAgeTooltip || fetchedAt === null) {
        return token;
    }

    return (
        <HqTooltip label={dataAgeTooltipLabel(fetchedAt, now)}>
            {token}
        </HqTooltip>
    );
}
