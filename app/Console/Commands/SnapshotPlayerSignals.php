<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DifficultyVariant;
use App\Enums\PlayerStatus;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerDailySignal;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Services\MatchDifficulty;
use App\Services\StartProbabilities;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('season:snapshot-player-signals')]
#[Description('Store today\'s status, next-match start probability, next rival difficulty and market listing of every league player')]
class SnapshotPlayerSignals extends Command
{
    public function handle(StartProbabilities $startProbabilities, MatchDifficulty $matchDifficulty): int
    {
        $season = Season::current();
        $players = Player::query()
            ->whereIn('team_id', $season->teams()->select('teams.id'))
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->with('team')
            ->get();

        if ($players->isEmpty()) {
            $this->info('No hay jugadores que guardar.');

            return self::SUCCESS;
        }

        $positions = PlayerSeason::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', $players->modelKeys())
            ->get(['player_id', 'position'])
            ->mapWithKeys(fn (PlayerSeason $playerSeason): array => [$playerSeason->player_id => $playerSeason->position]);
        $nextByTeam = $startProbabilities->nextFixtures($season, array_values(array_unique($players->pluck('team_id')->all())));
        $starts = $startProbabilities->forPlayersNextFixture($players, $season);

        $items = [];
        $itemPlayerIds = [];

        foreach ($players as $player) {
            $fixture = $nextByTeam[$player->team_id] ?? null;

            if ($fixture !== null) {
                $items[] = [$fixture, $player->team_id, DifficultyVariant::forPosition($positions[$player->id] ?? null)];
                $itemPlayerIds[] = $player->id;
            }
        }

        $difficulties = $items === [] ? [] : array_combine($itemPlayerIds, $matchDifficulty->forMany($items));
        $listed = MarketPlayer::query()->whereIn('player_id', $players->modelKeys())->pluck('player_id')->flip();
        $today = now()->toDateString();
        $now = now();

        $rows = $players->map(fn (Player $player): array => [
            'season_id' => $season->id,
            'player_id' => $player->id,
            'date' => $today,
            'status' => $player->status->value,
            'next_fixture_id' => ($nextByTeam[$player->team_id] ?? null)?->id,
            'start_probability' => $starts[$player->id]['probability'] ?? null,
            'predicted_starter' => $starts[$player->id]['predicted_starter'] ?? false,
            'confirmed_starter' => $starts[$player->id]['confirmed_starter'] ?? null,
            'next_difficulty' => ($difficulties[$player->id] ?? null)?->difficulty,
            'listed' => $listed->has($player->id),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        PlayerDailySignal::query()->upsert(
            $rows,
            ['player_id', 'date'],
            ['season_id', 'status', 'next_fixture_id', 'start_probability', 'predicted_starter', 'confirmed_starter', 'next_difficulty', 'listed', 'updated_at'],
        );

        $this->info(count($rows).' jugadores guardados para el '.$today.'.');

        return self::SUCCESS;
    }
}
