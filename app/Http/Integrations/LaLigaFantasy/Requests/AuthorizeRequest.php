<?php

declare(strict_types=1);

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class AuthorizeRequest extends Request
{
    protected Method $method = Method::GET;

    private readonly string $policy;

    private readonly string $clientId;

    private readonly string $redirectUri;

    private readonly string $guestId;

    public function __construct(
        string $policy,
        string $clientId,
        string $redirectUri,
        string $guestId,
    ) {
        $this->policy = $policy;
        $this->clientId = $clientId;
        $this->redirectUri = $redirectUri;
        $this->guestId = $guestId;
    }

    public function resolveEndpoint(): string
    {
        return 'laligadspprob2c.onmicrosoft.com/oauth2/v2.0/authorize';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'p' => strtoupper($this->policy),
            'guestId' => $this->guestId,
            'scope' => "openid {$this->clientId} offline_access",
            'response_type' => 'code',
            'la' => 'es-es',
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
        ];
    }
}
