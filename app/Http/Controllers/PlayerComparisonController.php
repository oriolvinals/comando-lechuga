<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ComparedPlayers;
use App\Services\LeagueCloud;
use App\Services\SeasonClock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The player comparator (`/jugadores/comparar?ids=…&vista=a|b|c`). Every
 * heavy prop is a closure, so switching view or changing the selection is a
 * partial reload (`only`) that never recomputes what it doesn't need.
 */
class PlayerComparisonController extends Controller
{
    public const int MAX_PLAYERS = 3;

    /** @var list<string> */
    public const array VIEWS = ['a', 'b', 'c'];

    public function show(Request $request, SeasonClock $clock, ComparedPlayers $comparedPlayers, LeagueCloud $leagueCloud): Response
    {
        $season = Season::current();
        $ids = $this->comparableIds($this->requestedIds($request->query('ids')), $season);
        $week = $this->comparisonWeek($season, $clock);

        return Inertia::render('players/compare', [
            'currentWeek' => $week,
            'totalWeeks' => $season->total_weeks,
            'view' => $this->requestedView($request->query('vista')),
            'ids' => $ids,
            'players' => fn (): array => $comparedPlayers->forIds($ids, $season, $week),
            'league' => fn (): array => $leagueCloud->rows($season, $week),
            'managers' => fn (): array => $this->managers($season),
        ]);
    }

    /**
     * Positive integers from a comma-separated `ids`, first occurrence only.
     * Anything that is not a string (e.g. `ids[]=1`) counts as no ids.
     *
     * @return list<int>
     */
    private function requestedIds(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $id = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id === false || in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * The requested ids that are league players of this season (a fantasy
     * id and season figures), in the requested order, at most three.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function comparableIds(array $ids, Season $season): array
    {
        if ($ids === []) {
            return [];
        }

        $existing = Player::query()
            ->whereIn('id', $ids)
            ->whereNotNull('fantasy_id')
            ->whereHas('seasons', fn ($query) => $query->where('season_id', $season->id))
            ->pluck('id')
            ->all();

        $valid = array_values(array_filter($ids, fn (int $id): bool => in_array($id, $existing, true)));

        return array_slice($valid, 0, self::MAX_PLAYERS);
    }

    private function requestedView(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, self::VIEWS, true) ? $raw : 'a';
    }

    /**
     * The first jornada that hasn't kicked off: past columns are 1…N−1
     * (a live jornada counts as past, so its scores and DAZN estimates show)
     * and the upcoming ones start at N — the "Titularidad J{N}" of the mock.
     * Never past total_weeks + 1: once the last jornada has kicked off every
     * jornada is a past column and there is no upcoming one.
     */
    private function comparisonWeek(Season $season, SeasonClock $clock): int
    {
        $week = $clock->weekState($season, $season->current_week) === SeasonClock::NOT_STARTED
            ? $season->current_week
            : $season->current_week + 1;

        return max(1, min($week, $season->total_weeks + 1));
    }

    /**
     * @return list<array{id: int, name: string, logo: string, color: string|null}>
     */
    private function managers(Season $season): array
    {
        $managers = SeasonManager::query()
            ->where('season_id', $season->id)
            ->orderBy('name')
            ->get()
            ->map(fn (SeasonManager $manager): array => [
                'id' => $manager->id,
                'name' => $manager->name,
                'logo' => $manager->logo ? asset($manager->logo) : '',
                'color' => $manager->primary_color,
            ])
            ->all();

        return array_values($managers);
    }
}
