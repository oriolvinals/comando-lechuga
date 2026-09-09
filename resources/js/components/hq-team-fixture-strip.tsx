import { Shield } from 'lucide-react';
import { useLayoutEffect, useRef } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqScrollRow } from '@/components/hq-scroll-row';
import { opponentOf, resultFor, RESULT_STRIP_CLASSES } from '@/lib/team-fixture-result';
import { cn } from '@/lib/utils';
import type { Fixture } from '@/types/models';

interface HqTeamFixtureStripProps {
    fixtures: Fixture[];
    teamId: number;
    selectedWeek: number;
    onSelectWeek: (week: number) => void;
}

/**
 * The team ficha's single jornada selector — every fixture this season
 * (played and upcoming), each tile showing the actual score (not a V/E/D
 * letter) so this strip also works as the season's results-at-a-glance
 * calendar. Selecting a tile drives the pitch above it; it no longer
 * navigates to the fixture's own page (this used to be a plain link list
 * separate from a week picker above the pitch — the two were merged).
 */
export function HqTeamFixtureStrip({
    fixtures,
    teamId,
    selectedWeek,
    onSelectWeek,
}: HqTeamFixtureStripProps) {
    const selectedRef = useRef<HTMLButtonElement>(null);
    const isFirstRender = useRef(true);

    useLayoutEffect(() => {
        const button = selectedRef.current;
        const scroller = button?.parentElement;

        if (!button || !scroller) {
            return;
        }

        scroller.scrollTo({
            left:
                button.offsetLeft -
                scroller.clientWidth / 2 +
                button.offsetWidth / 2,
            behavior: isFirstRender.current ? 'instant' : 'smooth',
        });
        isFirstRender.current = false;
    }, [selectedWeek]);

    return (
        <HqScrollRow contentClassName="px-1 py-1 pb-3" showProgress={false}>
            {fixtures.map((fixture) => {
                const opponent = opponentOf(fixture, teamId);
                const result = resultFor(fixture, teamId);
                const isSelected = fixture.week_number === selectedWeek;
                const isLocal = fixture.local_team.id === teamId;
                const ownScore = isLocal
                    ? fixture.local_score
                    : fixture.guest_score;
                const rivalScore = isLocal
                    ? fixture.guest_score
                    : fixture.local_score;
                const hasScore = ownScore !== null && rivalScore !== null;

                return (
                    <button
                        key={fixture.id}
                        ref={isSelected ? selectedRef : undefined}
                        type="button"
                        onClick={() => onSelectWeek(fixture.week_number)}
                        className={cn(
                            'relative flex h-14 w-14 shrink-0 cursor-pointer flex-col items-center justify-center border-2 font-mono transition-colors',
                            result
                                ? RESULT_STRIP_CLASSES[result]
                                : 'border-dashed border-hq-border-strong text-hq-moss-dim',
                            isSelected &&
                                'border-hq-paper ring-2 ring-hq-paper',
                        )}
                    >
                        <span className="text-[10px] font-bold opacity-80">
                            J{fixture.week_number}
                        </span>
                        <span className="font-display text-lg leading-none">
                            {hasScore ? `${ownScore}-${rivalScore}` : '—'}
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
                    </button>
                );
            })}
        </HqScrollRow>
    );
}
