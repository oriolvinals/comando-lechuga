import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { formatCurrency } from '@/lib/format';
import { crestTintStyle } from '@/lib/season-team-colors';
import type { SeasonTeam } from '@/types/models';

interface HeroPanelProps {
    week: number;
    leader: SeasonTeam | undefined;
    totalTeams: number;
}

export function HeroPanel({ week, leader, totalTeams }: HeroPanelProps) {
    return (
        <div className="flex flex-col items-center gap-5 pt-9 pb-7 text-center sm:flex-row sm:items-center sm:gap-7 sm:text-left">
            <img
                src="/images/logo.png"
                alt="Comando Lechuga"
                className="h-56 w-56 shrink-0 object-contain sm:h-80 sm:w-80 lg:h-96 lg:w-96"
            />
            <div className="w-full flex-1">
                <p className="mb-3 font-mono text-[11px] font-bold tracking-[.25em] text-hq-lime">
                    ▸ PARTE DE OPERACIONES — JORNADA{' '}
                    {String(week).padStart(2, '0')}
                </p>
                <h1 className="mb-6 font-display text-2xl leading-[1.05] tracking-wide text-hq-paper uppercase [text-shadow:3px_3px_0_rgba(196,255,61,0.25)] sm:max-w-xl sm:text-3xl lg:text-4xl">
                    1 campeón.{' '}
                    <span className="text-hq-lime">
                        {Math.max(totalTeams - 1, 0)} excusas.
                    </span>
                </h1>

                {leader && (
                    <div className="hq-panel-cut mx-auto flex max-w-2xl items-center gap-3 border-l-4 border-l-hq-gold px-4 py-4 text-left sm:mx-0 sm:gap-5 sm:px-6 sm:py-5">
                        <EntityImage
                            src={leader.logo}
                            alt={leader.name}
                            fallback={Shield}
                            shape="square"
                            style={crestTintStyle(leader.primary_color)}
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
        </div>
    );
}
