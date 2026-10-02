<?php

declare(strict_types=1);

namespace App\Http\Integrations\Instagram\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetContainerStatusRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $graphVersion,
        private readonly string $containerId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "{$this->graphVersion}/{$this->containerId}";
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return [
            'fields' => 'status_code,status',
        ];
    }
}
