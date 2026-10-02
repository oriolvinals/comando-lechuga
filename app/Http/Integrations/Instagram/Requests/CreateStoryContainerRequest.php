<?php

declare(strict_types=1);

namespace App\Http\Integrations\Instagram\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;

/**
 * Creates a STORIES video container. Story mentions are `user_tags` with a username only (no x/y coordinates).
 */
class CreateStoryContainerRequest extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    /**
     * @param  list<string>  $mentionedUsernames
     */
    public function __construct(
        private readonly string $graphVersion,
        private readonly string $userId,
        private readonly string $videoUrl,
        private readonly array $mentionedUsernames,
    ) {}

    public function resolveEndpoint(): string
    {
        return "{$this->graphVersion}/{$this->userId}/media";
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        $body = [
            'media_type' => 'STORIES',
            'video_url' => $this->videoUrl,
        ];

        if ($this->mentionedUsernames !== []) {
            $body['user_tags'] = (string) json_encode(
                array_map(fn (string $username): array => ['username' => $username], $this->mentionedUsernames),
                JSON_THROW_ON_ERROR,
            );
        }

        return $body;
    }
}
