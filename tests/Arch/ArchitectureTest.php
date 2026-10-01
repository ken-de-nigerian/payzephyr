<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;

/*
 * Rules the package already keeps, written down so it goes on keeping them.
 */

arch('the code follows Pest\'s php preset')->preset()->php();

// No eval, no weak hashes or clock-based ids (Square idempotency keys were
// uniqid() until this caught it), no parse_str() and the like.
arch('the code follows Pest\'s security preset')->preset()->security();

arch('every file declares strict types')
    ->expect('KenDeNigerian\PayZephyr')
    ->toUseStrictTypes();

arch('nothing is left in from debugging')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

// env() returns null once the configuration is cached; only config files may call it.
arch('env() is read only in config files')
    ->expect('env')
    ->not->toBeUsed();

arch('contracts are interfaces')
    ->expect('KenDeNigerian\PayZephyr\Contracts')
    ->toBeInterfaces();

arch('every exception is a PaymentException, so one catch covers the package')
    ->expect('KenDeNigerian\PayZephyr\Exceptions')
    ->toExtend(PaymentException::class)
    ->ignoring(PaymentException::class);

arch('data objects are final and immutable')
    ->expect('KenDeNigerian\PayZephyr\DataObjects')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();

arch('drivers are final; AbstractDriver is the extension point')
    ->expect('KenDeNigerian\PayZephyr\Drivers')
    ->classes()
    ->toBeFinal()
    ->ignoring(AbstractDriver::class);

arch('events and support classes are final')
    ->expect(['KenDeNigerian\PayZephyr\Events', 'KenDeNigerian\PayZephyr\Support'])
    ->toBeFinal();

arch('enums and traits live where their names say')
    ->expect('KenDeNigerian\PayZephyr\Enums')->toBeEnums()
    ->and('KenDeNigerian\PayZephyr\Traits')->toBeTraits();
