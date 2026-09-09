import type { Fixture, Team } from '@/types/models';

export type TeamFixtureResult = 'win' | 'draw' | 'loss' | 'live' | null;

/** The rival club for a fixture, from `teamId`'s perspective. */
export function opponentOf(fixture: Fixture, teamId: number): Team {
    return fixture.local_team.id === teamId
        ? fixture.guest_team
        : fixture.local_team;
}

/**
 * A fixture's result from `teamId`'s perspective. A live (in-progress) match
 * returns 'live' rather than a real result — its score is provisional, the
 * same way the standings table counts it (see TeamsController::standingsFor()),
 * but the UI marks it as still-in-progress instead of presenting a score that
 * could still change as final.
 */
export function resultFor(fixture: Fixture, teamId: number): TeamFixtureResult {
    if (
        fixture.state === 'scheduled' ||
        fixture.local_score === null ||
        fixture.guest_score === null
    ) {
        return null;
    }

    if (fixture.state !== 'finished') {
        return 'live';
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

/** For a bordered badge (e.g. the fixture calendar strip) — includes the live state. */
export const RESULT_STRIP_CLASSES: Record<'win' | 'draw' | 'loss' | 'live', string> = {
    win: 'border-hq-lime text-hq-lime',
    draw: 'border-hq-gold text-hq-gold',
    loss: 'border-hq-live text-hq-live',
    live: 'border-hq-live text-hq-live animate-pulse',
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
