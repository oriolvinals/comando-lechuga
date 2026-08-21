<?php

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use InvalidArgumentException;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetPlayerRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private int $playerFantasyId)
    {
        if ($playerFantasyId < 1) {
            throw new InvalidArgumentException('The player fantasy ID must be positive.');
        }
    }

    public function resolveEndpoint(): string
    {
        return "api/v1/competition/1/player/{$this->playerFantasyId}";
    }
}
