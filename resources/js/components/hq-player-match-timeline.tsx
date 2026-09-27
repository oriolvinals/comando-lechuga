import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    Armchair,
    ChevronDown,
    Home,
    Plane,
    Shield,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqJornadaStatsGrid } from '@/components/hq-jornada-stats-grid';
import { HqLed } from '@/components/hq-led';
import { HqManagerChip } from '@/components/hq-manager-chip';
import { HqTooltip } from '@/components/hq-tooltip';
import {
    hasMatchEvents,
    MatchEventIcons,
} from '@/components/match-event-icons';
import { formatMatchDateTime } from '@/lib/format';
import { didNotPlayMatch } from '@/lib/player-labels';
import { daznPointsBadgeClass, matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type {
    Fixture,
    FixtureState,
    PlayerFichaScore,
    PlayerPosition,
} from '@/types/models';

interface HqPlayerMatchTimelineProps {
    scores: PlayerFichaScore[];
    teamFixtures: Fixture[];
    currentWeek: number;
    playerPosition: PlayerPosition;
    teamId: number;
}

type MatchResult = 'win' | 'draw' | 'loss';

const RESULT_LABELS: Record<MatchResult, string> = {
    win: 'V',
    draw: 'E',
    loss: 'D',
};

const RESULT_NAMES: Record<MatchResult, string> = {
    win: 'Victoria',
    draw: 'Empate',
    loss: 'Derrota',
};

const RESULT_CLASSES: Record<MatchResult, string> = {
    win: 'bg-hq-lime/15 text-hq-lime',
    draw: 'bg-hq-gold/15 text-hq-gold',
    loss: 'bg-hq-live/15 text-hq-live',
};

const LIVE_STATES: FixtureState[] = ['first_half', 'half_time', 'second_half'];

/**
 * Desktop columns (mock `.mlh`/`.lr`): jornada · rival · resultado ·
 * titularidad + minutos · DAZN · puntos · chevron. Below `md` each row folds
 * into two lines: jornada | rival · resultado · puntos, then titularidad |
 * DAZN — every cell is placed explicitly there and falls back to source
 * order from `md`.
 */
const ROW_GRID =
    'grid grid-cols-[34px_minmax(0,1fr)_auto_auto] items-center gap-x-2.5 gap-y-1.5 px-3.5 py-[11px] md:grid-cols-[40px_minmax(150px,1.5fr)_92px_minmax(170px,1.3fr)_58px_62px_22px] md:gap-x-2.5 md:px-4 md:py-2.5';

const CELL_WEEK =
    'col-start-1 row-span-2 row-start-1 self-start pt-[3px] md:col-auto md:row-auto md:row-span-1 md:self-center md:pt-0';
const CELL_RIVAL = 'col-start-2 row-start-1 md:col-auto md:row-auto';
const CELL_RESULT = 'col-start-3 row-start-1 md:col-auto md:row-auto';
const CELL_ROLE =
    'col-span-2 col-start-2 row-start-2 md:col-auto md:col-span-1 md:row-auto';
const CELL_DAZN =
    'col-start-4 row-start-2 flex items-center justify-end md:col-auto md:row-auto';
const CELL_POINTS =
    'col-start-4 row-start-1 flex justify-end md:col-auto md:row-auto';
const CELL_CHEVRON = 'hidden justify-center text-hq-moss-dim md:flex';

const TAG_CLASS =
    'border px-[5px] py-[3px] font-mono text-[10px] leading-none font-bold tracking-[0.06em] uppercase';

function isLive(state: FixtureState): boolean {
    return LIVE_STATES.includes(state);
}

function resultFor(fixture: Fixture, teamId: number): MatchResult | null {
    if (fixture.local_score === null || fixture.guest_score === null) {
        return null;
    }

    const isHome = fixture.local_team.id === teamId;
    const own = isHome ? fixture.local_score : fixture.guest_score;
    const rival = isHome ? fixture.guest_score : fixture.local_score;

    if (own === rival) {
        return 'draw';
    }

    return own > rival ? 'win' : 'loss';
}

function formatWeekdayDate(isoDate: string): string {
    return new Intl.DateTimeFormat('es-ES', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'Europe/Madrid',
    }).format(new Date(isoDate));
}

function WeekCell({ week }: { week: number }) {
    return (
        <span
            className={cn(
                'font-mono text-xs leading-none font-bold text-hq-moss',
                CELL_WEEK,
            )}
        >
            J{week}
        </span>
    );
}

/** Rival crest, name and "Casa/Fuera · fecha" (mock `.rv`). */
function RivalCell({ fixture, teamId }: { fixture: Fixture; teamId: number }) {
    const isHome = fixture.local_team.id === teamId;
    const rival = isHome ? fixture.guest_team : fixture.local_team;
    const VenueIcon = isHome ? Home : Plane;

    return (
        <span className={cn('flex min-w-0 items-center gap-[9px]', CELL_RIVAL)}>
            <EntityImage
                src={rival.logo}
                alt=""
                fallback={Shield}
                shape="square"
                className="size-[26px] shrink-0 rounded-none bg-transparent"
            />
            <span className="min-w-0">
                <span className="block truncate text-[13.5px] leading-[1.15] font-extrabold text-hq-paper">
                    {rival.main_name}
                </span>
                <span className="mt-1 flex items-center gap-1 font-mono text-[10.5px] leading-none whitespace-nowrap text-hq-moss-dim">
                    <VenueIcon
                        aria-hidden="true"
                        className="size-[11px] shrink-0"
                    />
                    {isHome ? 'Casa' : 'Fuera'} ·{' '}
                    {formatWeekdayDate(fixture.date)}
                </span>
            </span>
        </span>
    );
}

/** V/E/D square and the own–rival score; a pulsing frame while live. */
function ResultCell({ fixture, teamId }: { fixture: Fixture; teamId: number }) {
    const result = resultFor(fixture, teamId);

    if (result === null) {
        return (
            <span
                className={cn(
                    'font-mono text-xs text-hq-moss-dim',
                    CELL_RESULT,
                )}
            >
                {fixture.state === 'postponed' ? 'Aplazado' : 'vs'}
            </span>
        );
    }

    const isHome = fixture.local_team.id === teamId;
    const own = isHome ? fixture.local_score : fixture.guest_score;
    const rival = isHome ? fixture.guest_score : fixture.local_score;
    const live = isLive(fixture.state);

    return (
        <span className={cn('inline-flex items-center gap-[7px]', CELL_RESULT)}>
            <span
                aria-label={
                    live
                        ? `${RESULT_NAMES[result]} en juego`
                        : RESULT_NAMES[result]
                }
                className={cn(
                    'relative inline-flex size-[22px] shrink-0 items-center justify-center font-mono text-[11px] leading-none font-bold',
                    RESULT_CLASSES[result],
                    live && 'border border-hq-live',
                )}
            >
                {RESULT_LABELS[result]}
                {live && (
                    <span
                        aria-hidden="true"
                        className="absolute -top-[3px] -right-[3px] size-1.5 animate-hq-pulse rounded-full bg-hq-live shadow-[0_0_0_2px_var(--color-hq-ink)]"
                    />
                )}
            </span>
            <b className="font-mono text-sm leading-none font-bold text-hq-paper tabular-nums">
                {own}–{rival}
            </b>
        </span>
    );
}

function PointsChip({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex h-7 min-w-[34px] items-center justify-center border border-transparent px-[5px] font-mono text-sm leading-none font-bold tabular-nums md:h-8 md:min-w-10 md:text-base',
                className,
            )}
        >
            {children}
        </span>
    );
}

/** TITULAR / SUPLENTE, the substitution minute or the bench glyph, and minutes played. */
function RoleCell({ score }: { score: PlayerFichaScore }) {
    const minutes = score.stats?.mins_played?.[0] ?? 0;
    const subMinute = score.sub_minute;

    return (
        <span
            className={cn('flex flex-wrap items-center gap-[7px]', CELL_ROLE)}
        >
            <span
                className={cn(
                    TAG_CLASS,
                    score.starter
                        ? 'border-hq-border-bright text-hq-paper'
                        : 'border-hq-khaki text-hq-khaki',
                )}
            >
                {score.starter ? 'Titular' : 'Suplente'}
            </span>
            {subMinute !== null ? (
                <HqTooltip
                    label={
                        score.subbed_out
                            ? `Sustituido en el ${subMinute}'`
                            : `Entró en el ${subMinute}'`
                    }
                >
                    <span
                        className={cn(
                            'border border-current px-1 py-0.5 font-mono text-[10px] leading-none font-bold whitespace-nowrap',
                            score.subbed_out ? 'text-hq-live' : 'text-hq-lime',
                        )}
                    >
                        ↳{subMinute}'
                    </span>
                </HqTooltip>
            ) : (
                !score.starter && (
                    <HqTooltip label="Suplente, no llegó a jugar">
                        <Armchair
                            aria-label="Suplente, no llegó a jugar"
                            className="size-3.5 text-hq-moss-dim"
                        />
                    </HqTooltip>
                )
            )}
            <HqTooltip label="Minutos jugados" className="ml-auto">
                <span className="font-mono text-xs leading-none text-hq-moss">
                    <HqLed className="text-[17px]">{minutes}</HqLed>'
                </span>
            </HqTooltip>
        </span>
    );
}

/** A jornada the player has a lineup row for: a button that expands every stat. */
function ScoreRow({
    week,
    score,
    playerPosition,
    isOpen,
    onToggle,
}: {
    week: number;
    score: PlayerFichaScore;
    playerPosition: PlayerPosition;
    isOpen: boolean;
    onToggle: () => void;
}) {
    const fixture = score.fixture;
    const stats = score.stats ?? {};
    const didNotPlay = didNotPlayMatch(stats, fixture.state);
    const dazn = score.stats?.marca_points?.[1] ?? null;
    const panelId = `match-log-${score.id}`;

    return (
        <div
            className={cn(
                'border-b border-hq-border',
                isOpen &&
                    'bg-hq-panel shadow-[inset_3px_0_0_var(--color-hq-lime)]',
            )}
        >
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={isOpen}
                aria-controls={panelId}
                className={cn(
                    ROW_GRID,
                    'w-full cursor-pointer text-left transition-colors hover:bg-hq-panel focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-hq-lime',
                    isOpen && 'bg-hq-panel-alt hover:bg-hq-panel-alt',
                )}
            >
                <WeekCell week={week} />
                <RivalCell fixture={fixture} teamId={score.team_id} />
                <ResultCell fixture={fixture} teamId={score.team_id} />
                <RoleCell score={score} />
                <span className={CELL_DAZN}>
                    <span className="mr-1.5 hq-label md:hidden">DAZN</span>
                    {dazn !== null && !didNotPlay ? (
                        <HqTooltip label="Puntos DAZN">
                            <span
                                className={cn(
                                    'inline-flex h-[22px] min-w-[30px] items-center justify-center px-[5px] font-mono text-xs leading-none font-bold tabular-nums',
                                    daznPointsBadgeClass(dazn),
                                )}
                            >
                                {dazn}
                            </span>
                        </HqTooltip>
                    ) : (
                        <span className="font-mono text-xs text-hq-moss-dim">
                            –
                        </span>
                    )}
                </span>
                <span className={CELL_POINTS}>
                    {score.points !== null ? (
                        <PointsChip
                            className={matchPointsBadgeClass(score.points)}
                        >
                            {score.points}
                        </PointsChip>
                    ) : (
                        <PointsChip className="border-dashed border-hq-border-bright text-hq-moss-dim">
                            —
                        </PointsChip>
                    )}
                </span>
                <span className="sr-only">
                    {isOpen ? 'Ocultar' : 'Ver'} todas las estadísticas
                </span>
                <span className={CELL_CHEVRON}>
                    <ChevronDown
                        aria-hidden="true"
                        className={cn(
                            'size-4 transition-transform',
                            isOpen && 'rotate-180 text-hq-lime',
                        )}
                    />
                </span>
            </button>

            {isOpen && (
                <div id={panelId}>
                    <div className="flex flex-wrap items-center gap-2.5 border-t border-hq-border px-3.5 py-3 md:px-4">
                        {hasMatchEvents(stats, playerPosition) ? (
                            <MatchEventIcons
                                stats={stats}
                                position={playerPosition}
                                className="mb-0"
                            />
                        ) : (
                            <span className="font-mono text-[11.5px] text-hq-moss-dim">
                                Sin goles, tarjetas ni penaltis
                            </span>
                        )}
                        {didNotPlay && (
                            <span
                                className={cn(
                                    TAG_CLASS,
                                    'border-hq-olive bg-hq-olive/10 text-hq-olive',
                                )}
                            >
                                No jugó
                            </span>
                        )}
                        <span className="flex min-w-0 flex-wrap items-center gap-2.5 md:ml-auto">
                            {score.lineup_manager ? (
                                <>
                                    <span className="hq-label">
                                        Alineado por
                                    </span>
                                    <HqManagerChip
                                        manager={score.lineup_manager}
                                    />
                                </>
                            ) : (
                                <span className="hq-label">
                                    Ningún manager lo alineó
                                </span>
                            )}
                            <Link
                                href={fixturesShow(fixture.id).url}
                                className="inline-flex min-h-11 items-center gap-1 border border-hq-lime px-2.5 font-mono text-[11px] font-bold tracking-[0.05em] text-hq-lime uppercase hover:bg-hq-lime/10 sm:min-h-8"
                            >
                                Ver partido
                                <ArrowUpRight
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                            </Link>
                        </span>
                    </div>
                    <p className="px-3.5 pb-3 font-mono text-[11.5px] leading-snug text-hq-moss-dim md:px-4">
                        {fixture.local_team.main_name} {fixture.local_score}–
                        {fixture.guest_score} {fixture.guest_team.main_name} ·{' '}
                        {formatMatchDateTime(fixture.date)}
                        {fixture.venue ? ` · ${fixture.venue}` : ''}
                    </p>
                    <div className="border-t border-hq-border">
                        <HqJornadaStatsGrid stats={score.stats} columns={3} />
                    </div>
                </div>
            )}
        </div>
    );
}

/** A finished jornada of the player's club without a lineup row for them. */
function NotCalledUpRow({
    week,
    fixture,
    teamId,
}: {
    week: number;
    fixture: Fixture;
    teamId: number;
}) {
    return (
        <div className="border-b border-hq-border">
            <Link
                href={fixturesShow(fixture.id).url}
                aria-label={`Jornada ${week}: no convocado. Ver partido`}
                className={cn(
                    ROW_GRID,
                    'opacity-80 transition-colors hover:bg-hq-panel hover:opacity-100',
                )}
            >
                <WeekCell week={week} />
                <RivalCell fixture={fixture} teamId={teamId} />
                <ResultCell fixture={fixture} teamId={teamId} />
                <span className={cn('flex items-center', CELL_ROLE)}>
                    <span
                        className={cn(
                            TAG_CLASS,
                            'border-dashed border-hq-live text-hq-live',
                        )}
                    >
                        No convocado
                    </span>
                </span>
                <span className={CELL_DAZN}>
                    <span className="font-mono text-xs text-hq-moss-dim">
                        –
                    </span>
                </span>
                <span className={CELL_POINTS}>
                    <PointsChip className="border-dashed border-hq-live bg-hq-live/5 text-hq-live">
                        NC
                    </PointsChip>
                </span>
                <span className={CELL_CHEVRON}>
                    <ArrowUpRight aria-hidden="true" className="size-4" />
                </span>
            </Link>
        </div>
    );
}

/** A jornada not played yet (or still being played without a lineup row), or with no fixture at all. */
function UpcomingRow({
    week,
    fixture,
    teamId,
}: {
    week: number;
    fixture: Fixture | null;
    teamId: number;
}) {
    if (fixture === null) {
        return (
            <div className={cn(ROW_GRID, 'border-b border-hq-border')}>
                <WeekCell week={week} />
                <span className="col-span-3 col-start-2 row-start-1 font-mono text-xs text-hq-moss-dim md:col-span-6 md:col-start-2">
                    Todavía no hay datos de este jugador para esta jornada
                </span>
            </div>
        );
    }

    const live = isLive(fixture.state);

    return (
        <div className="border-b border-hq-border">
            <Link
                href={fixturesShow(fixture.id).url}
                aria-label={`Jornada ${week}: ${live ? 'en juego' : 'aún no jugada'}. Ver partido`}
                className={cn(
                    ROW_GRID,
                    'opacity-80 transition-colors hover:bg-hq-panel hover:opacity-100',
                )}
            >
                <WeekCell week={week} />
                <RivalCell fixture={fixture} teamId={teamId} />
                <ResultCell fixture={fixture} teamId={teamId} />
                <span
                    className={cn(
                        'flex flex-wrap items-center gap-[7px]',
                        CELL_ROLE,
                    )}
                >
                    <span
                        className={cn(
                            TAG_CLASS,
                            live
                                ? 'border-hq-live text-hq-live'
                                : 'border-hq-azure text-hq-azure',
                        )}
                    >
                        {live ? 'En juego' : 'Aún no jugada'}
                    </span>
                    <span className="font-mono text-[11.5px] text-hq-moss-dim">
                        {formatMatchDateTime(fixture.date)}
                    </span>
                </span>
                <span className={CELL_DAZN} />
                <span className={CELL_POINTS}>
                    <PointsChip className="border-dashed border-hq-border-bright text-hq-moss-dim">
                        —
                    </PointsChip>
                </span>
                <span className={CELL_CHEVRON}>
                    <ArrowUpRight aria-hidden="true" className="size-4" />
                </span>
            </Link>
        </div>
    );
}

/**
 * The player's match log (mock `.mlog`), newest jornada first: one row per
 * jornada up to the current one with rival (home/away, date), result,
 * titular/suplente + substitution minute, minutes, DAZN and points. A row
 * the player has a lineup for is a button: it expands every stat with its
 * fantasy points, the event icons, the manager who fielded them and a link
 * to the match. "No convocado" and not-yet-played rows link to the match.
 */
export function HqPlayerMatchTimeline({
    scores,
    teamFixtures,
    currentWeek,
    playerPosition,
    teamId,
}: HqPlayerMatchTimelineProps) {
    const [openWeeks, setOpenWeeks] = useState<Set<number>>(() => new Set());
    const scoresByWeek = new Map(
        scores.map((score) => [score.fixture.week_number, score]),
    );
    const fixturesByWeek = new Map(
        teamFixtures.map((fixture) => [fixture.week_number, fixture]),
    );

    const toggleWeek = (week: number) => {
        setOpenWeeks((previous) => {
            const next = new Set(previous);

            if (next.has(week)) {
                next.delete(week);
            } else {
                next.add(week);
            }

            return next;
        });
    };

    const weeks = Array.from(
        { length: currentWeek },
        (_, index) => currentWeek - index,
    );

    return (
        <div>
            <div
                aria-hidden="true"
                className="hidden grid-cols-[40px_minmax(150px,1.5fr)_92px_minmax(170px,1.3fr)_58px_62px_22px] items-center gap-x-2.5 border-b border-hq-border-strong px-4 py-[9px] font-mono text-[10.5px] leading-[1.2] font-semibold tracking-[0.07em] text-hq-moss-dim uppercase md:grid"
            >
                <span>Jor.</span>
                <span>Rival</span>
                <span>Resultado</span>
                <span>Titularidad · minutos</span>
                <span className="text-right">DAZN</span>
                <span className="text-right">Puntos</span>
                <span />
            </div>

            {weeks.map((week) => {
                const score = scoresByWeek.get(week);
                const teamFixture = fixturesByWeek.get(week) ?? null;

                // A scored week uses the match the player actually appeared
                // in — around a mid-season transfer it can differ from the
                // current club's fixture for that week number.
                if (
                    score &&
                    score.fixture.state !== 'scheduled' &&
                    score.fixture.state !== 'postponed'
                ) {
                    return (
                        <ScoreRow
                            key={week}
                            week={week}
                            score={score}
                            playerPosition={playerPosition}
                            isOpen={openWeeks.has(week)}
                            onToggle={() => toggleWeek(week)}
                        />
                    );
                }

                if (score) {
                    return (
                        <UpcomingRow
                            key={week}
                            week={week}
                            fixture={score.fixture}
                            teamId={score.team_id}
                        />
                    );
                }

                if (teamFixture?.state === 'finished') {
                    return (
                        <NotCalledUpRow
                            key={week}
                            week={week}
                            fixture={teamFixture}
                            teamId={teamId}
                        />
                    );
                }

                return (
                    <UpcomingRow
                        key={week}
                        week={week}
                        fixture={teamFixture}
                        teamId={teamId}
                    />
                );
            })}
        </div>
    );
}
