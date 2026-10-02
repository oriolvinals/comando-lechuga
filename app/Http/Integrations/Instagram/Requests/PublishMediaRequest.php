<?php

declare(strict_types=1);

namespace App\Http\Integrations\Instagram\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;

class PublishMediaRequest extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $graphVersion,
        private readonly string $userId,
        private readonly string $containerId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "{$this->graphVersion}/{$this->userId}/media_publish";
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return [
            'creation_id' => $this->containerId,
        ];
    }
}
