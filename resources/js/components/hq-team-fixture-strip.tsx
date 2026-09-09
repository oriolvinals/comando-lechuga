import { Link } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqScrollRow } from '@/components/hq-scroll-row';
import {
    opponentOf,
    resultFor,
    RESULT_LABEL,
    RESULT_STRIP_CLASSES,
} from '@/lib/team-fixture-result';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type { Fixture } from '@/types/models';

interface HqTeamFixtureStripProps {
    fixtures: Fixture[];
    teamId: number;
}

export function HqTeamFixtureStrip({
    fixtures,
    teamId,
}: HqTeamFixtureStripProps) {
    return (
        <HqScrollRow contentClassName="px-1 py-1 pb-3">
            {fixtures.map((fixture) => {
                const opponent = opponentOf(fixture, teamId);
                const result = resultFor(fixture, teamId);

                return (
                    <Link
                        key={fixture.id}
                        href={fixturesShow(fixture.id).url}
                        className={cn(
                            'relative flex h-14 w-14 shrink-0 flex-col items-center justify-center border-2 font-mono',
                            result
                                ? RESULT_STRIP_CLASSES[result]
                                : 'border-dashed border-hq-border-strong text-hq-moss-dim',
                        )}
                    >
                        <span className="text-[10px] font-bold opacity-80">
                            J{fixture.week_number}
                        </span>
                        <span className="font-display text-lg leading-none">
                            {result === 'live'
                                ? '●'
                                : result
                                  ? RESULT_LABEL[result]
                                  : '—'}
                        </span>
                        <span
                            title={opponent.main_name}
                            className="absolute -bottom-2 left-1/2 h-3.5 w-3.5 -translate-x-1/2"
                        >
                            <EntityImage
                                src={opponent.logo}
                                alt={opponent.main_name}
                                fallback={Shield}
                                shape="square"
                                className="h-full w-full object-contain drop-shadow-[0_1px_2px_rgba(0,0,0,0.9)]"
                            />
                        </span>
                    </Link>
                );
            })}
        </HqScrollRow>
    );
}
