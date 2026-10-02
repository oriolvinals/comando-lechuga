<?php

declare(strict_types=1);

use App\Enums\PublishedStoryType;
use App\Enums\SeasonActivityType;
use App\Http\Integrations\Instagram\InstagramConnector;
use App\Http\Integrations\Instagram\Requests\CreateStoryContainerRequest;
use App\Http\Integrations\Instagram\Requests\GetContainerStatusRequest;
use App\Http\Integrations\Instagram\Requests\PublishMediaRequest;
use App\Models\Activity;
use App\Models\PublishedStory;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\InstagramAccessTokens;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    Sleep::fake();
    Storage::fake('public');

    $this->remotionPath = sys_get_temp_dir().'/remotion-test-'.uniqid();
    foreach (['data', 'src/generated', 'out'] as $directory) {
        File::ensureDirectoryExists("{$this->remotionPath}/{$directory}");
    }

    config([
        'services.remotion.path' => $this->remotionPath,
        'services.instagram.user_id' => '17841400000000000',
        'services.instagram.access_token' => 'seed-token',
        'services.instagram.graph_version' => 'v24.0',
    ]);

    $this->season = Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 22:05', 'Europe/Madrid'));
});

afterEach(function (): void {
    File::deleteDirectory($this->remotionPath);
});

/**
 * Fakes the Remotion scripts: the build splits the exported buys into parts of the given sizes (one part with every
 * buy by default) and the render writes one MP4 per part.
 *
 * @param  list<int>|null  $playersPerPart
 */
function fakeRemotion(string $path, ?array $playersPerPart = null): void
{
    Process::fake([
        '*build:compras*' => function (PendingProcess $process) use ($path, $playersPerPart) {
            preg_match('/(\d{4}-\d{2}-\d{2})$/', (string) $process->command, $match);
            $data = json_decode((string) file_get_contents("{$path}/data/compras-{$match[1]}.json"), true);
            $sizes = $playersPerPart ?? [count($data['buys'])];
            $parts = array_map(fn (int $size): array => ['frames' => [
                ['kind' => 'intro'],
                ...array_fill(0, $size, ['kind' => 'player']),
            ]], $sizes);
            file_put_contents("{$path}/src/generated/compras-{$match[1]}.json", json_encode(['parts' => $parts]));

            return Process::result('built');
        },
        '*render:compras*' => function (PendingProcess $process) use ($path) {
            preg_match('/(\d{4}-\d{2}-\d{2})$/', (string) $process->command, $match);
            $spec = json_decode((string) file_get_contents("{$path}/src/generated/compras-{$match[1]}.json"), true);
            $count = count($spec['parts']);
            $output = [];
            foreach (range(1, $count) as $part) {
                $video = "{$path}/out/compras-{$match[1]}".($count === 1 ? '' : "-p{$part}").'.mp4';
                file_put_contents($video, "video {$part}");
                array_push($output, "\e[32mRendered 150/300\e[0m", 'Rendered 300/300', $video, 'faststart: OK');
            }

            return Process::result(implode("\r\n", $output));
        },
    ]);
}

/**
 * Binds an Instagram connector whose container creation answers with $createResponses in turn (then OK), whose
 * containers are FINISHED and whose publishes return media-1, media-2…
 *
 * @param  list<MockResponse>  $createResponses
 */
function fakeInstagram(array $createResponses = [], ?MockResponse $publishFailure = null, int $failPublishAt = 0): MockClient
{
    $containers = 0;
    $publishes = 0;
    $mockClient = new MockClient([
        CreateStoryContainerRequest::class => function () use (&$containers, $createResponses): MockResponse {
            return $createResponses[$containers++] ?? MockResponse::make(['id' => 'container-'.$containers]);
        },
        GetContainerStatusRequest::class => MockResponse::make(['status_code' => 'FINISHED']),
        PublishMediaRequest::class => function () use (&$publishes, $publishFailure, $failPublishAt): MockResponse {
            $publishes++;

            return $publishes === $failPublishAt && $publishFailure !== null
                ? $publishFailure
                : MockResponse::make(['id' => 'media-'.$publishes]);
        },
    ]);

    app()->instance(InstagramConnector::class, (new InstagramConnector(new InstagramAccessTokens))->withMockClient($mockClient));

    return $mockClient;
}

function signingOn(Season $season, string $occurredAt, string $instagramUsername = ''): Activity
{
    return Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => SeasonManager::factory()->create([
            'season_id' => $season->id,
            'instagram_username' => $instagramUsername,
        ])->id,
        'occurred_at' => CarbonImmutable::parse($occurredAt, 'Europe/Madrid'),
    ]);
}

/**
 * @return list<string>
 */
function sentMentions(MockClient $mockClient): array
{
    return collect($mockClient->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest())
        ->filter(fn (PendingRequest $request): bool => $request->getRequest() instanceof CreateStoryContainerRequest)
        ->map(fn (PendingRequest $request): string => (string) ($request->body()?->all()['user_tags'] ?? ''))
        ->values()
        ->all();
}

test('does nothing beyond the cheap query when there are no unshared signings today', function (): void {
    signingOn($this->season, '2026-10-02 22:00')->forceFill(['shared_at' => now()])->save();
    fakeRemotion($this->remotionPath);
    $mockClient = fakeInstagram();

    $this->artisan('stories:publish-market-signings')
        ->expectsOutputToContain('Sin fichajes pendientes del 2026-10-02: nada que publicar.')
        ->assertSuccessful();

    Process::assertNothingRan();
    $mockClient->assertNothingSent();
    expect(PublishedStory::query()->count())->toBe(0);
});

test('renders, publishes and marks the unshared signings of the Madrid day, mentioning their buyers', function (): void {
    $first = signingOn($this->season, '2026-10-02 21:58', 'oriolvinals');
    $second = signingOn($this->season, '2026-10-02 22:00');
    $yesterday = signingOn($this->season, '2026-10-01 23:59');
    fakeRemotion($this->remotionPath);
    $mockClient = fakeInstagram();

    $this->artisan('stories:publish-market-signings')
        ->expectsOutputToContain('Comprobando fichajes pendientes del 2026-10-02…')
        ->expectsOutputToContain('Exportando datos…')
        ->expectsOutputToContain('2 fichajes pendientes → 1 parte')
        ->expectsOutputToContain('Renderizando parte 1/1…')
        ->expectsOutputToContain('Subiendo a storage…')
        ->expectsOutputToContain('Creando contenedor en Instagram…')
        ->expectsOutputToContain('Publicada parte 1/1 · media_id media-1')
        ->expectsOutputToContain('Marcadas 2 actividades como compartidas.')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'npm run build:compras -- 2026-10-02'
        && $process->path === $this->remotionPath
        && $process->timeout === 600);
    Process::assertRan('npm run render:compras -- 2026-10-02');

    $data = json_decode((string) file_get_contents("{$this->remotionPath}/data/compras-2026-10-02.json"), true);
    expect(array_column($data['buys'], 'id'))->toBe([$first->id, $second->id])
        ->and(sentMentions($mockClient))->toBe(['[{"username":"oriolvinals"}]'])
        ->and($first->refresh()->shared_at)->not->toBeNull()
        ->and($second->refresh()->shared_at)->not->toBeNull()
        ->and($yesterday->refresh()->shared_at)->toBeNull();

    $mockClient->assertSent(fn ($request, $response): bool => $request instanceof CreateStoryContainerRequest
        && $response->getPendingRequest()->body()->all()['video_url'] === Storage::disk('public')->url('stories/compras-2026-10-02.mp4')
        && $response->getPendingRequest()->query()->get('access_token') === 'seed-token');

    $story = PublishedStory::query()->sole();
    expect($story->type)->toBe(PublishedStoryType::MarketSignings)
        ->and($story->date->toDateString())->toBe('2026-10-02')
        ->and([$story->batch, $story->part, $story->parts, $story->signings_count])->toBe([1, 1, 1, 2])
        ->and($story->activity_ids)->toBe([$first->id, $second->id])
        ->and($story->media_id)->toBe('media-1');

    Storage::disk('public')->assertMissing('stories/compras-2026-10-02.mp4');
    expect("{$this->remotionPath}/out/compras-2026-10-02.mp4")->not->toBeFile();
});

test('publishes a later batch with only the signings that arrived after the first story', function (): void {
    $shared = signingOn($this->season, '2026-10-02 20:00');
    $shared->forceFill(['shared_at' => now()])->save();
    PublishedStory::factory()->create(['date' => '2026-10-02', 'batch' => 1, 'activity_ids' => [$shared->id], 'signings_count' => 1]);
    $late = signingOn($this->season, '2026-10-02 22:01');
    fakeRemotion($this->remotionPath);
    fakeInstagram();

    $this->artisan('stories:publish-market-signings')->assertSuccessful();

    $data = json_decode((string) file_get_contents("{$this->remotionPath}/data/compras-2026-10-02.json"), true);
    expect(array_column($data['buys'], 'id'))->toBe([$late->id])
        ->and(PublishedStory::query()->where('batch', 2)->sole()->activity_ids)->toBe([$late->id])
        ->and($late->refresh()->shared_at)->not->toBeNull();
});

test('publishes the parts in order with each part\'s mentions, and resumes a failed part without republishing the first', function (): void {
    $partOne = signingOn($this->season, '2026-10-02 22:00', 'abeel19');
    $partTwo = signingOn($this->season, '2026-10-02 22:01', 'ciid95');
    fakeRemotion($this->remotionPath, [1, 1]);
    $failingClient = fakeInstagram(publishFailure: MockResponse::make(['error' => ['message' => 'Media upload failed', 'code' => 9007]], 400), failPublishAt: 2);

    $this->artisan('stories:publish-market-signings')
        ->expectsOutputToContain('Renderizando parte 1/2…')
        ->expectsOutputToContain('Rendered 150/300 (50 %)')
        ->expectsOutputToContain('Renderizando parte 2/2…')
        ->expectsOutputToContain('Publicada parte 1/2 · media_id media-1')
        ->expectsOutputToContain('Media upload failed')
        ->assertFailed();

    expect(PublishedStory::query()->pluck('part')->all())->toBe([1])
        ->and(sentMentions($failingClient))->toBe(['[{"username":"abeel19"}]', '[{"username":"ciid95"}]'])
        ->and($partOne->refresh()->shared_at)->toBeNull()
        ->and($partTwo->refresh()->shared_at)->toBeNull();

    $retryClient = fakeInstagram();

    $this->artisan('stories:publish-market-signings')
        ->expectsOutputToContain('Parte 1/2 ya publicada (media_id media-1)')
        ->assertSuccessful();

    expect(sentMentions($retryClient))->toBe(['[{"username":"ciid95"}]'])
        ->and(PublishedStory::query()->orderBy('part')->get(['batch', 'part', 'parts'])->toArray())
        ->toBe([['batch' => 1, 'part' => 1, 'parts' => 2], ['batch' => 1, 'part' => 2, 'parts' => 2]])
        ->and($partOne->refresh()->shared_at)->not->toBeNull()
        ->and($partTwo->refresh()->shared_at)->not->toBeNull();
});

test('publishes the empty-market story only on the last run of a day without signings', function (): void {
    fakeRemotion($this->remotionPath);
    fakeInstagram();

    $this->travelTo(CarbonImmutable::parse('2026-10-02 22:50', 'Europe/Madrid'));
    $this->artisan('stories:publish-market-signings')->assertSuccessful();
    Process::assertNothingRan();

    $this->travelTo(CarbonImmutable::parse('2026-10-02 23:05', 'Europe/Madrid'));
    $this->artisan('stories:publish-market-signings')->assertSuccessful();

    $data = json_decode((string) file_get_contents("{$this->remotionPath}/data/compras-2026-10-02.json"), true);
    $story = PublishedStory::query()->sole();
    expect($data['buys'])->toBe([])
        ->and([$story->signings_count, $story->activity_ids])->toBe([0, []]);

    $this->artisan('stories:publish-market-signings')->assertSuccessful();
    expect(PublishedStory::query()->count())->toBe(1);
});

test('does not publish the empty-market story on the last run when the day had signings', function (): void {
    signingOn($this->season, '2026-10-02 20:00')->forceFill(['shared_at' => now()])->save();
    fakeRemotion($this->remotionPath);
    $mockClient = fakeInstagram();
    $this->travelTo(CarbonImmutable::parse('2026-10-02 23:05', 'Europe/Madrid'));

    $this->artisan('stories:publish-market-signings')->assertSuccessful();

    $mockClient->assertNothingSent();
});

test('a past --date publishes its unshared signings outside the time window', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Europe/Madrid'));
    $signing = signingOn($this->season, '2026-09-30 22:00');
    fakeRemotion($this->remotionPath);

    $mockClient = fakeInstagram();

    $this->artisan('stories:publish-market-signings', ['--date' => '2026-09-30'])->assertSuccessful();

    $data = json_decode((string) file_get_contents("{$this->remotionPath}/data/compras-2026-09-30.json"), true);
    Process::assertRan('npm run build:compras -- 2026-09-30');
    Process::assertRan('npm run render:compras -- 2026-09-30');
    $mockClient->assertSent(fn ($request, $response): bool => $request instanceof CreateStoryContainerRequest
        && $response->getPendingRequest()->body()->all()['video_url'] === Storage::disk('public')->url('stories/compras-2026-09-30.mp4'));
    expect($data['date'])->toBe('2026-09-30')
        ->and(array_column($data['buys'], 'id'))->toBe([$signing->id])
        ->and($signing->refresh()->shared_at)->not->toBeNull()
        ->and(PublishedStory::query()->sole()->date->toDateString())->toBe('2026-09-30');
});

test('a past --date whose signings are all shared (even by the backfill, without a registry row) does nothing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Europe/Madrid'));
    signingOn($this->season, '2026-10-01 22:00')->forceFill(['shared_at' => '2026-10-01 22:00:00'])->save();
    fakeRemotion($this->remotionPath);
    $mockClient = fakeInstagram();

    $this->artisan('stories:publish-market-signings', ['--date' => '2026-10-01'])
        ->expectsOutputToContain('Sin fichajes pendientes del 2026-10-01: nada que publicar.')
        ->assertSuccessful();

    Process::assertNothingRan();
    $mockClient->assertNothingSent();
    expect(PublishedStory::query()->count())->toBe(0);
});

test('a past --date without signings publishes the empty-market story right away, once', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Europe/Madrid'));
    fakeRemotion($this->remotionPath);
    fakeInstagram();

    $this->artisan('stories:publish-market-signings', ['--date' => '2026-09-29'])->assertSuccessful();
    $this->artisan('stories:publish-market-signings', ['--date' => '2026-09-29'])->assertSuccessful();

    $story = PublishedStory::query()->sole();
    expect($story->date->toDateString())->toBe('2026-09-29')
        ->and($story->signings_count)->toBe(0);
});

test('rejects a future or malformed --date', function (string $date): void {
    fakeRemotion($this->remotionPath);

    $this->artisan('stories:publish-market-signings', ['--date' => $date])->assertFailed();

    Process::assertNothingRan();
})->with(['2026-10-03', '2026-13-01', 'ayer']);

test('a dry run exports and renders but neither publishes nor marks', function (): void {
    $signing = signingOn($this->season, '2026-10-02 22:00', 'oriolvinals');
    fakeRemotion($this->remotionPath);
    $mockClient = fakeInstagram();

    $this->artisan('stories:publish-market-signings', ['--dry-run' => true])
        ->expectsOutputToContain('Simulación (--dry-run)')
        ->assertSuccessful();

    Process::assertRan('npm run render:compras -- 2026-10-02');
    $mockClient->assertNothingSent();
    Storage::disk('public')->assertExists('stories/compras-2026-10-02.mp4');
    expect($signing->refresh()->shared_at)->toBeNull()
        ->and(PublishedStory::query()->count())->toBe(0);
});

test('on the production server the render runs niced and with the configured Chrome concurrency', function (): void {
    config(['services.remotion.nice' => true, 'services.remotion.concurrency' => '1']);
    signingOn($this->season, '2026-10-02 22:00');
    fakeRemotion($this->remotionPath);
    fakeInstagram();

    $this->artisan('stories:publish-market-signings')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'nice -n 10 npm run render:compras -- 2026-10-02'
        && $process->environment === ['REMOTION_CONCURRENCY' => '1']);
});

test('--force republishes every signing of the day, already shared or not', function (): void {
    $shared = signingOn($this->season, '2026-10-02 20:00');
    $shared->forceFill(['shared_at' => now()])->save();
    PublishedStory::factory()->create(['date' => '2026-10-02', 'activity_ids' => [$shared->id], 'signings_count' => 1]);
    fakeRemotion($this->remotionPath);
    fakeInstagram();

    $this->artisan('stories:publish-market-signings', ['--force' => true])->assertSuccessful();

    expect(PublishedStory::query()->where('batch', 2)->sole()->activity_ids)->toBe([$shared->id]);
});

test('fails without marking anything when the render fails', function (): void {
    $signing = signingOn($this->season, '2026-10-02 22:00');
    Process::fake(['*' => Process::result(output: '', errorOutput: 'remotion exploded', exitCode: 1)]);
    $mockClient = fakeInstagram();

    $this->artisan('stories:publish-market-signings')
        ->expectsOutputToContain('remotion exploded')
        ->assertFailed();

    $mockClient->assertNothingSent();
    expect($signing->refresh()->shared_at)->toBeNull();
});

test('is scheduled every 15 minutes from 20:05 to 23:05 Madrid time', function (): void {
    Artisan::all();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'stories:publish-market-signings'));

    $dueAt = [];
    $minute = CarbonImmutable::parse('2026-10-02 00:00', 'Europe/Madrid');

    for ($offset = 0; $offset < 24 * 60; $offset++) {
        $this->travelTo($minute->addMinutes($offset));

        if ($events->contains(fn (Event $event): bool => $event->isDue(app()))) {
            $dueAt[] = now('Europe/Madrid')->format('H:i');
        }
    }

    expect($dueAt)->toBe([
        '20:05', '20:20', '20:35', '20:50', '21:05', '21:20', '21:35', '21:50',
        '22:05', '22:20', '22:35', '22:50', '23:05',
    ])->and($events->every(fn (Event $event): bool => $event->withoutOverlapping && $event->onOneServer
        && $event->mutexName() === 'stories:publish-market-signings'))->toBeTrue();
});
