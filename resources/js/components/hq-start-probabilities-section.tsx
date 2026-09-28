import { Link } from '@inertiajs/react';
import { Shield, User } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqProbableMatchPitch } from '@/components/hq-probable-match-pitch';
import { HqChannelHeader } from '@/components/hq-section';
import {
    HqStartAttribution,
    HqStartMeter,
    HqStartOutcomeChip,
    HqStartStaleBanner,
    HqStartStateLabel,
} from '@/components/hq-start-probability';
import { HqStatusBadge } from '@/components/hq-status-badge';
import type { FixtureViewMode } from '@/lib/fixture-view-mode';
import { formatMatchDay } from '@/lib/format';
import {
    averageProbability,
    formatDataAge,
    isUnavailable,
    splitStartEntries,
} from '@/lib/start-probability';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type {
    Fixture,
    FixtureStartProbabilities,
    StartProbabilityEntry,
    StartProbabilityTeamBlock,
    Team,
} from '@/types/models';

type Side = 'local' | 'guest';

function ColumnHead({ team, children }: { team: Team; children: ReactNode }) {
    return (
        <div className="flex items-center gap-2 border-b border-hq-border px-3.5 py-[9px] font-mono text-[11px] leading-none font-bold tracking-[0.08em] text-hq-moss uppercase sm:px-4">
            <EntityImage
                src={team.logo}
                alt=""
                fallback={Shield}
                shape="square"
                className="h-4 w-4 shrink-0 rounded-none bg-transparent"
            />
            <span className="truncate">{team.main_name}</span>
            <span className="ml-auto flex items-center gap-1.5 font-medium whitespace-nowrap text-hq-moss-dim normal-case">
                {children}
            </span>
        </div>
    );
}

function SubHead({ label, count }: { label: string; count: number }) {
    return (
        <div className="flex items-center gap-2 border-b border-hq-border-strong px-3.5 pt-3 pb-[7px] font-mono text-[10.5px] leading-none font-bold tracking-[0.1em] text-hq-moss uppercase sm:px-4">
            {label}
            <span className="text-hq-moss-dim">{count}</span>
        </div>
    );
}

/**
 * One player (mock `.t-lrow`): photo with the position tag, the name and
 * our status badge inline on one line (the name truncates before the badge
 * wraps, so every row stays the same height) — plus "FF aún le da N %" on
 * its own small line for a baja FútbolFantasy still rates — then the bar
 * and %, or the outcome chip once confirmed. The name link is stretched
 * over the whole row via its `::after`, so the row itself is ≥ 50 px tall
 * and the whole thing opens the ficha; the bar/chip column stays on the
 * right and sits `relative z-10` so its own hover/focus tooltip still wins
 * over the stretched link.
 */
function StartRow({
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

function TeamColumn({
    team,
    block,
    now,
    starterRowsClassName,
}: {
    team: Team;
    block: StartProbabilityTeamBlock | null;
    now: number;
    starterRowsClassName: string;
}) {
    if (block === null) {
        return (
            <>
                <ColumnHead team={team}>sin datos</ColumnHead>
                <p className="px-3.5 py-3 font-mono text-xs leading-[1.45] text-hq-moss sm:px-4">
                    FútbolFantasy aún no tiene la alineación de este equipo.
                </p>
            </>
        );
    }

    const confirmed = block.confirmed_source !== null;
    const muted = block.is_stale;
    const { starters, bench, rest, out } = splitStartEntries(
        block.players,
        confirmed,
    );
    const nonStarters = [...bench, ...rest];
    const average = averageProbability(starters);

    return (
        <>
            <ColumnHead team={team}>
                {confirmed ? (
                    'XI confirmado'
                ) : (
                    <>
                        {average !== null && (
                            <span className="max-md:hidden">
                                media XI {average} % ·
                            </span>
                        )}
                        {block.fetched_at && (
                            <span
                                className={cn(
                                    muted && 'font-bold text-hq-gold',
                                )}
                            >
                                {muted && '▲ datos de '}
                                {formatDataAge(block.fetched_at, now)}
                            </span>
                        )}
                    </>
                )}
            </ColumnHead>
            <div className={starterRowsClassName}>
                {starters.map((entry) => (
                    <StartRow
                        key={entry.player.id}
                        entry={entry}
                        confirmed={confirmed}
                        muted={muted}
                        fetchedAt={block.fetched_at}
                    />
                ))}
            </div>
            <SubHead
                label={confirmed ? 'Suplentes destacados' : 'Banquillo y dudas'}
                count={nonStarters.length}
            />
            {nonStarters.map((entry) => (
                <StartRow
                    key={entry.player.id}
                    entry={entry}
                    confirmed={confirmed}
                    muted={muted}
                    fetchedAt={block.fetched_at}
                />
            ))}
            {out.length > 0 && (
                <>
                    <SubHead label="Bajas" count={out.length} />
                    {out.map((entry) => (
                        <StartRow
                            key={entry.player.id}
                            entry={entry}
                            confirmed={confirmed}
                            dim
                            muted={muted}
                            fetchedAt={block.fetched_at}
                        />
                    ))}
                </>
            )}
        </>
    );
}

interface HqStartProbabilitiesSectionProps {
    probabilities: FixtureStartProbabilities;
    fixture: Fixture;
    viewMode: FixtureViewMode;
}

/**
 * The match ficha before kickoff (variant A). FútbolFantasy's probable XIs —
 * or the confirmed ones — on the landscape pitch (desktop "campo" view) and
 * as two columns of rows with the 10-cell bar ("lista" view; phones always).
 * Under each XI: every other available player as bench/doubt rows, then the
 * bajas from our status. Then the attribution (the colour legend lives in
 * the tone tooltips/hover states instead of a standing key here).
 */
export function HqStartProbabilitiesSection({
    probabilities,
    fixture,
    viewMode,
}: HqStartProbabilitiesSectionProps) {
    const now = useNow(60_000);
    const [mobileSide, setMobileSide] = useState<Side>('local');
    const sides: {
        side: Side;
        team: Team;
        block: StartProbabilityTeamBlock | null;
    }[] = [
        { side: 'local', team: fixture.local_team, block: probabilities.local },
        { side: 'guest', team: fixture.guest_team, block: probabilities.guest },
    ];
    const blocks = sides.flatMap(({ block }) =>
        block === null ? [] : [block],
    );
    const allConfirmed =
        blocks.length > 0 &&
        blocks.every((block) => block.confirmed_source !== null);
    const staleBlock = blocks.find(
        (block) => block.is_stale && block.fetched_at !== null,
    );
    const showPitch = viewMode === 'pitch';

    return (
        <section className="border-b border-hq-border">
            <HqChannelHeader
                code="XI"
                title={
                    allConfirmed
                        ? 'Alineación confirmada'
                        : 'Alineación probable'
                }
                action={
                    <>
                        <HqStartStateLabel
                            confirmed={allConfirmed}
                            stale={staleBlock !== undefined}
                        />
                        <span>
                            J{fixture.week_number} ·{' '}
                            {formatMatchDay(fixture.date)}
                        </span>
                    </>
                }
            />
            {staleBlock?.fetched_at && (
                <HqStartStaleBanner
                    fetchedAt={staleBlock.fetched_at}
                    now={now}
                />
            )}
            {showPitch && (
                <div className="hidden lg:block">
                    <HqProbableMatchPitch
                        local={probabilities.local}
                        guest={probabilities.guest}
                        localTeam={fixture.local_team}
                        guestTeam={fixture.guest_team}
                    />
                </div>
            )}
            <div
                role="group"
                aria-label="Equipo a mostrar"
                className="flex border-b border-hq-border-strong md:hidden"
            >
                {sides.map(({ side, team }) => (
                    <button
                        key={side}
                        type="button"
                        aria-pressed={mobileSide === side}
                        onClick={() => setMobileSide(side)}
                        className={cn(
                            '-mb-px min-h-11 flex-1 border-b-2 font-mono text-[11.5px] font-bold tracking-[0.07em] uppercase',
                            mobileSide === side
                                ? 'border-hq-lime text-hq-lime'
                                : 'border-transparent text-hq-moss',
                        )}
                    >
                        {team.main_name}
                    </button>
                ))}
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2">
                {sides.map(({ side, team, block }, index) => (
                    <div
                        key={side}
                        className={cn(
                            'min-w-0',
                            index === 1 && 'md:border-l md:border-hq-border',
                            mobileSide !== side && 'max-md:hidden',
                        )}
                    >
                        <TeamColumn
                            team={team}
                            block={block}
                            now={now}
                            starterRowsClassName={showPitch ? 'lg:hidden' : ''}
                        />
                    </div>
                ))}
            </div>
            <HqStartAttribution
                sources={blocks.map((block) => ({
                    label: block.team.short_name,
                    url: block.source_url,
                }))}
                confirmedByWorldcup26={blocks.some(
                    (block) => block.confirmed_source === 'worldcup26',
                )}
            />
        </section>
    );
}
