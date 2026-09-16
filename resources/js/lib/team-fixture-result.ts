import type { Fixture, Team } from '@/types/models';

export type TeamFixtureResult = 'win' | 'draw' | 'loss' | null;

/** The rival club for a fixture, from `teamId`'s perspective. */
export function opponentOf(fixture: Fixture, teamId: number): Team {
    return fixture.local_team.id === teamId
        ? fixture.guest_team
        : fixture.local_team;
}

/**
 * A fixture's result from `teamId`'s perspective, computed from the
 * scoreline as it stands right now — provisional (and still able to change)
 * while the match is live, final once it's `finished`. Callers that need to
 * tell those two apart (e.g. to blink a live indicator) combine this with
 * `isLiveFixtureState(fixture.state)` themselves; this never returns a
 * separate "live" tone; a live loss still reads as a loss, just pulsing.
 */
export function resultFor(fixture: Fixture, teamId: number): TeamFixtureResult {
    if (
        fixture.state === 'scheduled' ||
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

export const RESULT_LABEL: Record<'win' | 'draw' | 'loss', string> = {
    win: 'V',
    draw: 'E',
    loss: 'D',
};

/** For a bordered badge (e.g. the fixture calendar strip) — pair with `animate-pulse` while the match is live, its own concern per `resultFor`'s doc comment. */
export const RESULT_STRIP_CLASSES: Record<'win' | 'draw' | 'loss', string> = {
    win: 'border-hq-lime text-hq-lime',
    draw: 'border-hq-gold text-hq-gold',
    loss: 'border-hq-live text-hq-live',
};

/** For a small filled square badge (e.g. the standings "Forma" column) — finished results only. */
export const RESULT_BADGE_CLASSES: Record<'win' | 'draw' | 'loss', string> = {
    win: 'bg-hq-lime/20 text-hq-lime',
    draw: 'bg-hq-gold/20 text-hq-gold',
    loss: 'bg-hq-live/20 text-hq-live',
};

/** Border-only variant of RESULT_BADGE_CLASSES's palette — e.g. a match tooltip's border. */
export const RESULT_BORDER_CLASSES: Record<'win' | 'draw' | 'loss', string> = {
    win: 'border-hq-lime',
    draw: 'border-hq-gold',
    loss: 'border-hq-live',
};
