<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class HandleGodMode
{
    /**
     * Name of the request attribute the resolved flag is stored under, and of
     * the forever cookie that remembers an explicit opt-in across visits.
     */
    private const string ATTRIBUTE = 'god_mode';

    /**
     * Resolves the generic "god mode" switch that unlocks hidden features
     * (e.g. the max-bid card) for the rest of the request:
     * - `?god_mode=<key>`, matching the configured `services.god_mode.key`
     *   secret, turns it on and remembers that in a forever cookie (which
     *   Laravel's EncryptCookies middleware encrypts, so it can't be forged).
     * - `?god_mode=0` turns it off and forgets the cookie, even if present.
     * - No `god_mode` parameter at all: the remembered cookie decides.
     * - Any other value (wrong key, a bare `?god_mode`, or an empty value) is
     *   off for this request only, and leaves the cookie untouched. When the
     *   configured key is empty or unset, god mode can never be enabled by
     *   the parameter, though an already-remembered cookie still works.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, $this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): bool
    {
        if (!$request->has(self::ATTRIBUTE)) {
            return $request->cookie(self::ATTRIBUTE) === '1';
        }

        $godMode = $request->query(self::ATTRIBUTE);

        if ($godMode === '0') {
            Cookie::queue(Cookie::forget(self::ATTRIBUTE));

            return false;
        }

        if (is_scalar($godMode) && $this->matchesConfiguredKey((string) $godMode)) {
            Cookie::queue(Cookie::forever(self::ATTRIBUTE, '1'));

            return true;
        }

        return false;
    }

    private function matchesConfiguredKey(string $value): bool
    {
        $key = config('services.god_mode.key');

        if (!is_string($key) || $key === '') {
            return false;
        }

        return hash_equals($key, $value);
    }

    /**
     * Whether god mode is on for this request, as resolved by this
     * middleware. Defaults to false when the middleware never ran.
     */
    public static function isEnabled(Request $request): bool
    {
        return $request->attributes->get(self::ATTRIBUTE, false) === true;
    }
}
