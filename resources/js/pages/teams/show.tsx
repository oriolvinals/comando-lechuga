import { Head } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLineupPitch } from '@/components/hq-lineup-pitch';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { PlayerRow } from '@/components/hq-player-row';
import { HqPlayerStatsModal } from '@/components/hq-player-stats-modal';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqTeamFixtureStrip } from '@/components/hq-team-fixture-strip';
import AppLayout from '@/layouts/app-layout';
import { POSITION_GROUP_LABELS } from '@/lib/player-labels';
import type {
    Fixture,
    ManagerLineupPlayerEntry,
    NextFixtureSlot,
    Player,
    PlayerPosition,
    StandingsRow,
    Team,
} from '@/types/models';

const GROUP_ORDER: PlayerPosition[] = [
    'goalkeeper',
    'defender',
    'midfield',
    'striker',
    'coach',
];

interface TeamWeekLineup {
    week_number: number;
    fixture: Fixture;
    players: ManagerLineupPlayerEntry[];
}

interface TeamShowProps {
    team: Team;
    squad: Player[];
    standing: StandingsRow | null;
    nextFixtures: (NextFixtureSlot | null)[];
    fixtures: Fixture[];
    currentWeek: number;
    weeklyLineups: TeamWeekLineup[];
    [key: string]: unknown;
}

export default function TeamShow({
    team,
    squad,
    standing,
    nextFixtures,
    fixtures,
    currentWeek,
    weeklyLineups,
}: TeamShowProps) {
    const [selectedWeek, setSelectedWeek] = useState(currentWeek);
    const [selectedPlayer, setSelectedPlayer] =
        useState<ManagerLineupPlayerEntry | null>(null);

    const lineupForWeek = weeklyLineups.find(
        (lineup) => lineup.week_number === selectedWeek,
    );

    const groups = GROUP_ORDER.map((position) => ({
        position,
        players: squad.filter((player) => player.position === position),
    })).filter((group) => group.players.length > 0);

    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-6 py-9 lg:flex-row lg:items-start">
                <Head title={team.main_name} />

                <div className="w-full shrink-0 lg:w-1/4">
                    <div className="hq-card-cut p-4 text-center">
                        <EntityImage
                            src={team.logo}
                            alt={team.main_name}
                            fallback={Shield}
                            shape="square"
                            className="mx-auto mb-3 h-16 w-16"
                        />
                        <h1 className="mb-3 font-display text-xl text-hq-paper uppercase">
                            {team.main_name}
                        </h1>

                        {standing && (
                            <>
                                <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                    <span className="font-mono text-[11px] text-hq-moss">
                                        POSICIÓN
                                    </span>
                                    <span className="bg-hq-border px-1.5 font-mono font-bold text-hq-paper">
                                        {standing.position}º
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                    <span className="font-mono text-[11px] text-hq-moss">
                                        PJ / PG / PE / PP
                                    </span>
                                    <span className="font-mono font-bold text-hq-paper">
                                        {standing.played} / {standing.won} /{' '}
                                        {standing.drawn} / {standing.lost}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                                    <span className="font-mono text-[11px] text-hq-moss">
                                        GF-GC
                                    </span>
                                    <span className="font-mono font-bold text-hq-paper">
                                        {standing.goals_for}-
                                        {standing.goals_against}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t border-hq-border-strong pt-1.5">
                                    <span className="font-mono text-[11px] text-hq-lime">
                                        PTS
                                    </span>
                                    <span className="font-mono font-bold text-hq-lime">
                                        {standing.points}
                                    </span>
                                </div>
                            </>
                        )}

                        <div className="flex items-center justify-between border-t border-hq-border py-1.5">
                            <span className="font-mono text-[11px] text-hq-moss">
                                PRÓXIMOS
                            </span>
                            <HqNextFixtures fixtures={nextFixtures} size="sm" />
                        </div>
                    </div>

                    <div className="mt-6">
                        <h2 className="mb-3 font-display text-lg tracking-wide text-hq-paper uppercase">
                            Alineación de la jornada
                        </h2>
                        <div className="mb-4 min-w-0">
                            <HqTeamFixtureStrip
                                fixtures={fixtures}
                                teamId={team.id}
                                selectedWeek={selectedWeek}
                                onSelectWeek={setSelectedWeek}
                            />
                        </div>
                        <div className="mx-auto max-w-[360px]">
                            {lineupForWeek ? (
                                <>
                                    <HqLineupPitch
                                        players={lineupForWeek.players}
                                        tacticalFormation={null}
                                        onSelectPlayer={setSelectedPlayer}
                                    />
                                    {lineupForWeek.players.length < 11 && (
                                        <p className="mt-2 text-center font-mono text-[10px] text-hq-moss-dim">
                                            {lineupForWeek.players.length} de
                                            11 titulares identificados
                                        </p>
                                    )}
                                </>
                            ) : (
                                <div className="border border-dashed border-hq-border-strong px-6 py-9 text-center">
                                    <p className="font-mono text-[11px] text-hq-moss-dim">
                                        Alineación aún no disponible en esa
                                        jornada.
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className="min-w-0 flex-1">
                    <h2 className="mb-3 font-display text-lg tracking-wide text-hq-paper uppercase">
                        Plantilla
                    </h2>
                    {groups.length === 0 ? (
                        <p className="font-mono text-[11px] text-hq-moss-dim">
                            Este equipo no tiene jugadores en la liga.
                        </p>
                    ) : (
                        groups.map((group) => (
                            <div key={group.position} className="mt-6 first:mt-0">
                                <div className="mb-2 flex items-center gap-2">
                                    <HqPositionTag position={group.position} />
                                    <span className="font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                                        {POSITION_GROUP_LABELS[group.position]}
                                    </span>
                                </div>
                                {group.players.map((player) => (
                                    <PlayerRow key={player.id} player={player} />
                                ))}
                            </div>
                        ))
                    )}
                </div>
            </div>

            <HqPlayerStatsModal
                entry={
                    selectedPlayer
                        ? {
                              player: selectedPlayer.player,
                              team: selectedPlayer.player.team,
                              points: selectedPlayer.points ?? 0,
                              daznPoints:
                                  selectedPlayer.stats?.mins_played !==
                                  undefined
                                      ? selectedPlayer.stats.marca_points?.[1]
                                      : undefined,
                              stats: selectedPlayer.stats ?? {},
                              fixture: lineupForWeek?.fixture ?? null,
                          }
                        : null
                }
                onClose={() => setSelectedPlayer(null)}
            />
        </div>
    );
}

TeamShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
