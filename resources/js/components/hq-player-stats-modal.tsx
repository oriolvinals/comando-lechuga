import { Link } from '@inertiajs/react';
import { ArrowUpRight, Shield, User, X } from 'lucide-react';
import { useEffect, useId, useRef } from 'react';
import { HqCompareButton } from '@/components/compare/compare-button';
import { EntityImage } from '@/components/entity-image';
import { HqDaznBadge } from '@/components/hq-dazn-badge';
import { HqFixtureCard } from '@/components/hq-fixture-card';
import { HqJornadaStatsGrid } from '@/components/hq-jornada-stats-grid';
import { HqPositionTag } from '@/components/hq-position-tag';
import { MatchEventIcons } from '@/components/match-event-icons';
import { toCompareEntry } from '@/lib/compare-selection';
import { matchPointsBadgeClass } from '@/lib/points';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import { show as seasonManagersShow } from '@/routes/season-managers';
import { show as teamsShow } from '@/routes/teams';
import type {
    DaznFields,
    Fixture,
    JornadaStats,
    ManagerLineupPlayerEntry,
    Player,
    SeasonManager,
    Team,
} from '@/types/models';

export interface HqPlayerStatsEntry {
    /** Only what the sheet shows, so the comparator can pass its own player shape. */
    player: Pick<Player, 'id' | 'nickname' | 'image' | 'position'>;
    team: Team;
    points: number;
    /** DAZN rating fields of that match — omit when the player had no minutes. */
    dazn?: DaznFields;
    stats: JornadaStats;
    lineupManager?: SeasonManager | null;
    subMinute?: { minute: number; direction: 'in' | 'out' } | null;
    /** The match this jornada's stats came from — shown below the stats grid with its result. Omit (or null) when the modal is already opened from that match's own ficha, where repeating it would be redundant. */
    fixture?: Fixture | null;
}

/** A fantasy lineup pick (pitch token) as the modal's entry — DAZN only once the player actually has minutes that jornada. */
export function lineupPlayerStatsEntry(
    selected: ManagerLineupPlayerEntry,
): HqPlayerStatsEntry {
    return {
        player: selected.player,
        team: selected.player.team,
        points: selected.points ?? 0,
        dazn:
            selected.stats?.mins_played !== undefined ||
            selected.dazn_estimate !== null
                ? selected
                : undefined,
        stats: selected.stats ?? {},
        fixture: selected.fixture,
    };
}

interface HqPlayerStatsModalProps {
    entry: HqPlayerStatsEntry | null;
    onClose: () => void;
}

/**
 * A player's jornada sheet (mock `.modal`): a centred ruled box on desktop, a
 * bottom sheet on phones. Header "Ficha de la jornada · J{n}" + close, then
 * identity (photo with sub minute, name, position, club, lineup manager),
 * the points tier chip and DAZN score, match events, that jornada's fixture
 * as a mini scoreboard, the stats grid and a link to the full ficha. Esc,
 * the backdrop and the close button all dismiss it.
 */
export function HqPlayerStatsModal({
    entry,
    onClose,
}: HqPlayerStatsModalProps) {
    const titleId = useId();
    const closeButtonRef = useRef<HTMLButtonElement>(null);
    const onCloseRef = useRef(onClose);
    const isOpen = entry !== null;

    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        const previouslyFocused = document.activeElement as HTMLElement | null;
        closeButtonRef.current?.focus();

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onCloseRef.current();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => {
            window.removeEventListener('keydown', handleKeyDown);
            previouslyFocused?.focus?.();
        };
    }, [isOpen]);

    if (!entry) {
        return null;
    }

    const {
        player,
        team,
        points,
        dazn,
        stats,
        lineupManager,
        subMinute,
        fixture,
    } = entry;

    return (
        <div
            className="fixed inset-0 z-[200] flex cursor-pointer items-end justify-center bg-black/65 md:items-center md:p-4"
            onClick={onClose}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                className="max-h-[90vh] w-full cursor-default overflow-y-auto border border-hq-border-bright bg-hq-ink shadow-[0_30px_80px_rgba(0,0,0,0.6)] md:max-h-[88vh] md:max-w-[440px]"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="sticky top-0 z-10 flex items-center justify-between border-b border-hq-border bg-hq-ink py-2 pr-2.5 pl-4 font-mono text-[11px] leading-none font-bold tracking-[0.1em] text-hq-moss-dim uppercase">
                    <span>
                        Ficha de la jornada
                        {fixture ? ` · J${fixture.week_number}` : ''}
                    </span>
                    <button
                        ref={closeButtonRef}
                        type="button"
                        onClick={onClose}
                        aria-label="Cerrar"
                        className="flex h-11 w-11 cursor-pointer items-center justify-center border border-hq-border-strong text-hq-moss hover:border-hq-border-bright hover:text-hq-paper md:h-[30px] md:w-[30px]"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3.5 p-4">
                    <div className="relative h-16 w-16">
                        <EntityImage
                            src={player.image}
                            alt={player.nickname}
                            fallback={User}
                            shape="square"
                            className="h-16 w-16 rounded-none border border-hq-border-strong bg-hq-panel-alt object-cover object-top"
                        />
                        {subMinute && (
                            <span
                                className={cn(
                                    'absolute -right-2 -bottom-1.5 border border-current bg-hq-ink px-1 py-0.5 font-mono text-[10px] leading-none font-bold whitespace-nowrap',
                                    subMinute.direction === 'out'
                                        ? 'text-hq-live'
                                        : 'text-hq-lime',
                                )}
                            >
                                ↳{subMinute.minute}'
                            </span>
                        )}
                    </div>
                    <div className="min-w-0">
                        <h2
                            id={titleId}
                            className="truncate text-xl leading-none font-black text-hq-paper uppercase"
                        >
                            {player.nickname}
                        </h2>
                        <div className="mt-[7px] flex flex-wrap items-center gap-1.5">
                            <HqPositionTag position={player.position} />
                            <Link
                                href={teamsShow(team.id).url}
                                className="inline-flex min-w-0 items-center gap-1.5 font-mono text-xs text-hq-moss hover:text-hq-paper"
                            >
                                <EntityImage
                                    src={team.logo}
                                    alt=""
                                    fallback={Shield}
                                    shape="square"
                                    className="h-4 w-4 rounded-none bg-transparent"
                                />
                                <span className="truncate">
                                    {team.main_name}
                                </span>
                            </Link>
                        </div>
                        {lineupManager && (
                            <Link
                                href={seasonManagersShow(lineupManager.id).url}
                                className="mt-[7px] inline-flex max-w-full items-center gap-1.5 font-mono text-xs font-bold text-hq-khaki hover:text-hq-paper"
                            >
                                <span
                                    className="h-2 w-2 shrink-0"
                                    style={{
                                        backgroundColor: managerColor(
                                            lineupManager.primary_color,
                                        ),
                                    }}
                                />
                                <span className="truncate">
                                    {lineupManager.name}
                                </span>
                            </Link>
                        )}
                    </div>
                    <div className="flex flex-col items-end gap-1.5">
                        <span
                            className={cn(
                                'inline-flex h-8 min-w-10 items-center justify-center px-2 font-mono text-base leading-none font-bold tabular-nums',
                                matchPointsBadgeClass(points),
                            )}
                        >
                            {points}
                        </span>
                        {dazn && <HqDaznBadge entry={dazn} size="md" />}
                    </div>
                </div>

                <div className="px-4 pb-4 empty:hidden">
                    <MatchEventIcons stats={stats} position={player.position} />
                </div>

                {fixture && (
                    <div className="border-t border-hq-border">
                        <HqFixtureCard
                            fixture={fixture}
                            label={`Jornada ${fixture.week_number}`}
                        />
                    </div>
                )}

                <div className="border-t border-hq-border">
                    <HqJornadaStatsGrid stats={stats} showEmptyStats={false} />
                </div>

                <div className="flex items-center justify-between gap-2 border-t border-hq-border px-3 py-2 sm:px-4">
                    <HqCompareButton
                        player={toCompareEntry(player)}
                        onWaiting={onClose}
                    />
                    <Link
                        href={playersShow(player.id).url}
                        className="inline-flex h-11 cursor-pointer items-center justify-center gap-1.5 px-3 font-mono text-xs font-bold tracking-[0.06em] text-hq-lime uppercase hover:bg-hq-panel sm:h-9"
                    >
                        Ver ficha completa
                        <ArrowUpRight className="h-3.5 w-3.5" />
                    </Link>
                </div>
            </div>
        </div>
    );
}
