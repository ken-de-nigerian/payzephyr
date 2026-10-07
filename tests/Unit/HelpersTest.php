<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Payment;

test('the payment() helper resolves the Payment builder from the container', function (): void {
    expect(payment())->toBeInstanceOf(Payment::class);
});

test('loading helpers.php again leaves an existing payment() function alone', function (): void {
    // Composer's "files" autoload runs helpers.php once, before any test. An
    // application that defines its own payment() first, or a second autoloader
    // including the file again, must not hit "Cannot redeclare payment()".
    // Including it here also runs the guard under coverage, which the
    // autoload-time include never can.
    require __DIR__.'/../../src/helpers.php';

    expect(function_exists('payment'))->toBeTrue()
        ->and(payment())->toBeInstanceOf(Payment::class);
});
