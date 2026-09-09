import { Link } from '@inertiajs/react';
import { HqScrollRow } from '@/components/hq-scroll-row';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import type { Fixture } from '@/types/models';

interface HqTeamFixtureStripProps {
    fixtures: Fixture[];
    teamId: number;
}

type Result = 'win' | 'draw' | 'loss' | null;

function resultFor(fixture: Fixture, teamId: number): Result {
    if (
        fixture.state !== 'finished' ||
        fixture.local_score === null ||
        fixture.guest_score === null
    ) {
        return null;
    }

    const isLocal = fixture.local_team.id === teamId;
    const ownScore = isLocal ? fixture.local_score : fixture.guest_score;
    const rivalScore = isLocal ? fixture.guest_score : fixture.local_score;

    if (ownScore > rivalScore) {
        return 'win';
    }

    if (ownScore === rivalScore) {
        return 'draw';
    }

    return 'loss';
}

const RESULT_LABEL: Record<'win' | 'draw' | 'loss', string> = {
    win: 'V',
    draw: 'E',
    loss: 'D',
};

const RESULT_CLASSES: Record<'win' | 'draw' | 'loss', string> = {
    win: 'border-hq-lime text-hq-lime',
    draw: 'border-hq-moss text-hq-moss',
    loss: 'border-hq-live text-hq-live',
};

export function HqTeamFixtureStrip({
    fixtures,
    teamId,
}: HqTeamFixtureStripProps) {
    return (
        <HqScrollRow contentClassName="px-1 py-1 pb-3">
            {fixtures.map((fixture) => {
                const opponent =
                    fixture.local_team.id === teamId
                        ? fixture.guest_team
                        : fixture.local_team;
                const result = resultFor(fixture, teamId);

                return (
                    <Link
                        key={fixture.id}
                        href={fixturesShow(fixture.id).url}
                        className={cn(
                            'relative flex h-14 w-14 shrink-0 flex-col items-center justify-center border-2 font-mono',
                            result
                                ? RESULT_CLASSES[result]
                                : 'border-dashed border-hq-border-strong text-hq-moss-dim',
                        )}
                    >
                        <span className="text-[10px] font-bold opacity-80">
                            J{fixture.week_number}
                        </span>
                        <span className="font-display text-lg leading-none">
                            {result ? RESULT_LABEL[result] : '—'}
                        </span>
                        <img
                            src={opponent.logo}
                            alt={opponent.main_name}
                            title={opponent.main_name}
                            className="absolute -bottom-2 left-1/2 h-3.5 w-3.5 -translate-x-1/2 object-contain drop-shadow-[0_1px_2px_rgba(0,0,0,0.9)]"
                        />
                    </Link>
                );
            })}
        </HqScrollRow>
    );
}
