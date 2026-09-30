<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeasonPrize;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\BanquilloDeOro;
use App\Services\Prizes\ElAtracador;
use App\Services\Prizes\ElCriminal;
use App\Services\Prizes\ElPupas;
use App\Services\Prizes\FichajeDelPueblo;
use App\Services\Prizes\LaVictima;
use App\Services\Prizes\Matrimonio;
use App\Services\Prizes\NocheMagica;
use App\Services\Prizes\PrizeCalculator;
use App\Services\Prizes\PrizeRanking;
use App\Services\Prizes\PrizeRow;
use App\Services\Prizes\ReyDelDomingo;
use Illuminate\Support\Facades\Cache;

/**
 * The current standing of every season-end prize, cached for 10 minutes
 * and forgotten after the syncs that change it (ForgetSeasonPrizeStandings).
 *
 * @phpstan-import-type PuebloCandidate from FichajeDelPueblo
 *
 * @phpstan-type PrizeStandingRow array{season_manager_id: int, place: int|null, value: int|float|null, context: array<string, mixed>}
 * @phpstan-type PrizeStanding array{key: string, name: string, amount: int, rule: string, decided: bool, leaders: list<int>, shares: array<int, float>, rows: list<PrizeStandingRow>, candidates: list<PuebloCandidate>}
 * @phpstan-type PrizePlayer array{id: int, nickname: string, image: string}
 */
final class SeasonPrizeStandings
{
    public const int CACHE_MINUTES = 10;

    /**
     * @return array{prizes: list<PrizeStanding>, players: array<int, PrizePlayer>}
     */
    public function forSeason(Season $season): array
    {
        /** @var array{prizes: list<PrizeStanding>, players: array<int, PrizePlayer>} */
        return Cache::remember(self::cacheKey($season), now()->addMinutes(self::CACHE_MINUTES), fn (): array => $this->build($season));
    }

    public static function cacheKey(Season $season): string
    {
        return "season-prizes:{$season->id}";
    }

    public static function forget(Season $season): void
    {
        Cache::forget(self::cacheKey($season));
    }

    /**
     * @return array{prizes: list<PrizeStanding>, players: array<int, PrizePlayer>}
     */
    private function build(Season $season): array
    {
        /** @var array<int, int> $positions */
        $positions = SeasonManager::query()->where('season_id', $season->id)->pluck('position', 'id')->all();
        $prizes = [];

        foreach (SeasonPrize::cases() as $prize) {
            $calculator = $this->calculator($prize);
            $candidates = [];

            if ($calculator === null) {
                $ranked = [];
                $leaders = [];
            } elseif ($calculator instanceof FichajeDelPueblo) {
                $candidates = $calculator->candidates($season);
                $winners = array_values(array_unique(array_merge([], ...array_column($candidates, 'winners'))));
                $ranked = $this->rankAfterWinners($calculator->rows($season), $winners, $positions);
                $leaders = array_values(array_intersect(
                    array_map(fn (array $entry): int => $entry['row']->seasonManagerId, $ranked),
                    $winners,
                ));
            } else {
                $ranked = PrizeRanking::rank($calculator->rows($season), $positions);
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
     * The Fichaje del Pueblo's leaders are every tied player's own winner,
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
            SeasonPrize::NocheMagica => NocheMagica::class,
            SeasonPrize::ElAtracador => ElAtracador::class,
            SeasonPrize::ReyDelDomingo => ReyDelDomingo::class,
            SeasonPrize::BanquilloDeOro => BanquilloDeOro::class,
            SeasonPrize::ElCriminal => ElCriminal::class,
            SeasonPrize::LaVictima => LaVictima::class,
            SeasonPrize::ElPupas => ElPupas::class,
            SeasonPrize::Matrimonio => Matrimonio::class,
            SeasonPrize::FichajeDelPueblo => FichajeDelPueblo::class,
            SeasonPrize::HuecoLibre => null,
        };

        return $class === null ? null : app($class);
    }

    /**
     * Every player a prize refers to (Matrimonio, Criminal, Banquillo,
     * Fichaje del Pueblo), by id, in the shape the page draws.
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
