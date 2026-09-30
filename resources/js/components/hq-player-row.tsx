import { Link, router } from '@inertiajs/react';
import { Shield, User } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { HqCompareToggle } from '@/components/compare/compare-toggle';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqManagerChip } from '@/components/hq-manager-chip';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { HqNextStart } from '@/components/hq-next-start';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { toCompareEntry } from '@/lib/compare-selection';
import { formatCurrency } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import { show as teamsShow } from '@/routes/teams';
import type { Player } from '@/types/models';

interface PlayerRowLayout {
    /** Show the player's club under their name (and the owner in its own column). Off on a team's own ficha, where every row is the same club — the owner moves under the name. */
    showTeam?: boolean;
    /** Show the position column. Off when rows are already grouped under a position heading. */
    showPosition?: boolean;
    /** Show the next fixtures + difficulty column. Off on a team's own ficha, which already shows the club's next fixtures once. */
    showNextFixtures?: boolean;
    /** Show the "comparar" box before the photo (players list, team roster). */
    comparable?: boolean;
}

interface PlayerRowProps extends PlayerRowLayout {
    player: Player;
}

/**
 * Desktop columns (mock `.ptable`): photo · player · pos · estado · pertenece
 * a · próximos + dificultad · últimas 3 · valor + hoy · pts (with the
 * 32px "comparar" box in front when comparable), minus the ones
 * the layout turns off — so a hidden column never leaves a gap. Applied
 * through the `--player-row-columns` custom property.
 */
function rowGridStyle({
    showTeam = true,
    showPosition = true,
    showNextFixtures = true,
    comparable = false,
}: PlayerRowLayout): CSSProperties {
    const columns = [
        comparable && '32px',
        '46px',
        'minmax(140px,1.4fr)',
        showPosition && '44px',
        '76px',
        showTeam && 'minmax(110px,1fr)',
        showNextFixtures && '118px',
        '120px',
        'minmax(124px,0.9fr)',
        '48px',
    ].filter(Boolean);

    return { '--player-row-columns': columns.join(' ') } as CSSProperties;
}

/** The desktop grid; its columns come from {@link rowGridStyle}. */
const ROW_GRID = 'lg:grid-cols-(--player-row-columns)';

/** The column headings for a list of {@link PlayerRow}s (desktop only). */
export function PlayerRowHeader({
    showTeam = true,
    showPosition = true,
    showNextFixtures = true,
    comparable = false,
}: PlayerRowLayout) {
    return (
        <div
            aria-hidden="true"
            style={rowGridStyle({
                showTeam,
                showPosition,
                showNextFixtures,
                comparable,
            })}
            className={cn(
                'hidden items-end gap-3 border-b border-hq-border-strong px-4 py-[9px] font-mono text-[10.5px] leading-[1.2] font-semibold tracking-[0.07em] text-hq-moss-dim uppercase lg:grid',
                ROW_GRID,
            )}
        >
            {comparable && <span />}
            <span />
            <span>Jugador</span>
            {showPosition && <span className="text-center">Pos.</span>}
            <span>Estado</span>
            {showTeam && <span>Pertenece a</span>}
            {showNextFixtures && <span>Próximos · dificultad</span>}
            <span>Últimas 3 jornadas</span>
            <span className="text-right">Valor · hoy</span>
            <span className="text-right">Pts</span>
        </div>
    );
}

/** Phone-only caption above a folded cell ("Próximos", "Últimas 3"). */
function MobileCaption({ children }: { children: ReactNode }) {
    return <span className="hq-label lg:hidden">{children}</span>;
}

/**
 * One player in a list (mock `.ptable` row / `.pcard`). The whole row opens
 * the player ficha — the name is the real link, for keyboard and
 * middle-click — and the club and owner are links of their own. On phones:
 * photo · identity · points, then value + today and the owner on a dashed
 * rule, then last 3 | next fixtures.
 */
export function PlayerRow({
    player,
    showTeam = true,
    showPosition = true,
    showNextFixtures = true,
    comparable = false,
}: PlayerRowProps) {
    const playerUrl = playersShow(player.id).url;
    const ownerManager = player.owner_manager;

    return (
        <div
            onClick={() => router.visit(playerUrl)}
            style={rowGridStyle({
                showTeam,
                showPosition,
                showNextFixtures,
                comparable,
            })}
            className={cn(
                'grid cursor-pointer items-center gap-x-2.5 gap-y-2.5 border-b border-hq-border px-3.5 py-3 transition-colors hover:bg-hq-panel lg:gap-x-3 lg:px-4 lg:py-2.5',
                comparable
                    ? 'grid-cols-[32px_40px_minmax(0,1fr)_auto]'
                    : 'grid-cols-[40px_minmax(0,1fr)_auto]',
                ROW_GRID,
            )}
        >
            {comparable && (
                <HqCompareToggle
                    player={toCompareEntry(player)}
                    className="self-center"
                />
            )}
            <EntityImage
                src={player.image}
                alt=""
                fallback={User}
                shape="square"
                className="h-10 w-10 rounded-none border border-hq-border-strong bg-hq-panel-alt object-cover object-top lg:h-[46px] lg:w-[46px]"
            />

            <div className="min-w-0">
                <Link
                    href={playerUrl}
                    onClick={(event) => event.stopPropagation()}
                    className="block truncate text-sm leading-[1.15] font-extrabold text-hq-paper hover:underline"
                >
                    {player.nickname}
                </Link>
                <div className="mt-1 flex flex-wrap items-center gap-1.5 font-mono text-[11px] leading-none text-hq-moss-dim">
                    {showTeam ? (
                        <Link
                            href={teamsShow(player.team.id).url}
                            onClick={(event) => event.stopPropagation()}
                            className="inline-flex items-center gap-[5px] hover:text-hq-paper"
                        >
                            <EntityImage
                                src={player.team.logo}
                                alt=""
                                fallback={Shield}
                                shape="square"
                                className="h-3.5 w-3.5 rounded-none bg-transparent"
                            />
                            {player.team.short_name}
                        </Link>
                    ) : (
                        <HqManagerChip
                            manager={ownerManager}
                            className="text-[11px]"
                        />
                    )}
                    {showPosition && (
                        <HqPositionTag
                            position={player.position}
                            className="lg:hidden"
                        />
                    )}
                    <HqStatusBadge
                        status={player.status}
                        className="lg:hidden"
                    />
                </div>
                <HqNextStart
                    start={player.next_start ?? null}
                    status={player.status}
                    className="mt-1.5"
                />
            </div>

            {showPosition && (
                <div className="hidden justify-center lg:flex">
                    <HqPositionTag position={player.position} />
                </div>
            )}

            <div className="hidden min-w-0 lg:block">
                <HqStatusBadge status={player.status} />
            </div>

            {showTeam && (
                <div className="hidden min-w-0 lg:block">
                    <HqManagerChip manager={ownerManager} />
                </div>
            )}

            {showNextFixtures && (
                <div className="order-9 flex flex-col items-end gap-[5px] justify-self-end lg:order-none lg:items-start lg:justify-self-auto">
                    <MobileCaption>Próximos</MobileCaption>
                    <HqNextFixtures
                        fixtures={player.next_fixtures}
                        size="sm"
                        focusable={false}
                    />
                </div>
            )}

            <div
                className={cn(
                    'order-8 flex flex-col gap-[5px] lg:order-none lg:col-span-1',
                    showNextFixtures
                        ? comparable
                            ? 'col-span-3'
                            : 'col-span-2'
                        : 'col-span-full',
                )}
            >
                <MobileCaption>Últimas 3</MobileCaption>
                <HqRecentScores
                    scores={player.recent_scores}
                    finished={player.recent_scores_finished}
                    opponents={player.recent_scores_opponents}
                    size="sm"
                    className="pb-2 lg:hidden"
                />
                <HqRecentScores
                    scores={player.recent_scores}
                    finished={player.recent_scores_finished}
                    opponents={player.recent_scores_opponents}
                    className="hidden pb-2 lg:flex"
                />
            </div>

            <div className="col-span-full flex min-w-0 items-center gap-2 border-t border-dashed border-hq-border pt-2.5 lg:col-span-1 lg:flex-col lg:items-end lg:justify-center lg:gap-1 lg:border-0 lg:pt-0">
                <span className="font-mono text-[13px] font-semibold text-hq-paper tabular-nums">
                    {formatCurrency(player.market_value)}
                </span>
                <HqMarketValueDifference
                    difference={player.market_value_difference}
                    trend={player.market_trend}
                    className="text-xs"
                />
                {showTeam && (
                    <span className="ml-auto min-w-0 lg:hidden">
                        <HqManagerChip manager={ownerManager} />
                    </span>
                )}
            </div>

            <div
                className={cn(
                    'row-start-1 justify-self-end lg:col-start-auto lg:row-start-auto',
                    comparable ? 'col-start-4' : 'col-start-3',
                )}
            >
                <HqLed tone="lime" className="text-[22px] lg:text-2xl">
                    {player.points}
                </HqLed>
            </div>
        </div>
    );
}
