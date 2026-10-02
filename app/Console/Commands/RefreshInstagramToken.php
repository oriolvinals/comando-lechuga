<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\Instagram\InstagramConnector;
use App\Services\InstagramAccessTokens;
use App\Services\InstagramApiException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Long-lived Instagram tokens last 60 days: refreshing weekly keeps the one in use (InstagramAccessTokens) alive.
 */
#[Signature('instagram:refresh-token')]
#[Description('Refresh the long-lived Instagram access token and store the new one')]
class RefreshInstagramToken extends Command
{
    public function handle(InstagramConnector $connector, InstagramAccessTokens $accessTokens): int
    {
        try {
            $response = $connector->refreshAccessToken();
            $accessToken = $response->json('access_token');

            if ($response->failed() || !is_string($accessToken) || $accessToken === '') {
                throw InstagramApiException::fromResponse($response, 'refresh the access token');
            }

            $expiresIn = $response->json('expires_in');
            $token = $accessTokens->store($accessToken, is_numeric($expiresIn) ? (int) $expiresIn : null);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        $this->info('Instagram access token refreshed'.($token->expires_at !== null ? ", valid until {$token->expires_at->toDateString()}." : '.'));

        return self::SUCCESS;
    }
}
