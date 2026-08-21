<?php

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetCurrentWeekRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'api/v1/competition/1/week/current';
    }
}
