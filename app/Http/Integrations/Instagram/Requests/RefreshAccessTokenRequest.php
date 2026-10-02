<?php

declare(strict_types=1);

namespace App\Http\Integrations\Instagram\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Extends a long-lived token (valid, at least 24 h old) for another 60 days. Unversioned endpoint.
 */
class RefreshAccessTokenRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return 'refresh_access_token';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'grant_type' => 'ig_refresh_token',
        ];
    }
}
