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

function healthConfig(bool $requireAuth, array $ips = [], array $tokens = []): void
{
    config([
        'payments.health_check.require_auth' => $requireAuth,
        'payments.health_check.allowed_ips' => $ips,
        'payments.health_check.allowed_tokens' => $tokens,
    ]);
    app()->forgetInstance('payments.config');
}

test('with auth required, an allowed IP is enough when only an allowlist is configured', function () {
    // The docs say to set tokens or IPs. With IPs alone, the middleware used
    // to demand a token nobody could have configured, and refused everyone.
    healthConfig(true, ips: ['10.0.0.5']);

    expect(makeHealthRequest(['REMOTE_ADDR' => '10.0.0.5'])->getStatusCode())->toBe(200)
        ->and(makeHealthRequest(['REMOTE_ADDR' => '10.0.0.6'])->getStatusCode())->toBe(403);
});

test('with auth required and both configured, both are enforced', function () {
    healthConfig(true, ips: ['10.0.0.5'], tokens: ['health-secret']);

    expect(makeHealthRequest(['REMOTE_ADDR' => '10.0.0.5'])->getStatusCode())->toBe(HttpStatusCodes::UNAUTHORIZED)
        ->and(makeHealthRequest(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_AUTHORIZATION' => 'Bearer health-secret'])->getStatusCode())->toBe(200)
        ->and(makeHealthRequest(['REMOTE_ADDR' => '10.0.0.9', 'HTTP_AUTHORIZATION' => 'Bearer health-secret'])->getStatusCode())->toBe(403);
});

test('with auth required and nothing to authenticate against, every request is refused and the reason logged once', function () {
    healthConfig(true);
    Illuminate\Support\Facades\Cache::flush();

    $errors = [];
    Illuminate\Support\Facades\Log::shouldReceive('channel')->andReturnSelf();
    Illuminate\Support\Facades\Log::shouldReceive('error')->andReturnUsing(function ($message) use (&$errors) {
        $errors[] = $message;

        return true;
    });

    expect(makeHealthRequest(['HTTP_AUTHORIZATION' => 'Bearer anything'])->getStatusCode())->toBe(HttpStatusCodes::UNAUTHORIZED)
        ->and(makeHealthRequest()->getStatusCode())->toBe(HttpStatusCodes::UNAUTHORIZED)
        ->and($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('requires authentication but has nothing to authenticate against');
});

test('the shipped default requires auth everywhere except local and testing', function (string $appEnv, bool $expected) {
    // An install that publishes config gets an explicit value it can see; one
    // that does not gets a closed endpoint in production, open only on a
    // developer's machine and in the test suite.
    $originalEnv = $_ENV['APP_ENV'] ?? null;
    $originalServer = $_SERVER['APP_ENV'] ?? null;
    unset($_ENV['PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH'], $_SERVER['PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH']);
    putenv('PAYMENTS_HEALTH_CHECK_REQUIRE_AUTH');
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $appEnv;
    putenv("APP_ENV=$appEnv");

    try {
        $config = require __DIR__.'/../../config/payments.php';

        expect($config['health_check']['require_auth'])->toBe($expected);
    } finally {
        $originalEnv === null ? $_ENV = array_diff_key($_ENV, ['APP_ENV' => 1]) : $_ENV['APP_ENV'] = $originalEnv;
        $originalServer === null ? $_SERVER = array_diff_key($_SERVER, ['APP_ENV' => 1]) : $_SERVER['APP_ENV'] = $originalServer;
        putenv($originalEnv === null ? 'APP_ENV' : "APP_ENV=$originalEnv");
    }
})->with([
    'production' => ['production', true],
    'staging' => ['staging', true],
    'local' => ['local', false],
    'testing' => ['testing', false],
]);

test('a token sent as an array is refused, not cast', function () {
    // ?token[]=x arrives as an array. Casting it to a string raised an
    // "Array to string conversion" warning, which Laravel turns into a 500.
    healthConfig(false, tokens: ['health-secret']);

    expect(makeHealthRequest(query: ['token' => ['health-secret']])->getStatusCode())->toBe(HttpStatusCodes::UNAUTHORIZED);
});

test('a request with no resolvable address is refused by the allowlist, not crashed by it', function () {
    healthConfig(false, ips: ['10.0.0.5']);

    $request = Request::create('/payments/health', 'GET');
    $request->server->remove('REMOTE_ADDR');

    $response = (new HealthEndpointMiddleware)->handle($request, fn () => response()->json(['status' => 'operational']));

    expect($request->ip())->toBeNull()
        ->and($response->getStatusCode())->toBe(403);
});

test('allowlist entries are trimmed, and empty ones ignored', function () {
    // PAYMENTS_HEALTH_CHECK_ALLOWED_IPS="203.0.113.5, 198.51.100.7," split into
    // " 198.51.100.7" and "", and the second address was refused.
    config([
        'payments.health_check.allowed_ips' => ['203.0.113.5', ' 198.51.100.7', ''],
        'payments.health_check.allowed_tokens' => ['first', ' secret-token ', ''],
    ]);
    app()->forgetInstance('payments.config');

    expect(makeHealthRequest(['REMOTE_ADDR' => '198.51.100.7'], ['X-Health-Token' => 'secret-token'])->getStatusCode())->toBe(200)
        ->and(makeHealthRequest(['REMOTE_ADDR' => '198.51.100.7'], ['X-Health-Token' => ''])->getStatusCode())->toBe(401);
});

test('an allowlist entry that is not a string is ignored, not a 500', function () {
    // ipMatches() takes a string. A nested array in the list reached it as
    // one and raised a TypeError on every request.
    config([
        'payments.health_check.allowed_ips' => [['203.0.113.5'], '203.0.113.5'],
        'payments.health_check.allowed_tokens' => [42, 'secret-token'],
    ]);
    app()->forgetInstance('payments.config');

    expect(makeHealthRequest(['REMOTE_ADDR' => '203.0.113.5'], ['X-Health-Token' => 'secret-token'])->getStatusCode())->toBe(200)
        ->and(makeHealthRequest(['REMOTE_ADDR' => '198.51.100.1'], ['X-Health-Token' => 'secret-token'])->getStatusCode())->toBe(403);
});
