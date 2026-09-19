import { Flag, MapPin, Users } from 'lucide-react';
import type { Fixture } from '@/types/models';

const attendanceFormat = new Intl.NumberFormat('es-ES');

/**
 * The strip under the scoreboard: where the match was played, how many people
 * were there and who refereed it. Any of the three can still be missing (e.g.
 * before kickoff), so each renders on its own, and the whole strip disappears
 * when none is known.
 */
export function HqFixtureMatchDetails({ fixture }: { fixture: Fixture }) {
    const hasVenue = fixture.venue !== '';
    // An attendance of 0 is no figure worth showing, same as a missing one.
    const attendance = fixture.attendance ?? 0;
    const hasAttendance = attendance > 0;
    const hasReferee = fixture.referee !== '';

    if (!hasVenue && !hasAttendance && !hasReferee) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center justify-center gap-x-6 gap-y-1.5 border-t border-hq-border bg-hq-ink/40 px-4 py-2.5 font-mono text-[11px] text-hq-moss">
            {hasVenue && (
                <span className="inline-flex items-center gap-1.5">
                    <MapPin className="h-3.5 w-3.5 text-hq-khaki" />
                    <b className="font-medium text-hq-paper">{fixture.venue}</b>
                    {fixture.venue_city !== '' && (
                        <span className="text-hq-moss-dim">
                            {fixture.venue_city}
                        </span>
                    )}
                </span>
            )}
            {hasAttendance && (
                <span className="inline-flex items-center gap-1.5">
                    <Users className="h-3.5 w-3.5 text-hq-khaki" />
                    <b className="font-medium text-hq-paper">
                        {attendanceFormat.format(attendance)}
                    </b>
                    <span className="text-hq-moss-dim">espectadores</span>
                </span>
            )}
            {hasReferee && (
                <span className="inline-flex items-center gap-1.5">
                    <Flag className="h-3.5 w-3.5 text-hq-khaki" />
                    <span className="text-hq-moss-dim">Árbitro</span>
                    <b className="font-medium text-hq-paper">
                        {fixture.referee}
                    </b>
                </span>
            )}
        </div>
    );
}
