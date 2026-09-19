<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Console\Features;
use KenDeNigerian\PayZephyr\Contracts\TraceRecorderInterface;
use KenDeNigerian\PayZephyr\Models\PaymentTraceEvent;
use KenDeNigerian\PayZephyr\Services\NullTraceRecorder;
use KenDeNigerian\PayZephyr\Services\TraceRecorder;

/**
 * Trace as an installable feature.
 *
 * The registry drives InstallCommand and UninstallCommand generically, so
 * most of what matters here is that trace's entry is *correct* - a wrong
 * migration pattern or table config key fails silently by installing or
 * removing nothing. These assertions are the double-entry check against
 * Features::optional().
 */
function traceInstallCleanState(): void
{
    foreach (glob(database_path('migrations/*_create_payment_trace_events_table.php')) ?: [] as $file) {
        @unlink($file);
    }

    $envPath = base_path('.env');
    if (File::exists($envPath)) {
        $contents = preg_replace('/^PAYZEPHYR_FEATURE_\w+=.*$/m', '', File::get($envPath));
        File::put($envPath, trim((string) $contents)."\n");
    }
}

function publishedTraceMigrations(): array
{
    return glob(database_path('migrations/*_create_payment_trace_events_table.php')) ?: [];
}

beforeEach(function () {
    traceInstallCleanState();
});

afterEach(function () {
    traceInstallCleanState();
});

// ---------------------------------------------------------------------------
// The registry entry
// ---------------------------------------------------------------------------

test('trace is offered as an optional feature', function () {
    expect(Features::optionalKeys())->toContain('trace')
        ->and(Features::exists('trace'))->toBeTrue();
});

test('trace resolves through the same parsing every other feature uses', function () {
    expect(Features::parseList(' Trace , TRACE '))->toBe(['trace'])
        ->and(Features::resolveDependencies(['trace']))->toBe(['trace']);
});

test("the registry's migration pattern actually matches the shipped migration", function () {
    // A typo here would make the installer publish nothing and report success.
    $pattern = Features::get('trace')['migrationPattern'];
    $shipped = glob(__DIR__.'/../../database/migrations/'.$pattern);

    expect($shipped)->toHaveCount(1);
});

test("the registry's table key resolves to the table the model actually uses", function () {
    // If these drift, uninstall drops the wrong table - or nothing at all.
    $feature = Features::get('trace');
    $configured = config('payments.'.$feature['tableConfigKey'], $feature['defaultTable']);

    expect($configured)->toBe((new PaymentTraceEvent)->getTable())
        ->and($feature['defaultTable'])->toBe('payment_trace_events');
});

test("the registry's env var is the one the runtime flag reads", function () {
    expect(Features::get('trace')['envVar'])->toBe('PAYZEPHYR_FEATURE_TRACE');
});

// ---------------------------------------------------------------------------
// Installing
// ---------------------------------------------------------------------------

test('installing trace publishes its migration', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'trace']);

    expect(publishedTraceMigrations())->not->toBeEmpty();
});

test('installing another feature leaves trace alone', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    expect(publishedTraceMigrations())->toBeEmpty();
});

test('trace is not installed unless it is asked for', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true]);

    expect(publishedTraceMigrations())->toBeEmpty();
});

test('--all includes trace', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    expect(publishedTraceMigrations())->not->toBeEmpty();
});

test('installing trace records the flag the runtime actually reads', function () {
    File::put(base_path('.env'), "APP_ENV=testing\n");

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'trace']);

    expect(File::get(base_path('.env')))->toContain('PAYZEPHYR_FEATURE_TRACE=true');
});

test('installing trace twice does not publish the migration twice', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'trace']);
    $first = publishedTraceMigrations();

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'trace']);

    expect(publishedTraceMigrations())->toBe($first)
        ->and($first)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Uninstalling
// ---------------------------------------------------------------------------

test('uninstalling trace drops its table and removes its migration', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'trace']);
    expect(Schema::hasTable('payment_trace_events'))->toBeTrue();

    Artisan::call('payzephyr:uninstall', ['--no-interaction' => true, '--force' => true, '--features' => 'trace']);

    expect(Schema::hasTable('payment_trace_events'))->toBeFalse()
        ->and(publishedTraceMigrations())->toBeEmpty();
});

test('uninstalling trace clears the flag, so the app stops trying to write', function () {
    // Ordering note: removeResource() drops the table before clearing the
    // flag, so a live app briefly sees tracing on with no table behind it.
    // TraceRecorder::record() is guaranteed not to throw, which is what makes
    // that survivable rather than lucky.
    File::put(base_path('.env'), "APP_ENV=testing\nPAYZEPHYR_FEATURE_TRACE=true\n");

    Artisan::call('payzephyr:uninstall', ['--no-interaction' => true, '--force' => true, '--features' => 'trace']);

    expect(File::get(base_path('.env')))->toContain('PAYZEPHYR_FEATURE_TRACE=false');
});

test('uninstalling another feature leaves the trace table standing', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'trace,refunds']);

    Artisan::call('payzephyr:uninstall', ['--no-interaction' => true, '--force' => true, '--features' => 'refunds']);

    expect(Schema::hasTable('payment_trace_events'))->toBeTrue()
        ->and(publishedTraceMigrations())->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Installed state and the runtime flag are separate things
// ---------------------------------------------------------------------------

test('publishing the migration does not by itself switch tracing on', function () {
    // Installing creates the table; the flag is what makes PayZephyr write to
    // it. A published migration with the flag still off must stay silent.
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'trace']);

    config(['payments.features.trace' => false]);
    app()->forgetInstance('payments.config');
    app()->forgetInstance(TraceRecorderInterface::class);

    expect(app(TraceRecorderInterface::class))->toBeInstanceOf(NullTraceRecorder::class);
});

test('with the table installed and the flag on, the real recorder is wired up', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'trace']);

    config(['payments.features.trace' => true]);
    app()->forgetInstance('payments.config');
    app()->forgetInstance(TraceRecorderInterface::class);

    expect(app(TraceRecorderInterface::class))->toBeInstanceOf(TraceRecorder::class);
});
