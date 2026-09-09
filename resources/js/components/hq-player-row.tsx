import { Link, router } from '@inertiajs/react';
import { Shield, User } from 'lucide-react';
import type { MouseEvent as ReactMouseEvent } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqRecentScores } from '@/components/hq-recent-scores';
import { formatCurrency } from '@/lib/format';
import { STATUS_BADGE_CLASS, STATUS_SHORT_LABELS } from '@/lib/player-labels';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import { show as seasonManagersShow } from '@/routes/season-managers';
import { show as teamsShow } from '@/routes/teams';
import type { Player } from '@/types/models';

export function PlayerRow({ player }: { player: Player }) {
    const ownerManager = player.owner_manager;
    const goToOwnerManager = (event: ReactMouseEvent) => {
        if (!ownerManager) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        router.visit(seasonManagersShow(ownerManager.id).url);
    };
    const goToTeam = (event: ReactMouseEvent) => {
        event.preventDefault();
        event.stopPropagation();
        router.visit(teamsShow(player.team.id).url);
    };

    return (
        <Link href={playersShow(player.id).url} className="block">
            {/* Desktop / tablet row */}
            <div className="hq-card-cut mb-1.5 hidden items-center justify-between px-3.5 py-2.5 transition-[filter] hover:brightness-125 xl:flex">
                <div className="flex min-w-0 items-center gap-3">
                    <EntityImage
                        src={player.image}
                        alt={player.nickname}
                        fallback={User}
                        className="h-11 w-11 shrink-0 bg-hq-border"
                    />
                    <div className="w-[190px] shrink-0">
                        <p className="truncate text-sm font-extrabold text-hq-paper">
                            {player.nickname}
                        </p>
                        <span
                            role="link"
                            tabIndex={0}
                            onClick={goToTeam}
                            className="mt-0.5 flex w-fit cursor-pointer items-center gap-1.5 hover:text-hq-paper"
                        >
                            <EntityImage
                                src={player.team.logo}
                                alt={player.team.main_name}
                                fallback={Shield}
                                shape="square"
                                className="h-3.5 w-3.5"
                            />
                            <span className="font-mono text-[10px] text-hq-moss-dim">
                                {player.team.short_name}
                            </span>
                        </span>
                    </div>
                    <div className="w-11 shrink-0 text-center">
                        <HqPositionTag position={player.position} />
                    </div>
                    <div className="w-16 shrink-0">
                        {player.status !== 'ok' && (
                            <span
                                className={cn(
                                    'border px-1.5 py-0.5 font-mono text-[9px] font-bold uppercase',
                                    STATUS_BADGE_CLASS[player.status],
                                )}
                            >
                                {STATUS_SHORT_LABELS[player.status]}
                            </span>
                        )}
                    </div>
                    <div className="flex w-[150px] shrink-0 items-center gap-1.5 font-mono text-[11px] text-hq-moss">
                        {ownerManager ? (
                            <span
                                role="link"
                                tabIndex={0}
                                onClick={goToOwnerManager}
                                className="flex min-w-0 cursor-pointer items-center gap-1.5 hover:text-hq-paper"
                            >
                                <span
                                    className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                                    style={{
                                        backgroundColor: managerColor(
                                            ownerManager.primary_color,
                                        ),
                                    }}
                                />
                                <span className="truncate">
                                    {ownerManager.name}
                                </span>
                            </span>
                        ) : (
                            <span className="text-hq-moss-dim">Libre</span>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-6">
                    <HqNextFixtures fixtures={player.next_fixtures} />
                    <HqRecentScores
                        scores={player.recent_scores}
                        finished={player.recent_scores_finished}
                        opponents={player.recent_scores_opponents}
                        className="w-[130px]"
                    />
                    <div className="w-[130px] shrink-0 text-right">
                        <p className="font-mono text-[13px] font-bold text-hq-paper">
                            {formatCurrency(player.market_value)}
                        </p>
                        {player.market_value_difference !== 0 && (
                            <p
                                className={cn(
                                    'font-mono text-[10px] font-bold',
                                    player.market_value_difference > 0
                                        ? 'text-hq-lime'
                                        : 'text-hq-live',
                                )}
                            >
                                {player.market_value_difference > 0 ? '▲' : '▼'}{' '}
                                {formatCurrency(
                                    Math.abs(player.market_value_difference),
                                )}
                            </p>
                        )}
                    </div>
                    <div className="w-[52px] shrink-0 text-center font-display text-xl text-hq-lime">
                        {player.points}
                    </div>
                </div>
            </div>

            {/* Mobile row */}
            <div className="hq-card-cut mb-2 px-3 py-2.5 transition-[filter] hover:brightness-125 xl:hidden">
                <div className="flex items-center gap-2.5">
                    <EntityImage
                        src={player.image}
                        alt={player.nickname}
                        fallback={User}
                        className="h-9 w-9 shrink-0 bg-hq-border"
                    />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-extrabold text-hq-paper">
                            {player.nickname}
                        </p>
                        <div className="mt-0.5 flex items-center gap-1.5">
                            <span
                                role="link"
                                tabIndex={0}
                                onClick={goToTeam}
                                className="flex w-fit cursor-pointer items-center gap-1.5 hover:text-hq-paper"
                            >
                                <EntityImage
                                    src={player.team.logo}
                                    alt={player.team.main_name}
                                    fallback={Shield}
                                    shape="square"
                                    className="h-[10px] w-[10px]"
                                />
                                <span className="font-mono text-[9px] text-hq-moss-dim">
                                    {player.team.short_name}
                                </span>
                            </span>
                            <HqPositionTag position={player.position} />
                            {player.status !== 'ok' && (
                                <span
                                    className={cn(
                                        'border px-1 py-0.5 font-mono text-[8px] font-bold uppercase',
                                        STATUS_BADGE_CLASS[player.status],
                                    )}
                                >
                                    {STATUS_SHORT_LABELS[player.status]}
                                </span>
                            )}
                        </div>
                    </div>
                    <span className="shrink-0 font-display text-lg text-hq-lime">
                        {player.points}
                    </span>
                </div>
                <div className="mt-2 flex items-center justify-between border-t border-hq-ink pt-2">
                    <p className="font-mono text-[11px] font-bold text-hq-paper">
                        {formatCurrency(player.market_value)}
                        {player.market_value_difference !== 0 && (
                            <span
                                className={cn(
                                    'ml-2 text-[10px]',
                                    player.market_value_difference > 0
                                        ? 'text-hq-lime'
                                        : 'text-hq-live',
                                )}
                            >
                                {player.market_value_difference > 0 ? '▲' : '▼'}{' '}
                                {formatCurrency(
                                    Math.abs(player.market_value_difference),
                                )}
                            </span>
                        )}
                    </p>
                    <div className="flex items-center gap-1.5 font-mono text-[10px] text-hq-moss">
                        {ownerManager ? (
                            <span
                                role="link"
                                tabIndex={0}
                                onClick={goToOwnerManager}
                                className="flex min-w-0 cursor-pointer items-center gap-1.5 hover:text-hq-paper"
                            >
                                <span
                                    className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                                    style={{
                                        backgroundColor: managerColor(
                                            ownerManager.primary_color,
                                        ),
                                    }}
                                />
                                <span className="max-w-[110px] truncate">
                                    {ownerManager.name}
                                </span>
                            </span>
                        ) : (
                            <span className="text-hq-moss-dim">Libre</span>
                        )}
                    </div>
                </div>
                <div className="mt-2 flex items-center justify-between border-t border-hq-ink pt-2">
                    <HqNextFixtures fixtures={player.next_fixtures} size="sm" />
                    <HqRecentScores
                        scores={player.recent_scores}
                        finished={player.recent_scores_finished}
                        opponents={player.recent_scores_opponents}
                        size="sm"
                    />
                </div>
            </div>
        </Link>
    );
}
