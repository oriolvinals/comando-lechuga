<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->season = Season::factory()->create(['start_date' => now()->subMonths(2), 'end_date' => now()->addMonths(8)]);
    $this->madrid = Team::factory()->create(['main_name' => 'Real Madrid', 'short_name' => 'RMA']);
    $this->villarreal = Team::factory()->create(['main_name' => 'Villarreal CF', 'short_name' => 'VIL']);
});

function rankingsFixture(object $test, int $week, FixtureState $state = FixtureState::Finished, ?Team $local = null, ?Team $guest = null): Fixture
{
    return Fixture::factory()->create([
        'season_id' => $test->season->id,
        'week_number' => $week,
        'state' => $state,
        'team_local_id' => ($local ?? $test->madrid)->id,
        'team_guest_id' => ($guest ?? $test->villarreal)->id,
        'local_score' => $state === FixtureState::Scheduled ? null : 2,
        'guest_score' => $state === FixtureState::Scheduled ? null : 1,
        'date' => now()->subWeeks(10 - $week),
    ]);
}

function rankingsPlayer(Team $team, string $nickname, PlayerPosition $position): Player
{
    return Player::factory()->create(['team_id' => $team->id, 'nickname' => $nickname, 'position' => $position, 'status' => PlayerStatus::Ok]);
}

/**
 * @param  array<string, int>  $stats  fantasy stat key => value
 */
function rankingsLineup(Player $player, Fixture $fixture, array $stats): FixtureLineup
{
    return FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $player->team_id,
        'fantasy_stats' => array_map(fn (int $value): array => [$value, 0], ['mins_played' => 90, ...$stats]),
    ]);
}

function lineUp(SeasonManager $manager, Player $player, int $week): void
{
    $lineup = ManagerLineup::query()->firstOrCreate(
        ['season_manager_id' => $manager->id, 'week_number' => $week],
        ['tactical_formation' => [4, 4, 2], 'points' => 0],
    );
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id]);
}

test('sums each player\'s season and ranks every action\'s top 5, ties by fewer matches then name', function (): void {
    $week1 = rankingsFixture($this, 1);
    $week2 = rankingsFixture($this, 2);
    $vinicius = rankingsPlayer($this->madrid, 'Vini Jr.', PlayerPosition::Striker);
    $mbappe = rankingsPlayer($this->madrid, 'Mbappé', PlayerPosition::Striker);
    $arda = rankingsPlayer($this->madrid, 'Arda Güler', PlayerPosition::Midfield);
    $courtois = rankingsPlayer($this->madrid, 'Courtois', PlayerPosition::Goalkeeper);
    rankingsLineup($vinicius, $week1, ['goals' => 1]);
    rankingsLineup($vinicius, $week2, ['goals' => 1]);
    rankingsLineup($mbappe, $week1, ['goals' => 2]);
    rankingsLineup($arda, $week2, ['goals' => 2]);
    rankingsLineup($courtois, $week1, ['saves' => 4]);

    $this->get(route('players.rankings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('players/rankings')
            ->where('stat', null)
            ->where('weeksPlayed', 2)
            ->where('stats.0', ['key' => 'goals', 'bad' => false])
            ->where('stats.13', ['key' => 'goals_conceded', 'bad' => true])
            ->where('overview.0.stat', 'goals')
            ->where('overview.0.count', 3)
            ->where('overview.0.rows', fn ($rows): bool => collect($rows)->map(fn (array $row): array => [$row['rank'], $row['player']['nickname'], $row['value'], $row['matches']])->all() === [
                [1, 'Arda Güler', 2, 1],
                [1, 'Mbappé', 2, 1],
                [1, 'Vini Jr.', 2, 2],
            ])
            ->where('overview.11.stat', 'saves')
            ->where('overview.11.rows.0.player.nickname', 'Courtois')
        );
});

test('ranks DAZN\'s official points, only of the fixtures whose ratings are published', function (): void {
    $published = rankingsFixture($this, 1);
    $published->update(['dazn_published' => true]);
    $pending = rankingsFixture($this, 2);
    $mbappe = rankingsPlayer($this->madrid, 'Mbappé', PlayerPosition::Striker);
    FixtureLineup::factory()->create(['fixture_id' => $published->id, 'player_id' => $mbappe->id, 'team_id' => $this->madrid->id, 'fantasy_stats' => ['mins_played' => [90, 2], 'marca_points' => [-1, 3]]]);
    FixtureLineup::factory()->create(['fixture_id' => $pending->id, 'player_id' => $mbappe->id, 'team_id' => $this->madrid->id, 'fantasy_stats' => ['mins_played' => [90, 2], 'marca_points' => [-1, 4]]]);

    $this->get(route('players.rankings', ['stat' => 'marca_points']))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('ranking.0.player.nickname', 'Mbappé')
            ->where('ranking.0.value', 3)
        );
});

test('ranks a single jornada when one is chosen', function (): void {
    $week1 = rankingsFixture($this, 1);
    $week2 = rankingsFixture($this, 2);
    $mbappe = rankingsPlayer($this->madrid, 'Mbappé', PlayerPosition::Striker);
    $vinicius = rankingsPlayer($this->madrid, 'Vini Jr.', PlayerPosition::Striker);
    rankingsLineup($mbappe, $week1, ['goals' => 3]);
    rankingsLineup($vinicius, $week2, ['goals' => 1]);

    $this->get(route('players.rankings', ['stat' => 'goals', 'jornada' => 2]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('filters.week', 2)
            ->has('ranking', 1)
            ->where('ranking.0.player.nickname', 'Vini Jr.')
        );

    $this->get(route('players.rankings', ['stat' => 'goals', 'jornada' => 9]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('filters.week', null)
            ->has('ranking', 2)
        );
});

test('ranks one action for the chosen positions, every player with a total above zero', function (): void {
    $fixture = rankingsFixture($this, 1);
    $militao = rankingsPlayer($this->madrid, 'Militão', PlayerPosition::Defender);
    $valverde = rankingsPlayer($this->madrid, 'Valverde', PlayerPosition::Midfield);
    $mbappe = rankingsPlayer($this->madrid, 'Mbappé', PlayerPosition::Striker);
    rankingsLineup($militao, $fixture, ['effective_clearance' => 7]);
    rankingsLineup($valverde, $fixture, ['effective_clearance' => 3]);
    rankingsLineup($mbappe, $fixture, ['effective_clearance' => 1]);
    rankingsLineup(rankingsPlayer($this->madrid, 'Rüdiger', PlayerPosition::Defender), $fixture, []);

    $this->get(route('players.rankings', ['stat' => 'effective_clearance', 'position' => 'defender,midfield']))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('stat', 'effective_clearance')
            ->where('overview', null)
            ->where('ranking', fn ($rows): bool => collect($rows)->pluck('player.nickname')->all() === ['Militão', 'Valverde'])
            ->where('filters.position', ['defender', 'midfield'])
        );
});

test('for one manager, counts only the matches he lined each player up for, and lists only those players', function (): void {
    $week1 = rankingsFixture($this, 1);
    $week2 = rankingsFixture($this, 2);
    $manager = SeasonManager::factory()->create(['season_id' => $this->season->id]);
    $mbappe = rankingsPlayer($this->madrid, 'Mbappé', PlayerPosition::Striker);
    $vinicius = rankingsPlayer($this->madrid, 'Vini Jr.', PlayerPosition::Striker);
    rankingsLineup($mbappe, $week1, ['goals' => 2]);
    rankingsLineup($mbappe, $week2, ['goals' => 1]);
    rankingsLineup($vinicius, $week1, ['goals' => 3]);
    lineUp($manager, $mbappe, 2);

    $this->get(route('players.rankings', ['stat' => 'goals', 'season_manager' => $manager->id]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('filters.seasonManager', $manager->id)
            ->has('ranking', 1)
            ->where('ranking.0.player.nickname', 'Mbappé')
            ->where('ranking.0.value', 1)
            ->where('ranking.0.matches', 1)
        );
});

test('tells who lined each ranked player up this season', function (): void {
    $fixture = rankingsFixture($this, 1);
    $alpha = SeasonManager::factory()->create(['season_id' => $this->season->id, 'name' => 'Alpha FC']);
    $beta = SeasonManager::factory()->create(['season_id' => $this->season->id, 'name' => 'Beta FC']);
    $mbappe = rankingsPlayer($this->madrid, 'Mbappé', PlayerPosition::Striker);
    rankingsLineup($mbappe, $fixture, ['goals' => 1]);
    lineUp($beta, $mbappe, 1);
    lineUp($alpha, $mbappe, 2);

    $this->get(route('players.rankings', ['stat' => 'goals']))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('ranking.0.lined_up_by', fn ($managers): bool => collect($managers)->pluck('name')->all() === ['Alpha FC', 'Beta FC'])
        );
});

test('breaks a player\'s action down by match, with the matches he missed and who lined him up', function (): void {
    $week1 = rankingsFixture($this, 1);
    $week2 = rankingsFixture($this, 2, local: $this->villarreal, guest: $this->madrid);
    rankingsFixture($this, 3, FixtureState::Scheduled);
    $manager = SeasonManager::factory()->create(['season_id' => $this->season->id, 'name' => 'Lined FC']);
    $militao = rankingsPlayer($this->madrid, 'Militão', PlayerPosition::Defender);
    rankingsLineup($militao, $week1, ['effective_clearance' => 6]);
    lineUp($manager, $militao, 1);

    $this->get(route('players.rankings', ['stat' => 'effective_clearance', 'player' => $militao->id]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('breakdown.player_id', $militao->id)
            ->has('breakdown.matches', 2)
            ->where('breakdown.matches.0.week_number', 1)
            ->where('breakdown.matches.0.value', 6)
            ->where('breakdown.matches.0.is_home', true)
            ->where('breakdown.matches.0.rival.short_name', 'VIL')
            ->where('breakdown.matches.0.played', true)
            ->where('breakdown.matches.0.lined_up_by.0.name', 'Lined FC')
            ->where('breakdown.matches.1.week_number', 2)
            ->where('breakdown.matches.1.value', 0)
            ->where('breakdown.matches.1.is_home', false)
            ->where('breakdown.matches.1.played', false)
            ->where('breakdown.matches.1.lined_up_by', [])
        );
});
