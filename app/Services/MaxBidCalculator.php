<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MaxBidStatus;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * "Puja máxima rentable": the most you can bid for a player and still expect
 * to get your money back within the 14-day clause lock, by accepting the
 * best of the league's daily sale offers (uniform ±10 % of that day's value).
 * See docs/superpowers/specs/2026-09-26-max-bid-design.md.
 */
class MaxBidCalculator
{
    public const int LOCK_DAYS = 14;

    public const float OFFER_SPREAD = 0.1;

    public const float CONFIDENCE = 0.75;

    public const float INCREMENT_DECAY = 0.9;

    public const int MOMENTUM_DAYS = 3;

    public const float SPORT_DAILY_RATE = 0.01;

    public const float BENCH_PARTICIPATION = 0.4;

    /** @var list<PlayerStatus> */
    private const array UNAVAILABLE_STATUSES = [PlayerStatus::Injured, PlayerStatus::Suspended, PlayerStatus::OutOfLeague];

    /** @var array<string, float> Market-wide daily pace, cached per reference date for the backtest. */
    private array $marketPaceByDate = [];

    /**
     * Not read yet: sportFactors() below is a neutral stub until Task 3, which
     * uses it to weigh the player's opponents' league position.
     */
    public function __construct(protected readonly LeagueStandings $standings) {}

    public function estimate(Player $player, Season $season, ?CarbonInterface $at = null): MaxBidEstimate
    {
        $at = CarbonImmutable::parse($at ?? now())->endOfDay();

        $values = PlayerMarket::query()
            ->where('player_id', $player->id)
            ->whereDate('date', '<=', $at)
            ->orderByDesc('date')
            ->limit(self::MOMENTUM_DAYS + 1)
            ->pluck('value')
            ->reverse()
            ->values()
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
        $value = $values === [] ? 0 : end($values);

        if (in_array($player->status, self::UNAVAILABLE_STATUSES, true)) {
            return new MaxBidEstimate(MaxBidStatus::Unavailable, $value);
        }

        if (count($values) < self::MOMENTUM_DAYS + 1) {
            return new MaxBidEstimate(MaxBidStatus::NoData, $value);
        }

        $momentum = ($values[self::MOMENTUM_DAYS] - $values[0]) / self::MOMENTUM_DAYS;
        $marketAdjustment = -$this->marketPace($season, $at) * $value;
        $sport = $this->sportFactors($player, $season, $at);
        $sportAdjustment = $value * $sport['score'] * self::SPORT_DAILY_RATE;
        $increment = $momentum + $marketAdjustment + $sportAdjustment;

        if ($sport['participation'] < self::BENCH_PARTICIPATION && $increment > 0) {
            $increment /= 2;
        }

        $projection = self::project($value, $increment);
        $profitable = $increment > 0;

        return new MaxBidEstimate(
            status: $profitable ? MaxBidStatus::Profitable : MaxBidStatus::Unprofitable,
            value: $value,
            bid: $profitable ? self::solveBid($projection) : null,
            projection: $projection,
            momentumIncrement: $momentum,
            marketAdjustment: $marketAdjustment,
            sportAdjustment: $sportAdjustment,
            dailyIncrement: $increment,
            sportScore: $sport['score'],
            form: $sport['form'],
            participation: $sport['participation'],
            recentParticipation: $sport['recent_participation'],
            rivalsEffect: $sport['rivals_effect'],
            upcomingRivals: $sport['upcoming_rivals'],
        );
    }

    /**
     * Value from today (day 0) to the end of the lock, the daily increment
     * fading 10 % per day.
     *
     * @return list<int>
     */
    public static function project(int $value, float $dailyIncrement): array
    {
        $projection = [$value];
        $current = (float) $value;

        for ($day = 1; $day <= self::LOCK_DAYS; $day++) {
            $current += $dailyIncrement * self::INCREMENT_DECAY ** $day;
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
     * The amount the best offer of the lock beats with CONFIDENCE probability.
     *
     * @param  list<int>  $projection
     */
    public static function solveBid(array $projection): int
    {
        $days = array_slice($projection, 1);

        if ($days === []) {
            throw new InvalidArgumentException('The projection must include at least one day beyond day 0.');
        }

        $low = min($days) * (1 - self::OFFER_SPREAD);
        $high = max($days) * (1 + self::OFFER_SPREAD);

        for ($iteration = 0; $iteration < 60; $iteration++) {
            $middle = ($low + $high) / 2;

            if (self::bestOfferProbabilityAtMost($middle, $projection) < 1 - self::CONFIDENCE) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }

        return (int) round($low);
    }

    /**
     * Value-weighted daily pace of the whole market over the momentum window,
     * over players of the season's teams still in the league that have a value
     * on both ends. 0 when either end has no data.
     */
    private function marketPace(Season $season, CarbonImmutable $at): float
    {
        $today = $at->toDateString();

        return $this->marketPaceByDate[$today] ??= (function () use ($season, $at): float {
            $totalOn = fn (CarbonImmutable $day): int => (int) PlayerMarket::query()
                ->whereDate('date', $day)
                ->whereHas('player', fn ($query) => $query
                    ->whereIn('team_id', $season->teams()->select('teams.id'))
                    ->where('status', '!=', PlayerStatus::OutOfLeague))
                ->sum('value');

            $start = $totalOn($at->subDays(self::MOMENTUM_DAYS));
            $end = $totalOn($at);

            return $start > 0 && $end > 0 ? ($end - $start) / $start / self::MOMENTUM_DAYS : 0.0;
        })();
    }

    /**
     * Sport score and its parts. Neutral until Task 3.
     *
     * @return array{score: float, form: float, participation: float, recent_participation: list<array{starter: bool, minutes: int}>, rivals_effect: float, upcoming_rivals: list<array{team: Team, position: int, days_until: int, difficulty: float, weight: float}>}
     */
    private function sportFactors(Player $player, Season $season, CarbonImmutable $at): array
    {
        return ['score' => 0.0, 'form' => 0.0, 'participation' => 1.0, 'recent_participation' => [], 'rivals_effect' => 0.0, 'upcoming_rivals' => []];
    }
}
