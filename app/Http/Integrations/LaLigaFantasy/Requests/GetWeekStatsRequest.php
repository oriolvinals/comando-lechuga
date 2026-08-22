<?php

declare(strict_types=1);

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use InvalidArgumentException;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetWeekStatsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $weekNumber)
    {
        if ($weekNumber < 1) {
            throw new InvalidArgumentException('The week number must be positive.');
        }
    }

    public function resolveEndpoint(): string
    {
        return "stats/v1/competition/1/stats/week/{$this->weekNumber}";
    }
}
