<?php

use KenDeNigerian\PayZephyr\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', 'Integration');

require_once __DIR__.'/Helpers/fake_drivers.php';

/**
 * Mock the authenticated user behind the Auth facade.
 *
 * Production code calls auth()->guard()->check()/id() rather than
 * auth()->check()/id(): the Auth *Factory* contract only exposes guard() and
 * shouldUse(), while check()/id() live on the Guard that factory resolves.
 * Both reach the same default guard at runtime - AuthManager::__call forwards
 * to it - but only the explicit form is type-safe, so tests mock the same
 * shape the production call actually takes.
 *
 * @param  int|string|null  $id  The authenticated user id, or null when $check is false.
 */
function mockAuthGuard(bool $check, int|string|null $id = null): void
{
    $guard = Mockery::mock(Illuminate\Contracts\Auth\Guard::class);
    $guard->shouldReceive('check')->andReturn($check);
    $guard->shouldReceive('id')->andReturn($id);

    Illuminate\Support\Facades\Auth::shouldReceive('guard')->andReturn($guard);
}
