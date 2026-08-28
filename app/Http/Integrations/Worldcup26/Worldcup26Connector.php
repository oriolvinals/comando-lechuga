<?php

declare(strict_types=1);

namespace App\Http\Integrations\Worldcup26;

use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use App\Http\Integrations\Worldcup26\Requests\GetFixturesRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\HasTimeout;

class Worldcup26Connector extends Connector
{
    use HasTimeout;

    protected float $connectTimeout = 3;

    protected float $requestTimeout = 10;

    public function resolveBaseUrl(): string
    {
        return (string) config('services.worldcup26.base_url');
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getFixtures(int $pageIndex = 1): Response
    {
        return $this->send(new GetFixturesRequest($pageIndex));
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getEvent(int $matchDataId): Response
    {
        return $this->send(new GetEventRequest($matchDataId));
    }
}
