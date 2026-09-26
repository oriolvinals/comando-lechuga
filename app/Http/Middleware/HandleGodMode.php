<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Support\Header;
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
     *   Laravel's EncryptCookies middleware encrypts, so it can't be forged —
     *   a plain-text/unencrypted cookie fails decryption and is dropped
     *   entirely before this middleware ever sees it).
     * - `?god_mode=0` turns it off and forgets the cookie, even if present.
     * - No `god_mode` parameter at all: the remembered cookie decides, and
     *   only a literal `'1'` counts.
     * - Any other value (wrong key, a bare `?god_mode`, or an empty value) is
     *   off for this request only, and leaves the cookie untouched. When the
     *   configured key is empty or unset, god mode can never be enabled by
     *   the parameter, though an already-remembered cookie still works. A
     *   bare `?god_mode` arrives here as `null` (Laravel's global
     *   `ConvertEmptyStringsToNull` middleware converts the empty string
     *   before this one runs), which is treated the same as an empty value.
     * - An array value (e.g. `?god_mode[]=x`) is simply ignored: off for this
     *   request, cookie untouched, no redirect (there is no scalar attempt
     *   worth scrubbing from the URL).
     *
     * Only the query string is ever consulted (never the request body), via
     * `$request->query`, so a POST field named `god_mode` has no effect.
     *
     * Once resolved from a query value, `god_mode` never needs to stay in the
     * URL: a plain GET request redirects to the same URL with the param
     * stripped (every other query param, e.g. `confianza`, is preserved),
     * carrying any cookie change along on that redirect. This is skipped for
     * an Inertia partial reload (an XHR fetch, not a page the user looks at
     * or could bookmark/share) and, to keep it simple, for any non-GET
     * request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->query->has(self::ATTRIBUTE)) {
            $request->attributes->set(self::ATTRIBUTE, $request->cookie(self::ATTRIBUTE) === '1');

            return $next($request);
        }

        $godMode = $request->query(self::ATTRIBUTE);

        if (is_array($godMode)) {
            $request->attributes->set(self::ATTRIBUTE, false);

            return $next($request);
        }

        $enabled = $this->applyScalarValue((string) $godMode);

        if ($this->shouldRedirectWithoutParam($request)) {
            return $this->redirectWithoutParam($request);
        }

        $request->attributes->set(self::ATTRIBUTE, $enabled);

        return $next($request);
    }

    /**
     * Applies a scalar `god_mode` query value: queues or forgets the cookie
     * as a side effect, and returns whether god mode is on for this request.
     */
    private function applyScalarValue(string $godMode): bool
    {
        if ($godMode === '0') {
            Cookie::queue(Cookie::forget(self::ATTRIBUTE));

            return false;
        }

        if ($this->matchesConfiguredKey($godMode)) {
            Cookie::queue(Cookie::forever(self::ATTRIBUTE, '1'));

            return true;
        }

        return false;
    }

    private function shouldRedirectWithoutParam(Request $request): bool
    {
        return $request->isMethod('GET') && !$request->headers->has(Header::PARTIAL_ONLY);
    }

    private function redirectWithoutParam(Request $request): RedirectResponse
    {
        return redirect()->to($request->fullUrlWithoutQuery(self::ATTRIBUTE));
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
