import { Head, Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqEmptyState } from '@/components/hq-empty-state';
import { HqLed } from '@/components/hq-led';
import { HqLineupPitch } from '@/components/hq-lineup-pitch';
import { HqNextFixtures } from '@/components/hq-next-fixtures';
import { PlayerRow, PlayerRowHeader } from '@/components/hq-player-row';
import {
    HqPlayerStatsModal,
    lineupPlayerStatsEntry,
} from '@/components/hq-player-stats-modal';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqSection } from '@/components/hq-section';
import { HqTeamFixtureStrip } from '@/components/hq-team-fixture-strip';
import { HqTeamFormStrip } from '@/components/hq-team-form-strip';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/format';
import { POSITION_GROUP_LABELS } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
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
    substitutes: ManagerLineupPlayerEntry[];
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

function signed(value: number): string {
    return `${value > 0 ? '+' : ''}${value}`;
}

/** One cell of the KPI strip (mock `.kpi`); the grid draws the rules between cells. */
function Kpi({
    label,
    hot = false,
    children,
    sub,
}: {
    label: string;
    hot?: boolean;
    children: ReactNode;
    sub?: ReactNode;
}) {
    return (
        <div
            className={cn(
                'min-w-0 bg-hq-ink px-3.5 py-3.5 sm:px-4',
                hot && 'bg-linear-to-b from-hq-lime/6 to-hq-ink',
            )}
        >
            <p className="hq-label">{label}</p>
            <div className="mt-2 flex min-h-[30px] items-center font-mono text-[19px] leading-[1.1] font-semibold tracking-[-0.02em] whitespace-nowrap text-hq-paper tabular-nums sm:text-[22px] min-[80rem]:text-[19px] min-[90rem]:text-[22px]">
                {children}
            </div>
            {sub && (
                <div className="mt-1.5 truncate font-mono text-[11.5px] leading-snug text-hq-moss">
                    {sub}
                </div>
            )}
        </div>
    );
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

    const selectedFixture = fixtures.find(
        (fixture) => fixture.week_number === selectedWeek,
    );

    const tacticalFormation = (() => {
        if (!selectedFixture) {
            return null;
        }

        const isLocal = selectedFixture.local_team.id === team.id;
        const formation = isLocal
            ? selectedFixture.local_formation
            : selectedFixture.guest_formation;

        return formation ? formation.split('-').map(Number) : null;
    })();

    const groups = GROUP_ORDER.map((position) => ({
        position,
        players: squad.filter((player) => player.position === position),
    })).filter((group) => group.players.length > 0);

    const squadValue = squad.reduce(
        (sum, player) => sum + player.market_value,
        0,
    );
    const squadValueDifference = squad.reduce(
        (sum, player) => sum + player.market_value_difference,
        0,
    );

    return (
        <div className="flex-1">
            <Head title={team.main_name} />

            <div className="flex items-start gap-3.5 border-b border-hq-border bg-[radial-gradient(ellipse_at_0%_0%,rgba(196,255,61,0.07),transparent_55%)] px-3.5 pt-4 pb-3.5 sm:items-center sm:gap-5 sm:px-5 sm:pt-[22px] sm:pb-[18px]">
                <span className="flex size-[72px] shrink-0 items-center justify-center border border-hq-border-bright bg-hq-well sm:size-24">
                    <EntityImage
                        src={team.logo}
                        alt={team.main_name}
                        fallback={Shield}
                        shape="square"
                        className="size-14 rounded-none bg-transparent object-contain sm:size-[74px]"
                    />
                </span>
                <div className="min-w-0">
                    <span className="block font-mono text-[11px] leading-none font-semibold tracking-[0.14em] text-hq-lime">
                        CH·E{String(team.id).padStart(2, '0')} · EQUIPO
                    </span>
                    <h1 className="mt-[5px] mb-2.5 font-display text-[30px] leading-[0.92] break-words text-hq-paper uppercase sm:text-[44px]">
                        {team.main_name}
                    </h1>
                    {standing && (
                        <div className="flex flex-wrap items-center gap-2.5">
                            <span className="font-mono text-xs text-hq-moss">
                                {standing.position}º en LaLiga
                            </span>
                            <HqTeamFormStrip row={standing} />
                        </div>
                    )}
                </div>
            </div>

            <div
                className={cn(
                    'grid grid-cols-2 gap-px border-b border-hq-border bg-hq-border',
                    standing
                        ? 'md:grid-cols-3 min-[80rem]:grid-cols-[repeat(4,minmax(0,1fr))_minmax(0,1.4fr)_minmax(0,1fr)]'
                        : 'md:grid-cols-2',
                )}
            >
                {standing && (
                    <>
                        <Kpi
                            label="Posición"
                            hot
                            sub={`${standing.points} pts`}
                        >
                            <HqLed tone="lime" glow className="text-[34px]">
                                {standing.position}º
                            </HqLed>
                        </Kpi>
                        <Kpi
                            label="PJ / PG / PE / PP"
                            sub={`DG ${signed(standing.goal_difference)}`}
                        >
                            {standing.played} / {standing.won} /{' '}
                            {standing.drawn} / {standing.lost}
                        </Kpi>
                        <Kpi label="GF-GC">
                            {standing.goals_for}-{standing.goals_against}
                        </Kpi>
                        <Kpi label="Pts">
                            <HqLed tone="lime" className="text-[34px]">
                                {standing.points}
                            </HqLed>
                        </Kpi>
                    </>
                )}
                <Kpi
                    label="Valor plantilla"
                    sub={
                        squadValueDifference !== 0 ? (
                            <span
                                className={
                                    squadValueDifference > 0
                                        ? 'text-hq-lime'
                                        : 'text-hq-neg'
                                }
                            >
                                {squadValueDifference > 0 ? '▲' : '▼'}{' '}
                                {formatCurrency(Math.abs(squadValueDifference))}
                            </span>
                        ) : (
                            'sin cambios hoy'
                        )
                    }
                >
                    {formatCurrency(squadValue)}
                </Kpi>
                <Kpi label="Próximos">
                    <HqNextFixtures fixtures={nextFixtures} />
                </Kpi>
            </div>

            <div className="grid grid-cols-1 min-[80rem]:grid-cols-[430px_minmax(0,1fr)]">
                <aside className="min-w-0 border-hq-border min-[80rem]:border-r">
                    <HqSection
                        code="CH·01"
                        title="Alineación de la jornada"
                        action={
                            selectedFixture && (
                                <Link
                                    href={fixturesShow(selectedFixture.id).url}
                                    className="inline-flex min-h-11 items-center font-bold text-hq-lime hover:underline sm:min-h-0"
                                >
                                    VER PARTIDO →
                                </Link>
                            )
                        }
                        flush
                    >
                        <HqTeamFixtureStrip
                            fixtures={fixtures}
                            teamId={team.id}
                            selectedWeek={selectedWeek}
                            onSelectWeek={setSelectedWeek}
                        />
                        {lineupForWeek ? (
                            <div className="p-3.5 sm:p-4">
                                <div className="mx-auto max-w-[360px]">
                                    <HqLineupPitch
                                        players={lineupForWeek.players}
                                        substitutes={lineupForWeek.substitutes}
                                        tacticalFormation={tacticalFormation}
                                        onSelectPlayer={setSelectedPlayer}
                                        showTeamBadge={false}
                                        showStarterBadge={false}
                                        showLiveIndicator={false}
                                        fixture={lineupForWeek.fixture}
                                        teamId={team.id}
                                    />
                                    {lineupForWeek.players.length < 11 && (
                                        <p className="mt-2.5 text-center hq-label">
                                            {lineupForWeek.players.length} de 11
                                            titulares identificados
                                        </p>
                                    )}
                                </div>
                            </div>
                        ) : (
                            <HqEmptyState
                                glyph="▦"
                                title="Sin alineación"
                                className="mx-auto max-w-[360px] sm:mx-auto"
                            >
                                Alineación aún no disponible en esa jornada.
                            </HqEmptyState>
                        )}
                    </HqSection>
                </aside>

                <HqSection
                    code="CH·02"
                    title="Plantilla"
                    action={`${squad.length} ${squad.length === 1 ? 'jugador' : 'jugadores'}`}
                    className="min-w-0"
                    flush
                >
                    {groups.length === 0 ? (
                        <p className="p-3.5 font-mono text-[12.5px] text-hq-moss sm:p-4">
                            Este equipo no tiene jugadores en la liga.
                        </p>
                    ) : (
                        groups.map((group, index) => (
                            <section
                                key={group.position}
                                aria-label={
                                    POSITION_GROUP_LABELS[group.position]
                                }
                            >
                                <div className="flex items-center gap-2 border-b border-hq-border-strong px-3.5 pt-3.5 pb-2 font-mono text-[10.5px] leading-none font-bold tracking-[0.1em] text-hq-moss uppercase sm:px-4">
                                    <HqPositionTag position={group.position} />
                                    <span>
                                        {POSITION_GROUP_LABELS[group.position]}
                                    </span>
                                    <span className="text-hq-moss-dim">
                                        {group.players.length}
                                    </span>
                                </div>
                                {index === 0 && (
                                    <PlayerRowHeader
                                        showTeam={false}
                                        showPosition={false}
                                    />
                                )}
                                {group.players.map((player) => (
                                    <PlayerRow
                                        key={player.id}
                                        player={player}
                                        showTeam={false}
                                        showPosition={false}
                                    />
                                ))}
                            </section>
                        ))
                    )}
                </HqSection>
            </div>

            <HqPlayerStatsModal
                entry={
                    selectedPlayer
                        ? {
                              ...lineupPlayerStatsEntry(selectedPlayer),
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
