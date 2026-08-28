<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * Keys that belong to PlayerSeason, not Player — intercepted in create()
     * below so existing call sites like Player::factory()->create(['points' => 90])
     * keep working after the Player/PlayerSeason split.
     *
     * @var list<string>
     */
    private const array SEASON_KEYS = ['position', 'market_value', 'market_value_difference', 'points', 'average_points'];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fantasy_id' => $this->faker->unique()->numberBetween(1, 99999),
            'nickname' => $this->faker->name(),
            'status' => $this->faker->randomElement(PlayerStatus::cases()),
            'image' => '',
            'team_id' => Team::factory(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, Player>|Player
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        $seasonAttributes = array_intersect_key($attributes, array_flip(self::SEASON_KEYS));
        $playerAttributes = array_diff_key($attributes, $seasonAttributes);

        // Persist via make()+store()+callAfterCreating() — mirroring Factory::create()'s
        // own terminal branch — rather than calling parent::create($playerAttributes, $parent)
        // directly. Laravel's base create() re-enters create() (via state()->create()) whenever
        // $attributes is non-empty, and since state()/newInstance() construct a new PlayerFactory
        // instance, that re-entrant call still resolves to *this* override. Left uncorrected, the
        // PlayerSeason-routing logic below would then run twice per factory call — once inside the
        // re-entrant call and once here — inserting two PlayerSeason rows for the same player+season
        // and failing the player_seasons unique constraint. make() is not overridden here, so calling
        // it directly avoids the bounce back into this method.
        $made = $this->make($playerAttributes, $parent);

        /** @var Collection<int, Player> $players */
        $players = $made instanceof Model ? new Collection([$made]) : $made;

        $this->store($players);
        $this->callAfterCreating($players, $parent);

        $result = $made;

        $season = Season::query()
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->first() ?? Season::factory()->create([
                'start_date' => now()->subDay(),
                'end_date' => now()->addDay(),
            ]);

        foreach ($players as $player) {
            $playerSeason = PlayerSeason::factory()->for($player)->for($season)->create($seasonAttributes);

            $player->position = $playerSeason->position;
            $player->market_value = $playerSeason->market_value;
            $player->market_value_difference = $playerSeason->market_value_difference;
            $player->points = $playerSeason->points;
            $player->average_points = $playerSeason->average_points;

            // Mark the mirrored PlayerSeason attributes as not dirty: they were set on
            // an already-persisted Player instance and would otherwise show up in
            // getDirty(), causing a later ->save()/->update() on this instance to try
            // writing them back as real columns (which no longer exist on `players`).
            $player->syncOriginal();
        }

        return $result;
    }
}
