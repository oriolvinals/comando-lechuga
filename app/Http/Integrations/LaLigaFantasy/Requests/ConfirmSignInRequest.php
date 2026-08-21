<?php

declare(strict_types=1);

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use GuzzleHttp\RequestOptions;
use Saloon\Enums\Method;
use Saloon\Http\Request;

class ConfirmSignInRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $transaction,
        private readonly string $policy,
        private readonly string $csrfToken,
    ) {}

    public function resolveEndpoint(): string
    {
        return "laligadspprob2c.onmicrosoft.com/{$this->policy}/api/SelfAsserted/confirmed";
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'rememberMe' => 'false',
            'csrf_token' => $this->csrfToken,
            'tx' => $this->transaction,
            'p' => $this->policy,
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function defaultConfig(): array
    {
        return [
            RequestOptions::ALLOW_REDIRECTS => false,
        ];
    }
}
