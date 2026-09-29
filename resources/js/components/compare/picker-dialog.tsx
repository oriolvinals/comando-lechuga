import { Search, User, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { searchLeague, suggestPlayers } from '@/components/compare/derive';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import type {
    CompareManager,
    ComparedPlayer,
    LeagueCloudRow,
} from '@/types/models';

interface ComparePickerDialogProps {
    title: string;
    league: LeagueCloudRow[];
    managersById: Map<number, CompareManager>;
    excludeIds: number[];
    /** Suggestions are "same position, closest value" to this player. */
    base: ComparedPlayer | null;
    onPick: (id: number) => void;
    /** Escape, ×, backdrop or after a pick. */
    onClose: () => void;
}

/**
 * The centred picker (mock `dialog.pick`): native showModal() — focus trap,
 * Escape and backdrop click — with a fixed height so the list scrolls inside.
 */
export function ComparePickerDialog({
    title,
    league,
    managersById,
    excludeIds,
    base,
    onPick,
    onClose,
}: ComparePickerDialogProps) {
    const dialogRef = useRef<HTMLDialogElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const [query, setQuery] = useState('');
    const titleId = useId();
    const rows =
        query.trim() !== ''
            ? searchLeague(league, query, excludeIds, 30)
            : suggestPlayers(league, base, excludeIds);

    useEffect(() => {
        const dialog = dialogRef.current;

        if (dialog && !dialog.open) {
            dialog.showModal();
            inputRef.current?.focus();
        }
    }, []);

    return (
        <dialog
            ref={dialogRef}
            aria-labelledby={titleId}
            onClose={onClose}
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    dialogRef.current?.close();
                }
            }}
            className="m-auto h-[min(560px,calc(100dvh-48px))] w-[min(560px,calc(100%-32px))] max-w-none overflow-hidden border border-hq-border-bright bg-hq-ink p-0 text-hq-paper backdrop:bg-[rgba(4,5,3,0.74)] open:flex open:flex-col motion-safe:open:animate-[cmp-pick-in_0.22s_cubic-bezier(0.16,1,0.3,1)] max-[480px]:h-[min(560px,calc(100dvh-32px))] max-[480px]:w-[calc(100%-20px)]"
        >
            <header className="flex shrink-0 items-center gap-2 border-b border-hq-border px-4 py-2.5">
                <span id={titleId} className="hq-label">
                    {title}
                </span>
                <kbd
                    aria-hidden="true"
                    className="ml-auto border border-hq-border-strong px-[5px] py-0.5 font-mono text-[10px] text-hq-moss-dim max-[480px]:hidden"
                >
                    Esc
                </kbd>
                <button
                    type="button"
                    aria-label="Cerrar"
                    onClick={() => dialogRef.current?.close()}
                    className="flex size-11 cursor-pointer items-center justify-center border border-hq-border-strong text-hq-moss hover:text-hq-paper sm:size-8"
                >
                    <X aria-hidden="true" className="size-3.5" />
                </button>
            </header>
            <label className="flex h-12 shrink-0 items-center gap-2 border-b border-hq-border px-4 text-hq-moss focus-within:text-hq-lime">
                <Search aria-hidden="true" className="size-4" />
                <input
                    ref={inputRef}
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && rows[0]) {
                            event.preventDefault();
                            onPick(rows[0].id);
                        }
                    }}
                    placeholder="Buscar jugador o equipo…"
                    autoComplete="off"
                    aria-label="Buscar jugador"
                    className="min-w-0 flex-1 bg-transparent font-mono text-base text-hq-paper placeholder-hq-moss-dim outline-none md:text-[13px]"
                />
            </label>
            <div className="min-h-0 flex-1 overflow-y-auto">
                {query.trim() === '' && (
                    <p className="px-4 pt-3 pb-1 hq-label">
                        {base
                            ? `Mismo puesto y valor parecido a ${base.name}`
                            : 'Más puntos'}
                    </p>
                )}
                {rows.length === 0 ? (
                    <p className="p-4 font-mono text-xs text-hq-moss">
                        Ningún jugador coincide con «{query}».
                    </p>
                ) : (
                    rows.map((row) => {
                        const owner =
                            row.owner_id !== null
                                ? managersById.get(row.owner_id)
                                : undefined;

                        return (
                            <button
                                key={row.id}
                                type="button"
                                onClick={() => onPick(row.id)}
                                className="grid min-h-12 w-full cursor-pointer grid-cols-[32px_minmax(0,1fr)_auto_40px] items-center gap-2.5 border-b border-hq-border px-4 py-2 text-left hover:bg-hq-panel focus-visible:bg-hq-panel"
                            >
                                <EntityImage
                                    src={row.image}
                                    alt=""
                                    fallback={User}
                                    shape="square"
                                    className="size-8 rounded-none object-cover object-top"
                                />
                                <span className="min-w-0">
                                    <b className="block truncate text-sm font-extrabold text-hq-paper">
                                        {row.name}
                                    </b>
                                    <small className="block truncate font-mono text-[11px] text-hq-moss-dim">
                                        {row.team_short} ·{' '}
                                        {owner ? owner.name : 'libre'}
                                    </small>
                                </span>
                                <HqPositionTag position={row.position} />
                                <span className="text-right font-mono text-sm font-bold text-hq-lime tabular-nums">
                                    {row.points}
                                </span>
                            </button>
                        );
                    })
                )}
            </div>
        </dialog>
    );
}
