<?php

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;

class LoginRequest extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $email,
        private readonly string $password,
        private readonly string $transaction,
        private readonly string $policy,
        private readonly string $csrfToken,
        private readonly string $authorizeUrl,
    ) {}

    public function resolveEndpoint(): string
    {
        return "laligadspprob2c.onmicrosoft.com/{$this->policy}/SelfAsserted";
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'tx' => $this->transaction,
            'p' => $this->policy,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Origin' => 'https://login.laliga.es',
            'Referer' => $this->authorizeUrl,
            'X-CSRF-TOKEN' => $this->csrfToken,
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return [
            'request_type' => 'RESPONSE',
            'signInName' => $this->email,
            'password_login' => $this->password,
        ];
    }
}
