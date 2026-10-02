<?php

declare(strict_types=1);

use App\Http\Integrations\Instagram\InstagramConnector;
use App\Http\Integrations\Instagram\Requests\RefreshAccessTokenRequest;
use App\Models\InstagramAccessToken;
use App\Services\InstagramAccessTokens;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    config(['services.instagram.access_token' => 'seed-token']);
});

function bindInstagramConnector(MockClient $mockClient): void
{
    app()->instance(InstagramConnector::class, (new InstagramConnector(new InstagramAccessTokens))->withMockClient($mockClient));
}

test('refreshes the token in use and stores the new one as current', function (): void {
    $this->freezeTime();
    $mockClient = new MockClient([
        RefreshAccessTokenRequest::class => MockResponse::make(['access_token' => 'new-token', 'token_type' => 'bearer', 'expires_in' => 5_184_000]),
    ]);
    bindInstagramConnector($mockClient);

    $this->artisan('instagram:refresh-token')->assertSuccessful();

    $mockClient->assertSent(fn ($request, $response): bool => $request instanceof RefreshAccessTokenRequest && str_starts_with($response->getPendingRequest()->getUrl(), 'https://graph.instagram.com/refresh_access_token')
        && $response->getPendingRequest()->query()->get('grant_type') === 'ig_refresh_token'
        && $response->getPendingRequest()->query()->get('access_token') === 'seed-token');

    $token = InstagramAccessToken::query()->sole();
    expect($token->access_token)->toBe('new-token')
        ->and($token->expires_at->toDateTimeString())->toBe(now()->addSeconds(5_184_000)->toDateTimeString())
        ->and((new InstagramAccessTokens)->current())->toBe('new-token');

    bindInstagramConnector(new MockClient([
        RefreshAccessTokenRequest::class => MockResponse::make(['access_token' => 'newer-token', 'expires_in' => 5_184_000]),
    ]));

    $this->artisan('instagram:refresh-token')->assertSuccessful();

    expect(InstagramAccessToken::query()->sole()->access_token)->toBe('newer-token');
});

test('fails saying the token expired on error 190 and keeps the current one', function (): void {
    InstagramAccessToken::factory()->create(['access_token' => 'old-token']);
    bindInstagramConnector(new MockClient([
        RefreshAccessTokenRequest::class => MockResponse::make(['error' => ['message' => 'Session has expired', 'code' => 190]], 400),
    ]));

    $this->artisan('instagram:refresh-token')
        ->expectsOutputToContain('expired or invalid (error 190)')
        ->assertFailed();

    expect(InstagramAccessToken::query()->sole()->access_token)->toBe('old-token');
});

test('is scheduled weekly', function (): void {
    Artisan::all();

    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'instagram:refresh-token'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 4 * * 1');
});
