<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every JSON response of the public API with when it was generated
 * and the timezone its datetimes use, and rewrites every ISO 8601 datetime
 * already in the payload (serialised in the app's storage timezone, UTC)
 * into that same Europe/Madrid timezone. An AI advisor can then tell (and
 * quote) how fresh the data is, and never has to convert a timestamp itself.
 * The stamp merges into an existing `meta`, such as pagination, instead of
 * replacing it. The middleware is prepended to the `api` group so it also
 * wraps route-model-binding 404s.
 */
class AddApiResponseMeta
{
    public const string TIMEZONE = 'Europe/Madrid';

    /** Matches an ISO 8601 datetime (date + time + offset), never a bare date. */
    private const string DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (!is_array($payload)) {
            return $response;
        }

        $payload = self::convertDatetimesToMadrid($payload);

        if (!array_is_list($payload)) {
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

            $payload['meta'] = [
                ...$meta,
                'generated_at' => now()->setTimezone(self::TIMEZONE)->toIso8601String(),
                'timezone' => self::TIMEZONE,
            ];
        }

        $response->setData($payload);

        return $response;
    }

    /**
     * Recursively rewrites every ISO 8601 datetime string of the payload into
     * Europe/Madrid, leaving bare dates (e.g. a market history entry's
     * `date`) and every other value untouched.
     */
    private static function convertDatetimesToMadrid(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::convertDatetimesToMadrid(...), $value);
        }

        if (is_string($value) && preg_match(self::DATETIME_PATTERN, $value) === 1) {
            return CarbonImmutable::parse($value)->setTimezone(self::TIMEZONE)->toIso8601String();
        }

        return $value;
    }
}
