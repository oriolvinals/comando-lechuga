import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { useState } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { MatchEventIcons } from '@/components/match-event-icons';
import { didNotPlayMatch } from '@/lib/player-labels';
import { matchPointsBadgeClass } from '@/lib/points';
import { managerColor } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import { show as seasonManagersShow } from '@/routes/season-managers';
import type { Fixture, PlayerScore } from '@/types/models';

const LEGEND_ITEMS = [
    { icon: <span className="text-base">⚽</span>, label: 'Gol' },
    {
        icon: (
            <span className="border border-hq-live px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-live">
                PP
            </span>
        ),
        label: 'Gol en propia',
    },
    {
        icon: <span className="text-base text-hq-med">➜</span>,
        label: 'Asistencia',
    },
    {
        icon: <span className="hq-crest-cut h-5 w-3.5 bg-hq-gold" />,
        label: 'Amarilla',
    },
    {
        icon: (
            <span className="relative inline-block h-5 w-5">
                <span className="hq-crest-cut absolute top-0.5 left-0 h-4 w-3 bg-hq-gold/60" />
                <span className="hq-crest-cut absolute top-0 left-2 h-4 w-3 bg-hq-gold" />
            </span>
        ),
        label: 'Doble amarilla',
    },
    {
        icon: <span className="hq-crest-cut h-5 w-3.5 bg-hq-live" />,
        label: 'Roja',
    },
    {
        icon: (
            <span className="border border-hq-gold px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-gold">
                P+
            </span>
        ),
        label: 'Provoca penalti',
    },
    {
        icon: (
            <span className="border border-hq-ember px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-ember">
                P−
            </span>
        ),
        label: 'Comete penalti',
    },
    {
        icon: (
            <span className="border border-hq-live px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-live">
                P✗
            </span>
        ),
        label: 'Penalti fallado',
    },
    {
        icon: (
            <span className="border border-hq-lime px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-lime">
                P✓
            </span>
        ),
        label: 'Penalti parado',
    },
    {
        icon: (
            <span className="border border-hq-lime px-1.5 py-0.5 font-mono text-[11px] font-bold text-hq-lime">
                0
            </span>
        ),
        label: 'Portería a cero',
    },
];

function TeamColumn({
    scores,
    minPlayedRows,
    showDazn,
    fixtureState,
    onSelect,
}: {
    scores: PlayerScore[];
    minPlayedRows: number;
    showDazn: boolean;
    fixtureState: Fixture['state'];
    onSelect: (score: PlayerScore) => void;
}) {
    const played = scores.filter(
        (score) => !didNotPlayMatch(score.stats, fixtureState),
    );
    const benched = scores.filter((score) =>
        didNotPlayMatch(score.stats, fixtureState),
    );
    const fillerCount = Math.max(0, minPlayedRows - played.length);

    return (
        <div className="border border-t-0 border-hq-border">
            {played.map((score, index) => (
                <PlayerRow
                    key={score.id}
                    score={score}
                    alt={index % 2 === 1}
                    showDazn={showDazn}
                    onSelect={onSelect}
                />
            ))}
            {Array.from({ length: fillerCount }, (_, index) => (
                <div
                    key={`filler-${index}`}
                    className={cn(
                        'hidden items-center gap-2.5 border-b border-hq-ink px-3 py-2.5 last:border-b-0 sm:flex',
                        (played.length + index) % 2 === 1 &&
                            'bg-hq-panel-alt/50',
                    )}
                >
                    <div className="h-14 w-11 shrink-0" />
                </div>
            ))}
            {benched.length > 0 && (
                <p className="mt-3 border-b border-hq-ink bg-hq-panel-alt px-3 py-1.5 font-mono text-[10px] font-bold tracking-widest text-hq-moss-dim uppercase">
                    No jugaron
                </p>
            )}
            {benched.map((score, index) => (
                <PlayerRow
                    key={score.id}
                    score={score}
                    alt={index % 2 === 1}
                    showDazn={false}
                    onSelect={onSelect}
                />
            ))}
        </div>
    );
}

function PlayerRow({
    score,
    alt,
    showDazn,
    onSelect,
}: {
    score: PlayerScore;
    alt: boolean;
    showDazn: boolean;
    onSelect: (score: PlayerScore) => void;
}) {
    const marcaPoints = score.stats.marca_points?.[1];

    return (
        <div
            onClick={() => onSelect(score)}
            className={cn(
                'flex cursor-pointer items-center gap-2.5 border-b border-hq-ink px-3 py-2.5 transition-colors last:border-b-0 hover:bg-hq-panel-alt',
                alt && 'bg-hq-panel-alt/50',
            )}
        >
            <div className="relative h-14 w-11 shrink-0">
                <EntityImage
                    src={score.player.image}
                    alt={score.player.nickname}
                    fallback={User}
                    className="absolute top-0 h-11 w-11 bg-hq-border"
                />
                <HqPositionTag
                    position={score.player.position}
                    className="absolute bottom-0 left-1/2 -translate-x-1/2 bg-hq-ink whitespace-nowrap"
                />
            </div>
            <div className="flex flex-1 flex-col items-start justify-center gap-0.5">
                <span className="text-[12.5px] font-bold text-hq-paper">
                    {score.player.nickname}
                </span>
                <MatchEventIcons
                    stats={score.stats}
                    position={score.player.position}
                />
                {score.lineup_manager && (
                    <Link
                        href={seasonManagersShow(score.lineup_manager.id).url}
                        onClick={(event) => event.stopPropagation()}
                        className="flex items-center gap-1.5 font-mono text-[12px] font-bold text-hq-moss hover:text-hq-paper"
                    >
                        <span
                            className="h-2.5 w-2.5 shrink-0 rounded-[1px]"
                            style={{
                                backgroundColor: managerColor(
                                    score.lineup_manager.primary_color,
                                ),
                            }}
                        />
                        {score.lineup_manager.name}
                    </Link>
                )}
            </div>
            <div className="flex flex-col items-end gap-2.5">
                <span
                    className={cn(
                        'hq-tag-cut w-10 py-0.5 text-center font-display text-lg',
                        matchPointsBadgeClass(score.points),
                    )}
                >
                    {score.points}
                </span>
                {showDazn && marcaPoints !== undefined && (
                    <span className="flex items-center gap-1 font-mono text-[11px] text-hq-moss-dim">
                        <img
                            src="/images/dazn-logo.png"
                            alt="DAZN"
                            className="h-4 w-4"
                        />
                        {marcaPoints}
                    </span>
                )}
            </div>
        </div>
    );
}

interface HqFixtureScoreListProps {
    fixture: Fixture;
    scores: PlayerScore[];
    onSelect: (score: PlayerScore) => void;
}

export function HqFixtureScoreList({
    fixture,
    scores,
    onSelect,
}: HqFixtureScoreListProps) {
    const [activeTeam, setActiveTeam] = useState<'local' | 'guest'>('local');
    const localScores = scores.filter(
        (score) => score.team_id === fixture.local_team.id,
    );
    const guestScores = scores.filter(
        (score) => score.team_id === fixture.guest_team.id,
    );
    const minPlayedRows = Math.max(
        localScores.filter(
            (score) => !didNotPlayMatch(score.stats, fixture.state),
        ).length,
        guestScores.filter(
            (score) => !didNotPlayMatch(score.stats, fixture.state),
        ).length,
    );

    return (
        <>
            <div className="mt-6 flex border border-b-0 border-hq-border sm:hidden">
                <button
                    type="button"
                    onClick={() => setActiveTeam('local')}
                    className={cn(
                        'flex flex-1 items-center justify-center gap-2 py-2 font-mono text-xs font-bold tracking-wider uppercase transition-colors',
                        activeTeam === 'local'
                            ? 'bg-hq-lime text-hq-ink'
                            : 'border-b border-hq-border text-hq-moss',
                    )}
                >
                    <img
                        src={fixture.local_team.logo}
                        alt=""
                        className="h-4 w-4 object-contain"
                    />
                    {fixture.local_team.short_name}
                </button>
                <button
                    type="button"
                    onClick={() => setActiveTeam('guest')}
                    className={cn(
                        'flex flex-1 items-center justify-center gap-2 py-2 font-mono text-xs font-bold tracking-wider uppercase transition-colors',
                        activeTeam === 'guest'
                            ? 'bg-hq-lime text-hq-ink'
                            : 'border-b border-hq-border text-hq-moss',
                    )}
                >
                    <img
                        src={fixture.guest_team.logo}
                        alt=""
                        className="h-4 w-4 object-contain"
                    />
                    {fixture.guest_team.short_name}
                </button>
            </div>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div
                    className={cn(activeTeam !== 'local' && 'hidden sm:block')}
                >
                    <TeamColumn
                        scores={localScores}
                        minPlayedRows={minPlayedRows}
                        showDazn={fixture.state === 'finished'}
                        fixtureState={fixture.state}
                        onSelect={onSelect}
                    />
                </div>
                <div
                    className={cn(activeTeam !== 'guest' && 'hidden sm:block')}
                >
                    <TeamColumn
                        scores={guestScores}
                        minPlayedRows={minPlayedRows}
                        showDazn={fixture.state === 'finished'}
                        fixtureState={fixture.state}
                        onSelect={onSelect}
                    />
                </div>
            </div>
            <div className="mt-6 mb-2.5 flex items-center gap-2.5">
                <span className="h-px flex-1 bg-hq-border" />
                <span className="font-mono text-[10px] tracking-[.15em] text-hq-moss-dim uppercase">
                    Leyenda de iconos
                </span>
                <span className="h-px flex-1 bg-hq-border" />
            </div>
            <div className="flex flex-wrap gap-2.5 border border-hq-border bg-hq-panel px-4 py-3 font-mono text-[12px] text-hq-moss">
                {LEGEND_ITEMS.map((item) => (
                    <span
                        key={item.label}
                        className="inline-flex items-center gap-2 border border-hq-border bg-hq-panel-alt px-2.5 py-1.5"
                    >
                        {item.icon}
                        {item.label}
                    </span>
                ))}
            </div>
        </>
    );
}
