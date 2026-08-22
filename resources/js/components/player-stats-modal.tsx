import { Shield, User, X } from 'lucide-react';
import { useEffect } from 'react';
import { EntityImage } from '@/components/entity-image';
import { PositionBadge } from '@/components/position-badge';
import { StatusBadge } from '@/components/status-badge';
import { JORNADA_STAT_LABELS, JORNADA_STAT_ORDER } from '@/lib/player-labels';
import { cn } from '@/lib/utils';
import type { SeasonTeamLineupPlayerEntry } from '@/types/models';

interface PlayerStatsModalProps {
    entry: SeasonTeamLineupPlayerEntry | null;
    onClose: () => void;
}

export function PlayerStatsModal({ entry, onClose }: PlayerStatsModalProps) {
    useEffect(() => {
        if (!entry) {
            return;
        }

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [entry, onClose]);

    if (!entry) {
        return null;
    }

    const { player } = entry;

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            onClick={onClose}
        >
            <div
                className="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={onClose}
                        className="text-neutral-400 hover:text-neutral-600"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="flex flex-col items-center gap-2 text-center">
                    <EntityImage
                        src={player.image}
                        alt={player.nickname}
                        fallback={User}
                        className="h-20 w-20"
                    />
                    <h2 className="text-lg font-semibold">{player.nickname}</h2>
                    <div className="flex items-center gap-2">
                        <EntityImage
                            src={player.team.logo}
                            alt={player.team.name}
                            fallback={Shield}
                            className="h-5 w-5"
                        />
                        <span className="text-sm text-neutral-500">
                            {player.team.name}
                        </span>
                    </div>
                    <div className="flex items-center gap-2">
                        <PositionBadge position={entry.position} />
                        <StatusBadge status={player.status} />
                    </div>
                    <p className="text-sm text-neutral-500">
                        Puntos jornada:{' '}
                        <span className="font-semibold text-neutral-900">
                            {entry.points ?? '–'}
                        </span>
                    </p>
                </div>

                {entry.stats ? (
                    <ul className="mt-6 divide-y divide-neutral-100 text-sm">
                        {JORNADA_STAT_ORDER.map((key) => {
                            const [value, points] = entry.stats?.[key] ?? [
                                0, 0,
                            ];

                            return (
                                <li
                                    key={key}
                                    className="flex items-center justify-between py-1.5"
                                >
                                    <span className="text-neutral-600">
                                        {JORNADA_STAT_LABELS[key] ?? key}
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {value}
                                        </span>
                                        {points !== 0 && (
                                            <span
                                                className={cn(
                                                    'text-xs font-medium',
                                                    points > 0
                                                        ? 'text-emerald-600'
                                                        : 'text-rose-600',
                                                )}
                                            >
                                                {points > 0 ? '+' : ''}
                                                {points}
                                            </span>
                                        )}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p className="mt-6 text-center text-sm text-neutral-500">
                        Sin datos de esta jornada.
                    </p>
                )}
            </div>
        </div>
    );
}
