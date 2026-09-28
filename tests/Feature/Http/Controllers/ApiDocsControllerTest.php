<?php

declare(strict_types=1);

use App\Models\Season;

test('serves the api docs inline as plain text instead of triggering a download', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get('/api-docs');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition === null || !str_contains($disposition, 'attachment'))->toBeTrue();
    expect($response->getFile()->getRealPath())->toBe(realpath(resource_path('docs/api-docs.md')));
});

test('guides the AI advisor through onboarding, rules and the endpoint reference', function (): void {
    $doc = (string) file_get_contents(resource_path('docs/api-docs.md'));

    expect($doc)
        ->toContain('## 1. Cómo usar esta API (instrucciones para la IA)')
        ->toContain('¿Qué manager eres?')
        ->toContain('Prefiero no decirlo')
        ->toContain('Dudas de puntuación')
        ->toContain('## 2. Manual del juego')
        ->toContain('5-4-1')
        ->toContain('## 3. Cómo leer las señales')
        ->toContain('## 4. Preguntas típicas')
        ->toContain('## 5. Referencia de endpoints')
        ->toContain('### GET /api/season')
        ->toContain('### GET /api/teams')
        ->toContain('## 6. Glosario')
        ->toContain('FútbolFantasy');
});
