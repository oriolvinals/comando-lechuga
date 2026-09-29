<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DifficultyVariant;
use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How hard a match is for one of its teams, from the rival's team strength,
 * home advantage and — for the rival's next match only — its missing
 * regulars. See docs/superpowers/specs/2026-09-29-team-strength-design.md §2.2.
 *
 * Internally it works with an ease `e` (higher = easier):
 * `e = −rating(rival) + homeBonus·(home ? +1 : −1) [+ absenceWeight·z(a)]`,
 * shown as `difficulty = clamp(5 − scaleSlope·e, 0, 10)`.
 *
 * Strength, absences and standings are computed once per season and date and
 * memoized per instance, so `forMany()` costs the same number of queries for
 * one match as for a whole calendar.
 *
 * @phpstan-type TeamAbsence array{fixture_id: int, share: float, z: float}
 */
final class MatchDifficulty
{
    /** @var array<int, Season|null> */
    private array $seasons = [];

    /** @var array<int, array<int, TeamAbsence>> */
    private array $absencesMemo = [];

    /** @var array<string, array<int, int>> */
    private array $positionsMemo = [];

    public function __construct(
        private readonly TeamStrength $strength,
        private readonly StartProbabilities $startProbabilities,
        private readonly LeagueStandings $standings,
    ) {}

    /**
     * Null when the fixture has no date or `$teamId` is not one of its two
     * teams. Without `$at` the match is judged now, the only time the
     * rival's absences count.
     */
    public function for(Fixture $fixture, int $teamId, DifficultyVariant $variant, ?CarbonInterface $at = null): ?MatchDifficultyResult
    {
        return $this->forMany([[$fixture, $teamId, $variant]], $at)[0];
    }

    /**
     * {@see for()} for many matches at once, sharing one strength, absence
     * and standings computation per season.
     *
     * @param  list<array{0: Fixture, 1: int, 2: DifficultyVariant}>  $items
     * @return list<MatchDifficultyResult|null> in the same order as `$items`
     */
    public function forMany(array $items, ?CarbonInterface $at = null): array
    {
        $isNow = $at === null;
        $at = $isNow ? CarbonImmutable::now() : CarbonImmutable::parse($at);
        $parameters = new TeamStrengthParameters;

        $this->loadSeasons($items);

        return array_map(
            fn (array $item): ?MatchDifficultyResult => $this->result($item[0], $item[1], $item[2], $at, $isNow, $parameters),
            $items,
        );
    }

    private function result(Fixture $fixture, int $teamId, DifficultyVariant $variant, CarbonImmutable $at, bool $isNow, TeamStrengthParameters $parameters): ?MatchDifficultyResult
    {
        if ($fixture->getAttribute('date') === null || !in_array($teamId, [$fixture->team_local_id, $fixture->team_guest_id], true)) {
            return null;
        }

        $season = $this->seasons[$fixture->season_id] ?? null;

        if ($season === null) {
            return null;
        }

        $isHome = $fixture->team_local_id === $teamId;
        $rivalId = $isHome ? $fixture->team_guest_id : $fixture->team_local_id;

        $rivalRating = $this->strength->ratingsAt($season, $at)[$rivalId] ?? null;
        $rivalStrength = -($rivalRating?->for($variant) ?? 0.0);
        $home = $parameters->homeBonus * ($isHome ? 1 : -1);

        $absences = 0.0;
        $absenceAdjusted = false;

        if ($isNow && $variant !== DifficultyVariant::Defense) {
            $rivalAbsence = $this->absences($season, $parameters)[$rivalId] ?? null;

            if ($rivalAbsence !== null && $rivalAbsence['fixture_id'] === $fixture->id) {
                $absences = $parameters->absenceWeight * $rivalAbsence['z'];
                $absenceAdjusted = $rivalAbsence['share'] > 0.0;
            }
        }

        $ease = $rivalStrength + $home + $absences;
        $difficulty = round(max(0.0, min(10.0, 5 - $parameters->scaleSlope * $ease)), 1);

        return new MatchDifficultyResult(
            difficulty: $difficulty,
            rivalEase: round((5 - $difficulty) / 5, 3),
            variant: $variant,
            absenceAdjusted: $absenceAdjusted,
            rivalPosition: $this->positions($season, $isNow ? null : $at)[$rivalId] ?? null,
            components: [
                'rival_strength' => round($rivalStrength, 3),
                'home' => round($home, 3),
                'absences' => round($absences, 3),
            ],
        );
    }

    /**
     * @param  list<array{0: Fixture, 1: int, 2: DifficultyVariant}>  $items
     */
    private function loadSeasons(array $items): void
    {
        $missing = array_values(array_diff(
            array_unique(array_map(fn (array $item): int => $item[0]->season_id, $items)),
            array_keys($this->seasons),
        ));

        if ($missing === []) {
            return;
        }

        $found = Season::query()->whereIn('id', $missing)->get()->keyBy('id');

        foreach ($missing as $seasonId) {
            $this->seasons[$seasonId] = $found->get($seasonId);
        }
    }

    /**
     * The standings positions at `$until` (now when null), memoized per
     * season and minute.
     *
     * @return array<int, int> keyed by team id
     */
    private function positions(Season $season, ?CarbonImmutable $until): array
    {
        $key = $season->id.':'.($until?->format('Y-m-d H:i') ?? 'now');

        return $this->positionsMemo[$key] ??= $this->standings->positions($season, $until);
    }

    /**
     * Each team with a next match: that match, the share `a` of its regulars'
     * value that won't play it, and `a`'s z-score among those teams (0 when
     * every team has the same share). Memoized per season; only ever
     * computed for "now".
     *
     * @return array<int, TeamAbsence> keyed by team id
     */
    private function absences(Season $season, TeamStrengthParameters $parameters): array
    {
        if (isset($this->absencesMemo[$season->id])) {
            return $this->absencesMemo[$season->id];
        }

        $teamIds = array_values(array_map(intval(...), $season->teams()->pluck('teams.id')->all()));
        $nextFixtures = $this->startProbabilities->nextFixtures($season, $teamIds);

        if ($nextFixtures === []) {
            return $this->absencesMemo[$season->id] = [];
        }

        $regulars = $this->regularStarters($season, array_keys($nextFixtures), $parameters);
        $playerIds = array_merge([], ...array_values($regulars));

        $players = Player::query()->whereIn('id', $playerIds)->get(['id', 'team_id', 'status'])->keyBy('id');
        $values = PlayerSeason::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', $playerIds)
            ->pluck('market_value', 'player_id');
        $blocks = $this->startProbabilities->forTeamsNextFixtures($season, $nextFixtures);

        $shares = [];

        foreach ($nextFixtures as $teamId => $fixture) {
            $block = $blocks[$teamId] ?? null;
            $entries = $block === null ? null : collect($block['players'])->keyBy(fn (array $entry): int => $entry['player']->id);
            $totalValue = 0;
            $missingValue = 0;

            foreach ($regulars[$teamId] ?? [] as $playerId) {
                $player = $players->get($playerId);

                if (!$player instanceof Player || $player->team_id !== $teamId) {
                    continue;
                }

                $value = (int) $values->get($playerId, 0);
                $totalValue += $value;

                if ($this->willMiss($player, $entries?->get($playerId), $parameters)) {
                    $missingValue += $value;
                }
            }

            $shares[$teamId] = $totalValue > 0 ? (float) ($missingValue / $totalValue) : 0.0;
        }

        $zScores = self::zScores($shares);

        $absences = [];

        foreach ($nextFixtures as $teamId => $fixture) {
            $absences[$teamId] = ['fixture_id' => $fixture->id, 'share' => $shares[$teamId], 'z' => $zScores[$teamId]];
        }

        return $this->absencesMemo[$season->id] = $absences;
    }

    /**
     * A regular won't play when FútbolFantasy's XI for the match leaves him
     * out (not a predicted starter and under `absentProbabilityBelow` %, or
     * not in a confirmed lineup); when FF has nothing on him, when his
     * status says he is injured, suspended or gone.
     *
     * @param  array{player: Player, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, pitch_position: string|null}|null  $entry
     */
    private function willMiss(Player $player, ?array $entry, TeamStrengthParameters $parameters): bool
    {
        if ($entry !== null) {
            return $entry['confirmed_starter'] === false
                || (!$entry['predicted_starter'] && $entry['probability'] !== null && $entry['probability'] < $parameters->absentProbabilityBelow);
        }

        return in_array($player->status, [PlayerStatus::Injured, PlayerStatus::Suspended, PlayerStatus::OutOfLeague], true);
    }

    /**
     * Each team's regular starters: players whose minutes for it across the
     * season's finished matches add up to at least `regularMinutesShare` of
     * the team's 90 × matches played.
     *
     * @param  list<int>  $teamIds
     * @return array<int, list<int>> player ids keyed by team id
     */
    private function regularStarters(Season $season, array $teamIds, TeamStrengthParameters $parameters): array
    {
        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->where('date', '<', now())
            ->get(['id', 'team_local_id', 'team_guest_id']);

        if ($fixtures->isEmpty()) {
            return [];
        }

        $matchesByTeam = [];

        foreach ($fixtures as $fixture) {
            $matchesByTeam[$fixture->team_local_id] = ($matchesByTeam[$fixture->team_local_id] ?? 0) + 1;
            $matchesByTeam[$fixture->team_guest_id] = ($matchesByTeam[$fixture->team_guest_id] ?? 0) + 1;
        }

        $minutes = [];

        FixtureLineup::query()
            ->whereIn('fixture_id', $fixtures->pluck('id'))
            ->whereIn('team_id', $teamIds)
            ->whereNotNull('player_id')
            ->get(['team_id', 'player_id', 'fantasy_stats'])
            ->each(function (FixtureLineup $lineup) use (&$minutes): void {
                $minutes[$lineup->team_id][(int) $lineup->player_id] = ($minutes[$lineup->team_id][(int) $lineup->player_id] ?? 0)
                    + (int) ($lineup->fantasy_stats['mins_played'][0] ?? 0);
            });

        $regulars = [];

        foreach ($minutes as $teamId => $minutesByPlayer) {
            $threshold = $parameters->regularMinutesShare * ($matchesByTeam[$teamId] ?? 0) * 90;

            $regulars[$teamId] = array_keys(array_filter(
                $minutesByPlayer,
                fn (int $playerMinutes): bool => $playerMinutes > 0 && $playerMinutes >= $threshold,
            ));
        }

        return $regulars;
    }

    /**
     * Population z-scores; all zero when the standard deviation is 0.
     *
     * @param  array<int, float>  $values  keyed by team id
     * @return array<int, float> keyed by team id
     */
    private static function zScores(array $values): array
    {
        $mean = array_sum($values) / count($values);
        $standardDeviation = sqrt(array_sum(array_map(fn (float $value): float => ($value - $mean) ** 2, $values)) / count($values));

        return array_map(
            fn (float $value): float => $standardDeviation > 0.0 ? ($value - $mean) / $standardDeviation : 0.0,
            $values,
        );
    }
}
