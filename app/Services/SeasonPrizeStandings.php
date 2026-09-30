<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeasonPrize;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\BenchPoints;
use App\Services\Prizes\BestNight;
use App\Services\Prizes\LongestPartnership;
use App\Services\Prizes\MostBuyoutsMade;
use App\Services\Prizes\MostBuyoutsSuffered;
use App\Services\Prizes\MostOverpaid;
use App\Services\Prizes\MostOwnedPlayer;
use App\Services\Prizes\PrizeCalculator;
use App\Services\Prizes\PrizeDataFingerprint;
use App\Services\Prizes\PrizeRanking;
use App\Services\Prizes\PrizeRow;
use App\Services\Prizes\SquadHistory;
use App\Services\Prizes\SundayKing;
use App\Services\Prizes\WorstWeeks;
use Illuminate\Support\Facades\Cache;

/**
 * The current standing of every season-end prize, cached under a
 * fingerprint of the data the prizes read (PrizeDataFingerprint): any change
 * to it misses the cache. The TTL is only a safety net for what the
 * fingerprint leaves out (player names and photos).
 *
 * @phpstan-import-type OwnedPlayerCandidate from MostOwnedPlayer
 *
 * @phpstan-type PrizeStandingRow array{season_manager_id: int, place: int|null, value: int|float|null, context: array<string, mixed>}
 * @phpstan-type PrizeStanding array{key: string, name: string, amount: int, rule: string, decided: bool, leaders: list<int>, shares: array<int, float>, rows: list<PrizeStandingRow>, candidates: list<OwnedPlayerCandidate>}
 * @phpstan-type PrizePlayer array{id: int, nickname: string, image: string}
 */
final class SeasonPrizeStandings
{
    public const int CACHE_MINUTES = 60;

    public function __construct(private readonly PrizeDataFingerprint $fingerprint) {}

    /**
     * @return array{prizes: list<PrizeStanding>, players: array<int, PrizePlayer>}
     */
    public function forSeason(Season $season): array
    {
        /** @var array{prizes: list<PrizeStanding>, players: array<int, PrizePlayer>} */
        return Cache::remember($this->cacheKey($season), now()->addMinutes(self::CACHE_MINUTES), fn (): array => $this->build($season));
    }

    public function cacheKey(Season $season): string
    {
        return "season-prizes:{$season->id}:{$this->fingerprint->forSeason($season)}";
    }

    /**
     * @return array{prizes: list<PrizeStanding>, players: array<int, PrizePlayer>}
     */
    private function build(Season $season): array
    {
        /** @var array<int, int> $positions */
        $positions = SeasonManager::query()->where('season_id', $season->id)->pluck('position', 'id')->all();
        $history = SquadHistory::forSeason($season);
        $prizes = [];

        foreach (SeasonPrize::cases() as $prize) {
            $calculator = $this->calculator($prize);
            $candidates = [];

            if ($calculator === null) {
                $ranked = [];
                $leaders = [];
            } elseif ($calculator instanceof MostOwnedPlayer) {
                $candidates = $calculator->candidates($season, $history);
                $winners = array_values(array_unique(array_merge([], ...array_column($candidates, 'winners'))));
                $ranked = $this->rankAfterWinners($calculator->rowsFor($season, $candidates), $winners, $positions);
                $leaders = array_values(array_intersect(
                    array_map(fn (array $entry): int => $entry['row']->seasonManagerId, $ranked),
                    $winners,
                ));
            } else {
                $rows = $calculator instanceof BenchPoints ? $calculator->rows($season, $history) : $calculator->rows($season);
                $ranked = PrizeRanking::rank($rows, $positions);
                $leaders = PrizeRanking::leaders($ranked);
            }

            $prizes[] = [
                'key' => $prize->value,
                'name' => $prize->label(),
                'amount' => $prize->amount(),
                'rule' => $prize->rule(),
                'decided' => $prize->isDecided(),
                'leaders' => $leaders,
                'shares' => PrizeRanking::shares($prize, $leaders),
                'rows' => array_map(fn (array $entry): array => [
                    'season_manager_id' => $entry['row']->seasonManagerId,
                    'place' => $entry['place'],
                    'value' => $entry['row']->value,
                    'context' => $entry['row']->context,
                ], $ranked),
                'candidates' => $candidates,
            ];
        }

        return ['prizes' => $prizes, 'players' => $this->players($prizes)];
    }

    /**
     * The MostOwnedPlayer leaders are every tied player's own winner,
     * not the highest value: they go first with place 1 and the rest are
     * ranked after them.
     *
     * @param  list<PrizeRow>  $rows
     * @param  list<int>  $winners
     * @param  array<int, int>  $positions
     * @return list<array{row: PrizeRow, place: int|null}>
     */
    private function rankAfterWinners(array $rows, array $winners, array $positions): array
    {
        $winning = array_values(array_filter($rows, fn (PrizeRow $row): bool => in_array($row->seasonManagerId, $winners, true)));
        $others = array_values(array_filter($rows, fn (PrizeRow $row): bool => !in_array($row->seasonManagerId, $winners, true)));

        return [
            ...array_map(fn (array $entry): array => ['row' => $entry['row'], 'place' => 1], PrizeRanking::rank($winning, $positions)),
            ...array_map(fn (array $entry): array => [
                'row' => $entry['row'],
                'place' => $entry['place'] === null ? null : $entry['place'] + count($winning),
            ], PrizeRanking::rank($others, $positions)),
        ];
    }

    private function calculator(SeasonPrize $prize): ?PrizeCalculator
    {
        $class = match ($prize) {
            SeasonPrize::BestNight => BestNight::class,
            SeasonPrize::MostBuyoutsMade => MostBuyoutsMade::class,
            SeasonPrize::SundayKing => SundayKing::class,
            SeasonPrize::BenchPoints => BenchPoints::class,
            SeasonPrize::MostOverpaid => MostOverpaid::class,
            SeasonPrize::MostBuyoutsSuffered => MostBuyoutsSuffered::class,
            SeasonPrize::WorstWeeks => WorstWeeks::class,
            SeasonPrize::LongestPartnership => LongestPartnership::class,
            SeasonPrize::MostOwnedPlayer => MostOwnedPlayer::class,
            SeasonPrize::OpenSlot => null,
        };

        return $class === null ? null : app($class);
    }

    /**
     * Every player a prize refers to (LongestPartnership, MostOverpaid, BenchPoints,
     * MostOwnedPlayer), by id, in the shape the page draws.
     *
     * @param  list<PrizeStanding>  $prizes
     * @return array<int, PrizePlayer>
     */
    private function players(array $prizes): array
    {
        $ids = [];

        foreach ($prizes as $prize) {
            foreach ($prize['rows'] as $row) {
                $context = $row['context'];
                $ids[] = $context['player_id'] ?? null;
                $ids[] = $context['worst']['player_id'] ?? null;
                $ids[] = $context['top_miss']['player_id'] ?? null;
            }

            foreach ($prize['candidates'] as $candidate) {
                $ids[] = $candidate['player_id'];
            }
        }

        return Player::query()
            ->whereIn('id', array_unique(array_filter($ids)))
            ->get(['id', 'nickname', 'image'])
            ->mapWithKeys(fn (Player $player): array => [$player->id => [
                'id' => $player->id,
                'nickname' => $player->nickname,
                'image' => $player->image,
            ]])
            ->all();
    }
}
