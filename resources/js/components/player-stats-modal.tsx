import { Shield, User, X } from 'lucide-react';
import { useEffect } from 'react';
import { EntityImage } from '@/components/entity-image';
import { PositionBadge } from '@/components/position-badge';
import { StatusBadge } from '@/components/status-badge';
import { formatCurrency } from '@/lib/format';
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
                </div>

                <dl className="mt-6 grid grid-cols-2 gap-4 text-center">
                    <div>
                        <dt className="text-xs text-neutral-500">
                            Puntos jornada
                        </dt>
                        <dd className="text-lg font-semibold">
                            {entry.points ?? '–'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-neutral-500">
                            Valor de mercado
                        </dt>
                        <dd className="text-lg font-semibold">
                            {formatCurrency(player.market_value)}
                        </dd>
                        {player.market_value_difference !== 0 && (
                            <p
                                className={cn(
                                    'text-xs font-medium',
                                    player.market_value_difference > 0
                                        ? 'text-emerald-600'
                                        : 'text-rose-600',
                                )}
                            >
                                {player.market_value_difference > 0 ? '+' : ''}
                                {formatCurrency(player.market_value_difference)}
                            </p>
                        )}
                    </div>
                </dl>
            </div>
        </div>
    );
}
