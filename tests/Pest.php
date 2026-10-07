<?php

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;
use KenDeNigerian\PayZephyr\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit', 'Integration');

// Static checks of the source. They run with every suite, and are left out of
// the coverage run (--exclude-group=arch): they cover nothing, and loading the
// whole package under a coverage driver multiplies the report's size.
uses(TestCase::class)->group('arch')->in('Arch');

require_once __DIR__.'/Helpers/fake_drivers.php';
require_once __DIR__.'/Helpers/webhook_requests.php';
require_once __DIR__.'/Helpers/concurrent_writes.php';
require_once __DIR__.'/Helpers/log_capture.php';

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
    $guard = Mockery::mock(Guard::class);
    $guard->shouldReceive('check')->andReturn($check);
    $guard->shouldReceive('id')->andReturn($id);

    Auth::shouldReceive('guard')->andReturn($guard);
}
