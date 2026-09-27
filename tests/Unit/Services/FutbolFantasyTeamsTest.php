<?php

declare(strict_types=1);

use App\Services\FutbolFantasyTeams;

test('maps all 20 LaLiga teams to a distinct FútbolFantasy slug that has a team code', function (): void {
    expect(FutbolFantasyTeams::SLUGS)->toHaveCount(20)
        ->and(array_unique(FutbolFantasyTeams::SLUGS))->toHaveCount(20)
        ->and(array_keys(FutbolFantasyTeams::CODES))->toEqualCanonicalizing(array_values(FutbolFantasyTeams::SLUGS));
});

test('resolves a team page and the code FútbolFantasy writes for the team', function (): void {
    expect(FutbolFantasyTeams::slugFor(15))->toBe('real-madrid')
        ->and(FutbolFantasyTeams::codeFor(15))->toBe('RMD')
        ->and(FutbolFantasyTeams::codeFor(26))->toBe('DEP')
        ->and(FutbolFantasyTeams::codeFor(12))->toBe('MLG')
        ->and(FutbolFantasyTeams::pageUrlFor(2))->toBe('https://www.futbolfantasy.com/laliga/equipos/atletico');
});

test('knows nothing about a team outside the map', function (): void {
    expect(FutbolFantasyTeams::slugFor(999))->toBeNull()
        ->and(FutbolFantasyTeams::codeFor(999))->toBeNull()
        ->and(FutbolFantasyTeams::pageUrlFor(999))->toBeNull();
});
