import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency } from '@/lib/format';
import type { SeasonTeam } from '@/types/models';

interface HeroPanelProps {
    week: number;
    leader: SeasonTeam | undefined;
}

export function HeroPanel({ week, leader }: HeroPanelProps) {
    return (
        <div className="px-6 pt-9 pb-7 sm:px-8">
            <p className="mb-3 font-mono text-[11px] font-bold tracking-[.25em] text-hq-lime">
                ▸ PARTE DE OPERACIONES — JORNADA {String(week).padStart(2, '0')}
            </p>
            <h1 className="mb-1.5 font-display text-4xl leading-[0.92] tracking-wide text-hq-paper uppercase [text-shadow:3px_3px_0_rgba(196,255,61,0.25)] sm:text-6xl">
                Comando
                <br />
                <span className="text-hq-lime">Lechuga</span>
            </h1>
            <p className="mb-6 max-w-lg text-sm leading-relaxed text-hq-moss">
                Cuartel general de la liga. Operaciones de la jornada,
                clasificación, suministros del mercado y transmisiones del
                frente.
            </p>

            {leader && (
                <div className="hq-panel-cut flex max-w-xl items-center gap-5 border border-hq-border-strong bg-gradient-to-br from-hq-panel-alt to-hq-panel px-5 py-4">
                    <div className="hq-badge-cut flex h-14 w-14 shrink-0 items-center justify-center bg-hq-lime font-display text-2xl text-hq-ink">
                        ★
                    </div>
                    <div>
                        <p className="mb-1 font-mono text-[10px] tracking-widest text-hq-lime">
                            COMANDANTE AL MANDO
                        </p>
                        <div className="flex items-center gap-2">
                            <EntityImage
                                src={leader.logo}
                                alt={leader.name}
                                fallback={Shield}
                                shape="square"
                                className="h-6 w-6 bg-hq-border p-0.5"
                            />
                            <span className="text-lg font-extrabold text-hq-paper">
                                {leader.name}
                            </span>
                        </div>
                    </div>
                    <div className="ml-auto text-right">
                        <span className="font-display text-4xl text-hq-lime">
                            {leader.total_points}
                        </span>
                        <span className="ml-1.5 font-mono text-[9px] tracking-widest text-hq-moss">
                            PTS TOTALES
                        </span>
                        <p className="mt-0.5 font-mono text-[10px] text-hq-moss-dim">
                            {formatCurrency(leader.value)}
                        </p>
                    </div>
                </div>
            )}
        </div>
    );
}
