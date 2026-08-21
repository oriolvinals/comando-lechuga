<?php

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use InvalidArgumentException;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetTeamLineupRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private int $seasonTeamFantasyId,
        private int $weekNumber,
        private string $accessToken,
    ) {
        if ($seasonTeamFantasyId < 1 || $weekNumber < 1 || $accessToken === '') {
            throw new InvalidArgumentException('The team ID, week number, and access token are required.');
        }
    }

    public function resolveEndpoint(): string
    {
        return "api/v1/competition/1/teams/{$this->seasonTeamFantasyId}/lineup/week/{$this->weekNumber}";
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
