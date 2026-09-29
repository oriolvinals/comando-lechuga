import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Link2, Plus } from 'lucide-react';
import type { ReactElement } from 'react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { HqChartTooltip } from '@/components/compare/chart-tooltip';
import { CompareContext } from '@/components/compare/compare-context';
import type { CompareContextValue } from '@/components/compare/compare-context';
import { derivePlayer } from '@/components/compare/derive';
import { ComparePickerDialog } from '@/components/compare/picker-dialog';
import { useComparison } from '@/components/compare/use-comparison';
import { CompareVerdict } from '@/components/compare/verdict';
import { CompareViewA } from '@/components/compare/view-a';
import { CompareViewB } from '@/components/compare/view-b';
import { CompareViewC } from '@/components/compare/view-c';
import { CompareViewSwitch } from '@/components/compare/view-switch';
import AppLayout from '@/layouts/app-layout';
import { COMPARE_MAX } from '@/lib/compare-selection';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { index as playersIndex } from '@/routes/players';
import type {
    CompareManager,
    CompareView,
    ComparedPlayer,
    LeagueCloudRow,
} from '@/types/models';

interface PlayersCompareProps {
    currentWeek: number;
    totalWeeks: number;
    view: CompareView;
    ids: number[];
    players: ComparedPlayer[];
    league: LeagueCloudRow[];
    managers: CompareManager[];
    [key: string]: unknown;
}

interface PickerTarget {
    replaceIndex: number | null;
    opener: HTMLElement | null;
}

type CopyState = 'idle' | 'done' | 'failed';

export default function PlayersCompare({
    currentWeek,
    totalWeeks,
    view,
    ids,
    players,
    league,
    managers,
}: PlayersCompareProps) {
    const { godMode } = usePage().props;
    const now = useNow(60_000);
    const comparison = useComparison({ ids, view, players });
    const [picker, setPicker] = useState<PickerTarget | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const [copyState, setCopyState] = useState<CopyState>('idle');
    const picked = useRef(false);
    const copyResetTimer = useRef<number | undefined>(undefined);
    const announceTimer = useRef<number | undefined>(undefined);
    const derived = useMemo(
        () => players.map((player) => derivePlayer(player, currentWeek, now)),
        [players, currentWeek, now],
    );
    const managersById = useMemo(
        () => new Map(managers.map((manager) => [manager.id, manager])),
        [managers],
    );

    const openPicker = useCallback((replaceIndex: number | null) => {
        picked.current = false;
        setPicker({
            replaceIndex,
            opener:
                document.activeElement instanceof HTMLElement
                    ? document.activeElement
                    : null,
        });
    }, []);
    const pickerOpen = picker !== null;
    const canAdd = ids.length < COMPARE_MAX;

    useEffect(
        () => () => {
            window.clearTimeout(copyResetTimer.current);
            window.clearTimeout(announceTimer.current);
        },
        [],
    );

    const context: CompareContextValue = {
        players,
        derived,
        league,
        managersById,
        currentWeek,
        totalWeeks,
        now,
        add: comparison.add,
        remove: comparison.remove,
        openPicker,
        announce: setAnnouncement,
    };

    // "/" opens the picker here (the shell's player search would leave the page).
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target;

            if (
                event.key !== '/' ||
                pickerOpen ||
                !canAdd ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey ||
                (target instanceof HTMLElement &&
                    (target.isContentEditable ||
                        ['INPUT', 'SELECT', 'TEXTAREA'].includes(
                            target.tagName,
                        )))
            ) {
                return;
            }

            event.preventDefault();
            openPicker(null);
        };

        // Capture phase: runs before the shell's own "/" (player search), which skips prevented events.
        window.addEventListener('keydown', onKeyDown, true);

        return () => window.removeEventListener('keydown', onKeyDown, true);
    }, [pickerOpen, canAdd, openPicker]);

    const copyLink = () => {
        window.clearTimeout(copyResetTimer.current);

        const done = (state: CopyState) => {
            setCopyState(state);
            // Emptied first so a repeated "Enlace copiado" is announced again.
            setAnnouncement('');
            window.clearTimeout(announceTimer.current);
            announceTimer.current = window.setTimeout(
                () =>
                    setAnnouncement(
                        state === 'done'
                            ? 'Enlace copiado'
                            : 'No se pudo copiar',
                    ),
                100,
            );
            window.clearTimeout(copyResetTimer.current);
            copyResetTimer.current = window.setTimeout(
                () => setCopyState('idle'),
                2200,
            );
        };

        try {
            navigator.clipboard.writeText(window.location.href).then(
                () => done('done'),
                () => done('failed'),
            );
        } catch {
            done('failed');
        }
    };

    const copyLabel =
        copyState === 'done'
            ? 'Enlace copiado'
            : copyState === 'failed'
              ? 'No se pudo copiar'
              : 'Copiar enlace';

    return (
        <CompareContext.Provider value={context}>
            <HqChartTooltip>
                <div className="flex-1">
                    <Head title="Comparador" />
                    <p className="sr-only" aria-live="polite">
                        {announcement}
                    </p>

                    <div className="flex flex-wrap items-end gap-x-6 gap-y-3.5 border-b border-hq-border-strong px-3.5 pt-4 pb-3 sm:px-5 sm:pt-[22px] sm:pb-4">
                        <div className="min-w-0">
                            <Link
                                href={playersIndex().url}
                                className="mb-3 inline-flex cursor-pointer items-center gap-1.5 font-mono text-[11px] font-bold tracking-[0.1em] text-hq-lime uppercase hover:text-hq-paper"
                            >
                                <ArrowLeft
                                    aria-hidden="true"
                                    className="size-3"
                                />
                                Jugadores
                            </Link>
                            <h1 className="font-display text-[26px] leading-[0.95] text-hq-paper uppercase sm:text-[34px]">
                                Comparador
                            </h1>
                        </div>
                        <div className="ml-auto flex w-full items-end gap-2.5 sm:w-auto">
                            <CompareViewSwitch
                                view={comparison.view}
                                onChange={comparison.setView}
                            />
                            <button
                                type="button"
                                onClick={copyLink}
                                aria-label={copyLabel}
                                className={cn(
                                    'inline-flex h-10 shrink-0 cursor-pointer items-center justify-center gap-[7px] border border-hq-border-strong px-3 font-mono text-[11px] font-bold tracking-[0.06em] whitespace-nowrap text-hq-moss uppercase transition-colors hover:border-hq-lime hover:text-hq-lime max-sm:w-11 max-sm:px-0',
                                    copyState === 'done' &&
                                        'border-hq-lime text-hq-lime',
                                )}
                            >
                                {copyState === 'done' ? (
                                    <Check
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                ) : (
                                    <Link2
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                )}
                                <span className="max-sm:sr-only">
                                    {copyLabel}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div
                        id="cmp-panel"
                        role="tabpanel"
                        aria-labelledby={`cmp-tab-${comparison.view}`}
                    >
                        {players.length < 2 ? (
                            <div className="flex flex-col items-start gap-3.5 px-4 pt-9 pb-12 font-mono text-[13px] leading-normal text-hq-moss">
                                <p className="m-0">
                                    {players.length === 0
                                        ? 'No hay jugadores en el comparador. Elige 2 o 3 en la lista.'
                                        : `Solo está ${players[0].name}. Añade otro jugador para comparar.`}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {players.length === 1 && (
                                        <button
                                            type="button"
                                            onClick={() => openPicker(null)}
                                            className="inline-flex h-11 cursor-pointer items-center gap-2 bg-hq-lime px-3.5 font-mono text-[11.5px] font-bold tracking-[0.06em] text-hq-ink uppercase sm:h-9"
                                        >
                                            <Plus
                                                aria-hidden="true"
                                                className="size-3.5"
                                            />
                                            Añadir jugador
                                        </button>
                                    )}
                                    <Link
                                        href={playersIndex().url}
                                        className="inline-flex h-11 cursor-pointer items-center border border-hq-border-strong px-3.5 font-mono text-[11.5px] font-bold tracking-[0.06em] text-hq-moss uppercase hover:border-hq-lime hover:text-hq-lime sm:h-9"
                                    >
                                        Elegir en la lista
                                    </Link>
                                </div>
                            </div>
                        ) : (
                            <>
                                {godMode && <CompareVerdict />}
                                {comparison.view === 'a' && <CompareViewA />}
                                {comparison.view === 'b' && <CompareViewB />}
                                {comparison.view === 'c' && <CompareViewC />}
                            </>
                        )}
                    </div>

                    {picker && (
                        <ComparePickerDialog
                            title={
                                picker.replaceIndex === null
                                    ? 'Añadir jugador'
                                    : `Cambiar a ${players[picker.replaceIndex].name}`
                            }
                            league={league}
                            managersById={managersById}
                            excludeIds={ids}
                            base={players[picker.replaceIndex ?? 0] ?? null}
                            onPick={(id) => {
                                picked.current = true;
                                const slot = picker.replaceIndex ?? ids.length;
                                const focus = `[data-replace="${slot}"]`;

                                if (picker.replaceIndex === null) {
                                    comparison.add(id, focus);
                                } else {
                                    comparison.replace(
                                        picker.replaceIndex,
                                        id,
                                        focus,
                                    );
                                }

                                setPicker(null);
                            }}
                            onClose={() => {
                                if (
                                    !picked.current &&
                                    picker.opener?.isConnected
                                ) {
                                    picker.opener.focus({
                                        preventScroll: true,
                                    });
                                }

                                setPicker(null);
                            }}
                        />
                    )}
                </div>
            </HqChartTooltip>
        </CompareContext.Provider>
    );
}

PlayersCompare.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
