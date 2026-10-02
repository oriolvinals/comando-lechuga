import { router, useForm } from '@inertiajs/react';
import {
    Check,
    NotebookPen,
    Pencil,
    Plus,
    Shield,
    Trash2,
    User,
    X,
} from 'lucide-react';
import type { FormEvent, KeyboardEvent, ReactNode } from 'react';
import { useState } from 'react';
import { fold } from '@/components/compare/derive';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import {
    formatMatchDateTime,
    formatMillions,
    formatNumber,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { ManagerSquare } from '@/pages/god/radar-helpers';
import { destroy, store, update } from '@/routes/god/clause-raises';
import type {
    RadarManager,
    RadarManualRaise,
    RadarRaiseCandidate,
} from '@/types/models';

type PickerPlayer = RadarRaiseCandidate['player'];

/**
 * The player picker's options: the players the manager owned this season
 * (every manager's when none is picked), split into the ones still in a
 * squad and the ones gone. The row being edited always stays listed.
 */
function pickerGroups(
    candidates: RadarRaiseCandidate[],
    managerId: number,
    editingPlayer: PickerPlayer | undefined,
): { label: string; players: PickerPlayer[] }[] {
    const current = new Map<number, PickerPlayer>();
    const past = new Map<number, PickerPlayer>();

    for (const candidate of candidates) {
        if (managerId && candidate.manager_id !== managerId) {
            continue;
        }

        if (candidate.current) {
            current.set(candidate.player.id, candidate.player);
            past.delete(candidate.player.id);
        } else if (!current.has(candidate.player.id)) {
            past.set(candidate.player.id, candidate.player);
        }
    }

    if (
        editingPlayer &&
        !current.has(editingPlayer.id) &&
        !past.has(editingPlayer.id)
    ) {
        past.set(editingPlayer.id, editingPlayer);
    }

    const sorted = (players: Map<number, PickerPlayer>): PickerPlayer[] =>
        [...players.values()].sort((a, b) =>
            a.nickname.localeCompare(b.nickname, 'es'),
        );

    return [
        { label: 'En plantilla', players: sorted(current) },
        { label: 'Antes', players: sorted(past) },
    ];
}

const FIELD_CLASS =
    'min-h-8 w-full min-w-0 border border-hq-border-strong bg-hq-panel px-2 font-mono text-xs text-hq-paper hover:border-hq-border-bright';

const ICON_BUTTON_CLASS =
    'flex size-8 cursor-pointer items-center justify-center border border-hq-border-strong text-hq-moss';

const EMPTY_FORM = {
    season_manager_id: '',
    player_id: '',
    captured_at: '',
    previous_clause: '',
    new_clause: '',
    note: '',
};

function PlayerPhoto({ player }: { player: PickerPlayer }) {
    return (
        <EntityImage
            src={player.image}
            alt=""
            fallback={User}
            shape="square"
            className="size-6 shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt object-cover object-top text-hq-moss-dim"
        />
    );
}

function TeamCrest({ player }: { player: PickerPlayer }) {
    return (
        <EntityImage
            src={player.team_logo}
            alt=""
            fallback={Shield}
            shape="square"
            className="size-4 shrink-0 rounded-none bg-transparent"
        />
    );
}

/** Text-filterable (accent-insensitive) player picker keeping the groups; arrows move, Enter picks, Escape closes. */
function PlayerPicker({
    groups,
    value,
    onChange,
    error,
    className,
}: {
    groups: { label: string; players: PickerPlayer[] }[];
    value: string;
    onChange: (playerId: string) => void;
    error?: string;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const needle = fold(query.trim());
    const filtered = groups
        .map((group) => ({
            ...group,
            players: group.players.filter((player) =>
                fold(player.nickname).includes(needle),
            ),
        }))
        .filter((group) => group.players.length > 0);
    const options = filtered.flatMap((group) => group.players);
    const selected = groups
        .flatMap((group) => group.players)
        .find((player) => String(player.id) === value);

    const pick = (player: PickerPlayer) => {
        onChange(String(player.id));
        setOpen(false);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            setOpen(true);
            setActive((index) =>
                options.length === 0
                    ? 0
                    : (index +
                          (event.key === 'ArrowDown' ? 1 : -1) +
                          options.length) %
                      options.length,
            );
        } else if (event.key === 'Enter' && open && options[active]) {
            event.preventDefault();
            pick(options[active]);
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    return (
        <div className={cn('relative flex min-w-0 flex-col gap-1', className)}>
            <label className="flex min-w-0 flex-col gap-1">
                <span className="hq-label">Jugador</span>
                <span className="relative flex min-w-0 items-center">
                    {selected && !open && (
                        <span className="pointer-events-none absolute left-1 flex items-center">
                            <PlayerPhoto player={selected} />
                        </span>
                    )}
                    {selected && !open && (
                        <span className="pointer-events-none absolute right-2 flex items-center gap-1 font-mono text-[11px] text-hq-moss-dim">
                            <TeamCrest player={selected} />
                            {selected.team_short_name}
                        </span>
                    )}
                    <input
                        role="combobox"
                        aria-expanded={open}
                        aria-controls="radar-player-options"
                        aria-autocomplete="list"
                        autoComplete="off"
                        placeholder="Buscar jugador"
                        className={cn(
                            FIELD_CLASS,
                            'cursor-text',
                            selected && !open && 'pr-16 pl-9',
                        )}
                        value={open ? query : (selected?.nickname ?? '')}
                        onFocus={() => {
                            setQuery('');
                            setActive(0);
                            setOpen(true);
                        }}
                        onBlur={() => setOpen(false)}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setActive(0);
                            setOpen(true);
                        }}
                        onKeyDown={onKeyDown}
                    />
                </span>
            </label>
            {open && (
                <div
                    id="radar-player-options"
                    role="listbox"
                    className="absolute top-full right-0 left-0 z-20 max-h-60 overflow-y-auto border border-hq-border-bright bg-hq-panel"
                >
                    {options.length === 0 && (
                        <span className="block px-2 py-2 font-mono text-xs text-hq-moss-dim">
                            Sin resultados
                        </span>
                    )}
                    {filtered.map((group) => (
                        <div
                            key={group.label}
                            role="group"
                            aria-label={group.label}
                        >
                            <span className="block px-2 pt-1.5 pb-0.5 hq-label">
                                {group.label}
                            </span>
                            {group.players.map((player) => (
                                <div
                                    key={player.id}
                                    role="option"
                                    aria-selected={String(player.id) === value}
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        pick(player);
                                    }}
                                    className={cn(
                                        'cursor-pointer px-2 py-2 font-mono text-xs text-hq-paper hover:bg-hq-border',
                                        options[active]?.id === player.id &&
                                            'bg-hq-border',
                                    )}
                                >
                                    <span className="flex min-w-0 items-center gap-2">
                                        <PlayerPhoto player={player} />
                                        <span className="min-w-0 flex-1 truncate font-sans text-[13px] font-bold">
                                            {player.nickname}
                                        </span>
                                        {player.position && (
                                            <HqPositionTag
                                                position={player.position}
                                            />
                                        )}
                                        <span className="flex shrink-0 items-center gap-1 text-[11px] text-hq-moss-dim">
                                            <TeamCrest player={player} />
                                            {player.team_short_name}
                                        </span>
                                    </span>
                                </div>
                            ))}
                        </div>
                    ))}
                </div>
            )}
            {error && (
                <span
                    role="alert"
                    className="font-mono text-[11px] text-hq-neg"
                >
                    {error}
                </span>
            )}
        </div>
    );
}

/** A labelled form field with its validation error under it. */
function Field({
    label,
    error,
    className,
    children,
}: {
    label: string;
    error?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <label className={cn('flex min-w-0 flex-col gap-1', className)}>
            <span className="hq-label">{label}</span>
            {children}
            {error && (
                <span
                    role="alert"
                    className="font-mono text-[11px] text-hq-neg"
                >
                    {error}
                </span>
            )}
        </label>
    );
}

/** «Subidas conocidas»: the clause raises the user entered (add, edit, delete; they override the inference) and, read-only, the ones the sync caught. */
export function RadarManualRaises({
    entries,
    managers,
    candidates,
}: {
    entries: RadarManualRaise[];
    managers: RadarManager[];
    candidates: RadarRaiseCandidate[];
}) {
    const managersById = new Map(
        managers.map((manager) => [manager.id, manager]),
    );
    const [editing, setEditing] = useState<RadarManualRaise | null>(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState<number | null>(
        null,
    );
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [failedDeleteId, setFailedDeleteId] = useState<number | null>(null);
    const form = useForm(EMPTY_FORM);
    const selectedManagerId = Number(form.data.season_manager_id);

    const playerGroups = pickerGroups(
        candidates,
        selectedManagerId,
        editing?.player,
    );

    /** The manager to fill in for a player: his current owner, else his only past one. */
    const ownerOf = (playerId: number): number | undefined => {
        const owners = candidates.filter(
            (candidate) => candidate.player.id === playerId,
        );
        const current = owners.find((candidate) => candidate.current);

        if (current) {
            return current.manager_id;
        }

        return owners.length === 1 ? owners[0].manager_id : undefined;
    };

    const hasOwned = (managerId: number, playerId: number): boolean =>
        candidates.some(
            (candidate) =>
                candidate.manager_id === managerId &&
                candidate.player.id === playerId,
        );

    const changeManager = (managerId: string) => {
        form.setData((data) => ({
            ...data,
            season_manager_id: managerId,
            player_id:
                managerId &&
                data.player_id &&
                !hasOwned(Number(managerId), Number(data.player_id))
                    ? ''
                    : data.player_id,
        }));
    };

    const changePlayer = (playerId: string) => {
        const ownerId = playerId ? ownerOf(Number(playerId)) : undefined;

        form.setData((data) => ({
            ...data,
            player_id: playerId,
            season_manager_id:
                data.season_manager_id || ownerId === undefined
                    ? data.season_manager_id
                    : String(ownerId),
        }));
    };

    const stopEditing = () => {
        setEditing(null);
        form.setData(EMPTY_FORM);
        form.clearErrors();
    };

    const startEditing = (entry: RadarManualRaise) => {
        setEditing(entry);
        setConfirmingDeleteId(null);
        form.clearErrors();
        form.setData({
            season_manager_id: String(entry.manager_id),
            player_id: String(entry.player.id),
            captured_at: entry.captured_at.slice(0, 16),
            previous_clause: String(entry.clause - entry.raise),
            new_clause: String(entry.clause),
            note: entry.note,
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                stopEditing();
            },
        };

        if (editing) {
            form.submit(update(editing.id), options);
        } else {
            form.submit(store(), options);
        }
    };

    const remove = (entry: RadarManualRaise) => {
        const fail = () => {
            setFailedDeleteId(entry.id);

            return false;
        };

        setFailedDeleteId(null);
        router.delete(destroy(entry.id).url, {
            preserveScroll: true,
            onStart: () => setDeletingId(entry.id),
            onFinish: () => setDeletingId(null),
            onSuccess: () => {
                setConfirmingDeleteId(null);

                if (editing?.id === entry.id) {
                    stopEditing();
                }
            },
            onError: () => {
                fail();
            },
            onHttpException: fail,
            onNetworkError: fail,
        });
    };

    return (
        <section
            aria-labelledby="radar-manual"
            className="border-t border-hq-border-strong"
        >
            <h2
                id="radar-manual"
                className="flex items-center gap-1.5 px-3.5 pt-3 pb-2 text-[15px] font-black uppercase"
            >
                <NotebookPen
                    aria-hidden="true"
                    className="size-4 text-hq-moss"
                />
                Subidas conocidas
                <small className="font-mono text-xs font-semibold text-hq-moss-dim normal-case">
                    {entries.length}
                </small>
            </h2>

            <form
                onSubmit={submit}
                className="grid grid-cols-2 items-start gap-2 px-3.5 pb-3 sm:grid-cols-6"
            >
                <Field
                    label="Mánager"
                    error={form.errors.season_manager_id}
                    className="sm:col-span-2"
                >
                    <select
                        required
                        className={cn(FIELD_CLASS, 'cursor-pointer')}
                        value={form.data.season_manager_id}
                        onChange={(event) => changeManager(event.target.value)}
                    >
                        <option value="">—</option>
                        {managers.map((manager) => (
                            <option key={manager.id} value={manager.id}>
                                {manager.name}
                            </option>
                        ))}
                    </select>
                </Field>
                <PlayerPicker
                    groups={playerGroups}
                    value={form.data.player_id}
                    onChange={changePlayer}
                    error={form.errors.player_id}
                    className="sm:col-span-2"
                />
                <Field
                    label="Cuándo"
                    error={form.errors.captured_at}
                    className="sm:col-span-2"
                >
                    <input
                        required
                        type="datetime-local"
                        className={cn(FIELD_CLASS, 'cursor-pointer')}
                        value={form.data.captured_at}
                        onChange={(event) =>
                            form.setData('captured_at', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Nota"
                    error={form.errors.note}
                    className="sm:order-last sm:col-span-5"
                >
                    <input
                        className={FIELD_CLASS}
                        maxLength={255}
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Cláusula anterior €"
                    error={form.errors.previous_clause}
                    className="sm:col-span-3"
                >
                    <input
                        inputMode="numeric"
                        placeholder="Automática"
                        className={cn(FIELD_CLASS, 'tabular-nums')}
                        value={
                            form.data.previous_clause
                                ? formatNumber(
                                      Number(form.data.previous_clause),
                                  )
                                : ''
                        }
                        onChange={(event) =>
                            form.setData(
                                'previous_clause',
                                event.target.value.replace(/\D/g, ''),
                            )
                        }
                    />
                </Field>
                <Field
                    label="Nueva cláusula €"
                    error={form.errors.new_clause}
                    className="sm:col-span-3"
                >
                    <input
                        required
                        inputMode="numeric"
                        className={cn(FIELD_CLASS, 'tabular-nums')}
                        value={
                            form.data.new_clause
                                ? formatNumber(Number(form.data.new_clause))
                                : ''
                        }
                        onChange={(event) =>
                            form.setData(
                                'new_clause',
                                event.target.value.replace(/\D/g, ''),
                            )
                        }
                    />
                </Field>
                <div className="col-span-2 flex gap-2 sm:order-last sm:col-span-1 sm:pt-[18px]">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="inline-flex min-h-8 flex-1 cursor-pointer items-center justify-center gap-1.5 border border-hq-lime/50 px-3 font-mono text-[11.5px] font-bold text-hq-lime uppercase hover:bg-hq-lime hover:text-hq-ink disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {editing ? (
                            <Check aria-hidden="true" className="size-3" />
                        ) : (
                            <Plus aria-hidden="true" className="size-3" />
                        )}
                        {editing ? 'Guardar' : 'Añadir'}
                    </button>
                    {editing && (
                        <button
                            type="button"
                            onClick={stopEditing}
                            aria-label="Cancelar edición"
                            title="Cancelar edición"
                            className={cn(
                                ICON_BUTTON_CLASS,
                                'hover:text-hq-paper',
                            )}
                        >
                            <X aria-hidden="true" className="size-3.5" />
                        </button>
                    )}
                </div>
            </form>

            {entries.map((entry) => {
                const manager = managersById.get(entry.manager_id);
                const isConfirmingDelete = confirmingDeleteId === entry.id;

                return (
                    <div
                        key={entry.id}
                        className={cn(
                            'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0.5 border-t border-hq-border px-3.5 py-1.5 sm:grid-cols-[minmax(0,1fr)_auto_auto]',
                            editing?.id === entry.id &&
                                'bg-hq-panel shadow-[inset_2px_0_0_var(--color-hq-lime)]',
                        )}
                    >
                        <span className="min-w-0">
                            <b className="block truncate text-[13px] font-extrabold">
                                {entry.player.nickname}
                            </b>
                            <small className="flex min-w-0 items-center gap-1.5 font-mono text-[11px] text-hq-moss-dim">
                                {manager && <ManagerSquare manager={manager} />}
                                <span className="truncate">
                                    {manager?.name} ·{' '}
                                    {formatMatchDateTime(entry.captured_at)}
                                    {entry.note && ` · ${entry.note}`}
                                </span>
                            </small>
                            {failedDeleteId === entry.id && (
                                <span
                                    role="alert"
                                    className="block font-mono text-[11px] text-hq-neg"
                                >
                                    No se ha podido borrar. Vuelve a probar.
                                </span>
                            )}
                        </span>
                        <span className="col-start-1 row-start-2 flex flex-wrap gap-x-3 font-mono text-xs tabular-nums sm:col-start-2 sm:row-start-1 sm:flex-col sm:items-end">
                            <span className="text-hq-paper">
                                → {formatMillions(entry.clause)}
                            </span>
                            <span className="text-hq-khaki">
                                +{formatMillions(entry.raise)} · coste{' '}
                                {formatMillions(entry.cost)}
                            </span>
                        </span>
                        <span className="col-start-2 row-span-2 row-start-1 flex gap-1 sm:col-start-3 sm:row-span-1">
                            {entry.source === 'sync' ? (
                                <span
                                    title="Detectada por la sincronización: no se puede editar"
                                    className="inline-flex min-h-8 min-w-[68px] items-center justify-center border border-dashed border-hq-border-strong px-2 font-mono text-[11px] font-bold tracking-[0.08em] text-hq-moss-dim uppercase"
                                >
                                    Auto
                                </span>
                            ) : isConfirmingDelete ? (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => remove(entry)}
                                        disabled={deletingId === entry.id}
                                        className="inline-flex min-h-8 cursor-pointer items-center gap-1.5 border border-hq-neg px-2 font-mono text-[11px] font-bold text-hq-neg uppercase hover:bg-hq-neg hover:text-hq-ink disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <Trash2
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                        Borrar
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setConfirmingDeleteId(null);
                                            setFailedDeleteId(null);
                                        }}
                                        aria-label="No borrar"
                                        title="No borrar"
                                        className={cn(
                                            ICON_BUTTON_CLASS,
                                            'hover:text-hq-paper',
                                        )}
                                    >
                                        <X
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                    </button>
                                </>
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => startEditing(entry)}
                                        aria-label={`Editar ${entry.player.nickname}`}
                                        title="Editar"
                                        className={cn(
                                            ICON_BUTTON_CLASS,
                                            'hover:text-hq-paper',
                                        )}
                                    >
                                        <Pencil
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setConfirmingDeleteId(entry.id)
                                        }
                                        aria-label={`Borrar ${entry.player.nickname}`}
                                        title="Borrar"
                                        className={cn(
                                            ICON_BUTTON_CLASS,
                                            'hover:border-hq-neg hover:text-hq-neg',
                                        )}
                                    >
                                        <Trash2
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                    </button>
                                </>
                            )}
                        </span>
                    </div>
                );
            })}
        </section>
    );
}
