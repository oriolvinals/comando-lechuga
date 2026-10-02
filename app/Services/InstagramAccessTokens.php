<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InstagramAccessToken;
use RuntimeException;

/**
 * The Instagram access token in use: the newest refreshed one, or the INSTAGRAM_ACCESS_TOKEN seed before the first
 * refresh.
 */
final class InstagramAccessTokens
{
    public function current(): string
    {
        $token = InstagramAccessToken::query()->latest('id')->value('access_token');
        $token = is_string($token) && $token !== '' ? $token : (string) config('services.instagram.access_token');

        if ($token === '') {
            throw new RuntimeException('No Instagram access token: set INSTAGRAM_ACCESS_TOKEN.');
        }

        return $token;
    }

    /**
     * Stores a refreshed token as the one in use and forgets the older ones.
     */
    public function store(string $accessToken, ?int $expiresInSeconds): InstagramAccessToken
    {
        $token = InstagramAccessToken::query()->create([
            'access_token' => $accessToken,
            'expires_at' => $expiresInSeconds !== null ? now()->addSeconds($expiresInSeconds) : null,
            'refreshed_at' => now(),
        ]);

        InstagramAccessToken::query()->whereKeyNot($token->id)->delete();

        return $token;
    }
}
