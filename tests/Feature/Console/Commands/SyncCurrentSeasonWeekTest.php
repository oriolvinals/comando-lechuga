<?php

use App\Console\Commands\SyncCurrentSeasonWeek;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetCurrentWeekRequest;
use App\Models\Season;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('updates the current season week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetCurrentWeekRequest::class => MockResponse::make([
            'weekNumber' => 2,
        ]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonWeek::class)
        ->expectsOutput('Week 2 synchronized.')
        ->assertSuccessful();

    expect($season->refresh()->current_week)->toBe(2);
});
