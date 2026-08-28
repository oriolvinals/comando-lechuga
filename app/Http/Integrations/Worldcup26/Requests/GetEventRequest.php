<?php

declare(strict_types=1);

namespace App\Http\Integrations\Worldcup26\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetEventRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $matchDataId)
    {
    }

    public function resolveEndpoint(): string
    {
        return "get/soccer/esp.1/events/{$this->matchDataId}";
    }
}
