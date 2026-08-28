<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class MatchDataPlayerMatcher
{
    /**
     * Resolves each player against the worldcup26 roster of their own team,
     * trying rules from strictest to loosest. A rule only commits a match
     * when it finds exactly one remaining candidate on both sides — ambiguous
     * cases are left for the next (looser) rule, and if no rule ever narrows
     * them to one, they're left unresolved for manual review.
     *
     * @param  Collection<int, Player>  $players
     * @param  array<int, array{id: int, displayName: string}>  $roster
     * @return array<int, int> Player::id => worldcup26 athlete id
     */
    public function match(Collection $players, array $roster): array
    {
        /** @var Collection<int, Player> $unresolvedPlayers */
        $unresolvedPlayers = $players->keyBy('id');

        /** @var Collection<int, array{id: int, displayName: string}> $availableRoster */
        $availableRoster = (new Collection($roster))->keyBy('id');

        /** @var array<int, int> $resolved */
        $resolved = [];

        $rules = [
            $this->exactMatch(...),
            $this->initialAndSurnameMatch(...),
            $this->firstNamePrefixMatch(...),
            $this->surnameMatch(...),
        ];

        foreach ($rules as $rule) {
            foreach ($unresolvedPlayers->all() as $playerId => $player) {
                $nickname = $this->fold($player->nickname);

                $candidates = $availableRoster->filter(
                    fn (array $entry): bool => $rule($nickname, $this->fold($entry['displayName'])),
                );

                if ($candidates->count() !== 1) {
                    continue;
                }

                $matchDataId = $candidates->keys()->first();
                $resolved[$playerId] = $matchDataId;
                $unresolvedPlayers->forget($playerId);
                $availableRoster->forget($matchDataId);
            }
        }

        return $resolved;
    }

    private function exactMatch(string $nickname, string $fullName): bool
    {
        return $nickname === $fullName;
    }

    private function surnameMatch(string $nickname, string $fullName): bool
    {
        $words = explode(' ', $nickname);
        $surname = end($words);

        return $surname !== '' && in_array($surname, explode(' ', $fullName), true);
    }

    private function firstNamePrefixMatch(string $nickname, string $fullName): bool
    {
        $firstName = (string) preg_replace('/\s*(jr\.?|junior)$/', '', $nickname);
        $firstNameFirstWord = explode(' ', $firstName)[0] ?? '';
        $fullNameFirstWord = explode(' ', $fullName)[0] ?? '';

        return $firstNameFirstWord !== '' && str_starts_with($fullNameFirstWord, $firstNameFirstWord);
    }

    private function initialAndSurnameMatch(string $nickname, string $fullName): bool
    {
        if (preg_match('/^([a-z])\.?\s+(.+)$/', $nickname, $matches) !== 1) {
            return false;
        }

        [, $initial, $surname] = $matches;
        $fullNameWords = explode(' ', $fullName);

        return ($fullNameWords[0][0] ?? '') === $initial && in_array($surname, $fullNameWords, true);
    }

    private function fold(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->value();
    }
}
