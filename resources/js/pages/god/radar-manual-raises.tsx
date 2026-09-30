import { router, useForm } from '@inertiajs/react';
import { Check, NotebookPen, Pencil, Plus, Trash2, X } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import {
    formatMatchDateTime,
    formatMillions,
    formatNumber,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { ManagerSquare, Segmented } from '@/pages/god/radar-helpers';
import { destroy, store, update } from '@/routes/god/clause-raises';
import type {
    RadarClause,
    RadarManager,
    RadarManualRaise,
} from '@/types/models';

type AmountMode = 'new_clause' | 'paid';

const FIELD_CLASS =
    'min-h-8 w-full min-w-0 border border-hq-border-strong bg-hq-panel px-2 font-mono text-xs text-hq-paper hover:border-hq-border-bright';

const ICON_BUTTON_CLASS =
    'flex size-8 cursor-pointer items-center justify-center border border-hq-border-strong text-hq-moss';

const EMPTY_FORM = {
    season_manager_id: '',
    player_id: '',
    captured_at: '',
    new_clause: '',
    paid: '',
    note: '',
};

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

/** «Subidas conocidas»: add, edit and delete the clause raises the user knows about; they override the inference. */
export function RadarManualRaises({
    entries,
    managers,
    clauses,
}: {
    entries: RadarManualRaise[];
    managers: RadarManager[];
    clauses: RadarClause[];
}) {
    const managersById = new Map(
        managers.map((manager) => [manager.id, manager]),
    );
    const [editing, setEditing] = useState<RadarManualRaise | null>(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState<number | null>(
        null,
    );
    const [amountMode, setAmountMode] = useState<AmountMode>('new_clause');
    const form = useForm(EMPTY_FORM);
    const amount =
        amountMode === 'new_clause' ? form.data.new_clause : form.data.paid;
    const selectedManagerId = Number(form.data.season_manager_id);

    const ownedPlayers = clauses
        .filter(
            (clause) =>
                !selectedManagerId || clause.owner_id === selectedManagerId,
        )
        .map((clause) => clause.player);
    const playerOptions = [
        ...ownedPlayers,
        ...(editing &&
        !ownedPlayers.some((player) => player.id === editing.player.id)
            ? [editing.player]
            : []),
    ].sort((a, b) => a.nickname.localeCompare(b.nickname, 'es'));

    const ownerOf = (playerId: number): number | undefined =>
        clauses.find((clause) => clause.player.id === playerId)?.owner_id;

    const changeManager = (managerId: string) => {
        form.setData((data) => ({
            ...data,
            season_manager_id: managerId,
            player_id:
                managerId &&
                data.player_id &&
                ownerOf(Number(data.player_id)) !== Number(managerId)
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
        setAmountMode('new_clause');
        form.setData(EMPTY_FORM);
        form.clearErrors();
    };

    const startEditing = (entry: RadarManualRaise) => {
        setEditing(entry);
        setConfirmingDeleteId(null);
        setAmountMode('new_clause');
        form.clearErrors();
        form.setData({
            season_manager_id: String(entry.manager_id),
            player_id: String(entry.player.id),
            captured_at: entry.captured_at.slice(0, 16),
            new_clause: String(entry.clause),
            paid: '',
            note: entry.note,
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            new_clause: amountMode === 'new_clause' ? data.new_clause : null,
            paid: amountMode === 'paid' ? data.paid : null,
        }));

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
        router.delete(destroy(entry.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setConfirmingDeleteId(null);

                if (editing?.id === entry.id) {
                    stopEditing();
                }
            },
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
                <Field
                    label="Jugador"
                    error={form.errors.player_id}
                    className="sm:col-span-2"
                >
                    <select
                        required
                        className={cn(FIELD_CLASS, 'cursor-pointer')}
                        value={form.data.player_id}
                        onChange={(event) => changePlayer(event.target.value)}
                    >
                        <option value="">—</option>
                        {playerOptions.map((player) => (
                            <option key={player.id} value={player.id}>
                                {player.nickname}
                            </option>
                        ))}
                    </select>
                </Field>
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
                    className="sm:order-last sm:col-span-2"
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
                    label={
                        amountMode === 'new_clause'
                            ? 'Nueva cláusula €'
                            : 'Pagado €'
                    }
                    error={form.errors.new_clause ?? form.errors.paid}
                    className="col-span-2 sm:col-span-3"
                >
                    <span className="flex gap-2">
                        <input
                            required
                            inputMode="numeric"
                            className={cn(FIELD_CLASS, 'tabular-nums')}
                            value={amount ? formatNumber(Number(amount)) : ''}
                            onChange={(event) =>
                                form.setData(
                                    amountMode,
                                    event.target.value.replace(/\D/g, ''),
                                )
                            }
                        />
                        <span className="shrink-0">
                            <Segmented<AmountMode>
                                label="Qué importe"
                                value={amountMode}
                                onChange={setAmountMode}
                                options={[
                                    { value: 'new_clause', label: 'Cláusula' },
                                    { value: 'paid', label: 'Pagado' },
                                ]}
                            />
                        </span>
                    </span>
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
                            {isConfirmingDelete ? (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => remove(entry)}
                                        className="inline-flex min-h-8 cursor-pointer items-center gap-1.5 border border-hq-neg px-2 font-mono text-[11px] font-bold text-hq-neg uppercase hover:bg-hq-neg hover:text-hq-ink"
                                    >
                                        <Trash2
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                        Borrar
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setConfirmingDeleteId(null)
                                        }
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
