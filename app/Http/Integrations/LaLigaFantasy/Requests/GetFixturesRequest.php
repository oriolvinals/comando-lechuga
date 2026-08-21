<?php

declare(strict_types=1);

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use InvalidArgumentException;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetFixturesRequest extends Request
{
    protected Method $method = Method::GET;

    private readonly int $weekNumber;

    public function __construct(int $weekNumber)
    {
        if ($weekNumber < 1 || $weekNumber > 38) {
            throw new InvalidArgumentException('The week number must be between 1 and 38.');
        }

        $this->weekNumber = $weekNumber;
    }

    public function resolveEndpoint(): string
    {
        return 'api/v1/competition/1/calendar';
    }

    /**
     * @return array<string, int>
     */
    protected function defaultQuery(): array
    {
        return [
            'weekNumber' => $this->weekNumber,
        ];
    }
}
