<?php

declare(strict_types=1);

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;

class ExchangeAuthorizationCodeRequest extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $authorizationCode,
        private readonly string $policy,
        private readonly string $clientId,
        private readonly string $redirectUri,
    ) {}

    public function resolveEndpoint(): string
    {
        return 'laligadspprob2c.onmicrosoft.com/oauth2/v2.0/token';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'p' => strtoupper($this->policy),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return [
            'code' => $this->authorizationCode,
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];
    }
}
