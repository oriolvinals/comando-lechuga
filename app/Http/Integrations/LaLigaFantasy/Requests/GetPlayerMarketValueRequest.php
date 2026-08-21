<?php

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use InvalidArgumentException;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetPlayerMarketValueRequest extends Request
{
    protected Method $method = Method::GET;

    private readonly int $playerFantasyId;

    public function __construct(int $playerFantasyId)
    {
        if ($playerFantasyId < 1) {
            throw new InvalidArgumentException('The player fantasy ID must be positive.');
        }

        $this->playerFantasyId = $playerFantasyId;
    }

    public function resolveEndpoint(): string
    {
        return "api/v1/competition/1/player/$this->playerFantasyId/market-value";
    }
}
