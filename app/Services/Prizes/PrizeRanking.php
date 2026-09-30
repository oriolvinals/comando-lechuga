<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Enums\SeasonPrize;

final class PrizeRanking
{
    /**
     * Highest value first (lowest first when $lowestFirst); nulls last without a place. Ties share the better
     * place (1, 1, 3) and are ordered among themselves by league position.
     *
     * @param  list<PrizeRow>  $rows
     * @param  array<int, int>  $leaguePositions  season manager id => league position
     * @return list<array{row: PrizeRow, place: int|null}>
     */
    public static function rank(array $rows, array $leaguePositions, bool $lowestFirst = false): array
    {
        usort($rows, function (PrizeRow $a, PrizeRow $b) use ($leaguePositions, $lowestFirst): int {
            $aMissing = $a->value === null;
            $bMissing = $b->value === null;

            if ($aMissing !== $bMissing) {
                return $aMissing ? 1 : -1;
            }

            $byValue = $lowestFirst ? $a->value <=> $b->value : $b->value <=> $a->value;

            if ($byValue !== 0) {
                return $byValue;
            }

            return ($leaguePositions[$a->seasonManagerId] ?? PHP_INT_MAX) <=> ($leaguePositions[$b->seasonManagerId] ?? PHP_INT_MAX);
        });

        return array_map(fn (PrizeRow $row): array => [
            'row' => $row,
            'place' => $row->value === null
                ? null
                : 1 + count(array_filter($rows, fn (PrizeRow $other): bool => $other->value !== null && ($lowestFirst ? $other->value < $row->value : $other->value > $row->value))),
        ], $rows);
    }

    /**
     * Managers in first place, only when that value is above zero (any value
     * counts when the lowest wins).
     *
     * @param  list<array{row: PrizeRow, place: int|null}>  $ranked
     * @return list<int>
     */
    public static function leaders(array $ranked, bool $lowestFirst = false): array
    {
        return array_values(array_map(
            fn (array $entry): int => $entry['row']->seasonManagerId,
            array_filter($ranked, fn (array $entry): bool => $entry['place'] === 1 && $entry['row']->value !== null && ($lowestFirst || $entry['row']->value > 0)),
        ));
    }

    /**
     * The prize split evenly between its leaders (decisión 1).
     *
     * @param  list<int>  $leaders
     * @return array<int, float> season manager id => euros
     */
    public static function shares(SeasonPrize $prize, array $leaders): array
    {
        if ($leaders === []) {
            return [];
        }

        return array_fill_keys($leaders, (float) $prize->amount() / count($leaders));
    }
}
