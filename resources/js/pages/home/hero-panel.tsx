import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency } from '@/lib/format';
import type { SeasonTeam } from '@/types/models';

interface HeroPanelProps {
    week: number;
    leader: SeasonTeam | undefined;
    totalTeams: number;
}

export function HeroPanel({ week, leader, totalTeams }: HeroPanelProps) {
    return (
        <div className="pt-9 pb-7">
            <p className="mb-3 font-mono text-[11px] font-bold tracking-[.25em] text-hq-lime">
                ▸ PARTE DE OPERACIONES — JORNADA {String(week).padStart(2, '0')}
            </p>
            <h1 className="mb-6 max-w-xl font-display text-3xl leading-[1.05] tracking-wide text-hq-paper uppercase [text-shadow:3px_3px_0_rgba(196,255,61,0.25)] sm:text-4xl">
                1 campeón.{' '}
                <span className="text-hq-lime">
                    {Math.max(totalTeams - 1, 0)} excusas.
                </span>
            </h1>

            {leader && (
                <div className="hq-panel-cut flex max-w-2xl items-center gap-6 border border-hq-border-strong bg-gradient-to-br from-hq-panel-alt to-hq-panel px-7 py-6">
                    <div className="hq-badge-cut flex h-20 w-20 shrink-0 items-center justify-center bg-hq-lime font-display text-4xl text-hq-ink">
                        ★
                    </div>
                    <div>
                        <p className="mb-1.5 font-mono text-xs tracking-widest text-hq-lime">
                            COMANDANTE AL MANDO
                        </p>
                        <div className="flex items-center gap-3">
                            <EntityImage
                                src={leader.logo}
                                alt={leader.name}
                                fallback={Shield}
                                shape="square"
                                className="h-9 w-9 bg-hq-border p-1"
                            />
                            <span className="text-2xl font-extrabold text-hq-paper">
                                {leader.name}
                            </span>
                        </div>
                    </div>
                    <div className="ml-auto text-right">
                        <span className="font-display text-5xl text-hq-lime">
                            {leader.total_points}
                        </span>
                        <span className="ml-1.5 font-mono text-[10px] tracking-widest text-hq-moss">
                            PTS TOTALES
                        </span>
                        <p className="mt-0.5 font-mono text-xs text-hq-moss-dim">
                            {formatCurrency(leader.value)}
                        </p>
                    </div>
                </div>
            )}
        </div>
    );
}
