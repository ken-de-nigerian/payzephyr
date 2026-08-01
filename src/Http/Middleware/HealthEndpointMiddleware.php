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

        // require_auth defaults to false for backward compatibility (see
        // ADR-0002 - flipping it silently 401-locks every existing consumer,
        // since allowed_tokens also defaults empty). Surface the exposure
        // instead of silently accepting it, without spamming the log per hit.
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

            if (empty($token) || ! in_array($token, $allowedTokens, true)) {
                return response()->json(['error' => 'Unauthorized'], HttpStatusCodes::UNAUTHORIZED);
            }
        }

        return $next($request);
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

        // Cache::add() is atomic - concurrent requests only ever log once
        // per interval, regardless of traffic volume.
        if (Cache::add($cacheKey, true, self::UNAUTHENTICATED_WARNING_INTERVAL_SECONDS)) {
            $this->log('warning', 'The /payments/health endpoint is exposed without authentication', [
                'hint' => 'Set PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH=true and PAYMENTS_HEALTH_CHECK_ALLOWED_TOKENS (or ALLOWED_IPS) in production. See docs/SECURITY.md#3-health-endpoint-security.',
            ]);
        }
    }
}
