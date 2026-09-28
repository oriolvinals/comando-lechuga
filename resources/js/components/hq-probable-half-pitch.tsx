import { Link } from '@inertiajs/react';
import { HqPositionTag } from '@/components/hq-position-tag';
import {
    HqStartAttribution,
    HqStartLegend,
    HqStartOutcomeChip,
    HqStartPitchToken,
    HqStartStaleBanner,
    HqStartStateLabel,
} from '@/components/hq-start-probability';
import { HqStatusBadge } from '@/components/hq-status-badge';
import {
    START_TONE_TEXT_CLASSES,
    formatDataAge,
    formationLabel,
    halfPitchSlots,
    isUnavailable,
    splitStartEntries,
    startOutcome,
    startTone,
} from '@/lib/start-probability';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type {
    StartProbabilityEntry,
    TeamNextStartProbabilities,
} from '@/types/models';

/**
 * Half-pitch markings (mock PITCH_SVG, mirrored): own box at the top, halfway
 * line at the bottom — matches the team ficha's real match pitch, goalkeeper
 * at the top, attacking down.
 */
function HalfPitchLines() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 300 355"
            preserveAspectRatio="none"
            className="absolute inset-2 h-[calc(100%-16px)] w-[calc(100%-16px)] fill-none stroke-hq-pitch-line [stroke-width:1.2] [&>*]:[vector-effect:non-scaling-stroke]"
        >
            <rect x="0" y="0" width="300" height="355" />
            <rect x="60" y="0" width="180" height="72" />
            <rect x="110" y="0" width="80" height="25" />
            <path d="M120 72 A34 34 0 0 0 180 72" />
            <path d="M110 355 A40 40 0 0 1 190 355" />
        </svg>
    );
}

/** A labelled row of player pills (mock `.t-strip`): position, name, status and the % — or "Se cae" once confirmed. */
function StartPills({
    label,
    entries,
    confirmed,
}: {
    label: string;
    entries: StartProbabilityEntry[];
    confirmed: boolean;
}) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-1.5 border-t border-hq-border px-3.5 py-2.5 sm:px-4">
            <span className="w-full font-mono text-[10px] leading-none font-bold tracking-[0.1em] text-hq-moss-dim uppercase">
                {label}
            </span>
            {entries.map((entry) => (
                <Link
                    key={entry.player.id}
                    href={playersShow(entry.player.id).url}
                    className={cn(
                        'inline-flex h-6 items-center gap-1.5 border px-[7px] font-mono text-[11.5px] leading-none font-semibold whitespace-nowrap text-hq-paper hover:border-hq-lime',
                        isUnavailable(entry.player.status)
                            ? 'border-hq-live/45'
                            : 'border-hq-border-strong',
                    )}
                >
                    <HqPositionTag
                        position={entry.player.position}
                        className="px-[3px] py-0.5 text-[8.5px]"
                    />
                    {entry.player.nickname}
                    <HqStatusBadge
                        status={entry.player.status}
                        className="px-1 py-0.5 text-[9px]"
                    />
                    {confirmed ? (
                        startOutcome(entry) === 'dropped' && (
                            <HqStartOutcomeChip facts={entry} />
                        )
                    ) : (
                        <b
                            className={cn(
                                'font-mono text-[11px] font-bold tabular-nums',
                                START_TONE_TEXT_CLASSES[
                                    startTone(
                                        entry.probability,
                                        entry.player.status,
                                    )
                                ],
                            )}
                        >
                            {entry.probability === null
                                ? '—'
                                : `${entry.probability}%`}
                        </b>
                    )}
                </Link>
            ))}
        </div>
    );
}

/**
 * The team ficha's jornada aside before the match (variant B):
 * FútbolFantasy's probable XI — or the confirmed one — on a half pitch
 * attacking down, goalkeeper at the top, each player by his real role when
 * known (see halfPitchSlots) and the formation tagged top-left like the
 * team ficha's confirmed pitch ("≈4-3-3" while approximated), then the doubts (≥ 30 %) and the
 * bajas from our status as pills, the key and the attribution.
 */
export function HqProbableHalfPitch({
    probabilities,
}: {
    probabilities: TeamNextStartProbabilities;
}) {
    const now = useNow(60_000);
    const confirmed = probabilities.confirmed_source !== null;
    const { starters, bench, out } = splitStartEntries(
        probabilities.players,
        confirmed,
    );
    const formation = formationLabel(probabilities);

    return (
        <div>
            {probabilities.is_stale && probabilities.fetched_at && (
                <HqStartStaleBanner
                    fetchedAt={probabilities.fetched_at}
                    now={now}
                />
            )}
            <div className="flex items-center justify-between gap-2 px-3.5 pt-2.5 font-mono text-[10.5px] leading-[1.2] font-medium tracking-[0.07em] text-hq-moss-dim uppercase sm:px-4">
                <span>
                    {confirmed ? 'Once confirmado' : 'Once probable'} · J
                    {probabilities.week_number}{' '}
                    {probabilities.is_home ? 'vs' : '@'}{' '}
                    {probabilities.opponent.short_name}
                </span>
                <HqStartStateLabel
                    confirmed={confirmed}
                    stale={probabilities.is_stale}
                />
            </div>
            <div className="p-3.5 sm:p-4">
                <div className="relative mx-auto aspect-[3/3.3] w-full max-w-[360px] overflow-hidden border border-hq-border bg-hq-pitch">
                    <HalfPitchLines />
                    {formation && (
                        <span className="absolute top-2 left-2 z-20 border border-hq-border-bright bg-hq-ink px-1.5 py-1 font-mono text-[10.5px] leading-none font-bold tracking-[0.06em] text-hq-moss uppercase">
                            {formation}
                        </span>
                    )}
                    {halfPitchSlots(starters).map(
                        ({ entry, left, top, crowded }) => (
                            <div
                                key={entry.player.id}
                                className="absolute z-[2] -translate-x-1/2 -translate-y-1/2"
                                style={{ left: `${left}%`, top: `${top}%` }}
                            >
                                <HqStartPitchToken
                                    entry={entry}
                                    confirmed={confirmed}
                                    size="sm"
                                    muted={probabilities.is_stale}
                                    fetchedAt={probabilities.fetched_at}
                                    className={crowded ? 'w-14' : undefined}
                                />
                            </div>
                        ),
                    )}
                </div>
            </div>
            <StartPills
                label={confirmed ? 'Suplentes destacados' : 'Dudas (≥ 30 %)'}
                entries={bench}
                confirmed={confirmed}
            />
            <StartPills label="Bajas" entries={out} confirmed={confirmed} />
            {!confirmed && (
                <HqStartLegend>
                    {probabilities.fetched_at && (
                        <span>
                            {formatDataAge(probabilities.fetched_at, now)}
                        </span>
                    )}
                </HqStartLegend>
            )}
            <HqStartAttribution
                sources={[
                    {
                        label: probabilities.team.short_name,
                        url: probabilities.source_url,
                    },
                ]}
                confirmedByWorldcup26={
                    probabilities.confirmed_source === 'worldcup26'
                }
            />
        </div>
    );
}
