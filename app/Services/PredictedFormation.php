<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MatchPositionLine;
use App\Enums\MatchPositionSide;

/**
 * Reads a team's shape off FútbolFantasy's probable XI: from where FF draws
 * each starter (`pitch_x`/`pitch_y`, FF attacking up — goalkeeper at the
 * bottom, the player's left on the screen's left), the formation ("4-3-3")
 * and each starter's position in worldcup26's own vocabulary ("Right Back",
 * "Center Left Midfielder", "Forward"…), so a probable XI draws exactly like
 * a confirmed lineup. Pure: spots in, shape out. It is a heuristic — FF
 * never names a formation or a role, only draws shirts.
 *
 * FF draws on a fixed template (the same `top` values on every team page),
 * so rows are fixed bands of `pitch_y`, not clusters:
 *
 * - ≥ 80 goalkeeper (87/88).
 * - 60–79 the back line (66 full-backs, 69–72 centre-backs).
 * - 50–59 in between (55/56): a wide shirt is a wing-back, a central one a
 *   pivot (Defensive Midfielder). Wing-backs join the back line when it has
 *   fewer than four — a back three plus two wing-backs is a back five
 *   (5-x-x), as worldcup26 labels those systems. Beside a back four they are
 *   wide midfielders instead.
 * - 38–49 the midfield (42/48).
 * - 30–37 the attacking midfield (34, the mediapunta).
 * - 22–29 the high flanks (27/29): a wide shirt is a winger (Left/Right
 *   Forward); a central one is an attacking midfielder behind a striker, or
 *   a forward when nobody stands further up.
 * - < 22 the striker line (18).
 *
 * "Wide" is `pitch_x` ≤ 20 or ≥ 80. Within a line the side comes from
 * `pitch_x` in five bands (Left < 20 < Center Left < 40 ≤ Center ≤ 60 <
 * Center Right < 80 < Right); if two shirts of a line fall in one band, the
 * line is ranked left to right instead.
 *
 * The formation counts the outfield lines back to front. Two adjacent
 * midfield lines merge when one holds a single player and together they
 * are at most three — a pivot behind two interiors, or two interiors behind
 * a mediapunta, is a midfield three ("4-3-3", not "4-1-2-3"), while a
 * 4-2-3-1 or a 4-1-4-1 keeps its lines. It is only given for a full XI (one
 * goalkeeper and ten outfield players); the positions are given whatever
 * the count.
 */
class PredictedFormation
{
    private const int GOALKEEPER_MIN_Y = 80;

    private const int BACK_LINE_MIN_Y = 60;

    private const int WING_BACK_MIN_Y = 50;

    private const int MIDFIELD_MIN_Y = 38;

    private const int ATTACKING_MIDFIELD_MIN_Y = 30;

    private const int HIGH_FLANK_MIN_Y = 22;

    private const int WIDE_LEFT_MAX_X = 20;

    private const int WIDE_RIGHT_MIN_X = 80;

    /** Outfield lines back to front — the formation's order. */
    private const array LINE_ORDER = [
        MatchPositionLine::Defender,
        MatchPositionLine::DefensiveMidfielder,
        MatchPositionLine::Midfielder,
        MatchPositionLine::AttackingMidfielder,
        MatchPositionLine::Forward,
    ];

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, array{x: int, y: int}>  $spots  each starter's spot on FF's pitch, keyed by the caller's id
     * @return array{formation: string|null, positions: array<TKey, string>}
     */
    public function derive(array $spots): array
    {
        /** @var array<string, array<TKey, int>> $lines x per key, per MatchPositionLine value */
        $lines = [];

        /** @var array<TKey, true> $wingBacks */
        $wingBacks = [];

        /** @var array<TKey, int> $wideMidfield wide shirts of the 50–59 band, placed once the back line is known */
        $wideMidfield = [];

        /** @var array<TKey, int> $highCentral central shirts of the 22–29 band, placed once the striker line is known */
        $highCentral = [];
        $goalkeepers = 0;
        $positions = [];

        foreach ($spots as $key => ['x' => $x, 'y' => $y]) {
            $isWide = $x <= self::WIDE_LEFT_MAX_X || $x >= self::WIDE_RIGHT_MIN_X;

            if ($y >= self::GOALKEEPER_MIN_Y) {
                $goalkeepers++;
                $positions[$key] = 'Goalkeeper';
            } elseif ($y >= self::WING_BACK_MIN_Y && $y < self::BACK_LINE_MIN_Y && $isWide) {
                $wideMidfield[$key] = $x;
            } elseif ($y >= self::HIGH_FLANK_MIN_Y && $y < self::ATTACKING_MIDFIELD_MIN_Y && !$isWide) {
                $highCentral[$key] = $x;
            } else {
                $lines[$this->bandLine($y)->value][$key] = $x;
            }
        }

        $hasStriker = array_filter($spots, fn (array $spot): bool => $spot['y'] < self::HIGH_FLANK_MIN_Y) !== [];

        foreach ($highCentral as $key => $x) {
            $lines[($hasStriker ? MatchPositionLine::AttackingMidfielder : MatchPositionLine::Forward)->value][$key] = $x;
        }

        $joinBackLine = count($lines[MatchPositionLine::Defender->value] ?? []) < 4;

        foreach ($wideMidfield as $key => $x) {
            $lines[($joinBackLine ? MatchPositionLine::Defender : MatchPositionLine::Midfielder)->value][$key] = $x;

            if ($joinBackLine) {
                $wingBacks[$key] = true;
            }
        }

        $counts = [];

        foreach (self::LINE_ORDER as $line) {
            $members = $lines[$line->value] ?? [];

            if ($members === []) {
                continue;
            }

            $counts[] = ['line' => $line, 'count' => count($members)];

            foreach ($this->sides($members) as $key => $side) {
                $positions[$key] = $this->positionText($line, $side, isset($wingBacks[$key]));
            }
        }

        $outfield = array_sum(array_column($counts, 'count'));

        return [
            'formation' => $goalkeepers === 1 && $outfield === 10 ? $this->formation($counts) : null,
            'positions' => $positions,
        ];
    }

    /** The line of an outfield shirt at this height, wide or central alike. */
    private function bandLine(int $y): MatchPositionLine
    {
        return match (true) {
            $y >= self::BACK_LINE_MIN_Y => MatchPositionLine::Defender,
            $y >= self::WING_BACK_MIN_Y => MatchPositionLine::DefensiveMidfielder,
            $y >= self::MIDFIELD_MIN_Y => MatchPositionLine::Midfielder,
            $y >= self::ATTACKING_MIDFIELD_MIN_Y => MatchPositionLine::AttackingMidfielder,
            default => MatchPositionLine::Forward,
        };
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $members  x per key
     * @return array<TKey, MatchPositionSide>
     */
    private function sides(array $members): array
    {
        asort($members);
        $sides = array_map(fn (int $x): MatchPositionSide => match (true) {
            $x <= self::WIDE_LEFT_MAX_X => MatchPositionSide::Left,
            $x < 40 => MatchPositionSide::CenterLeft,
            $x <= 60 => MatchPositionSide::Center,
            $x < self::WIDE_RIGHT_MIN_X => MatchPositionSide::CenterRight,
            default => MatchPositionSide::Right,
        }, $members);

        if (count(array_unique(array_map(fn (MatchPositionSide $side): string => $side->value, $sides))) === count($sides)) {
            return $sides;
        }

        $ranked = match (count($members)) {
            1 => [MatchPositionSide::Center],
            2 => [MatchPositionSide::CenterLeft, MatchPositionSide::CenterRight],
            3 => [MatchPositionSide::CenterLeft, MatchPositionSide::Center, MatchPositionSide::CenterRight],
            4 => [MatchPositionSide::Left, MatchPositionSide::CenterLeft, MatchPositionSide::CenterRight, MatchPositionSide::Right],
            default => [MatchPositionSide::Left, MatchPositionSide::CenterLeft, MatchPositionSide::Center, MatchPositionSide::CenterRight, MatchPositionSide::Right],
        };

        $index = 0;

        foreach (array_keys($sides) as $key) {
            $sides[$key] = $ranked[min($index, count($ranked) - 1)];
            $index++;
        }

        return $sides;
    }

    private function positionText(MatchPositionLine $line, MatchPositionSide $side, bool $isWingBack): string
    {
        if ($isWingBack) {
            return $side->leftToRight() < 2 ? 'Left Wing Back' : 'Right Wing Back';
        }

        return match ($line) {
            MatchPositionLine::Defender => match ($side) {
                MatchPositionSide::Left => 'Left Back',
                MatchPositionSide::CenterLeft => 'Center Left Defender',
                MatchPositionSide::Center => 'Center Defender',
                MatchPositionSide::CenterRight => 'Center Right Defender',
                MatchPositionSide::Right => 'Right Back',
            },
            MatchPositionLine::DefensiveMidfielder => match ($side->leftToRight() <=> 2) {
                -1 => 'Defensive Midfielder Left',
                0 => 'Defensive Midfielder',
                1 => 'Defensive Midfielder Right',
            },
            MatchPositionLine::AttackingMidfielder => match ($side->leftToRight() <=> 2) {
                -1 => 'Attacking Midfielder Left',
                0 => 'Attacking Midfielder',
                1 => 'Attacking Midfielder Right',
            },
            MatchPositionLine::Forward => match ($side) {
                MatchPositionSide::Left => 'Left Forward',
                MatchPositionSide::CenterLeft => 'Center Left Forward',
                MatchPositionSide::Center => 'Forward',
                MatchPositionSide::CenterRight => 'Center Right Forward',
                MatchPositionSide::Right => 'Right Forward',
            },
            default => match ($side) {
                MatchPositionSide::Left => 'Left Midfielder',
                MatchPositionSide::CenterLeft => 'Center Left Midfielder',
                MatchPositionSide::Center => 'Center Midfielder',
                MatchPositionSide::CenterRight => 'Center Right Midfielder',
                MatchPositionSide::Right => 'Right Midfielder',
            },
        };
    }

    /**
     * @param  list<array{line: MatchPositionLine, count: int}>  $counts  the outfield lines back to front
     */
    private function formation(array $counts): string
    {
        $numbers = [];
        $previousWasMidfield = false;

        foreach ($counts as ['line' => $line, 'count' => $count]) {
            $isMidfield = $line !== MatchPositionLine::Defender && $line !== MatchPositionLine::Forward;
            $last = count($numbers) - 1;

            if ($isMidfield && $previousWasMidfield && ($numbers[$last] === 1 || $count === 1) && $numbers[$last] + $count <= 3) {
                $numbers[$last] += $count;
            } else {
                $numbers[] = $count;
            }

            $previousWasMidfield = $isMidfield;
        }

        return implode('-', $numbers);
    }
}
