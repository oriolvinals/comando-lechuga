import { User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import type {
    PlayerPosition,
    SeasonTeamLineupPlayerEntry,
} from '@/types/models';

const ROW_ORDER: PlayerPosition[] = [
    'striker',
    'midfield',
    'defender',
    'goalkeeper',
];

interface LineupPitchProps {
    players: SeasonTeamLineupPlayerEntry[];
    tacticalFormation: number[];
    onSelectPlayer: (entry: SeasonTeamLineupPlayerEntry) => void;
}

export function LineupPitch({
    players,
    tacticalFormation,
    onSelectPlayer,
}: LineupPitchProps) {
    const rows = ROW_ORDER.map((position) => ({
        position,
        entries: players.filter((entry) => entry.position === position),
    })).filter((row) => row.entries.length > 0);

    return (
        <div>
            {tacticalFormation.length > 0 && (
                <p className="mb-2 text-center text-xs font-medium text-neutral-500">
                    Formación 1-{tacticalFormation.join('-')}
                </p>
            )}

            <div className="relative flex flex-col justify-between gap-4 rounded-lg bg-gradient-to-b from-emerald-600 to-emerald-700 px-4 py-6">
                <div className="pointer-events-none absolute inset-x-0 top-1/2 border-t border-white/30" />
                <div className="pointer-events-none absolute top-1/2 left-1/2 h-20 w-20 -translate-x-1/2 -translate-y-1/2 rounded-full border border-white/30" />

                {rows.map((row) => (
                    <div
                        key={row.position}
                        className="relative flex flex-wrap justify-center gap-4"
                    >
                        {row.entries.map((entry) => (
                            <button
                                key={entry.id}
                                type="button"
                                onClick={() => onSelectPlayer(entry)}
                                className="flex w-16 flex-col items-center gap-1"
                            >
                                <span className="relative">
                                    <EntityImage
                                        src={entry.player.image}
                                        alt={entry.player.nickname}
                                        fallback={User}
                                        className="h-10 w-10 ring-2 ring-white"
                                    />
                                    <span className="absolute -right-1 -bottom-1 rounded-full bg-neutral-900 px-1.5 text-[10px] font-semibold text-white">
                                        {entry.points}
                                    </span>
                                </span>
                                <span className="w-full truncate text-center text-xs font-medium text-white drop-shadow">
                                    {entry.player.nickname}
                                </span>
                            </button>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}
