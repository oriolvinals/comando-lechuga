import { User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqManagerChip } from '@/components/hq-manager-chip';
import {
    formatSignedPoints,
    matchPointsBadgeClass,
    pointsToneClass,
} from '@/lib/points';
import { cn } from '@/lib/utils';
import type {
    FixtureFantasyScoreboard,
    FixtureLineupEntry,
} from '@/types/models';

interface HqFixtureFantasyScoreboardProps {
    scoreboard: FixtureFantasyScoreboard;
    lineups: FixtureLineupEntry[];
    onSelect: (entry: FixtureLineupEntry) => void;
}

function statCount(entry: FixtureLineupEntry, key: string): number {
    return entry.stats?.[key]?.[0] ?? 0;
}

/** The two headline facts under the best player's name (goals, assists… minutes). */
function keyStats(
    entry: FixtureLineupEntry,
): { value: string; label: string }[] {
    const goals = statCount(entry, 'goals');
    const assists = statCount(entry, 'goal_assist');
    const penaltiesWon = statCount(entry, 'penalty_won');
    const saves = statCount(entry, 'saves');
    const recoveries = statCount(entry, 'ball_recovery');
    const facts: { value: string; label: string }[] = [];

    if (goals) {
        facts.push({ value: `${goals}`, label: goals > 1 ? 'goles' : 'gol' });
    }

    if (assists) {
        facts.push({
            value: `${assists}`,
            label: assists > 1 ? 'asistencias' : 'asistencia',
        });
    }

    if (penaltiesWon) {
        facts.push({ value: `${penaltiesWon}`, label: 'penalti provocado' });
    }

    if (saves) {
        facts.push({ value: `${saves}`, label: 'paradas' });
    }

    facts.push({
        value: `${statCount(entry, 'mins_played')}'`,
        label: 'jugados',
    });

    if (recoveries) {
        facts.push({ value: `${recoveries}`, label: 'recuperaciones' });
    }

    return facts.slice(0, 2);
}

function BestPlayer({
    entry,
    side,
    onSelect,
}: {
    entry: FixtureLineupEntry | undefined;
    side: 'local' | 'guest';
    onSelect: (entry: FixtureLineupEntry) => void;
}) {
    if (!entry?.player) {
        return <div />;
    }

    const points = entry.points ?? 0;

    return (
        <div
            className={cn(
                'flex min-w-0',
                side === 'guest' && 'justify-end text-right',
            )}
        >
            <button
                type="button"
                onClick={() => onSelect(entry)}
                aria-label={`Mejor fantasy: ${entry.player.nickname} · ${points} puntos`}
                className={cn(
                    'group flex min-w-0 cursor-pointer items-center gap-2.5 text-left',
                    side === 'guest' && 'flex-row-reverse text-right',
                )}
            >
                <EntityImage
                    src={entry.player.image}
                    alt=""
                    fallback={User}
                    shape="square"
                    className="h-[34px] w-[34px] shrink-0 rounded-none border border-hq-border-strong bg-hq-well object-cover text-hq-moss-dim group-hover:border-hq-lime md:h-10 md:w-10"
                    style={{ objectPosition: 'center 25%' }}
                />
                <span className="min-w-0">
                    <span className="block font-mono text-[10px] leading-none font-bold tracking-[0.12em] text-hq-moss-dim uppercase">
                        Mejor
                    </span>
                    <span
                        className={cn(
                            'mt-1 flex min-w-0 items-center gap-1.5',
                            side === 'guest' && 'justify-end',
                        )}
                    >
                        <span className="truncate font-mono text-[13px] leading-[1.1] font-bold text-hq-paper group-hover:text-hq-lime">
                            {entry.player.nickname}
                        </span>
                        <span
                            className={cn(
                                'inline-flex h-[18px] min-w-[22px] shrink-0 items-center justify-center px-[3px] font-mono text-[11px] leading-none font-bold tabular-nums',
                                matchPointsBadgeClass(points),
                            )}
                        >
                            {points}
                        </span>
                    </span>
                    <span className="mt-1 block font-mono text-[10.5px] leading-[1.2] whitespace-nowrap text-hq-moss-dim">
                        {keyStats(entry).map((fact, index) => (
                            <span key={fact.label}>
                                {index > 0 && ' · '}
                                <b className="font-bold text-hq-moss">
                                    {fact.value}
                                </b>{' '}
                                {fact.label}
                            </span>
                        ))}
                    </span>
                </span>
            </button>
        </div>
    );
}

/**
 * The finished match's "Marcador fantasy" (mock Opción 4): a second Doto
 * scoreboard with each side's total fantasy points, each side's best player
 * (opens the stats modal), and the managers who fielded players in it.
 */
export function HqFixtureFantasyScoreboard({
    scoreboard,
    lineups,
    onSelect,
}: HqFixtureFantasyScoreboardProps) {
    const { local, guest, managers } = scoreboard;
    const findEntry = (id: number | null) =>
        id === null ? undefined : lineups.find((entry) => entry.id === id);
    const localLoses = local.points < guest.points;
    const guestLoses = guest.points < local.points;

    return (
        <section
            aria-label="Marcador fantasy"
            className="grid grid-cols-2 items-center gap-x-2.5 gap-y-3 border-b border-hq-border bg-hq-well bg-[radial-gradient(ellipse_45%_120%_at_50%_50%,rgb(196_255_61/0.05),transparent_70%)] p-3 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] md:gap-[18px] md:px-6 md:py-3"
        >
            <div className="order-first col-span-2 text-center md:order-none md:col-span-1 md:col-start-2 md:row-start-1">
                <span className="block font-mono text-[10px] leading-none font-bold tracking-[0.12em] whitespace-nowrap text-hq-moss-dim uppercase">
                    Marcador fantasy
                </span>
                <div className="mt-[7px] mb-1.5 flex items-center justify-center gap-3">
                    <HqLed
                        tone="lime"
                        glow={!localLoses}
                        className={cn(
                            'text-[38px] md:text-[44px]',
                            localLoses && 'text-[#6f7a55]',
                        )}
                    >
                        {local.points}
                    </HqLed>
                    <span
                        aria-hidden="true"
                        className="font-dot text-2xl leading-none font-black text-hq-border-bright"
                    >
                        -
                    </span>
                    <HqLed
                        tone="lime"
                        glow={!guestLoses}
                        className={cn(
                            'text-[38px] md:text-[44px]',
                            guestLoses && 'text-[#6f7a55]',
                        )}
                    >
                        {guest.points}
                    </HqLed>
                </div>
                {managers.length > 0 && (
                    <ul
                        aria-label="Mánagers con jugadores en este partido"
                        className="flex flex-wrap justify-center gap-x-3 gap-y-1 font-mono text-[10.5px] leading-[1.2]"
                    >
                        {managers.map((manager) => (
                            <li
                                key={manager.id}
                                className="inline-flex min-w-0 items-center gap-1.5"
                            >
                                <HqManagerChip
                                    manager={manager}
                                    className="text-[10.5px]"
                                />
                                <b
                                    className={cn(
                                        'font-bold',
                                        pointsToneClass(manager.points),
                                    )}
                                >
                                    {formatSignedPoints(manager.points)}
                                </b>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <div className="min-w-0 md:col-start-1 md:row-start-1">
                <BestPlayer
                    entry={findEntry(local.best_lineup_id)}
                    side="local"
                    onSelect={onSelect}
                />
            </div>
            <div className="min-w-0 md:col-start-3 md:row-start-1">
                <BestPlayer
                    entry={findEntry(guest.best_lineup_id)}
                    side="guest"
                    onSelect={onSelect}
                />
            </div>
        </section>
    );
}
