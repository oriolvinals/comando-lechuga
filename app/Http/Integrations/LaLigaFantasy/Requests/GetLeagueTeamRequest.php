<?php

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use InvalidArgumentException;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetLeagueTeamRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $leagueId,
        private readonly int $teamFantasyId,
        private readonly string $accessToken,
    ) {
        if ($leagueId === '' || $teamFantasyId < 1 || $accessToken === '') {
            throw new InvalidArgumentException('The league ID, team ID, and access token are required.');
        }
    }

    public function resolveEndpoint(): string
    {
        return "api/v1/competition/1/leagues/{$this->leagueId}/teams/{$this->teamFantasyId}";
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
