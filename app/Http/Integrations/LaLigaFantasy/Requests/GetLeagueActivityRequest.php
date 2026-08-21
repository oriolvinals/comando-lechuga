<?php

declare(strict_types=1);

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use InvalidArgumentException;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetLeagueActivityRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $leagueId,
        private readonly int $page,
        private readonly string $accessToken,
    ) {
        if ($leagueId === '' || $accessToken === '') {
            throw new InvalidArgumentException('The league ID and access token are required.');
        }

        if ($page < 0) {
            throw new InvalidArgumentException('The page must be zero or greater.');
        }
    }

    public function resolveEndpoint(): string
    {
        return "api/v1/competition/1/leagues/{$this->leagueId}/activity/{$this->page}";
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => '*/*',
            'Authorization' => "Bearer {$this->accessToken}",
            'X-Lang' => 'es',
            'X-Version' => '10.0.4',
            'X-App' => 'Fantasy-iOS',
        ];
    }
}
