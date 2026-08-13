<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use KenDeNigerian\PayZephyr\Console\UninstallCommand;

/**
 * Deletes every migration file this test suite could have published, so
 * each test starts from a known-clean slate - mirrors
 * InstallCommandTest.php's cleanPublishedInstallerState(), under a distinct
 * name to avoid a global function redeclaration when the full suite runs.
 */
function uninstallTestCleanState(): void
{
    foreach ([
        '*_create_payment_transactions_table.php',
        '*_create_subscription_transactions_table.php',
        '*_create_webhook_events_table.php',
        '*_create_refund_transactions_table.php',
    ] as $pattern) {
        foreach (glob(database_path('migrations/'.$pattern)) ?: [] as $file) {
            @unlink($file);
        }
    }

    $envPath = base_path('.env');
    if (File::exists($envPath)) {
        $contents = preg_replace('/^PAYZEPHYR_FEATURE_\w+=.*$/m', '', File::get($envPath));
        File::put($envPath, trim((string) $contents)."\n");
    }
}

function uninstallTestMigrationFiles(): array
{
    return [
        'payments' => glob(database_path('migrations/*_create_payment_transactions_table.php')) ?: [],
        'webhooks' => glob(database_path('migrations/*_create_webhook_events_table.php')) ?: [],
        'subscriptions' => glob(database_path('migrations/*_create_subscription_transactions_table.php')) ?: [],
        'refunds' => glob(database_path('migrations/*_create_refund_transactions_table.php')) ?: [],
    ];
}

beforeEach(function () {
    uninstallTestCleanState();
});

afterEach(function () {
    uninstallTestCleanState();
});

test('uninstall command is registered', function () {
    expect(Artisan::all())->toHaveKey('payzephyr:uninstall');
});

test('uninstall command has correct signature', function () {
    expect((new UninstallCommand)->getName())->toBe('payzephyr:uninstall');
});

test('uninstall reports nothing to do when PayZephyr is not installed', function () {
    $exitCode = Artisan::call('payzephyr:uninstall', ['--force' => true]);

    expect($exitCode)->toBe(UninstallCommand::SUCCESS)
        ->and(Artisan::output())->toContain('does not appear to be installed');
});

test('uninstall refuses to run in a non-interactive environment without --force', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $exitCode = Artisan::call('payzephyr:uninstall', ['--no-interaction' => true]);

    expect($exitCode)->toBe(UninstallCommand::FAILURE)
        ->and(Artisan::output())->toContain('--force');

    // Nothing was touched.
    $files = uninstallTestMigrationFiles();
    expect($files['payments'])->not->toBeEmpty()
        ->and($files['refunds'])->not->toBeEmpty();
});

test('--force removes every PayZephyr-owned table and migration file', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);
    $this->artisan('migrate', ['--force' => true])->run();

    expect(Schema::hasTable('payment_transactions'))->toBeTrue()
        ->and(Schema::hasTable('webhook_events'))->toBeTrue()
        ->and(Schema::hasTable('subscription_transactions'))->toBeTrue()
        ->and(Schema::hasTable('refund_transactions'))->toBeTrue();

    $exitCode = Artisan::call('payzephyr:uninstall', ['--force' => true]);

    expect($exitCode)->toBe(UninstallCommand::SUCCESS);

    $files = uninstallTestMigrationFiles();
    expect($files['payments'])->toBeEmpty()
        ->and($files['webhooks'])->toBeEmpty()
        ->and($files['subscriptions'])->toBeEmpty()
        ->and($files['refunds'])->toBeEmpty();

    expect(Schema::hasTable('payment_transactions'))->toBeFalse()
        ->and(Schema::hasTable('webhook_events'))->toBeFalse()
        ->and(Schema::hasTable('subscription_transactions'))->toBeFalse()
        ->and(Schema::hasTable('refund_transactions'))->toBeFalse();
});

test('--features= removes only the named optional feature, leaving core and other features intact', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);
    $this->artisan('migrate', ['--force' => true])->run();

    Artisan::call('payzephyr:uninstall', ['--force' => true, '--features' => 'refunds']);

    $files = uninstallTestMigrationFiles();
    expect($files['refunds'])->toBeEmpty()
        ->and($files['payments'])->not->toBeEmpty()
        ->and($files['webhooks'])->not->toBeEmpty()
        ->and($files['subscriptions'])->not->toBeEmpty();

    expect(Schema::hasTable('refund_transactions'))->toBeFalse()
        ->and(Schema::hasTable('payment_transactions'))->toBeTrue()
        ->and(Schema::hasTable('subscription_transactions'))->toBeTrue();
});

test('--features= with an unknown feature name fails clearly and removes nothing', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $exitCode = Artisan::call('payzephyr:uninstall', ['--force' => true, '--features' => 'payouts']);

    expect($exitCode)->toBe(UninstallCommand::FAILURE)
        ->and(Artisan::output())->toContain('Unknown feature [payouts]');

    $files = uninstallTestMigrationFiles();
    expect($files['refunds'])->not->toBeEmpty();
});

test('--features= cannot be used to remove core resources', function () {
    // "payments"/"webhooks" are core, not entries in the optional Features
    // registry --features= validates against - core can only be removed via
    // a full, no-flag uninstall.
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $exitCode = Artisan::call('payzephyr:uninstall', ['--force' => true, '--features' => 'payments']);

    expect($exitCode)->toBe(UninstallCommand::FAILURE);

    $files = uninstallTestMigrationFiles();
    expect($files['payments'])->not->toBeEmpty();
});

test('repeated uninstall is safe: the second run reports nothing to do', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $first = Artisan::call('payzephyr:uninstall', ['--force' => true]);
    $second = Artisan::call('payzephyr:uninstall', ['--force' => true]);

    expect($first)->toBe(UninstallCommand::SUCCESS)
        ->and($second)->toBe(UninstallCommand::SUCCESS)
        ->and(Artisan::output())->toContain('does not appear to be installed');
});

test('uninstall never touches unrelated application tables', function () {
    Schema::create('unrelated_app_table', function ($table) {
        $table->id();
        $table->string('name');
    });

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);
    Artisan::call('payzephyr:uninstall', ['--force' => true]);

    expect(Schema::hasTable('unrelated_app_table'))->toBeTrue();

    Schema::dropIfExists('unrelated_app_table');
});

test('uninstall never touches config/payments.php', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--force' => true, '--all' => true]);
    expect(config_path('payments.php'))->toBeFile();

    Artisan::call('payzephyr:uninstall', ['--force' => true]);

    expect(config_path('payments.php'))->toBeFile();
});

test('uninstall clears the .env feature flag for a removed feature', function () {
    File::put(base_path('.env'), "APP_NAME=Test\n");
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    expect(File::get(base_path('.env')))->toContain('PAYZEPHYR_FEATURE_REFUNDS=true');

    Artisan::call('payzephyr:uninstall', ['--force' => true, '--features' => 'refunds']);

    expect(File::get(base_path('.env')))->toContain('PAYZEPHYR_FEATURE_REFUNDS=false');
});

test('cancelling the interactive confirmation removes nothing', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $this->artisan('payzephyr:uninstall')
        ->expectsConfirmation('Are you sure you want to continue? This cannot be undone.', 'no')
        ->assertExitCode(UninstallCommand::SUCCESS);

    $files = uninstallTestMigrationFiles();
    expect($files['refunds'])->not->toBeEmpty();
});

test('typing the wrong confirmation phrase cancels the uninstall', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $this->artisan('payzephyr:uninstall')
        ->expectsConfirmation('Are you sure you want to continue? This cannot be undone.', 'yes')
        ->expectsQuestion('Type "UNINSTALL" (in capitals) to confirm', 'nope')
        ->assertExitCode(UninstallCommand::SUCCESS);

    $files = uninstallTestMigrationFiles();
    expect($files['refunds'])->not->toBeEmpty();
});

test('confirming and typing UNINSTALL proceeds with the uninstall', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $this->artisan('payzephyr:uninstall')
        ->expectsConfirmation('Are you sure you want to continue? This cannot be undone.', 'yes')
        ->expectsQuestion('Type "UNINSTALL" (in capitals) to confirm', 'UNINSTALL')
        ->assertExitCode(UninstallCommand::SUCCESS);

    $files = uninstallTestMigrationFiles();
    expect($files['payments'])->toBeEmpty()
        ->and($files['refunds'])->toBeEmpty();
});

test('install -> uninstall -> reinstall -> migrate round trip recreates the table cleanly', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    $this->artisan('migrate', ['--force' => true])->run();
    expect(Schema::hasTable('refund_transactions'))->toBeTrue();

    Artisan::call('payzephyr:uninstall', ['--force' => true, '--features' => 'refunds']);
    expect(Schema::hasTable('refund_transactions'))->toBeFalse();

    // The migration repository row must have been cleaned up too, or a
    // fresh `migrate` run below would think this migration already ran and
    // silently skip recreating the table.
    $migrationFile = glob(database_path('migrations/*_create_refund_transactions_table.php'));
    expect($migrationFile)->toBeEmpty();

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    $this->artisan('migrate', ['--force' => true])->run();

    expect(Schema::hasTable('refund_transactions'))->toBeTrue();
});

test('the migrations tracking table row is removed so a republished migration is not silently skipped', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    $this->artisan('migrate', ['--force' => true])->run();

    expect(DB::table('migrations')->where('migration', 'like', '%create_refund_transactions_table')->exists())->toBeTrue();

    Artisan::call('payzephyr:uninstall', ['--force' => true, '--features' => 'refunds']);

    expect(DB::table('migrations')->where('migration', 'like', '%create_refund_transactions_table')->exists())->toBeFalse();
});

test('uninstall runs non-interactively without prompting', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $this->artisan('payzephyr:uninstall', ['--no-interaction' => true, '--force' => true])
        ->assertExitCode(UninstallCommand::SUCCESS);

    $files = uninstallTestMigrationFiles();
    expect($files['payments'])->toBeEmpty()
        ->and($files['refunds'])->toBeEmpty();
});

test('the non-interactive warning still states what will be removed', function () {
    // Non-interactive skips the confirmation prompts, so the warning is the
    // only notice a a destructive action is happening - it must still be shown.
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $this->artisan('payzephyr:uninstall', ['--no-interaction' => true, '--force' => true])
        ->expectsOutputToContain('cannot be undone')
        ->assertExitCode(UninstallCommand::SUCCESS);
});

test('uninstall succeeds when no .env file exists', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $envPath = base_path('.env');
    $original = File::exists($envPath) ? File::get($envPath) : null;
    if ($original !== null) {
        File::delete($envPath);
    }

    try {
        $this->artisan('payzephyr:uninstall', ['--no-interaction' => true, '--force' => true])
            ->assertExitCode(UninstallCommand::SUCCESS);
    } finally {
        if ($original !== null) {
            File::put($envPath, $original);
        }
    }
});
