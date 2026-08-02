<?php

use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Services\DriverFactory;

test('create throws when the resolved class exists but does not implement DriverInterface', function () {
    $factory = new DriverFactory;

    // 'stdClass' resolves (via the naming-convention fallback) to the raw
    // name itself, since no `Drivers\StdClassDriver` class exists. The class
    // does exist (it's a built-in PHP class) but does not implement
    // DriverInterface, so create() must reject it.
    expect(fn () => $factory->create('stdClass', []))
        ->toThrow(DriverNotFoundException::class, 'must implement DriverInterface');
});
