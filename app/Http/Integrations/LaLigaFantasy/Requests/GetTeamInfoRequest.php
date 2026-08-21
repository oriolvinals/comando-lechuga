<?php

declare(strict_types=1);

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetTeamInfoRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'stats/v1/competition/1/players/status';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'x-lang' => 'es',
        ];
    }
}
