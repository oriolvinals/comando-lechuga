<?php

declare(strict_types=1);

namespace App\Http\Integrations\FutbolFantasy\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetTeamPageRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $slug) {}

    public function resolveEndpoint(): string
    {
        return "laliga/equipos/{$this->slug}";
    }
}
