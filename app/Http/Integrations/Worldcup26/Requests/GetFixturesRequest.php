<?php

declare(strict_types=1);

namespace App\Http\Integrations\Worldcup26\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetFixturesRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $pageIndex = 1)
    {
    }

    public function resolveEndpoint(): string
    {
        return 'get/soccer/esp.1/fixtures';
    }

    /**
     * @return array<string, int|string>
     */
    protected function defaultQuery(): array
    {
        return [
            'status' => 'all',
            'page' => $this->pageIndex,
        ];
    }
}
