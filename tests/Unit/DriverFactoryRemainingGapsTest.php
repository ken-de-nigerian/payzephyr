<?php

use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Services\DriverFactory;

test('create throws when the resolved class exists but does not implement DriverInterface', function () {
    $factory = new DriverFactory;

    // 'stdClass' is not a bundled driver's name, so it is taken as a class
    // name. The class exists (it's a built-in PHP class) but does not
    // implement DriverInterface, so create() must reject it.
    expect(fn () => $factory->create('stdClass', []))
        ->toThrow(DriverNotFoundException::class, 'must implement DriverInterface');
});
