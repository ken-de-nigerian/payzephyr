<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;
use KenDeNigerian\PayZephyr\Http\Middleware\HealthEndpointMiddleware;

/**
 * Covers the allowed_ips / allowed_tokens branches of HealthEndpointMiddleware
 * that tests/Unit/HealthEndpointTest.php doesn't exercise: the IP allowlist
 * (including CIDR matching), token resolution from the X-Health-Token header
 * and the ?token= query string, and rejection of missing/invalid tokens.
 */
function makeHealthRequest(array $server = [], array $headers = [], array $query = []): \Symfony\Component\HttpFoundation\Response
{
    $middleware = new HealthEndpointMiddleware;

    $request = Request::create('/payments/health', 'GET', $query, [], [], $server);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $middleware->handle($request, fn ($req) => response()->json(['status' => 'operational']));
}

beforeEach(function () {
    config([
        'payments.health_check.require_auth' => false,
        'payments.health_check.allowed_ips' => [],
        'payments.health_check.allowed_tokens' => [],
    ]);
    app()->forgetInstance('payments.config');
});

test('health endpoint allows a request from a whitelisted IP', function () {
    config(['payments.health_check.allowed_ips' => ['203.0.113.5']]);
    app()->forgetInstance('payments.config');

    $response = makeHealthRequest(['REMOTE_ADDR' => '203.0.113.5']);

    expect($response->getStatusCode())->toBe(200);
});

test('health endpoint rejects a request from a non-whitelisted IP', function () {
    config(['payments.health_check.allowed_ips' => ['203.0.113.5']]);
    app()->forgetInstance('payments.config');

    $response = makeHealthRequest(['REMOTE_ADDR' => '198.51.100.9']);

    expect($response->getStatusCode())->toBe(403)
        ->and(json_decode($response->getContent(), true))->toBe(['error' => 'Unauthorized']);
});

test('health endpoint allows an IP inside an allowed CIDR range', function () {
    config(['payments.health_check.allowed_ips' => ['203.0.113.0/24']]);
    app()->forgetInstance('payments.config');

    $response = makeHealthRequest(['REMOTE_ADDR' => '203.0.113.200']);

    expect($response->getStatusCode())->toBe(200);
});

test('health endpoint rejects an IP outside an allowed CIDR range', function () {
    config(['payments.health_check.allowed_ips' => ['203.0.113.0/24']]);
    app()->forgetInstance('payments.config');

    $response = makeHealthRequest(['REMOTE_ADDR' => '198.51.100.5']);

    expect($response->getStatusCode())->toBe(403);
});

test('health endpoint authorizes via X-Health-Token header when bearer token is absent', function () {
    config([
        'payments.health_check.require_auth' => true,
        'payments.health_check.allowed_tokens' => ['header-token'],
    ]);
    app()->forgetInstance('payments.config');

    $response = makeHealthRequest([], ['X-Health-Token' => 'header-token']);

    expect($response->getStatusCode())->toBe(200);
});

test('health endpoint authorizes via the token query string as a last resort', function () {
    config([
        'payments.health_check.require_auth' => true,
        'payments.health_check.allowed_tokens' => ['query-token'],
    ]);
    app()->forgetInstance('payments.config');

    $response = makeHealthRequest([], [], ['token' => 'query-token']);

    expect($response->getStatusCode())->toBe(200);
});

test('health endpoint rejects an invalid bearer token', function () {
    config([
        'payments.health_check.require_auth' => true,
        'payments.health_check.allowed_tokens' => ['the-real-token'],
    ]);
    app()->forgetInstance('payments.config');

    $response = makeHealthRequest([], ['Authorization' => 'Bearer wrong-token']);

    expect($response->getStatusCode())->toBe(HttpStatusCodes::UNAUTHORIZED)
        ->and(json_decode($response->getContent(), true))->toBe(['error' => 'Unauthorized']);
});

test('health endpoint rejects a missing token when auth is required', function () {
    config([
        'payments.health_check.require_auth' => true,
        'payments.health_check.allowed_tokens' => [],
    ]);
    app()->forgetInstance('payments.config');

    $response = makeHealthRequest();

    expect($response->getStatusCode())->toBe(HttpStatusCodes::UNAUTHORIZED);
});
