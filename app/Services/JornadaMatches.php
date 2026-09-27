<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The "Ahora" block's matches strip on Inicio: a handful of the current
 * jornada's matches and, under each one, the managers whose jornada LINEUP
 * (not squad) has players in it, with their points in that match.
 *
 * - `not_started`: no match has kicked off — the first 3 by kickoff, and no
 *   managers yet (lineups only make sense once the jornada starts).
 * - `live`: every match being played right now, with live points.
 * - `started`: nothing on right now — the last finished match (final
 *   points) and the next 2 to play (managers without points). When fewer
 *   than 2 are left to play, earlier finished matches fill the 3 slots.
 * - `finished`: every match played, `current_week` not advanced yet — the
 *   last 3 results.
 *
 * @phpstan-type JornadaTeam array{id: int, short_name: string, logo: string}
 * @phpstan-type JornadaPlayer array{id: int, nickname: string, image: string, position: string, points: int|null}
 * @phpstan-type JornadaManager array{id: int, name: string, primary_color: string|null, points: int|null, players: list<JornadaPlayer>}
 * @phpstan-type JornadaMatch array{id: int, state: string, date: string, display_clock: string|null, local_score: int|null, guest_score: int|null, local_team: JornadaTeam, guest_team: JornadaTeam, managers: list<JornadaManager>|null}
 */
class JornadaMatches
{
    public const int MATCH_COUNT = 3;

    public const int UPCOMING_WHILE_STARTED = 2;

    private const array POSITION_ORDER = [
        PlayerPosition::Goalkeeper->value => 0,
        PlayerPosition::Defender->value => 1,
        PlayerPosition::Midfield->value => 2,
        PlayerPosition::Striker->value => 3,
        PlayerPosition::Coach->value => 4,
    ];

    /**
     * @return array{week: int, status: 'not_started'|'started'|'live'|'finished', matches: list<JornadaMatch>}
     */
    public function forSeason(Season $season): array
    {
        $week = $season->current_week;

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', $week)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $live = $fixtures->filter(fn (Fixture $fixture): bool => in_array($fixture->state, LeagueStandings::LIVE_STATES, true))->values();
        $finished = $fixtures->where('state', FixtureState::Finished)->values();
        $scheduled = $fixtures->where('state', FixtureState::Scheduled)->values();

        [$status, $shown] = match (true) {
            $live->isNotEmpty() => ['live', $live],
            $finished->isEmpty() => ['not_started', $scheduled->take(self::MATCH_COUNT)],
            $scheduled->isEmpty() => ['finished', $finished->take(-self::MATCH_COUNT)],
            default => ['started', $this->lastAndNext($finished, $scheduled)],
        };

        $managersByFixture = $status === 'not_started'
            ? null
            : $this->managersByFixture($season->id, $week, $fixtures, $shown);

        return [
            'week' => $week,
            'status' => $status,
            'matches' => array_values($shown
                ->map(fn (Fixture $fixture): array => $this->presentFixture(
                    $fixture,
                    $managersByFixture === null ? null : ($managersByFixture[$fixture->id] ?? []),
                ))
                ->all()),
        ];
    }

    /**
     * The last finished match(es) plus the next ones to play, 3 in total.
     *
     * @param  Collection<int, Fixture>  $finished
     * @param  Collection<int, Fixture>  $scheduled
     * @return Collection<int, Fixture>
     */
    private function lastAndNext(Collection $finished, Collection $scheduled): Collection
    {
        $upcoming = $scheduled->take(self::UPCOMING_WHILE_STARTED);

        return $finished->take(-(self::MATCH_COUNT - $upcoming->count()))->concat($upcoming)->values();
    }

    /**
     * Each shown fixture's managers, from the jornada's manager lineups: a
     * lineup player belongs to the fixture its `fixture_id` points at, or —
     * when that isn't resolved yet (it's only set once the real match
     * lineup exists) — the week's fixture of the player's team. Also backs
     * the match page's fantasy scoreboard (FixtureFantasyScoreboard).
     *
     * @param  EloquentCollection<int, Fixture>  $weekFixtures  Every fixture of the week.
     * @param  Collection<int, Fixture>  $shown  The fixtures to report managers for.
     * @return array<int, list<JornadaManager>>
     */
    public function managersByFixture(int $seasonId, int $week, EloquentCollection $weekFixtures, Collection $shown): array
    {
        $lineups = ManagerLineup::query()
            ->where('week_number', $week)
            ->whereIn('season_manager_id', SeasonManager::query()->select('id')->where('season_id', $seasonId))
            ->with(['seasonManager:id,name,primary_color', 'players.player:id,nickname,image,team_id'])
            ->get();

        $fixturesById = $weekFixtures->keyBy('id');
        $fixtureIdByTeam = $weekFixtures->reduce(function (array $carry, Fixture $fixture): array {
            $carry[$fixture->team_local_id] = $fixture->id;
            $carry[$fixture->team_guest_id] = $fixture->id;

            return $carry;
        }, []);
        $shownIds = array_values($shown->map(fn (Fixture $fixture): int => $fixture->id)->all());

        $fantasyPoints = $this->fantasyPoints($lineups, $shownIds);

        /** @var array<int, array<int, JornadaManager>> $managers */
        $managers = [];

        foreach ($lineups as $lineup) {
            foreach ($lineup->players as $entry) {
                $fixtureId = $entry->fixture_id !== null && $fixturesById->has($entry->fixture_id)
                    ? $entry->fixture_id
                    : ($fixtureIdByTeam[$entry->player->team_id] ?? null);

                if ($fixtureId === null || !in_array($fixtureId, $shownIds, true)) {
                    continue;
                }

                /** @var Fixture $fixture */
                $fixture = $fixturesById->get($fixtureId);
                $hasPoints = $fixture->state !== FixtureState::Scheduled;
                $manager = $lineup->seasonManager;

                $managers[$fixtureId][$manager->id] ??= [
                    'id' => $manager->id,
                    'name' => $manager->name,
                    'primary_color' => $manager->primary_color,
                    'points' => $hasPoints ? 0 : null,
                    'players' => [],
                ];

                $points = $hasPoints
                    ? ($fantasyPoints["{$fixtureId}-{$entry->player_id}"] ?? $entry->points ?? 0)
                    : null;

                $managers[$fixtureId][$manager->id]['players'][] = $this->presentPlayer($entry, $points);

                if ($points !== null) {
                    $managers[$fixtureId][$manager->id]['points'] += $points;
                }
            }
        }

        return array_map(fn (array $fixtureManagers): array => $this->sortManagers($fixtureManagers), $managers);
    }

    /**
     * The live/final fantasy points of every lineup player in the shown
     * fixtures, keyed by "fixture-player" — one bulk lookup rather than
     * the lazy-only `ManagerLineupPlayer::fixtureLineup()` relation.
     *
     * @param  EloquentCollection<int, ManagerLineup>  $lineups
     * @param  list<int>  $fixtureIds
     * @return array<string, int>
     */
    private function fantasyPoints(EloquentCollection $lineups, array $fixtureIds): array
    {
        $playerIds = $lineups->flatMap(fn (ManagerLineup $lineup) => $lineup->players->pluck('player_id'))->unique();

        if ($playerIds->isEmpty() || $fixtureIds === []) {
            return [];
        }

        return FixtureLineup::query()
            ->whereIn('fixture_id', $fixtureIds)
            ->whereIn('player_id', $playerIds)
            ->whereNotNull('fantasy_points')
            ->get(['fixture_id', 'player_id', 'fantasy_points'])
            ->mapWithKeys(fn (FixtureLineup $row): array => ["{$row->fixture_id}-{$row->player_id}" => (int) $row->fantasy_points])
            ->all();
    }

    /**
     * Managers by points in the match, then by how many players they have
     * in it, then by name; each one's players by points, then position.
     *
     * @param  array<int, JornadaManager>  $managers
     * @return list<JornadaManager>
     */
    private function sortManagers(array $managers): array
    {
        $managers = array_map(function (array $manager): array {
            usort($manager['players'], fn (array $a, array $b): int => ($b['points'] ?? 0) <=> ($a['points'] ?? 0)
                ?: self::POSITION_ORDER[$a['position']] <=> self::POSITION_ORDER[$b['position']]
                ?: $a['nickname'] <=> $b['nickname']);

            return $manager;
        }, array_values($managers));

        usort($managers, fn (array $a, array $b): int => ($b['points'] ?? 0) <=> ($a['points'] ?? 0)
            ?: count($b['players']) <=> count($a['players'])
            ?: $a['name'] <=> $b['name']);

        return $managers;
    }

    /**
     * @param  list<JornadaManager>|null  $managers
     * @return JornadaMatch
     */
    private function presentFixture(Fixture $fixture, ?array $managers): array
    {
        return [
            'id' => $fixture->id,
            'state' => $fixture->state->value,
            'date' => $fixture->date->toJSON(),
            'display_clock' => $fixture->display_clock,
            'local_score' => $fixture->local_score,
            'guest_score' => $fixture->guest_score,
            'local_team' => $this->presentTeam($fixture->localTeam),
            'guest_team' => $this->presentTeam($fixture->guestTeam),
            'managers' => $managers,
        ];
    }

    /**
     * @return JornadaPlayer
     */
    private function presentPlayer(ManagerLineupPlayer $entry, ?int $points): array
    {
        return [
            'id' => $entry->player_id,
            'nickname' => $entry->player->nickname,
            'image' => $entry->player->image ? asset('storage/'.$entry->player->image) : '',
            'position' => $entry->position->value,
            'points' => $points,
        ];
    }

    /**
     * @return JornadaTeam
     */
    private function presentTeam(Team $team): array
    {
        return [
            'id' => $team->id,
            'short_name' => $team->short_name,
            'logo' => $team->logo ? asset('storage/'.$team->logo) : '',
        ];
    }
}
