<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
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

    public const float PROXIMITY_HALF_LIFE_DAYS = 7.0;

    public const float FORM_WEIGHT = 0.4;

    public const float PARTICIPATION_WEIGHT = 0.3;

    public const float RIVALS_WEIGHT = 0.3;

    public const float DOUBTFUL_MAX_SPORT_SCORE = -0.5;

    public const int UPCOMING_RIVALS = 3;

    /** @var list<float> Weight of each of the team's last matches, newest first. */
    public const array RECENCY_WEIGHTS = [0.5, 0.3, 0.2];

    /** @var list<PlayerStatus> */
    private const array UNAVAILABLE_STATUSES = [PlayerStatus::Injured, PlayerStatus::Suspended, PlayerStatus::OutOfLeague];

    /** @var array<string, float> Market-wide daily pace, cached per season + reference date for the backtest. */
    private array $marketPaceByDate = [];

    /** @var array<string, array<int, int>> Standings positions, cached per reference date for the backtest. */
    private array $positionsByDate = [];

    public function __construct(private readonly LeagueStandings $standings) {}

    public function estimate(Player $player, Season $season, ?CarbonInterface $at = null, float $confidence = self::CONFIDENCE): MaxBidEstimate
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
            return new MaxBidEstimate(MaxBidStatus::Unavailable, $value, $confidence, self::LOCK_DAYS);
        }

        if (count($values) < self::MOMENTUM_DAYS + 1) {
            return new MaxBidEstimate(MaxBidStatus::NoData, $value, $confidence, self::LOCK_DAYS);
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
            confidence: $confidence,
            lockDays: self::LOCK_DAYS,
            bid: $profitable ? self::solveBid($projection, $confidence) : null,
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
     * @return array{score: float, form: float, participation: float, recent_participation: list<array{starter: bool, minutes: int}>, rivals_effect: float, upcoming_rivals: list<array{team: Team, position: int, days_until: int, difficulty: float, weight: float}>}
     */
    private function sportFactors(Player $player, Season $season, CarbonImmutable $at): array
    {
        $form = $this->form($player, $season, $at);
        [$participation, $recentParticipation] = $this->participation($player, $season, $at);
        $upcomingRivals = $this->upcomingRivals($player, $season, $at);
        $rivalsEffect = array_sum(array_map(
            fn (array $rival): float => $rival['weight'] * $rival['difficulty'],
            $upcomingRivals,
        )) / self::UPCOMING_RIVALS;

        $nextMatchWeight = $upcomingRivals === [] ? 0.0 : $upcomingRivals[0]['weight'];
        $score = $nextMatchWeight * (self::FORM_WEIGHT * $form + self::PARTICIPATION_WEIGHT * (2 * $participation - 1))
            + self::RIVALS_WEIGHT * self::UPCOMING_RIVALS * $rivalsEffect;

        if ($player->status === PlayerStatus::Doubtful) {
            $score = min($score, self::DOUBTFUL_MAX_SPORT_SCORE);
        }

        return [
            'score' => $score,
            'form' => $form,
            'participation' => $participation,
            'recent_participation' => $recentParticipation,
            'rivals_effect' => $rivalsEffect,
            'upcoming_rivals' => $upcomingRivals,
        ];
    }

    /**
     * Last three fantasy points against the season average up to `$at`, in
     * −1…1. A lineup still waiting for its points counts as 0.
     */
    private function form(Player $player, Season $season, CarbonImmutable $at): float
    {
        $points = FixtureLineup::query()
            ->where('fixture_lineups.player_id', $player->id)
            ->join('fixtures', 'fixtures.id', '=', 'fixture_lineups.fixture_id')
            ->where('fixtures.season_id', $season->id)
            ->where('fixtures.state', FixtureState::Finished)
            ->where('fixtures.date', '<=', $at)
            ->orderByDesc('fixtures.date')
            ->pluck('fixture_lineups.fantasy_points')
            ->map(fn (?int $points): int => $points ?? 0);

        if ($points->isEmpty()) {
            return 0.0;
        }

        $average = $points->avg();

        return max(-1.0, min(1.0, ($points->take(3)->avg() - $average) / max($average, 2)));
    }

    /**
     * Starts and minutes in the team's last three finished matches up to
     * `$at`, recency-weighted, in 0…1.
     *
     * @return array{0: float, 1: list<array{starter: bool, minutes: int}>}
     */
    private function participation(Player $player, Season $season, CarbonImmutable $at): array
    {
        $fixtureIds = Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->where('date', '<=', $at)
            ->where(fn ($query) => $query
                ->where('team_local_id', $player->team_id)
                ->orWhere('team_guest_id', $player->team_id))
            ->orderByDesc('date')
            ->limit(count(self::RECENCY_WEIGHTS))
            ->pluck('id');

        $lineups = FixtureLineup::query()
            ->where('player_id', $player->id)
            ->whereIn('fixture_id', $fixtureIds)
            ->get()
            ->keyBy('fixture_id');

        $participation = 0.0;
        $recent = [];

        foreach ($fixtureIds->values() as $index => $fixtureId) {
            $lineup = $lineups->get($fixtureId);
            $minutes = (int) ($lineup?->fantasy_stats['mins_played'][0] ?? 0);
            $starter = (bool) $lineup?->starter;
            $participation += self::RECENCY_WEIGHTS[$index] * (0.5 * ($starter ? 1 : 0) + 0.5 * min($minutes / 90, 1));
            $recent[] = ['starter' => $starter, 'minutes' => $minutes];
        }

        return [$participation, $recent];
    }

    /**
     * The team's next three fixtures after `$at`, each with the rival's
     * standings position on that date, its difficulty (−1 leader … +1 last)
     * and its proximity weight.
     *
     * @return list<array{team: Team, position: int, days_until: int, difficulty: float, weight: float}>
     */
    private function upcomingRivals(Player $player, Season $season, CarbonImmutable $at): array
    {
        $positions = $this->positionsByDate["{$season->id}:{$at->toDateString()}"] ??= $this->standings->positions($season, $at);
        $teamCount = max(count($positions), 2);

        $rivals = Fixture::query()
            ->where('season_id', $season->id)
            ->where('date', '>', $at)
            ->where(fn ($query) => $query
                ->where('team_local_id', $player->team_id)
                ->orWhere('team_guest_id', $player->team_id))
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->limit(self::UPCOMING_RIVALS)
            ->get()
            ->map(function (Fixture $fixture) use ($player, $positions, $teamCount, $at): array {
                $rival = $fixture->team_local_id === $player->team_id ? $fixture->guestTeam : $fixture->localTeam;

                if (!$rival instanceof Team) {
                    throw new RuntimeException("Fixture {$fixture->id} is missing its rival team.");
                }

                $position = $positions[$rival->id] ?? intdiv($teamCount + 1, 2);
                $daysUntil = (int) $at->startOfDay()->diffInDays($fixture->date->startOfDay());

                return [
                    'team' => $rival,
                    'position' => $position,
                    'days_until' => $daysUntil,
                    'difficulty' => ((float) $position - ($teamCount + 1) / 2) / (($teamCount - 1) / 2),
                    'weight' => 0.5 ** ($daysUntil / self::PROXIMITY_HALF_LIFE_DAYS),
                ];
            })
            ->values()
            ->all();

        return array_values($rivals);
    }
}
