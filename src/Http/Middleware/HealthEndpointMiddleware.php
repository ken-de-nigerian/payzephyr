<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
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

    public function handle(Request $request, Closure $next): Response
    {
        $config = app('payments.config') ?? config('payments', []);
        $healthConfig = $config['health_check'] ?? [];

        $requiresAuth = $healthConfig['require_auth'] ?? false;
        $allowedIps = $healthConfig['allowed_ips'] ?? [];
        $allowedTokens = $healthConfig['allowed_tokens'] ?? [];

        if (! $requiresAuth && empty($allowedIps) && empty($allowedTokens) && ! app()->environment(['local', 'testing'])) {
            $this->warnOnceIfUnauthenticated();
        }

        if (! empty($allowedIps)) {
            $clientIp = $request->ip();
            $allowed = false;

            foreach ($allowedIps as $allowedIp) {
                if ($this->ipMatches($clientIp, $allowedIp)) {
                    $allowed = true;
                    break;
                }
            }

            if (! $allowed) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        }

        if ($requiresAuth || ! empty($allowedTokens)) {
            $token = $request->bearerToken()
                ?? $request->header('X-Health-Token')
                ?? $request->query('token');

            if (empty($token) || ! $this->tokenIsAllowed((string) $token, $allowedTokens)) {
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
                'hint' => 'Set PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH=true and PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS (or ALLOWED_IPS) in production. See docs/SECURITY.md#3-health-endpoint-security.',
            ]);
        }
    }
}
