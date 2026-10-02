<?php

declare(strict_types=1);

namespace App\Http\Integrations\Instagram;

use App\Http\Integrations\Instagram\Requests\CreateStoryContainerRequest;
use App\Http\Integrations\Instagram\Requests\GetContainerStatusRequest;
use App\Http\Integrations\Instagram\Requests\PublishMediaRequest;
use App\Http\Integrations\Instagram\Requests\RefreshAccessTokenRequest;
use App\Services\InstagramAccessTokens;
use Saloon\Contracts\Authenticator;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Auth\QueryAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Instagram API with Instagram Login (graph.instagram.com). Every request carries the token in use
 * (InstagramAccessTokens::current()), so a refreshed token is picked up without a deploy.
 */
class InstagramConnector extends Connector
{
    use HasTimeout;

    protected float $connectTimeout = 5;

    protected float $requestTimeout = 60;

    public function __construct(private readonly InstagramAccessTokens $accessTokens) {}

    public function resolveBaseUrl(): string
    {
        return (string) config('services.instagram.base_url');
    }

    /**
     * @param  list<string>  $mentionedUsernames
     *
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function createStoryContainer(string $videoUrl, array $mentionedUsernames): Response
    {
        return $this->send(new CreateStoryContainerRequest($this->graphVersion(), $this->userId(), $videoUrl, $mentionedUsernames));
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getContainerStatus(string $containerId): Response
    {
        return $this->send(new GetContainerStatusRequest($this->graphVersion(), $containerId));
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function publishMedia(string $containerId): Response
    {
        return $this->send(new PublishMediaRequest($this->graphVersion(), $this->userId(), $containerId));
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function refreshAccessToken(): Response
    {
        return $this->send(new RefreshAccessTokenRequest);
    }

    protected function defaultAuth(): Authenticator
    {
        return new QueryAuthenticator('access_token', $this->accessTokens->current());
    }

    private function graphVersion(): string
    {
        return (string) config('services.instagram.graph_version');
    }

    private function userId(): string
    {
        return (string) config('services.instagram.user_id');
    }
}
