<?php

namespace App\Http\Integrations\LaLigaFantasy;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\HasTimeout;

class LaLigaFantasyConnector extends Connector
{
    use HasTimeout;

    protected float $connectTimeout = 3;

    protected float $requestTimeout = 10;

    public function resolveBaseUrl(): string
    {
        return (string) config('services.la_liga_fantasy.base_url');
    }
}
