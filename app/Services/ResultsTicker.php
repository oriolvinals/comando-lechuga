<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\MarketPlayer;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The shell's "Teletipo" strip: what's new in the season at a glance — live
 * matches, the last fully finished jornada's results, the current daily
 * market listings and the latest transfer-market activity — as plain arrays
 * for a shared Inertia prop.
 *
 * @phpstan-type TickerTeam array{id: int, short_name: string, main_name: string, logo: string}
 * @phpstan-type TickerFixture array{id: int, state: string, display_clock: string|null, local_score: int|null, guest_score: int|null, local_team: TickerTeam, guest_team: TickerTeam}
 * @phpstan-type TickerListing array{id: int, player_id: int, nickname: string, value: int, bids: int, market_value_difference: int, market_trend: string|null}
 * @phpstan-type TickerActivity array{id: int, type: string, manager_name: string, player_nickname: string|null, amount: int|null}
 */
class ResultsTicker
{
    use FiltersSeasonWeeks;

    public const int ACTIVITY_COUNT = 5;

    /**
     * @return array{live: array<int, TickerFixture>, finished_week: int|null, results: array<int, TickerFixture>, market: array<int, TickerListing>, activities: array<int, TickerActivity>}
     */
    public function forSeason(Season $season): array
    {
        $finishedWeek = $this->lastFinishedWeek($season);

        return [
            'live' => $this->liveFixtures($season),
            'finished_week' => $finishedWeek,
            'results' => $finishedWeek === null ? [] : $this->results($season, $finishedWeek),
            'market' => $this->marketListings($season),
            'activities' => $this->latestActivities($season),
        ];
    }

    /**
     * The highest week whose fixtures have all been played — while a jornada
     * is in progress, that is the previous one.
     */
    private function lastFinishedWeek(Season $season): ?int
    {
        $finishedWeeks = $this->finishedWeekNumbers($season);

        return $finishedWeeks === [] ? null : max($finishedWeeks);
    }

    /**
     * @return array<int, TickerFixture>
     */
    private function liveFixtures(Season $season): array
    {
        return $this->fixtureQuery($season)
            ->whereIn('state', LeagueStandings::LIVE_STATES)
            ->get()
            ->map(fn (Fixture $fixture): array => $this->presentFixture($fixture))
            ->all();
    }

    /**
     * @return array<int, TickerFixture>
     */
    private function results(Season $season, int $week): array
    {
        return $this->fixtureQuery($season)
            ->where('week_number', $week)
            ->where('state', FixtureState::Finished)
            ->get()
            ->map(fn (Fixture $fixture): array => $this->presentFixture($fixture))
            ->all();
    }

    /**
     * @return Builder<Fixture>
     */
    private function fixtureQuery(Season $season): Builder
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->orderBy('id');
    }

    /**
     * The private league's current daily market: the listings that have not
     * expired yet, soonest to expire first, with each player's daily value
     * move. Coaches are left out, like on the home market.
     *
     * @return array<int, TickerListing>
     */
    private function marketListings(Season $season): array
    {
        return MarketPlayer::query()
            ->whereHas('player.seasons', fn (Builder $query): Builder => $query
                ->where('season_id', $season->id)
                ->where('position', '!=', PlayerPosition::Coach))
            ->where('expires_at', '>', now())
            ->with([
                'player:id,nickname',
                'player.seasons' => fn (HasMany $query): HasMany => $query->where('season_id', $season->id),
            ])
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->map(function (MarketPlayer $listing): array {
                $playerSeason = $listing->player->seasons->first();

                return [
                    'id' => $listing->id,
                    'player_id' => $listing->player_id,
                    'nickname' => $listing->player->nickname,
                    'value' => $listing->value,
                    'bids' => $listing->bids,
                    'market_value_difference' => $playerSeason?->market_value_difference ?? 0,
                    'market_trend' => $playerSeason?->market_trend?->value,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, TickerActivity>
     */
    private function latestActivities(Season $season): array
    {
        return Activity::query()
            ->where('season_id', $season->id)
            ->with(['sourceSeasonManager:id,name', 'player:id,nickname'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_COUNT)
            ->get()
            ->map(fn (Activity $activity): array => [
                'id' => $activity->id,
                'type' => $activity->type->value,
                'manager_name' => $activity->sourceSeasonManager->name,
                'player_nickname' => $activity->player?->nickname,
                'amount' => $activity->amount,
            ])
            ->all();
    }

    /**
     * @return TickerFixture
     */
    private function presentFixture(Fixture $fixture): array
    {
        return [
            'id' => $fixture->id,
            'state' => $fixture->state->value,
            'display_clock' => $fixture->display_clock,
            'local_score' => $fixture->local_score,
            'guest_score' => $fixture->guest_score,
            'local_team' => $this->presentTeam($fixture->localTeam),
            'guest_team' => $this->presentTeam($fixture->guestTeam),
        ];
    }

    /**
     * @return TickerTeam
     */
    private function presentTeam(Team $team): array
    {
        return [
            'id' => $team->id,
            'short_name' => $team->short_name,
            'main_name' => $team->main_name,
            'logo' => $team->logo,
        ];
    }
}
