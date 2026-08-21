<?php

namespace App\Http\Integrations\LaLigaFantasy;

use App\Http\Integrations\LaLigaFantasy\Requests\GetAssetRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayersRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetTeamInfoRequest;
use InvalidArgumentException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\HasTimeout;

class LaLigaFantasyConnector extends Connector
{
    use HasTimeout;

    private const string AssetsHost = 'assets-fantasy.llt-services.com';

    protected float $connectTimeout = 3;

    protected float $requestTimeout = 10;

    public function resolveBaseUrl(): string
    {
        return (string) config('services.la_liga_fantasy.base_url');
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
    public function getAsset(string $url): Response
    {
        if (parse_url($url, PHP_URL_HOST) !== self::AssetsHost) {
            throw new InvalidArgumentException('The asset URL must use the Liga Fantasy assets host.');
        }

        return $this->send(new GetAssetRequest($url));
    }
}
