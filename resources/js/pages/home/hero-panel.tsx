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
                <div className="hq-panel-cut flex max-w-2xl items-center gap-5 border border-l-4 border-hq-border-strong border-l-hq-gold bg-gradient-to-br from-hq-panel-alt to-hq-panel px-6 py-5">
                    <EntityImage
                        src={leader.logo}
                        alt={leader.name}
                        fallback={Shield}
                        shape="square"
                        className="hq-crest-cut h-20 w-20 shrink-0 bg-hq-border p-2 text-hq-khaki"
                    />
                    <div className="flex-1">
                        <p className="mb-1 font-mono text-xs tracking-widest text-hq-gold">
                            COMANDANTE AL MANDO
                        </p>
                        <p className="text-2xl font-extrabold text-hq-paper">
                            {leader.name}
                        </p>
                        <p className="font-mono text-xs text-hq-moss-dim">
                            {formatCurrency(leader.value)}
                        </p>
                    </div>
                    <div className="text-right">
                        <span className="mb-1 inline-block rounded bg-hq-lime/10 px-2 py-1 font-mono text-[11px] text-hq-lime">
                            J{week} +{leader.live_points}
                        </span>
                        <div className="font-display text-5xl text-hq-paper">
                            {leader.total_points}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
