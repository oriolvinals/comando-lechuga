<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The «Compras del mercado» story data for one Madrid day: the same JSON the Remotion project's
 * scripts/export-compras.php writes (buys, managers, daily), restricted to the given signing activities.
 *
 * PUBLIC data only: activity amounts, player values and standings. Never manager balances, bids, max bids or any
 * god-mode figure, and no times of day (the signing's `at` hour of the research export is left out on purpose).
 */
final class MarketSigningsExport
{
    public const string TIMEZONE = 'Europe/Madrid';

    private const string PLACEHOLDER_PHOTO_MD5 = '3c3c1c9515b6f09e1c045cde40923bea';

    /** @var array<string, string> */
    private const array POSITION_CODES = ['goalkeeper' => 'POR', 'defender' => 'DEF', 'midfield' => 'MED', 'striker' => 'DEL'];

    /**
     * The Madrid day's start (inclusive) and end (exclusive), in the app's storage timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayBounds(string $date): array
    {
        $start = CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();

        return [
            $start->setTimezone((string) config('app.timezone')),
            $start->addDay()->setTimezone((string) config('app.timezone')),
        ];
    }

    /**
     * @param  Collection<int, Activity>  $signings  The signing activities of the story (any order; players required).
     * @return array{exportedAt: string, date: string, buys: list<array<string, mixed>>, managers: object, daily: list<array{0: string, 1: int, 2: int}>}
     */
    public function export(string $date, Collection $signings, ?int $seasonId = null): array
    {
        $signings = $signings
            ->filter(fn (Activity $activity): bool => $activity->player_id !== null)
            ->sortBy([['occurred_at', 'asc'], ['id', 'asc']])
            ->values();
        $seasonId = $signings->first()->season_id ?? $seasonId ?? $this->seasonIdFor($date);
        $teams = DB::table('teams')->get()->keyBy('id');

        $buys = [];

        foreach ($signings as $signing) {
            $player = DB::table('players')->where('id', $signing->player_id)->first();
            $playerSeason = DB::table('player_seasons')->where('player_id', $player->id)->where('season_id', $seasonId)->first();
            $team = $teams[$player->team_id] ?? null;
            $history = $this->valueHistory((int) $player->id, $date);
            $last = $history->last();

            $buys[] = [
                'id' => $signing->id,
                'amount' => (int) $signing->amount,
                'buyer' => $signing->source_season_manager_id,
                'value' => $last !== null && substr((string) $last->date, 0, 10) === $date ? (int) $last->value : null,
                'hist' => $history->pluck('value')->map(fn (mixed $value): int => (int) $value)->all(),
                'valueDiff' => $history->count() >= 2 ? (int) $history[$history->count() - 1]->value - (int) $history[$history->count() - 2]->value : 0,
                'trend' => $playerSeason->market_trend ?? null,
                'player' => [
                    'id' => (int) $player->id,
                    'name' => $player->nickname,
                    'photo' => $this->photo($player->image),
                    'pos' => self::POSITION_CODES[$playerSeason->position ?? ''] ?? null,
                    'points' => $playerSeason !== null ? (int) $playerSeason->points : null,
                    'avg' => $playerSeason !== null ? (float) $playerSeason->average_points : null,
                    'team' => $team !== null ? ['short' => $team->short_name, 'name' => $team->main_name, 'logo' => '/storage/'.$team->logo] : null,
                ],
            ];
        }

        [, $dayEnd] = self::dayBounds($date);
        $managers = [];

        foreach (collect($buys)->pluck('buyer')->unique() as $managerId) {
            $manager = DB::table('season_managers')->where('id', $managerId)->first();
            $managers[$managerId] = [
                'id' => $managerId,
                'name' => trim((string) preg_replace('/\s+/', ' ', (string) $manager->name)),
                'logo' => '/'.$manager->logo,
                'color' => $manager->primary_color ?: '#c4ff3d',
                'position' => $manager->position,
                'total' => $manager->total_points,
                'seasonSignings' => DB::table('activities')
                    ->where('season_id', $seasonId)
                    ->where('type', 'signing')
                    ->where('source_season_manager_id', $managerId)
                    ->where('occurred_at', '<', $dayEnd)
                    ->count(),
            ];
        }

        return [
            'exportedAt' => now()->toIso8601String(),
            'date' => $date,
            'buys' => $buys,
            'managers' => (object) $managers,
            'daily' => $this->dailySpend($seasonId, $date),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function toJson(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The last 14 daily values up to the date, oldest first.
     *
     * @return Collection<int, object{date: string, value: int|string}>
     */
    private function valueHistory(int $playerId, string $date): Collection
    {
        /** @var Collection<int, object{date: string, value: int|string}> $history */
        $history = DB::table('player_markets')
            ->where('player_id', $playerId)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->limit(14)
            ->get(['date', 'value'])
            ->reverse()
            ->values();

        return $history;
    }

    private function photo(?string $image): ?string
    {
        if ($image === null || $image === '') {
            return null;
        }

        $file = storage_path('app/public/'.$image);

        return is_file($file) && md5_file($file) !== self::PLACEHOLDER_PHOTO_MD5 ? '/storage/'.$image : null;
    }

    /**
     * Every manager's signing count and spend per Madrid day, the 14 days up to the date (days without signings = 0).
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function dailySpend(?int $seasonId, string $date): array
    {
        $start = CarbonImmutable::parse($date, self::TIMEZONE)->subDays(13);
        [$windowStart] = self::dayBounds($start->toDateString());
        [, $windowEnd] = self::dayBounds($date);

        $perDay = DB::table('activities')
            ->where('season_id', $seasonId)
            ->where('type', 'signing')
            ->where('occurred_at', '>=', $windowStart)
            ->where('occurred_at', '<', $windowEnd)
            ->get(['occurred_at', 'amount'])
            ->groupBy(fn (object $row): string => CarbonImmutable::parse((string) $row->occurred_at, (string) config('app.timezone'))
                ->setTimezone(self::TIMEZONE)
                ->toDateString());

        $daily = [];

        for ($offset = 0; $offset < 14; $offset++) {
            $day = $start->addDays($offset)->toDateString();
            $rows = $perDay->get($day, collect());
            $daily[] = [$day, $rows->count(), (int) $rows->sum('amount')];
        }

        return $daily;
    }

    private function seasonIdFor(string $date): ?int
    {
        $season = Season::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        return $season?->id;
    }
}
