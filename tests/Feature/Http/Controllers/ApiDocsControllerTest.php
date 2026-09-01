<?php

declare(strict_types=1);

use App\Models\Season;

test('serves the api docs inline as markdown instead of triggering a download', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get('/api-docs.md');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition === null || !str_contains($disposition, 'attachment'))->toBeTrue();
    expect($response->getFile()->getRealPath())->toBe(realpath(resource_path('docs/api-docs.md')));
});
