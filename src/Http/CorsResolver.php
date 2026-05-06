<?php declare(strict_types=1);

namespace Coding9\AgentReady\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the value of the `Access-Control-Allow-Origin` header for an
 * incoming request given a configured allowlist.
 *
 * The matrix:
 *
 *   - allowlist empty       → return null (don't emit any CORS header)
 *   - allowlist ['*']       → return '*' (development convenience)
 *   - request has no Origin → return null (not a browser cross-origin call)
 *   - Origin matches list   → return the matched origin (echo back, not '*')
 *   - Origin doesn't match  → return null (browser will block)
 */
final class CorsResolver
{
    /** @param array<int, string> $allowlist */
    public static function resolve(Request $request, array $allowlist): ?string
    {
        if ($allowlist === []) {
            return null;
        }

        if (in_array('*', $allowlist, true)) {
            return '*';
        }

        $origin = $request->headers->get('Origin');
        if (!is_string($origin) || $origin === '') {
            return null;
        }

        return in_array($origin, $allowlist, true) ? $origin : null;
    }
}
