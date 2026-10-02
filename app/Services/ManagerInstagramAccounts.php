<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Each manager's public Instagram account, keyed by the Liga Fantasy user id (SeasonManager::fantasy_user_id), which
 * stays the same across seasons. Stories mention these accounts; only public accounts can be mentioned.
 */
final class ManagerInstagramAccounts
{
    /** @var array<int, string> */
    public const array ACCOUNTS = [
        6392099 => 'abeel19',
        2890485 => 'oriolvinals',
        2035022 => 'pautorremilans',
        10160264 => 'ciid95',
        6572651 => 'juanji_7',
        2442084 => 's.pla11',
        11757415 => '6yung6rio6',
    ];

    public static function usernameFor(int $fantasyUserId): string
    {
        return self::ACCOUNTS[$fantasyUserId] ?? '';
    }
}
