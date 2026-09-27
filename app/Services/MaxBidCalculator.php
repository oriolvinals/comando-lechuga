<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Enums\MarketTrend;
use App\Enums\MaxBidStatus;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * "Puja máxima rentable": the most you can bid for a player and still expect
 * to get your money back within the 14-day clause lock, by accepting the
 * best of the league's daily sale offers (uniform ±10 % of that day's value).
 * See docs/superpowers/specs/2026-09-26-max-bid-design.md.
 *
 * Two steps: `gatherInputs()` does every database query, and the pure
 * `estimateFromInputs()` applies the formula with a set of MaxBidParameters,
 * so the backtest can replay the formula with other parameters in memory.
 */
class MaxBidCalculator
{
    public const int LOCK_DAYS = 14;

    public const float OFFER_SPREAD = 0.1;

    public const float CONFIDENCE = 0.75;

    public const int MOMENTUM_DAYS = 3;

    public const float DOUBTFUL_MAX_SPORT_SCORE = -0.5;

    public const int UPCOMING_RIVALS = 3;

    /** @var list<float> Weight of each of the team's last matches, newest first. */
    public const array RECENCY_WEIGHTS = [0.5, 0.3, 0.2];

    /** @var list<MarketTrend> Trends whose increment fades with `decayStrongRiseBreak` in a break. */
    public const array STRONG_RISE_TRENDS = [MarketTrend::RiseSteady, MarketTrend::RiseAccelerating, MarketTrend::RiseAcceleratingSharply];

    /** Values read per player: the seven the market trend needs (the momentum uses the last four). */
    private const int VALUES_READ = 7;

    /** @var list<PlayerStatus> */
    private const array UNAVAILABLE_STATUSES = [PlayerStatus::Injured, PlayerStatus::Suspended, PlayerStatus::OutOfLeague];

    /** @var array<string, float> Market-wide daily pace, cached per season + reference date for the backtest. */
    private array $marketPaceByDate = [];

    /** @var array<string, array<int, int>> Standings positions, cached per reference date for the backtest. */
    private array $positionsByDate = [];

    /** @var array<string, string|null> Latest published market day (Y-m-d) up to a date, cached per season + date. */
    private array $referenceDateByDate = [];

    /** @var array<int, Team> Rival teams, shared across estimates so a backtest holds one instance per team. */
    private array $teamsById = [];

    /**
     * Upcoming rivals, cached per season + team + reference date: every
     * player of a team shares them, so a backtest holds one copy per team-day.
     *
     * @var array<string, list<array{team: Team, position: int, days_until: int, difficulty: float}>>
     */
    private array $upcomingRivalsByTeamAndDate = [];

    public function __construct(
        private readonly LeagueStandings $standings,
        private readonly MaxBidParameters $parameters = new MaxBidParameters,
    ) {}

    public function estimate(Player $player, Season $season, ?CarbonInterface $at = null, float $confidence = self::CONFIDENCE): MaxBidEstimate
    {
        return self::estimateFromInputs($this->gatherInputs($player, $season, $at), $this->parameters, $confidence);
    }

    /**
     * Every database read the formula needs, for the player at the end of
     * `$at`'s day. An unavailable player or one with too little market
     * history gets a status-only result.
     *
     * Market values are published some time after midnight, so the market
     * data (the player's values and the market index) comes from one
     * reference day: the latest published day up to `$at`.
     */
    public function gatherInputs(Player $player, Season $season, ?CarbonInterface $at = null): MaxBidInputs
    {
        $moment = CarbonImmutable::parse($at ?? now());
        $at = $moment->endOfDay();
        $referenceDate = $this->referenceDate($season, $at);

        $values = $referenceDate === null ? [] : PlayerMarket::query()
            ->where('player_id', $player->id)
            ->whereDate('date', '<=', $referenceDate)
            ->orderByDesc('date')
            ->limit(self::VALUES_READ)
            ->pluck('value')
            ->reverse()
            ->values()
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
        $value = $values === [] ? 0 : end($values);

        if (in_array($player->status, self::UNAVAILABLE_STATUSES, true)) {
            return new MaxBidInputs($value, MaxBidStatus::Unavailable, referenceDate: $referenceDate);
        }

        if ($referenceDate === null || count($values) < self::MOMENTUM_DAYS + 1) {
            return new MaxBidInputs($value, MaxBidStatus::NoData, referenceDate: $referenceDate);
        }

        $momentumValues = array_slice($values, -(self::MOMENTUM_DAYS + 1));
        [$lastPoints, $seasonPointsAverage] = $this->points($player, $season, $at);
        $positions = $this->positionsByDate["{$season->id}:{$at->toDateString()}"] ??= $this->standings->positions($season, $at);
        $teamCount = max(count($positions), 2);
        [$recentParticipation, $recentTeamPoints] = $this->recentMatches($player, $season, $at);

        return new MaxBidInputs(
            value: $value,
            momentum: ($momentumValues[self::MOMENTUM_DAYS] - $momentumValues[0]) / self::MOMENTUM_DAYS,
            marketPace: $this->marketPace($season, CarbonImmutable::parse($referenceDate)->endOfDay()),
            lastPoints: $lastPoints,
            seasonPointsAverage: $seasonPointsAverage,
            recentParticipation: $recentParticipation,
            upcomingRivals: $this->upcomingRivals($player, $season, $moment, $positions, $teamCount),
            teamCount: $teamCount,
            doubtful: $player->status === PlayerStatus::Doubtful,
            recentTeamPoints: $recentTeamPoints,
            referenceDate: $referenceDate,
            strongRise: in_array(MarketTrend::fromDailyValues(array_values($values)), self::STRONG_RISE_TRENDS, true),
        );
    }

    /**
     * The formula: no database access, so it can be replayed with other
     * parameters over inputs gathered once.
     */
    public static function estimateFromInputs(MaxBidInputs $inputs, MaxBidParameters $parameters, float $confidence = self::CONFIDENCE): MaxBidEstimate
    {
        if ($inputs->presetStatus !== null) {
            return new MaxBidEstimate($inputs->presetStatus, $inputs->value, $confidence, self::LOCK_DAYS, referenceDate: $inputs->referenceDate);
        }

        $value = $inputs->value;
        $marketAdjustment = -$inputs->marketPace * $value;
        $sport = self::sportFactors($inputs, $parameters);
        $sportAdjustment = $value * $sport['score'] * $parameters->sportDailyRate;
        $increment = $inputs->momentum + $marketAdjustment + $sportAdjustment;

        if ($sport['participation'] < $parameters->benchParticipation && $increment > 0) {
            $increment *= $parameters->benchIncrementFactor;
        }

        $badScoreCaps = self::hadBadLastScore($inputs, $parameters) && !self::onStreak($inputs, $parameters);

        if (self::benchedInEachOfTheLastMatches($inputs, $parameters->benchesBeforeUnprofitable) || $badScoreCaps) {
            $increment = min($increment, 0.0);
        }

        $decay = match (true) {
            $inputs->isBreak() && $inputs->strongRise => $parameters->decayStrongRiseBreak,
            $inputs->isBreak() => $parameters->incrementDecayBreak,
            default => $parameters->incrementDecayMatchweek,
        };
        $projection = self::project($value, $increment, $decay);
        $profitable = $increment > 0;

        return new MaxBidEstimate(
            status: $profitable ? MaxBidStatus::Profitable : MaxBidStatus::Unprofitable,
            value: $value,
            confidence: $confidence,
            lockDays: self::LOCK_DAYS,
            bid: $profitable ? self::solveBid($projection, $confidence) : null,
            projection: $projection,
            momentumIncrement: $inputs->momentum,
            marketAdjustment: $marketAdjustment,
            sportAdjustment: $sportAdjustment,
            dailyIncrement: $increment,
            sportScore: $sport['score'],
            form: $sport['form'],
            participation: $sport['participation'],
            recentParticipation: $inputs->recentParticipation,
            rivalsEffect: $sport['rivals_effect'],
            upcomingRivals: $sport['upcoming_rivals'],
            referenceDate: $inputs->referenceDate,
        );
    }

    /**
     * Value from today (day 0) to the end of the lock, the daily increment
     * fading by `$incrementDecay` per day.
     *
     * @return list<int>
     */
    public static function project(int $value, float $dailyIncrement, float $incrementDecay): array
    {
        $projection = [$value];
        $current = (float) $value;

        for ($day = 1; $day <= self::LOCK_DAYS; $day++) {
            $current += $dailyIncrement * $incrementDecay ** $day;
            $projection[] = (int) round($current);
        }

        return $projection;
    }

    /**
     * Probability that none of the daily offers during the lock (days 1…14)
     * exceeds `$amount`.
     *
     * @param  list<int>  $projection
     */
    public static function bestOfferProbabilityAtMost(float $amount, array $projection): float
    {
        $probability = 1.0;

        foreach (array_slice($projection, 1) as $dayValue) {
            $probability *= max(0.0, min(1.0, ($amount / $dayValue - (1 - self::OFFER_SPREAD)) / (2 * self::OFFER_SPREAD)));
        }

        return $probability;
    }

    /**
     * The amount the best offer of the lock beats with `$confidence` probability
     * — a lower confidence accepts more risk, so it solves for a HIGHER bid.
     *
     * @param  list<int>  $projection
     */
    public static function solveBid(array $projection, float $confidence = self::CONFIDENCE): int
    {
        $days = array_slice($projection, 1);

        if ($days === []) {
            throw new InvalidArgumentException('The projection must include at least one day beyond day 0.');
        }

        $low = min($days) * (1 - self::OFFER_SPREAD);
        $high = max($days) * (1 + self::OFFER_SPREAD);

        for ($iteration = 0; $iteration < 60; $iteration++) {
            $middle = ($low + $high) / 2;

            if (self::bestOfferProbabilityAtMost($middle, $projection) < 1 - $confidence) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }

        return (int) round($low);
    }

    /**
     * @return array{score: float, form: float, participation: float, rivals_effect: float, upcoming_rivals: list<array{team: Team, position: int, days_until: int, difficulty: float, weight: float}>}
     */
    private static function sportFactors(MaxBidInputs $inputs, MaxBidParameters $parameters): array
    {
        $form = self::form($inputs);
        $participation = self::participation($inputs);
        $upcomingRivals = array_map(
            fn (array $rival): array => [...$rival, 'weight' => 0.5 ** ($rival['days_until'] / $parameters->proximityHalfLifeDays)],
            $inputs->upcomingRivals,
        );
        $rivalsEffect = array_sum(array_map(
            fn (array $rival): float => $rival['weight'] * $rival['difficulty'],
            $upcomingRivals,
        )) / self::UPCOMING_RIVALS;

        $nextMatchWeight = $upcomingRivals === [] ? 0.0 : $upcomingRivals[0]['weight'];
        $score = $nextMatchWeight * ($parameters->formWeight * $form + $parameters->participationWeight * (2 * $participation - 1))
            + $parameters->rivalsWeight * self::UPCOMING_RIVALS * $rivalsEffect;

        if ($inputs->doubtful) {
            $score = min($score, self::DOUBTFUL_MAX_SPORT_SCORE);
        }

        return [
            'score' => $score,
            'form' => $form,
            'participation' => $participation,
            'rivals_effect' => $rivalsEffect,
            'upcoming_rivals' => $upcomingRivals,
        ];
    }

    /**
     * Last three fantasy points against the season average, in −1…1.
     */
    private static function form(MaxBidInputs $inputs): float
    {
        if ($inputs->lastPoints === []) {
            return 0.0;
        }

        $average = $inputs->seasonPointsAverage;

        return max(-1.0, min(1.0, (array_sum($inputs->lastPoints) / count($inputs->lastPoints) - $average) / max($average, 2)));
    }

    /**
     * Starts and minutes in the team's last three finished matches,
     * recency-weighted, in 0…1.
     */
    private static function participation(MaxBidInputs $inputs): float
    {
        $participation = 0.0;

        foreach (self::RECENCY_WEIGHTS as $index => $weight) {
            $match = $inputs->recentParticipation[$index] ?? null;

            if ($match === null) {
                break;
            }

            $participation += $weight * (0.5 * ($match['starter'] ? 1 : 0) + 0.5 * min($match['minutes'] / 90, 1));
        }

        return $participation;
    }

    /**
     * Whether the player had 0 minutes in each of the team's last `$matches`
     * finished matches (never for 0, nor when the team has played fewer).
     */
    private static function benchedInEachOfTheLastMatches(MaxBidInputs $inputs, int $matches): bool
    {
        if ($matches <= 0 || count($inputs->recentParticipation) < $matches) {
            return false;
        }

        foreach (array_slice($inputs->recentParticipation, 0, $matches) as $match) {
            if ($match['minutes'] > 0) {
                return false;
            }
        }

        return true;
    }

    private static function hadBadLastScore(MaxBidInputs $inputs, MaxBidParameters $parameters): bool
    {
        $latestPoints = $inputs->latestPoints();

        return $latestPoints !== null && $parameters->badScoreRule->isBadScore($latestPoints, $inputs->seasonPointsAverage);
    }

    /**
     * The streak exception to the bad-score cap: a strong own market pace, a
     * team in form and a player who started each of its last three matches.
     */
    private static function onStreak(MaxBidInputs $inputs, MaxBidParameters $parameters): bool
    {
        $matches = count(self::RECENCY_WEIGHTS);

        if ($parameters->streakExceptionPace === null || $inputs->value <= 0) {
            return false;
        }

        return $inputs->momentum / $inputs->value >= $parameters->streakExceptionPace
            && count($inputs->recentTeamPoints) === $matches
            && array_sum($inputs->recentTeamPoints) >= $parameters->streakExceptionTeamPoints
            && count($inputs->recentParticipation) === $matches
            && array_filter($inputs->recentParticipation, fn (array $match): bool => !$match['starter']) === [];
    }

    /**
     * The latest day (Y-m-d) up to `$at` with any published market values,
     * null when there is none. Cached per season + date like the market index.
     */
    private function referenceDate(Season $season, CarbonImmutable $at): ?string
    {
        $cacheKey = "{$season->id}:{$at->toDateString()}";

        if (!array_key_exists($cacheKey, $this->referenceDateByDate)) {
            $latest = PlayerMarket::query()->whereDate('date', '<=', $at)->max('date');

            $this->referenceDateByDate[$cacheKey] = $latest === null ? null : substr((string) $latest, 0, 10);
        }

        return $this->referenceDateByDate[$cacheKey];
    }

    /**
     * Value-weighted daily pace of the whole market over the momentum window,
     * over players of the season's teams still in the league that have a
     * value on BOTH ends of the window (a self-join on `player_id`, so a
     * player who only joined or left mid-window doesn't skew the index).
     * 0 when either end totals to no data.
     */
    private function marketPace(Season $season, CarbonImmutable $at): float
    {
        $cacheKey = "{$season->id}:{$at->toDateString()}";

        return $this->marketPaceByDate[$cacheKey] ??= (function () use ($season, $at): float {
            $start = $at->subDays(self::MOMENTUM_DAYS);

            $totals = DB::table('player_markets as start_market')
                ->join('player_markets as end_market', 'end_market.player_id', '=', 'start_market.player_id')
                ->join('players', 'players.id', '=', 'start_market.player_id')
                ->whereDate('start_market.date', $start)
                ->whereDate('end_market.date', $at)
                ->whereIn('players.team_id', $season->teams()->select('teams.id'))
                ->where('players.status', '!=', PlayerStatus::OutOfLeague->value)
                ->selectRaw('sum(start_market.value) as start_total, sum(end_market.value) as end_total')
                ->first();

            $startTotal = (int) ($totals->start_total ?? 0);
            $endTotal = (int) ($totals->end_total ?? 0);

            return $startTotal > 0 && $endTotal > 0 ? ($endTotal - $startTotal) / $startTotal / self::MOMENTUM_DAYS : 0.0;
        })();
    }

    /**
     * The player's fantasy points in his finished lineups up to `$at`: the
     * last three, newest first, and the season average. A lineup still
     * waiting for its points counts as 0.
     *
     * @return array{0: list<int>, 1: float}
     */
    private function points(Player $player, Season $season, CarbonImmutable $at): array
    {
        $points = FixtureLineup::query()
            ->where('fixture_lineups.player_id', $player->id)
            ->join('fixtures', 'fixtures.id', '=', 'fixture_lineups.fixture_id')
            ->where('fixtures.season_id', $season->id)
            ->where('fixtures.state', FixtureState::Finished)
            ->where('fixtures.date', '<=', $at)
            ->orderByDesc('fixtures.date')
            ->pluck('fixture_lineups.fantasy_points')
            ->map(fn (?int $points): int => $points ?? 0)
            ->values()
            ->all();

        return [array_slice($points, 0, 3), $points === [] ? 0.0 : array_sum($points) / count($points)];
    }

    /**
     * The team's last three finished matches up to `$at`, newest first: the
     * player's start and minutes in each, and the team's points from each.
     *
     * @return array{0: list<array{starter: bool, minutes: int}>, 1: list<int>}
     */
    private function recentMatches(Player $player, Season $season, CarbonImmutable $at): array
    {
        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->where('date', '<=', $at)
            ->where(fn ($query) => $query
                ->where('team_local_id', $player->team_id)
                ->orWhere('team_guest_id', $player->team_id))
            ->orderByDesc('date')
            ->limit(count(self::RECENCY_WEIGHTS))
            ->get(['id', 'team_local_id', 'team_guest_id', 'local_score', 'guest_score']);

        $lineups = FixtureLineup::query()
            ->where('player_id', $player->id)
            ->whereIn('fixture_id', $fixtures->pluck('id'))
            ->get()
            ->keyBy('fixture_id');

        $participation = [];
        $teamPoints = [];

        foreach ($fixtures as $fixture) {
            $lineup = $lineups->get($fixture->id);
            $participation[] = [
                'starter' => (bool) $lineup?->starter,
                'minutes' => (int) ($lineup?->fantasy_stats['mins_played'][0] ?? 0),
            ];
            $teamPoints[] = self::teamPoints($fixture, $player->team_id);
        }

        return [$participation, $teamPoints];
    }

    /** 3 for a win, 1 for a draw, 0 for a loss (or a missing score), from the team's side. */
    private static function teamPoints(Fixture $fixture, int $teamId): int
    {
        if ($fixture->local_score === null || $fixture->guest_score === null) {
            return 0;
        }

        [$for, $against] = $fixture->team_local_id === $teamId
            ? [$fixture->local_score, $fixture->guest_score]
            : [$fixture->guest_score, $fixture->local_score];

        return $for > $against ? 3 : ($for === $against ? 1 : 0);
    }

    /**
     * The team's next three fixtures after `$moment` (any after its day, plus
     * one later that same day not yet played), never a postponed one, each
     * with the rival's standings position on that date and its difficulty
     * (−1 leader … +1 last). A match later today is 0 days away.
     *
     * @param  array<int, int>  $positions  team id → standings position
     * @return list<array{team: Team, position: int, days_until: int, difficulty: float}>
     */
    private function upcomingRivals(Player $player, Season $season, CarbonImmutable $moment, array $positions, int $teamCount): array
    {
        return $this->upcomingRivalsByTeamAndDate["{$season->id}:{$player->team_id}:{$moment->toDateTimeString()}"]
            ??= $this->queryUpcomingRivals($player, $season, $moment, $positions, $teamCount);
    }

    /**
     * @param  array<int, int>  $positions  team id → standings position
     * @return list<array{team: Team, position: int, days_until: int, difficulty: float}>
     */
    private function queryUpcomingRivals(Player $player, Season $season, CarbonImmutable $moment, array $positions, int $teamCount): array
    {
        $endOfDay = $moment->endOfDay();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', '!=', FixtureState::Postponed)
            ->where(fn ($query) => $query
                ->where('date', '>', $endOfDay)
                ->orWhere(fn ($query) => $query
                    ->where('date', '>', $moment)
                    ->whereNotIn('state', [FixtureState::Finished, FixtureState::Postponed])))
            ->where(fn ($query) => $query
                ->where('team_local_id', $player->team_id)
                ->orWhere('team_guest_id', $player->team_id))
            ->orderBy('date')
            ->limit(self::UPCOMING_RIVALS)
            ->get(['id', 'date', 'team_local_id', 'team_guest_id']);

        $rivalId = fn (Fixture $fixture): int => $fixture->team_local_id === $player->team_id ? $fixture->team_guest_id : $fixture->team_local_id;
        $missingIds = array_diff($fixtures->map($rivalId)->unique()->all(), array_keys($this->teamsById));

        if ($missingIds !== []) {
            foreach (Team::query()->findMany($missingIds) as $team) {
                $this->teamsById[$team->id] = $team;
            }
        }

        $rivals = [];

        foreach ($fixtures as $fixture) {
            $rival = $this->teamsById[$rivalId($fixture)] ?? throw new RuntimeException("Fixture {$fixture->id} is missing its rival team.");
            $position = $positions[$rival->id] ?? intdiv($teamCount + 1, 2);

            $rivals[] = [
                'team' => $rival,
                'position' => $position,
                'days_until' => (int) $moment->startOfDay()->diffInDays($fixture->date->startOfDay()),
                'difficulty' => LeagueStandings::difficulty($position, $teamCount),
            ];
        }

        return $rivals;
    }
}
