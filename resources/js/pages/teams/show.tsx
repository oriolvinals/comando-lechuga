import { Head } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { PlayerRow } from '@/components/hq-player-row';
import { HqPositionTag } from '@/components/hq-position-tag';
import AppLayout from '@/layouts/app-layout';
import { POSITION_GROUP_LABELS } from '@/lib/player-labels';
import type {
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

interface TeamShowProps {
    team: Team;
    squad: Player[];
    standing: StandingsRow | null;
    nextFixtures: (NextFixtureSlot | null)[];
    [key: string]: unknown;
}

export default function TeamShow({
    team,
    squad,
    standing,
    nextFixtures,
}: TeamShowProps) {
    const groups = GROUP_ORDER.map((position) => ({
        position,
        players: squad.filter((player) => player.position === position),
    })).filter((group) => group.players.length > 0);

    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 px-6 py-9 lg:flex-row lg:items-start">
                <Head title={team.main_name} />

                <div className="w-full shrink-0 lg:w-64">
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
                </div>

                <div className="min-w-0 flex-1 space-y-8">
                    <div>
                        <h2 className="mb-3 font-display text-lg tracking-wide text-hq-paper uppercase">
                            Plantilla
                        </h2>
                        {groups.length === 0 ? (
                            <p className="font-mono text-[11px] text-hq-moss-dim">
                                Este equipo no tiene jugadores en la liga.
                            </p>
                        ) : (
                            groups.map((group) => (
                                <div
                                    key={group.position}
                                    className="mt-6 first:mt-0"
                                >
                                    <div className="mb-2 flex items-center gap-2">
                                        <HqPositionTag position={group.position} />
                                        <span className="font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                                            {POSITION_GROUP_LABELS[group.position]}
                                        </span>
                                    </div>
                                    {group.players.map((player) => (
                                        <PlayerRow
                                            key={player.id}
                                            player={player}
                                        />
                                    ))}
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

TeamShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
