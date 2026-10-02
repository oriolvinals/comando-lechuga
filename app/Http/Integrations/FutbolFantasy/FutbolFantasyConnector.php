<?php

declare(strict_types=1);

namespace App\Http\Integrations\FutbolFantasy;

use App\Http\Integrations\FutbolFantasy\Requests\GetTeamPageRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * FútbolFantasy's public, server-rendered team pages (~2.4 MB each) — the
 * source of the start probabilities. One attempt per page (no retries);
 * the sync command spaces requests 2–5 s apart.
 */
class FutbolFantasyConnector extends Connector
{
    use HasTimeout;

    /** Anonymous and project-identifying — never personal data. */
    public const string USER_AGENT = 'ComandoLechuga/1.0 (fantasy league viewer)';

    protected float $connectTimeout = 5;

    protected float $requestTimeout = 20;

    public function resolveBaseUrl(): string
    {
        return (string) config('services.futbolfantasy.base_url');
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getTeamPage(string $slug): Response
    {
        return $this->send(new GetTeamPageRequest($slug));
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html',
            'Accept-Encoding' => 'gzip',
        ];
    }
}
