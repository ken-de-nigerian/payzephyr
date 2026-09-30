<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Support\PackageConfig;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use Symfony\Component\HttpFoundation\Response;

final class HealthEndpointMiddleware
{
    use LogsToPaymentChannel;

    /**
     * How often (in seconds) to re-emit the unauthenticated-health-endpoint
     * warning, so a busy uptime monitor doesn't spam the log on every hit.
     */
    private const UNAUTHENTICATED_WARNING_INTERVAL_SECONDS = 3600;

    private const MISCONFIGURED_WARNING_INTERVAL_SECONDS = 3600;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $healthConfig = PackageConfig::read()->at('health_check');

        $requiresAuth = $healthConfig->flag(false, 'require_auth');
        // Only a string can be an address or a token; anything else in the
        // list is ignored rather than compared.
        $allowedIps = array_values(array_filter($healthConfig->array('allowed_ips'), 'is_string'));
        $allowedTokens = array_values(array_filter($healthConfig->array('allowed_tokens'), 'is_string'));

        if (! $requiresAuth && empty($allowedIps) && empty($allowedTokens) && ! app()->environment(['local', 'testing'])) {
            $this->warnOnceIfUnauthenticated();
        }

        if (! empty($allowedIps)) {
            $clientIp = $request->ip();
            $allowed = false;

            foreach ($allowedIps as $allowedIp) {
                if ($clientIp !== null && $this->ipMatches($clientIp, $allowedIp)) {
                    $allowed = true;
                    break;
                }
            }

            if (! $allowed) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        }

        // With require_auth on and nothing to authenticate against, every
        // request is refused - failing closed - and the reason is logged, so
        // the endpoint does not just go dark with nobody knowing why.
        if ($requiresAuth && empty($allowedIps) && empty($allowedTokens)) {
            $this->warnOnceIfMisconfigured();

            return response()->json(['error' => 'Unauthorized'], HttpStatusCodes::UNAUTHORIZED);
        }

        // A token is demanded whenever tokens are configured. With require_auth
        // on and only an IP allowlist, the allowlist - already enforced above -
        // is the authentication: demanding a token nobody could configure
        // refused every request, although the docs say either is enough.
        if (! empty($allowedTokens)) {
            $token = $request->bearerToken()
                ?? $request->header('X-Health-Token')
                ?? $request->query('token');

            // Only a string can be a token. ?token[]=x arrives as an array, and
            // casting that to a string raised a warning Laravel turns into a 500.
            if (! is_string($token) || $token === '' || ! $this->tokenIsAllowed($token, $allowedTokens)) {
                return response()->json(['error' => 'Unauthorized'], HttpStatusCodes::UNAUTHORIZED);
            }
        }

        return $next($request);
    }

    /**
     * Compare the presented token against the allow list in constant time.
     *
     * in_array() with strict comparison short-circuits on the first differing
     * byte, which leaks the length and prefix of a valid token to an attacker
     * who can time the response. The package already verifies every webhook
     * signature with hash_equals; this is the same secret-comparison problem
     * and gets the same treatment. Every candidate is compared so the work does
     * not depend on which entry matches.
     *
     * @param  array<int, string>  $allowedTokens
     */
    private function tokenIsAllowed(string $token, array $allowedTokens): bool
    {
        $allowed = false;

        foreach ($allowedTokens as $candidate) {
            if (hash_equals((string) $candidate, $token)) {
                $allowed = true;
            }
        }

        return $allowed;
    }

    private function ipMatches(string $ip, string $pattern): bool
    {
        if ($ip === $pattern) {
            return true;
        }

        if (str_contains($pattern, '/')) {
            [$subnet, $mask] = explode('/', $pattern, 2);
            $mask = (int) $mask;

            if ($mask >= 0 && $mask <= 32) {
                $ipLong = ip2long($ip);
                $subnetLong = ip2long($subnet);

                if ($ipLong !== false && $subnetLong !== false) {
                    $maskLong = -1 << (32 - $mask);

                    return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
                }
            }
        }

        return false;
    }

    private function warnOnceIfUnauthenticated(): void
    {
        $cacheKey = 'payzephyr:health_check:unauthenticated_warning';

        if (Cache::add($cacheKey, true, self::UNAUTHENTICATED_WARNING_INTERVAL_SECONDS)) {
            $this->log('warning', 'The /payments/health endpoint is exposed without authentication', [
                'hint' => 'Set PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH=true and PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS (or ALLOWED_IPS) in production. See docs/security.md#health-endpoint.',
            ]);
        }
    }

    private function warnOnceIfMisconfigured(): void
    {
        $cacheKey = 'payzephyr:health_check:misconfigured_warning';

        if (Cache::add($cacheKey, true, self::MISCONFIGURED_WARNING_INTERVAL_SECONDS)) {
            $this->log('error', 'The /payments/health endpoint requires authentication but has nothing to authenticate against, so every request is refused', [
                'hint' => 'Set PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS or PAYMENTS_HEALTH_CHECK_ALLOWED_IPS, or set PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH=false to leave it open. See docs/security.md#health-endpoint.',
            ]);
        }
    }
}
