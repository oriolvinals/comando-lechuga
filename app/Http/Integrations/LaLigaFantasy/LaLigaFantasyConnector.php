<?php

declare(strict_types=1);

namespace App\Http\Integrations\LaLigaFantasy;

use App\Http\Integrations\LaLigaFantasy\Requests\GetAssetRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetCurrentWeekRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetFixturesRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueMarketRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueStandingRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayerMarketValueRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayerRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayersRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetTeamInfoRequest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\HasTimeout;

class LaLigaFantasyConnector extends Connector
{
    use HasTimeout;

    private const string AssetsHost = 'assets-fantasy.llt-services.com';

    private const string AccessTokenCacheKey = 'la_liga_fantasy.access_token';

    protected float $connectTimeout = 3;

    protected float $requestTimeout = 10;

    private ?string $accessToken = null;

    public function resolveBaseUrl(): string
    {
        return (string)config('services.la_liga_fantasy.base_url');
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getTeamInfo(): Response
    {
        return $this->send(new GetTeamInfoRequest);
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getPlayers(): Response
    {
        return $this->send(new GetPlayersRequest);
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getPlayerMarketValue(int $playerFantasyId): Response
    {
        return $this->send(new GetPlayerMarketValueRequest($playerFantasyId));
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getPlayer(int $playerFantasyId): Response
    {
        return $this->send(new GetPlayerRequest($playerFantasyId));
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getFixtures(int $weekNumber): Response
    {
        return $this->send(new GetFixturesRequest($weekNumber));
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getCurrentWeek(): Response
    {
        return $this->send(new GetCurrentWeekRequest);
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getLeagueMarket(string $leagueFantasyId): Response
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            throw new LogicException('An access token is required to request the league market.');
        }

        return $this->send(new GetLeagueMarketRequest(
            leagueId: $leagueFantasyId,
            accessToken: $this->accessToken,
        ));
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getLeagueStanding(string $leagueFantasyId): Response
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            throw new LogicException('An access token is required to request the league standing.');
        }

        return $this->send(new GetLeagueStandingRequest(
            leagueId: $leagueFantasyId,
            accessToken: $this->accessToken,
        ));
    }

    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     */
    public function getLeagueMarketWithLogin(
        LaLigaLoginConnector $loginConnector,
        string $leagueFantasyId,
    ): Response {
        $accessToken = $this->cachedAccessToken();

        if (is_string($accessToken) && $accessToken !== '') {
            try {
                return $this->withAccessToken($accessToken)->getLeagueMarket($leagueFantasyId)->throw();
            } catch (RequestException $exception) {
                if ($exception->getStatus() !== 403) {
                    throw $exception;
                }

                Cache::forget(self::AccessTokenCacheKey);
            }
        }

        $accessToken = $loginConnector->accessToken();
        $this->cacheAccessToken($accessToken);

        return $this->withAccessToken($accessToken)->getLeagueMarket($leagueFantasyId)->throw();
    }

    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     */
    public function getLeagueStandingWithLogin(
        LaLigaLoginConnector $loginConnector,
        string $leagueFantasyId,
    ): Response {
        $accessToken = $this->cachedAccessToken();

        if (is_string($accessToken) && $accessToken !== '') {
            try {
                return $this->withAccessToken($accessToken)->getLeagueStanding($leagueFantasyId)->throw();
            } catch (RequestException $exception) {
                if ($exception->getStatus() !== 403) {
                    throw $exception;
                }

                Cache::forget(self::AccessTokenCacheKey);
            }
        }

        $accessToken = $loginConnector->accessToken();
        $this->cacheAccessToken($accessToken);

        return $this->withAccessToken($accessToken)->getLeagueStanding($leagueFantasyId)->throw();
    }

    public function withAccessToken(string $accessToken): static
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    /**
     * @throws JsonException
     */
    private function cacheAccessToken(string $accessToken): void
    {
        $parts = explode('.', $accessToken);

        if (count($parts) !== 3) {
            Cache::put(self::AccessTokenCacheKey, Crypt::encryptString($accessToken), 3600);

            return;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($payload === false) {
            Cache::put(self::AccessTokenCacheKey, Crypt::encryptString($accessToken), 3600);

            return;
        }

        $claims = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $expiration = is_array($claims) && isset($claims['exp']) ? (int) $claims['exp'] : now()->addHour()->getTimestamp();
        $ttl = max(1, $expiration - now()->getTimestamp() - 60);

        Cache::put(self::AccessTokenCacheKey, Crypt::encryptString($accessToken), $ttl);
    }

    private function cachedAccessToken(): ?string
    {
        $encryptedAccessToken = Cache::get(self::AccessTokenCacheKey);

        if (!is_string($encryptedAccessToken)) {
            return null;
        }

        try {
            return Crypt::decryptString($encryptedAccessToken);
        } catch (DecryptException) {
            Cache::forget(self::AccessTokenCacheKey);

            return null;
        }
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getAsset(string $url): Response
    {
        if (parse_url($url, PHP_URL_HOST) !== self::AssetsHost) {
            throw new InvalidArgumentException('The asset URL must use the Liga Fantasy assets host.');
        }

        return $this->send(new GetAssetRequest($url));
    }
}
