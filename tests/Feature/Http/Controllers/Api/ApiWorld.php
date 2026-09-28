<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\FixtureState;
use App\Enums\MarketTrend;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;

/**
 * One small but complete league for the API-wide tests (the max-bid guard
 * and the doc-drift test). Every nullable object the docs describe field by
 * field is non-null in at least one response. Jornada 1 is finished,
 * jornada 2 is the current one and hasn't kicked off, and jornada 3 comes after.
 *
 * - "Comando Lechuga" owns Pedri (FC Barcelona). He was signed 9 days ago
 *   for 45 M€ and is worth 50 M€, with 41 days of market history. He
 *   started jornada 1 (9 points), is in the manager's jornada 1 and 2
 *   lineups, and has an 85 % start probability for jornada 2.
 * - "Ariobretxa" owns Tsygankov (Girona FC). Tsygankov's clause is not
 *   locked, he is shielded, and he has a 60 % start probability. Ariobretxa
 *   bought him via clause from "Comando Lechuga" 15 days ago.
 * - Bellingham (Real Madrid) is free and listed on the league's market.
 */
final readonly class ApiWorld
{
    /** Pedri's jornada 1 breakdown: 2 + 5 − 1 + 1 + 2 = 9 points. */
    private const array OWNED_PLAYER_STATS = [
        'mins_played' => [80, 2],
        'goals' => [1, 5],
        'goal_assist' => [0, 0],
        'yellow_card' => [1, -1],
        'ball_recovery' => [5, 1],
        'marca_points' => [2, 2],
    ];

    private function __construct(
        public int $seasonId,
        public int $managerId,
        public int $rivalManagerId,
        public int $ownedPlayerId,
        public int $listedPlayerId,
        public int $rivalPlayerId,
        public int $finishedFixtureId,
        public int $nextFixtureId,
    ) {}

    public static function seed(): self
    {
        $season = Season::factory()->create([
            'start_date' => now()->subDays(60)->toDateString(),
            'end_date' => now()->addDays(200)->toDateString(),
            'total_weeks' => 38,
            'current_week' => 2,
        ]);

        $barcelona = Team::factory()->create(['main_name' => 'FC Barcelona']);
        $madrid = Team::factory()->create(['main_name' => 'Real Madrid']);
        $girona = Team::factory()->create(['main_name' => 'Girona FC']);
        $sevilla = Team::factory()->create(['main_name' => 'Sevilla FC']);
        $season->teams()->attach([$barcelona->id, $madrid->id, $girona->id, $sevilla->id]);

        $finished = Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 1,
            'team_local_id' => $barcelona->id,
            'team_guest_id' => $madrid->id,
            'local_score' => 2,
            'guest_score' => 1,
            'state' => FixtureState::Finished,
            'date' => now()->subDays(8),
            'display_clock' => "90'+4'",
            'local_formation' => '4-3-3',
            'guest_formation' => '4-4-2',
            'venue' => 'Estadi Olímpic Lluís Companys',
            'venue_city' => 'Barcelona',
            'attendance' => 48_000,
            'referee' => 'Mateu Lahoz',
            'local_possession' => 58.5,
            'guest_possession' => 41.5,
        ]);
        $otherFinished = Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 1,
            'team_local_id' => $girona->id,
            'team_guest_id' => $sevilla->id,
            'local_score' => 0,
            'guest_score' => 0,
            'state' => FixtureState::Finished,
            'date' => now()->subDays(8)->addHours(2),
        ]);
        $next = Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 2,
            'team_local_id' => $barcelona->id,
            'team_guest_id' => $girona->id,
            'state' => FixtureState::Scheduled,
            'date' => now()->addDays(3),
        ]);
        Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 2,
            'team_local_id' => $madrid->id,
            'team_guest_id' => $sevilla->id,
            'state' => FixtureState::Scheduled,
            'date' => now()->addDays(3)->addHours(2),
        ]);
        Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 3,
            'team_local_id' => $sevilla->id,
            'team_guest_id' => $barcelona->id,
            'state' => FixtureState::Scheduled,
            'date' => now()->addDays(10),
        ]);
        Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 3,
            'team_local_id' => $girona->id,
            'team_guest_id' => $madrid->id,
            'state' => FixtureState::Scheduled,
            'date' => now()->addDays(10)->addHours(2),
        ]);

        $owned = Player::factory()->create([
            'nickname' => 'Pedri',
            'status' => PlayerStatus::Ok,
            'team_id' => $barcelona->id,
            'position' => PlayerPosition::Midfield,
            'market_value' => 50_000_000,
            'market_value_difference' => 400_000,
            'market_trend' => MarketTrend::RiseSteady,
            'points' => 20,
            'average_points' => 10.0,
        ]);
        $listed = Player::factory()->create([
            'nickname' => 'Bellingham',
            'status' => PlayerStatus::Ok,
            'team_id' => $madrid->id,
            'position' => PlayerPosition::Midfield,
            'market_value' => 60_000_000,
            'market_value_difference' => -200_000,
            'market_trend' => MarketTrend::FallSteady,
            'points' => 15,
            'average_points' => 7.5,
        ]);
        $rivalPlayer = Player::factory()->create([
            'nickname' => 'Tsygankov',
            'status' => PlayerStatus::Ok,
            'team_id' => $girona->id,
            'position' => PlayerPosition::Striker,
            'market_value' => 12_000_000,
            'market_value_difference' => 100_000,
            'market_trend' => MarketTrend::PositiveInflection,
            'points' => 8,
            'average_points' => 4.0,
        ]);

        foreach (range(40, 0) as $daysAgo) {
            PlayerMarket::factory()->create([
                'player_id' => $owned->id,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'value' => 50_000_000 - $daysAgo * 250_000,
            ]);
        }

        FixtureLineup::factory()->create([
            'fixture_id' => $finished->id,
            'player_id' => $owned->id,
            'team_id' => $barcelona->id,
            'starter' => true,
            'position' => 'Center Midfielder',
            'subbed_out' => true,
            'sub_minute' => 80,
            'fantasy_points' => 9,
            'fantasy_stats' => self::OWNED_PLAYER_STATS,
        ]);
        FixtureLineup::factory()->create([
            'fixture_id' => $finished->id,
            'player_id' => $listed->id,
            'team_id' => $madrid->id,
            'starter' => true,
            'position' => 'Center Midfielder',
            'fantasy_points' => 5,
            'fantasy_stats' => ['mins_played' => [90, 2], 'goals' => [0, 0], 'marca_points' => [3, 3]],
        ]);
        FixtureLineup::factory()->create([
            'fixture_id' => $otherFinished->id,
            'player_id' => $rivalPlayer->id,
            'team_id' => $girona->id,
            'starter' => true,
            'position' => 'Forward',
            'fantasy_points' => 3,
            'fantasy_stats' => ['mins_played' => [70, 2], 'goals' => [0, 0], 'marca_points' => [1, 1]],
        ]);
        FixtureEvent::factory()->create([
            'fixture_id' => $finished->id,
            'team_id' => $barcelona->id,
            'player_id' => $owned->id,
            'type' => 'goal',
            'minute' => 30,
        ]);

        FixtureLineupProbability::factory()->onPitch(50, 40)->create([
            'fixture_id' => $next->id,
            'player_id' => $owned->id,
            'probability' => 85,
            'fetched_at' => now()->subHour(),
        ]);
        FixtureLineupProbability::factory()->create([
            'fixture_id' => $next->id,
            'player_id' => $rivalPlayer->id,
            'probability' => 60,
            'fetched_at' => now()->subHour(),
        ]);

        $manager = SeasonManager::factory()->create([
            'season_id' => $season->id,
            'name' => 'Comando Lechuga',
            'logo' => 'images/managers/1.png',
            'primary_color' => '#3d7dfd',
            'secondary_color' => '#0a0a0a',
            'position' => 1,
            'last_position' => 2,
            'total_points' => 60,
            'value' => 150_000_000,
        ]);
        $rivalManager = SeasonManager::factory()->create([
            'season_id' => $season->id,
            'name' => 'Ariobretxa',
            'logo' => 'images/managers/2.png',
            'primary_color' => '#ff0000',
            'secondary_color' => '#ffffff',
            'position' => 2,
            'last_position' => 1,
            'total_points' => 40,
            'value' => 120_000_000,
        ]);

        ManagerPlayer::factory()->create([
            'season_manager_id' => $manager->id,
            'player_id' => $owned->id,
            'buyout_clause' => 60_000_000,
            'buyout_clause_locked_until' => now()->addDays(5),
            'shielded' => false,
            'shielded_until' => null,
        ]);
        ManagerPlayer::factory()->create([
            'season_manager_id' => $rivalManager->id,
            'player_id' => $rivalPlayer->id,
            'buyout_clause' => 15_000_000,
            'buyout_clause_locked_until' => now()->subDay(),
            'shielded' => true,
            'shielded_until' => now()->addDays(2),
        ]);

        Activity::factory()->create([
            'season_id' => $season->id,
            'type' => SeasonActivityType::Signing,
            'source_season_manager_id' => $manager->id,
            'target_season_manager_id' => null,
            'player_id' => $owned->id,
            'amount' => 45_000_000,
            'week_number' => null,
            'occurred_at' => now()->subDays(9),
        ]);
        Activity::factory()->create([
            'season_id' => $season->id,
            'type' => SeasonActivityType::WeeklyPrize,
            'source_season_manager_id' => $manager->id,
            'target_season_manager_id' => null,
            'player_id' => null,
            'amount' => 6_000_000,
            'week_number' => 1,
            'occurred_at' => now()->subDays(7),
        ]);
        Activity::factory()->create([
            'season_id' => $season->id,
            'type' => SeasonActivityType::Buyout,
            'source_season_manager_id' => $rivalManager->id,
            'target_season_manager_id' => $manager->id,
            'player_id' => $rivalPlayer->id,
            'amount' => 15_000_000,
            'week_number' => null,
            'occurred_at' => now()->subDays(15),
        ]);

        MarketPlayer::factory()->create([
            'player_id' => $listed->id,
            'sale_price' => 60_000_000,
            'value' => 60_000_000,
            'bids' => 2,
            'expires_at' => now()->addHours(5),
        ]);

        $managerWeek1 = ManagerLineup::factory()->create([
            'season_manager_id' => $manager->id,
            'week_number' => 1,
            'points' => 60,
            'tactical_formation' => [4, 3, 3],
        ]);
        ManagerLineupPlayer::factory()->create([
            'manager_lineup_id' => $managerWeek1->id,
            'player_id' => $owned->id,
            'fixture_id' => $finished->id,
            'position' => PlayerPosition::Midfield,
            'points' => 9,
        ]);
        $rivalWeek1 = ManagerLineup::factory()->create([
            'season_manager_id' => $rivalManager->id,
            'week_number' => 1,
            'points' => 40,
            'tactical_formation' => [4, 4, 2],
        ]);
        ManagerLineupPlayer::factory()->create([
            'manager_lineup_id' => $rivalWeek1->id,
            'player_id' => $rivalPlayer->id,
            'fixture_id' => $otherFinished->id,
            'position' => PlayerPosition::Striker,
            'points' => 3,
        ]);
        $managerWeek2 = ManagerLineup::factory()->create([
            'season_manager_id' => $manager->id,
            'week_number' => 2,
            'points' => 0,
            'tactical_formation' => [4, 3, 3],
        ]);
        ManagerLineupPlayer::factory()->create([
            'manager_lineup_id' => $managerWeek2->id,
            'player_id' => $owned->id,
            'fixture_id' => null,
            'position' => PlayerPosition::Midfield,
            'points' => null,
        ]);

        return new self(
            seasonId: $season->id,
            managerId: $manager->id,
            rivalManagerId: $rivalManager->id,
            ownedPlayerId: $owned->id,
            listedPlayerId: $listed->id,
            rivalPlayerId: $rivalPlayer->id,
            finishedFixtureId: $finished->id,
            nextFixtureId: $next->id,
        );
    }
}
