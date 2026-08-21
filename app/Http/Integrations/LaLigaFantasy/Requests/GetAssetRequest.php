<?php

declare(strict_types=1);

namespace App\Http\Integrations\LaLigaFantasy\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetAssetRequest extends Request
{
    public ?bool $allowBaseUrlOverride = true;

    protected Method $method = Method::GET;

    private readonly string $url;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function resolveEndpoint(): string
    {
        return $this->url;
    }
}
