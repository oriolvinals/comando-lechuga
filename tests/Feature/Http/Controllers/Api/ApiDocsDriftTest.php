<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Http\Controllers\Api\ApiWorld;

/**
 * The reference part of resources/docs/api-docs.md, parsed: each
 * "### GET /api/…" heading and, under it, the first-column paths of every
 * table whose header starts with "| Campo |".
 *
 * A field row is only picked up when its first cell is exactly `` | `path` ``
 * (a backtick-quoted path right after the leading pipe); any row whose first
 * cell doesn't match that shape is silently skipped, so a malformed row
 * escapes this drift check.
 *
 * @return array<string, list<string>> normalised uri (e.g. "api/players/{}") => field paths
 */
function documentedApiReference(): array
{
    $lines = file(resource_path('docs/api-docs.md'), FILE_IGNORE_NEW_LINES);
    $reference = [];
    $endpoint = null;
    $inFieldTable = false;

    foreach ($lines === false ? [] : $lines as $line) {
        if (preg_match('/^### GET \/(api\/\S+)$/', $line, $match) === 1) {
            $endpoint = normalizedApiUri($match[1]);
            $reference[$endpoint] = [];
            $inFieldTable = false;

            continue;
        }

        if (str_starts_with($line, '## ') || str_starts_with($line, '### ')) {
            $endpoint = null;

            continue;
        }

        if ($endpoint === null) {
            continue;
        }

        if (preg_match('/^\|\s*Campo\s*\|/', $line) === 1) {
            $inFieldTable = true;

            continue;
        }

        if (!str_starts_with($line, '|')) {
            $inFieldTable = false;

            continue;
        }

        if ($inFieldTable && preg_match('/^\|\s*`([^`]+)`/', $line, $match) === 1) {
            $reference[$endpoint][] = $match[1];
        }
    }

    return $reference;
}

function normalizedApiUri(string $uri): string
{
    return (string) preg_replace('/\{[^}]+\}/', '{}', $uri);
}

/**
 * "roster[].player.id" → ["roster", "[]", "player", "id"]; "[].rank" → ["[]", "rank"].
 *
 * @return list<string>
 */
function apiPathSegments(string $path): array
{
    $segments = [];

    foreach (explode('.', $path) as $part) {
        if ($part === '[]') {
            $segments[] = '[]';
        } elseif (str_ends_with($part, '[]')) {
            $segments[] = substr($part, 0, -2);
            $segments[] = '[]';
        } else {
            $segments[] = $part;
        }
    }

    return $segments;
}

/**
 * Whether the path exists in a decoded JSON value. "[]" matches when ANY
 * element of a non-empty list has the rest of the path. A key holding null
 * exists; descending into a null does not.
 *
 * @param  list<string>  $segments
 */
function apiPathExists(mixed $value, array $segments): bool
{
    if ($segments === []) {
        return true;
    }

    $segment = array_shift($segments);

    if ($segment === '[]') {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (apiPathExists($item, $segments)) {
                return true;
            }
        }

        return false;
    }

    return is_array($value) && array_key_exists($segment, $value) && apiPathExists($value[$segment], $segments);
}

beforeEach(function (): void {
    $this->world = ApiWorld::seed();
});

test('documents every public api endpoint, and only those', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/'))
        ->map(fn (RoutingRoute $route): string => normalizedApiUri($route->uri()))
        ->sort()
        ->values()
        ->all();

    $documented = collect(array_keys(documentedApiReference()))->sort()->values()->all();

    expect($documented)->toBe($routes);
});

test('documents at least one field for every endpoint', function (): void {
    foreach (documentedApiReference() as $endpoint => $fields) {
        expect($fields)->not->toBeEmpty("{$endpoint} has no field table");
    }
});

test('every documented field exists in a real response', function (): void {
    $world = $this->world;
    $samples = [
        'api/season' => ['/api/season'],
        'api/standings' => ['/api/standings'],
        'api/managers/{}' => ["/api/managers/{$world->managerId}", "/api/managers/{$world->rivalManagerId}"],
        'api/players' => ['/api/players'],
        'api/players/{}' => ["/api/players/{$world->ownedPlayerId}", "/api/players/{$world->listedPlayerId}"],
        'api/market' => ['/api/market'],
        'api/activity' => ['/api/activity'],
        'api/fixtures' => ['/api/fixtures'],
        'api/fixtures/{}' => ["/api/fixtures/{$world->finishedFixtureId}"],
        'api/teams' => ['/api/teams'],
    ];

    foreach (documentedApiReference() as $endpoint => $fields) {
        expect($samples)->toHaveKey($endpoint);

        $bodies = array_map(function (string $url): array {
            $response = $this->getJson($url);
            $response->assertOk();

            return (array) $response->json();
        }, $samples[$endpoint]);

        foreach ($fields as $path) {
            $segments = apiPathSegments($path);
            $fromRoot = in_array($segments[0], ['meta', 'links'], true);

            $found = collect($bodies)->contains(
                fn (array $body): bool => apiPathExists($fromRoot ? $body : ($body['data'] ?? null), $segments),
            );

            expect($found)->toBeTrue("{$endpoint}: documented field `{$path}` is missing from every sample response");
        }
    }
});

test('never names the private bid model', function (): void {
    $doc = mb_strtolower((string) file_get_contents(resource_path('docs/api-docs.md')));

    foreach (['puja máxima', 'puja maxima', 'max bid', 'max_bid', 'maxbid', 'god mode', 'god_mode', 'godmode'] as $term) {
        expect(str_contains($doc, $term))->toBeFalse("the docs mention \"{$term}\"");
    }
});
