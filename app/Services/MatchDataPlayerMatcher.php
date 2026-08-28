<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use Closure;
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
            // A rule can take more than one round to reach its fixed point: committing
            // one unambiguous pair removes a roster entry from contention, which can
            // turn a player who was blocked only by that contention into a new
            // unambiguous pair. Keep re-running the rule until a round commits nothing.
            do {
                $committedAny = $this->resolveUnambiguousPairs($rule, $unresolvedPlayers, $availableRoster, $resolved);
            } while ($committedAny);
        }

        return $resolved;
    }

    /**
     * Runs a single round of one rule: finds every (player, roster entry) pair
     * that's unambiguous in BOTH directions — the player matches exactly one
     * still-available entry, AND that entry is matched by exactly one
     * still-unresolved player — and commits every such pair at once. Checking
     * both directions (not just the player's) is what stops a roster entry
     * that two different still-unresolved players could plausibly claim from
     * being silently handed to whichever one happens to be considered first;
     * committing every safe pair from the round together (rather than one at a
     * time) is what makes the result independent of iteration order.
     *
     * @param  Closure(string, string): bool  $rule
     * @param  Collection<int, Player>  $unresolvedPlayers
     * @param  Collection<int, array{id: int, displayName: string}>  $availableRoster
     * @param  array<int, int>  $resolved
     */
    private function resolveUnambiguousPairs(Closure $rule, Collection $unresolvedPlayers, Collection $availableRoster, array &$resolved): bool
    {
        /** @var array<int, list<int>> $candidatesByPlayer */
        $candidatesByPlayer = [];

        /** @var array<int, int> $claimsByEntry */
        $claimsByEntry = [];

        foreach ($unresolvedPlayers as $playerId => $player) {
            $nickname = $this->fold($player->nickname);

            $matchDataIds = $availableRoster->filter(
                fn (array $entry): bool => $rule($nickname, $this->fold($entry['displayName'])),
            )->keys()->all();

            $candidatesByPlayer[$playerId] = $matchDataIds;

            foreach ($matchDataIds as $matchDataId) {
                $claimsByEntry[$matchDataId] = ($claimsByEntry[$matchDataId] ?? 0) + 1;
            }
        }

        $committedAny = false;

        foreach ($candidatesByPlayer as $playerId => $matchDataIds) {
            if (count($matchDataIds) !== 1) {
                continue;
            }

            $matchDataId = $matchDataIds[0];

            if ($claimsByEntry[$matchDataId] !== 1) {
                continue;
            }

            $resolved[$playerId] = $matchDataId;
            $unresolvedPlayers->forget($playerId);
            $availableRoster->forget($matchDataId);
            $committedAny = true;
        }

        return $committedAny;
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
        $firstNameFirstWord = explode(' ', $firstName)[0];
        $fullNameFirstWord = explode(' ', $fullName)[0];

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
