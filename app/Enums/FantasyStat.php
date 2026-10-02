<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The LaLiga Fantasy actions the player rankings rank, keyed as in
 * `fixture_lineups.fantasy_stats` (each one a `[value, points]` pair per
 * match), in the rankings' order: attack, defence, play, then errors and
 * cards. DAZN's rating is the exception: its value there is always -1, so
 * its points side is read instead, and only once the fixture is published.
 */
enum FantasyStat: string
{
    case Goals = 'goals';
    case GoalAssist = 'goal_assist';
    case OfftargetAttAssist = 'offtarget_att_assist';
    case TotalScoringAtt = 'total_scoring_att';
    case WonContest = 'won_contest';
    case PenAreaEntries = 'pen_area_entries';
    case PenaltyWon = 'penalty_won';
    case MinsPlayed = 'mins_played';
    case DaznPoints = 'marca_points';
    case EffectiveClearance = 'effective_clearance';
    case BallRecovery = 'ball_recovery';
    case Saves = 'saves';
    case PenaltySave = 'penalty_save';
    case GoalsConceded = 'goals_conceded';
    case PossLostAll = 'poss_lost_all';
    case YellowCard = 'yellow_card';
    case SecondYellowCard = 'second_yellow_card';
    case RedCard = 'red_card';
    case PenaltyConceded = 'penalty_conceded';
    case PenaltyFailed = 'penalty_failed';
    case OwnGoals = 'own_goals';

    /** A bad action: it takes points away, so the rankings show it apart and in red. */
    public function isBad(): bool
    {
        return in_array($this, [
            self::GoalsConceded,
            self::PossLostAll,
            self::YellowCard,
            self::SecondYellowCard,
            self::RedCard,
            self::PenaltyConceded,
            self::PenaltyFailed,
            self::OwnGoals,
        ], true);
    }
}
