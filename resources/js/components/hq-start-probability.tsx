import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { formatMatchDateShort } from '@/lib/format';
import {
    DOUBT_THRESHOLD,
    START_TONE_BG_CLASSES,
    START_TONE_BORDER_CLASSES,
    START_TONE_TEXT_CLASSES,
    dataAgeDays,
    pitchBadgeTone,
    startOutcome,
    startTone,
} from '@/lib/start-probability';
import type {
    StartFacts,
    StartOutcome,
    StartTone,
} from '@/lib/start-probability';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { PlayerStatus, StartProbabilityEntry } from '@/types/models';

/**
 * FútbolFantasy's % as a 10-cell bar plus the figure (mock `.t-meter` +
 * `.t-pct`) — the one bar used by the match list, the team aside and the
 * manager roster. Muted (desaturated) while the data is stale.
 */
export function HqStartMeter({
    probability,
    status,
    size = 'md',
    muted = false,
    className,
}: {
    probability: number | null;
    status: PlayerStatus;
    size?: 'md' | 'sm';
    muted?: boolean;
    className?: string;
}) {
    const tone = startTone(probability, status);
    const lit = probability === null ? 0 : Math.round(probability / 10);
    const label =
        probability === null
            ? 'Sin probabilidad de FútbolFantasy'
            : `${probability} % de ser titular`;

    return (
        <span
            title={label}
            className={cn(
                'inline-flex shrink-0 items-center',
                size === 'sm' ? 'gap-1.5' : 'gap-2',
                muted && 'opacity-60 saturate-[.15]',
                className,
            )}
        >
            <span aria-hidden="true" className="inline-flex gap-0.5">
                {Array.from({ length: 10 }, (_, cell) => (
                    <i
                        key={cell}
                        className={cn(
                            'block',
                            size === 'sm'
                                ? 'h-[9px] w-[3px]'
                                : 'h-[11px] w-[5px]',
                            cell < lit
                                ? START_TONE_BG_CLASSES[tone]
                                : 'bg-hq-border-strong',
                        )}
                    />
                ))}
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

const LEGEND: { tone: StartTone; label: string }[] = [
    { tone: 'high', label: '≥ 70 % titular fijo' },
    { tone: 'mid', label: '40–69 % duda' },
    { tone: 'low', label: '< 40 % suplente' },
    { tone: 'out', label: 'baja (LaLiga Fantasy)' },
];

/**
 * The tone key. `pitchNoteClassName` shows the pitch-only notes (lilac from
 * 90 %, dimmed = doubt) with that class — e.g. `hidden lg:inline-flex` where
 * the pitch is desktop only; omit it where there is no pitch.
 */
export function HqStartLegend({
    pitchNoteClassName,
    children,
}: {
    pitchNoteClassName?: string;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap gap-x-3 gap-y-1 px-3.5 py-2 font-mono text-[10.5px] leading-[1.2] text-hq-moss-dim sm:px-4">
            {LEGEND.map(({ tone, label }) => (
                <span key={tone} className="inline-flex items-center gap-[5px]">
                    <i
                        aria-hidden="true"
                        className={cn(
                            'block h-2 w-2',
                            START_TONE_BG_CLASSES[tone],
                        )}
                    />
                    {label}
                </span>
            ))}
            {pitchNoteClassName !== undefined && (
                <>
                    <span
                        className={cn(
                            'items-center gap-[5px]',
                            pitchNoteClassName,
                        )}
                    >
                        <i
                            aria-hidden="true"
                            className="block h-2 w-2 bg-hq-violet"
                        />
                        campo: ≥ 90 % en lila
                    </span>
                    <span className={pitchNoteClassName}>
                        campo: atenuado = duda (&lt; 60 %)
                    </span>
                </>
            )}
            {children}
        </div>
    );
}

/**
 * A probable (or confirmed) starter on a pitch (mock `.t-tok`): framed
 * photo with the % badge where the points chip usually sits — lilac from
 * 90 %, the tone scale below — dimmed with a dashed frame under 60 %. Once
 * confirmed the badge is ✓, or a gold "!" for a surprise starter. Links to
 * the player ficha.
 */
export function HqStartPitchToken({
    entry,
    confirmed,
    size = 'lg',
    muted = false,
}: {
    entry: StartProbabilityEntry;
    confirmed: boolean;
    size?: 'lg' | 'sm';
    muted?: boolean;
}) {
    const surprise = confirmed && startOutcome(entry) === 'surprise';
    const tone: StartTone = confirmed
        ? surprise
            ? 'mid'
            : 'high'
        : pitchBadgeTone(entry.probability, entry.player.status);
    const doubt = !confirmed && (entry.probability ?? 0) < DOUBT_THRESHOLD;
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

    return (
        <Link
            href={playersShow(entry.player.id).url}
            aria-label={label}
            title={label}
            className={cn(
                'group flex flex-col items-center outline-none',
                size === 'lg' ? 'w-32' : 'w-[72px]',
            )}
        >
            <span
                className={cn(
                    'relative block',
                    size === 'lg' ? 'h-13 w-13' : 'h-11 w-11',
                )}
            >
                <span
                    className={cn(
                        'absolute inset-0 overflow-hidden border-[1.5px] bg-hq-well transition-colors group-hover:border-hq-lime group-focus-visible:border-hq-lime',
                        surprise ? 'border-hq-gold' : 'border-hq-paper/80',
                        doubt && 'border-dashed opacity-60',
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
                    className={cn(
                        'absolute -right-3.5 -bottom-[5px] z-10 inline-flex h-[18px] min-w-[30px] items-center justify-center border bg-hq-ink px-[3px] font-mono text-[11px] leading-none font-bold tabular-nums',
                        START_TONE_TEXT_CLASSES[tone],
                        START_TONE_BORDER_CLASSES[tone],
                        muted && 'opacity-60 saturate-[.15]',
                    )}
                >
                    {badge}
                </span>
            </span>
            <span
                className={cn(
                    'mt-1.5 block max-w-full truncate bg-[rgba(6,7,5,0.82)] px-1 py-0.5 font-mono leading-[1.1] font-medium',
                    size === 'lg' ? 'text-[11px]' : 'text-[10.5px]',
                    doubt ? 'text-hq-moss' : 'text-hq-paper',
                )}
            >
                {entry.player.nickname}
            </span>
        </Link>
    );
}
