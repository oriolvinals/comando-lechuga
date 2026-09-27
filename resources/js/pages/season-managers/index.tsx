import { Head, Link, router } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { CSSProperties, ReactElement } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqLed } from '@/components/hq-led';
import { HqLineupPitch } from '@/components/hq-lineup-pitch';
import { HqPageHeader } from '@/components/hq-page-header';
import {
    HqPlayerStatsModal,
    lineupPlayerStatsEntry,
} from '@/components/hq-player-stats-modal';
import { HqTooltip } from '@/components/hq-tooltip';
import { HqWeekPickerBand } from '@/components/hq-week-scroll-picker';
import AppLayout from '@/layouts/app-layout';
import { teamFormTextClass } from '@/lib/points';
import { crestTintStyle, managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import {
    index as seasonManagersIndex,
    show as seasonManagersShow,
} from '@/routes/season-managers';
import type {
    Season,
    ManagerLineup,
    ManagerLineupPlayerEntry,
    WeekProgressMap,
} from '@/types/models';

const MEDAL_VARS = [
    'var(--color-hq-gold)',
    'var(--color-hq-silver)',
    'var(--color-hq-bronze)',
];

const MEDAL_TEXT_CLASSES = ['text-hq-gold', 'text-hq-silver', 'text-hq-bronze'];

interface SeasonManagersIndexProps {
    season: Season;
    filters: { week: number };
    lineups: ManagerLineup[];
    weekProgress: WeekProgressMap;
    [key: string]: unknown;
}

/**
 * The jornada's ranking at a glance (mock `.rankstrip`): every manager with a
 * lineup, in lineup-points order, as one scrollable ruled strip.
 */
function RankStrip({ lineups }: { lineups: ManagerLineup[] }) {
    return (
        <nav
            aria-label="Clasificación de la jornada"
            className="hq-no-scrollbar flex overflow-x-auto border-b border-hq-border"
        >
            {lineups.map((lineup, index) => (
                <Link
                    key={lineup.id}
                    href={seasonManagersShow(lineup.season_manager.id).url}
                    className="flex min-h-11 flex-[1_0_auto] items-center gap-[7px] border-r border-hq-border px-3 py-2 font-mono text-xs leading-none font-semibold whitespace-nowrap text-hq-paper last:border-r-0 hover:bg-hq-panel sm:px-3.5"
                >
                    <span
                        className={
                            index < 3
                                ? MEDAL_TEXT_CLASSES[index]
                                : 'text-hq-moss-dim'
                        }
                    >
                        {index + 1}º
                    </span>
                    <span
                        aria-hidden="true"
                        className="h-2 w-2 shrink-0"
                        style={{
                            backgroundColor: managerColor(
                                lineup.season_manager.primary_color,
                            ),
                        }}
                    />
                    <span>{lineup.season_manager.name}</span>
                    <b className={cn('ml-1', teamFormTextClass(lineup.points))}>
                        {lineup.points}
                    </b>
                </Link>
            ))}
        </nav>
    );
}

export default function SeasonManagersIndex({
    season,
    filters,
    lineups,
    weekProgress,
}: SeasonManagersIndexProps) {
    const [selectedPlayer, setSelectedPlayer] =
        useState<ManagerLineupPlayerEntry | null>(null);

    const goToWeek = (nextWeek: number) => {
        router.get(
            seasonManagersIndex().url,
            { week: nextWeek },
            { preserveScroll: true, preserveState: true },
        );
    };

    // Before any pick has a score the jornada ranking is meaningless (all
    // zero), so cards fall back to the general standings position instead.
    const anyPlayed = lineups.some((lineup) =>
        lineup.players.some((entry) => entry.points !== null),
    );

    return (
        <>
            <Head title="Managers" />

            <HqPageHeader
                code="CH·M · ALINEACIONES"
                title="Managers"
                meta={[
                    { label: 'Jornada', value: filters.week },
                    { label: 'Alineaciones', value: lineups.length },
                ]}
            />

            <HqWeekPickerBand
                week={filters.week}
                maxWeek={season.total_weeks}
                playedThroughWeek={season.current_week}
                weekProgress={weekProgress}
                onChange={goToWeek}
            />

            {lineups.length > 0 && anyPlayed && <RankStrip lineups={lineups} />}

            {lineups.length === 0 ? (
                <HqEmptyState glyph="▦" title="Sin alineaciones">
                    Nadie tenía alineación registrada esta jornada.
                </HqEmptyState>
            ) : (
                <div className="overflow-hidden">
                    <div className="-mr-px grid grid-cols-1 md:grid-cols-2 min-[73.75rem]:grid-cols-3">
                        {lineups.map((lineup, index) => {
                            const isMedal = anyPlayed && index < 3;

                            return (
                                <article
                                    key={lineup.id}
                                    style={
                                        isMedal
                                            ? ({
                                                  '--medal': MEDAL_VARS[index],
                                              } as CSSProperties)
                                            : undefined
                                    }
                                    className={cn(
                                        'min-w-0 border-r border-b border-hq-border px-3.5 pt-3.5 pb-[18px] sm:px-4',
                                        isMedal &&
                                            'shadow-[inset_0_3px_0_var(--medal)]',
                                    )}
                                >
                                    <header className="mb-3.5 flex items-center gap-2.5">
                                        <Link
                                            href={
                                                seasonManagersShow(
                                                    lineup.season_manager.id,
                                                ).url
                                            }
                                            className="group flex min-w-0 flex-1 items-center gap-2.5"
                                        >
                                            <EntityImage
                                                src={lineup.season_manager.logo}
                                                alt=""
                                                fallback={Shield}
                                                shape="square"
                                                style={crestTintStyle(
                                                    lineup.season_manager
                                                        .primary_color,
                                                )}
                                                className="h-[46px] w-[46px] shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt p-1 text-hq-khaki"
                                            />
                                            <span className="min-w-0">
                                                <span className="block truncate text-base leading-tight font-extrabold text-hq-paper uppercase group-hover:underline">
                                                    {lineup.season_manager.name}
                                                </span>
                                                <span className="mt-[5px] block font-mono text-xs text-hq-moss-dim">
                                                    {anyPlayed
                                                        ? `${index + 1}º de la jornada`
                                                        : `${lineup.season_manager.position}º en la general`}
                                                </span>
                                            </span>
                                        </Link>
                                        <HqTooltip
                                            label={`Puntos de la jornada ${filters.week}`}
                                            focusable
                                        >
                                            <HqLed
                                                tone={
                                                    lineup.points
                                                        ? 'lime'
                                                        : 'off'
                                                }
                                                className="text-[34px]"
                                            >
                                                {lineup.points}
                                            </HqLed>
                                        </HqTooltip>
                                    </header>

                                    <HqLineupPitch
                                        players={lineup.players}
                                        tacticalFormation={
                                            lineup.tactical_formation
                                        }
                                        onSelectPlayer={setSelectedPlayer}
                                    />
                                </article>
                            );
                        })}
                    </div>
                </div>
            )}

            <HqPlayerStatsModal
                entry={
                    selectedPlayer
                        ? lineupPlayerStatsEntry(selectedPlayer)
                        : null
                }
                onClose={() => setSelectedPlayer(null)}
            />
        </>
    );
}

SeasonManagersIndex.layout = (page: ReactElement) => (
    <AppLayout>{page}</AppLayout>
);
