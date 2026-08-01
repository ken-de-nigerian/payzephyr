<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Constants\HttpStatusCodes;

test('http status code constants have the expected values', function () {
    expect(HttpStatusCodes::OK)->toBe(200)
        ->and(HttpStatusCodes::ACCEPTED)->toBe(202)
        ->and(HttpStatusCodes::BAD_REQUEST)->toBe(400)
        ->and(HttpStatusCodes::UNAUTHORIZED)->toBe(401)
        ->and(HttpStatusCodes::FORBIDDEN)->toBe(403)
        ->and(HttpStatusCodes::NOT_FOUND)->toBe(404)
        ->and(HttpStatusCodes::TOO_MANY_REQUESTS)->toBe(429)
        ->and(HttpStatusCodes::INTERNAL_SERVER_ERROR)->toBe(500)
        ->and(HttpStatusCodes::BAD_GATEWAY)->toBe(502)
        ->and(HttpStatusCodes::SERVICE_UNAVAILABLE)->toBe(503);
});

test('isClientError detects 4xx status codes', function () {
    expect(HttpStatusCodes::isClientError(400))->toBeTrue()
        ->and(HttpStatusCodes::isClientError(404))->toBeTrue()
        ->and(HttpStatusCodes::isClientError(499))->toBeTrue()
        ->and(HttpStatusCodes::isClientError(399))->toBeFalse()
        ->and(HttpStatusCodes::isClientError(500))->toBeFalse();
});

test('isServerError detects 5xx status codes', function () {
    expect(HttpStatusCodes::isServerError(500))->toBeTrue()
        ->and(HttpStatusCodes::isServerError(503))->toBeTrue()
        ->and(HttpStatusCodes::isServerError(499))->toBeFalse();
});

test('isSuccess detects 2xx status codes', function () {
    expect(HttpStatusCodes::isSuccess(200))->toBeTrue()
        ->and(HttpStatusCodes::isSuccess(202))->toBeTrue()
        ->and(HttpStatusCodes::isSuccess(299))->toBeTrue()
        ->and(HttpStatusCodes::isSuccess(199))->toBeFalse()
        ->and(HttpStatusCodes::isSuccess(300))->toBeFalse();
});
