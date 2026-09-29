import {
    PitchLines,
    PITCH_TAG_CLASS,
    tokenWidthForRowCount,
} from '@/components/hq-lineup-pitch';
import {
    HqStartAttribution,
    HqStartPitchToken,
    HqStartRosterRow,
    HqStartStaleBanner,
    HqStartStateLabel,
} from '@/components/hq-start-probability';
import {
    formationLabel,
    halfPitchSlots,
    splitStartEntries,
} from '@/lib/start-probability';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import type { TeamNextStartProbabilities } from '@/types/models';

/** A count-labelled heading above a roster group (mock `.t-lrow` group head) — same treatment the match ficha's own lists use. */
function SubHead({ label, count }: { label: string; count: number }) {
    return (
        <div className="flex items-center gap-2 border-b border-hq-border-strong px-3.5 pt-3 pb-[7px] font-mono text-[10.5px] leading-none font-bold tracking-[0.1em] text-hq-moss uppercase sm:px-4">
            {label}
            <span className="text-hq-moss-dim">{count}</span>
        </div>
    );
}

/**
 * The team ficha's jornada aside before the match (variant B):
 * FútbolFantasy's probable XI — or the confirmed one — drawn on the exact
 * same portrait pitch as a played jornada's real lineup (`HqLineupPitch`:
 * same aspect, markings and row-sized tokens), goalkeeper at the bottom, each
 * player by his real role when known (see `halfPitchSlots`, which lands on
 * the same rows a confirmed lineup would) and the formation tagged top-left
 * like the confirmed pitch. Below the pitch — mirroring where the confirmed
 * pitch lists its own substitutes — the full non-XI roster as rows with the
 * 10-cell bar, then the bajas from our status in their own group, then the
 * attribution.
 */
export function HqProbableHalfPitch({
    probabilities,
}: {
    probabilities: TeamNextStartProbabilities;
}) {
    const now = useNow(60_000);
    const confirmed = probabilities.confirmed_source !== null;
    const { starters, bench, rest, out } = splitStartEntries(
        probabilities.players,
        confirmed,
    );
    const nonStarters = [...bench, ...rest];
    const formation = formationLabel(probabilities);
    const slots = halfPitchSlots(starters);
    const lineCounts = new Map<number, number>();

    slots.forEach(({ bottom }) => {
        lineCounts.set(bottom, (lineCounts.get(bottom) ?? 0) + 1);
    });

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
                <div className="hq-hud relative mx-auto aspect-[280/430] w-full max-w-[360px] border border-hq-border-strong bg-hq-pitch">
                    <PitchLines />
                    {formation && (
                        <span
                            className={cn(
                                PITCH_TAG_CLASS,
                                'top-2 left-2 border-hq-border-bright text-hq-moss',
                            )}
                        >
                            {formation}
                        </span>
                    )}
                    {slots.map(({ entry, left, bottom }) => (
                        <div
                            key={entry.player.id}
                            className={cn(
                                'absolute z-[2] flex -translate-x-1/2 justify-center',
                                tokenWidthForRowCount(
                                    lineCounts.get(bottom) ?? 1,
                                ),
                            )}
                            style={{ left: `${left}%`, bottom: `${bottom}%` }}
                        >
                            <HqStartPitchToken
                                entry={entry}
                                confirmed={confirmed}
                                size="sm"
                                muted={probabilities.is_stale}
                                fetchedAt={probabilities.fetched_at}
                                className="w-full"
                            />
                        </div>
                    ))}
                </div>
            </div>
            {nonStarters.length > 0 && (
                <>
                    <SubHead
                        label={
                            confirmed
                                ? 'Suplentes destacados'
                                : 'Banquillo y dudas'
                        }
                        count={nonStarters.length}
                    />
                    {nonStarters.map((entry) => (
                        <HqStartRosterRow
                            key={entry.player.id}
                            entry={entry}
                            confirmed={confirmed}
                            muted={probabilities.is_stale}
                            fetchedAt={probabilities.fetched_at}
                        />
                    ))}
                </>
            )}
            {out.length > 0 && (
                <>
                    <SubHead label="Bajas" count={out.length} />
                    {out.map((entry) => (
                        <HqStartRosterRow
                            key={entry.player.id}
                            entry={entry}
                            confirmed={confirmed}
                            dim
                            muted={probabilities.is_stale}
                            fetchedAt={probabilities.fetched_at}
                        />
                    ))}
                </>
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
