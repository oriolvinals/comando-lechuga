<?php

declare(strict_types=1);

use App\Http\Integrations\Instagram\InstagramConnector;
use App\Http\Integrations\Instagram\Requests\CreateStoryContainerRequest;
use App\Http\Integrations\Instagram\Requests\GetContainerStatusRequest;
use App\Http\Integrations\Instagram\Requests\PublishMediaRequest;
use App\Models\InstagramAccessToken;
use App\Services\InstagramAccessTokens;
use App\Services\InstagramApiException;
use App\Services\InstagramStoryPublisher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    Sleep::fake();
    config([
        'services.instagram.user_id' => '17841400000000000',
        'services.instagram.access_token' => 'seed-token',
        'services.instagram.graph_version' => 'v24.0',
    ]);
});

function storyPublisher(MockClient $mockClient): InstagramStoryPublisher
{
    return new InstagramStoryPublisher((new InstagramConnector(new InstagramAccessTokens))->withMockClient($mockClient));
}

test('creates a STORIES container with mentions, waits until FINISHED and publishes it', function (): void {
    $statuses = ['IN_PROGRESS', 'IN_PROGRESS', 'FINISHED'];
    $mockClient = new MockClient([
        CreateStoryContainerRequest::class => MockResponse::make(['id' => 'container-1']),
        GetContainerStatusRequest::class => function () use (&$statuses): MockResponse {
            return MockResponse::make(['status_code' => array_shift($statuses)]);
        },
        PublishMediaRequest::class => MockResponse::make(['id' => 'media-9']),
    ]);

    $mediaId = storyPublisher($mockClient)->publish('https://example.test/storage/stories/a.mp4', ['abeel19', 'abeel19', 'ciid95']);

    expect($mediaId)->toBe('media-9');
    $mockClient->assertSent(function ($request, $response): bool {
        if (!$request instanceof CreateStoryContainerRequest) {
            return false;
        }

        $pendingRequest = $response->getPendingRequest();

        return $pendingRequest->getUrl() === 'https://graph.instagram.com/v24.0/17841400000000000/media'
            && $pendingRequest->body()->all() === [
                'media_type' => 'STORIES',
                'video_url' => 'https://example.test/storage/stories/a.mp4',
                'user_tags' => '[{"username":"abeel19"},{"username":"ciid95"}]',
            ];
    });
    $mockClient->assertSentCount(3, GetContainerStatusRequest::class);
    $mockClient->assertSent(fn ($request, $response): bool => $request instanceof PublishMediaRequest && $response->getPendingRequest()->body()->all() === ['creation_id' => 'container-1']);
    Sleep::assertSleptTimes(2);
});

test('uses the newest refreshed token instead of the env seed', function (): void {
    InstagramAccessToken::factory()->create(['access_token' => 'refreshed-token']);
    $mockClient = new MockClient([
        CreateStoryContainerRequest::class => MockResponse::make(['id' => 'container-1']),
        GetContainerStatusRequest::class => MockResponse::make(['status_code' => 'FINISHED']),
        PublishMediaRequest::class => MockResponse::make(['id' => 'media-1']),
    ]);

    storyPublisher($mockClient)->publish('https://example.test/a.mp4', []);

    $mockClient->assertSent(fn ($request, $response): bool => $request instanceof CreateStoryContainerRequest && $response->getPendingRequest()->query()->get('access_token') === 'refreshed-token'
        && !array_key_exists('user_tags', $response->getPendingRequest()->body()->all()));
});

test('retries without a mention Instagram rejects and logs it', function (): void {
    Log::spy();
    $mockClient = new MockClient([
        CreateStoryContainerRequest::class => fn (PendingRequest $request): MockResponse => str_contains((string) ($request->body()->all()['user_tags'] ?? ''), 'privada')
            ? MockResponse::make(['error' => ['message' => 'The user privada cannot be tagged', 'code' => 100]], 400)
            : MockResponse::make(['id' => 'container-1']),
        GetContainerStatusRequest::class => MockResponse::make(['status_code' => 'FINISHED']),
        PublishMediaRequest::class => MockResponse::make(['id' => 'media-1']),
    ]);

    expect(storyPublisher($mockClient)->publish('https://example.test/a.mp4', ['abeel19', 'privada']))->toBe('media-1');

    $mockClient->assertSentCount(2, CreateStoryContainerRequest::class);
    $mockClient->assertSent(fn ($request, $response): bool => $request instanceof CreateStoryContainerRequest && ($response->getPendingRequest()->body()->all()['user_tags'] ?? null) === '[{"username":"abeel19"}]');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['rejected'] === ['privada'])->once();
});

test('fails clearly when the container ends in ERROR', function (): void {
    $mockClient = new MockClient([
        CreateStoryContainerRequest::class => MockResponse::make(['id' => 'container-1']),
        GetContainerStatusRequest::class => MockResponse::make(['status_code' => 'ERROR', 'status' => 'Error: video too long']),
    ]);

    expect(fn () => storyPublisher($mockClient)->publish('https://example.test/a.mp4', []))
        ->toThrow(InstagramApiException::class, 'container-1: ERROR');
    $mockClient->assertNotSent(PublishMediaRequest::class);
});

test('says explicitly that the token expired on error 190', function (): void {
    $mockClient = new MockClient([
        CreateStoryContainerRequest::class => MockResponse::make(['error' => ['message' => 'Error validating access token', 'code' => 190]], 400),
    ]);

    expect(fn () => storyPublisher($mockClient)->publish('https://example.test/a.mp4', ['abeel19']))
        ->toThrow(InstagramApiException::class, 'Instagram access token expired or invalid (error 190)');
    $mockClient->assertSentCount(1);
});
