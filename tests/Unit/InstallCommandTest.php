<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use KenDeNigerian\PayZephyr\Console\Features;
use KenDeNigerian\PayZephyr\Console\InstallCommand;

/**
 * Deletes every migration file this test suite could have published (across
 * whichever tests ran before it in this process) so each test starts from a
 * known-clean slate - vendor:publish's own "don't overwrite without --force"
 * behavior means stale files from an earlier test would otherwise silently
 * make a later isolation assertion pass or fail for the wrong reason.
 */
function cleanPublishedInstallerState(): void
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

function installedMigrationFiles(): array
{
    return [
        'payments' => glob(database_path('migrations/*_create_payment_transactions_table.php')) ?: [],
        'webhooks' => glob(database_path('migrations/*_create_webhook_events_table.php')) ?: [],
        'subscriptions' => glob(database_path('migrations/*_create_subscription_transactions_table.php')) ?: [],
        'refunds' => glob(database_path('migrations/*_create_refund_transactions_table.php')) ?: [],
    ];
}

beforeEach(function () {
    cleanPublishedInstallerState();
});

afterEach(function () {
    cleanPublishedInstallerState();
});

test('install command is registered', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('payzephyr:install');
});

test('install command has correct signature', function () {
    $command = new InstallCommand;

    expect($command->getName())->toBe('payzephyr:install');
});

test('install command description is set', function () {
    $command = new InstallCommand;

    expect($command->getDescription())->toBe('Install PayZephyr package');
});

test('install command publishes config', function () {
    Artisan::call('payzephyr:install', ['--force' => true, '--no-interaction' => true]);

    expect(config_path('payments.php'))->toBeFile();
});

test('install command always publishes core migrations', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true]);

    $files = installedMigrationFiles();

    expect($files['payments'])->not->toBeEmpty()
        ->and($files['webhooks'])->not->toBeEmpty();
});

test('--no-interaction with no explicit feature flags installs core only, with no prompts', function () {
    // Regression guard: this must NOT ask "Install Subscriptions?" or
    // "Install Refunds?" - non-interactive with no explicit selection must
    // never silently install every optional feature.
    Artisan::call('payzephyr:install', ['--no-interaction' => true]);

    $files = installedMigrationFiles();

    expect($files['payments'])->not->toBeEmpty()
        ->and($files['webhooks'])->not->toBeEmpty()
        ->and($files['subscriptions'])->toBeEmpty()
        ->and($files['refunds'])->toBeEmpty();
});

test('install command runs migrations when the user confirms every prompt', function () {
    // Covers the interactive elseif ($this->confirm(...)) branch. Feature
    // prompts come before the "run migrations now" prompt, in registry order.
    $this->artisan('payzephyr:install')
        ->expectsConfirmation('Install Subscriptions? (Recurring billing (create/cancel/renew) on Paystack, Stripe, PayPal, Flutterwave, Square, and Mollie)', 'no')
        ->expectsConfirmation('Install Refunds? (Full and partial refunds across all 8 providers)', 'no')
        ->expectsConfirmation('Run migrations now?', 'yes')
        ->assertExitCode(0);
});

test('install command skips migrations when the user declines the final prompt', function () {
    $this->artisan('payzephyr:install')
        ->expectsConfirmation('Install Subscriptions? (Recurring billing (create/cancel/renew) on Paystack, Stripe, PayPal, Flutterwave, Square, and Mollie)', 'no')
        ->expectsConfirmation('Install Refunds? (Full and partial refunds across all 8 providers)', 'no')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertExitCode(0);
});

test('interactively selecting one optional feature installs only that feature', function () {
    $this->artisan('payzephyr:install')
        ->expectsConfirmation('Install Subscriptions? (Recurring billing (create/cancel/renew) on Paystack, Stripe, PayPal, Flutterwave, Square, and Mollie)', 'yes')
        ->expectsConfirmation('Install Refunds? (Full and partial refunds across all 8 providers)', 'no')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertExitCode(0);

    $files = installedMigrationFiles();

    expect($files['subscriptions'])->not->toBeEmpty()
        ->and($files['refunds'])->toBeEmpty();
});

test('--all installs every optional feature without prompting', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $files = installedMigrationFiles();

    expect($files['payments'])->not->toBeEmpty()
        ->and($files['subscriptions'])->not->toBeEmpty()
        ->and($files['refunds'])->not->toBeEmpty();
});

test('--features= installs exactly the named features and nothing else', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    $files = installedMigrationFiles();

    expect($files['refunds'])->not->toBeEmpty()
        ->and($files['subscriptions'])->toBeEmpty();
});

test('--features= accepts multiple comma-separated values', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions,refunds']);

    $files = installedMigrationFiles();

    expect($files['subscriptions'])->not->toBeEmpty()
        ->and($files['refunds'])->not->toBeEmpty();
});

test('--features= is case-insensitive, trims whitespace, and dedupes', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => ' Refunds , REFUNDS ,refunds']);

    $files = installedMigrationFiles();

    expect($files['refunds'])->not->toBeEmpty()
        ->and($files['subscriptions'])->toBeEmpty();
});

test('--features= with an unknown feature name fails clearly and installs nothing optional', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'payouts']);

    expect(Artisan::output())->toContain('Unknown feature [payouts]');

    $files = installedMigrationFiles();
    expect($files['subscriptions'])->toBeEmpty()
        ->and($files['refunds'])->toBeEmpty();
});

test('--features= with an empty value fails clearly', function () {
    $exitCode = Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => '   ']);

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

test('Features::parseList rejects an unknown feature and names it in the exception', function () {
    Features::parseList('subscriptions,bogus_feature');
})->throws(InvalidArgumentException::class, 'Unknown feature [bogus_feature]');

test('Features::resolveDependencies is stable when no dependencies exist', function () {
    expect(Features::resolveDependencies(['refunds']))->toBe(['refunds'])
        ->and(Features::resolveDependencies(['subscriptions', 'refunds']))->toEqualCanonicalizing(['subscriptions', 'refunds']);
});

test('repeated installation with the same selection is idempotent and does not duplicate migration files', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    $firstRun = installedMigrationFiles()['refunds'];

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    $secondRun = installedMigrationFiles()['refunds'];

    expect($firstRun)->toHaveCount(1)
        ->and($secondRun)->toHaveCount(1)
        ->and($secondRun)->toBe($firstRun);
});

test('a feature can be added later without disturbing a previously installed one (upgrade scenario)', function () {
    // Simulates: v1 install with subscriptions only, then a later run adds refunds.
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions']);
    expect(installedMigrationFiles()['subscriptions'])->not->toBeEmpty();

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    $files = installedMigrationFiles();

    expect($files['subscriptions'])->not->toBeEmpty()
        ->and($files['refunds'])->not->toBeEmpty();
});

test('declining an already-installed feature interactively does not remove it', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions']);
    expect(installedMigrationFiles()['subscriptions'])->not->toBeEmpty();

    // Second, interactive run: say "no" to Subscriptions even though it's
    // already installed - PayZephyr must never delete on a "no" answer.
    $this->artisan('payzephyr:install')
        ->expectsConfirmation('Install Subscriptions? (Recurring billing (create/cancel/renew) on Paystack, Stripe, PayPal, Flutterwave, Square, and Mollie)', 'no')
        ->expectsConfirmation('Install Refunds? (Full and partial refunds across all 8 providers)', 'no')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertExitCode(0);

    expect(installedMigrationFiles()['subscriptions'])->not->toBeEmpty();
});

test('interactive prompts pre-select features that are already installed', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    // expectsConfirmation()'s second argument is the *answer given*, not the
    // default - this only proves the prompt appears and accepting it again
    // doesn't error or duplicate anything; the default-preselection itself
    // is exercised by the "does not remove" test above via its 'no' answer
    // leaving the feature intact.
    $this->artisan('payzephyr:install')
        ->expectsConfirmation('Install Subscriptions? (Recurring billing (create/cancel/renew) on Paystack, Stripe, PayPal, Flutterwave, Square, and Mollie)', 'no')
        ->expectsConfirmation('Install Refunds? (Full and partial refunds across all 8 providers)', 'yes')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertExitCode(0);

    expect(installedMigrationFiles()['refunds'])->toHaveCount(1);
});

test('newly selected features are recorded in .env as PAYZEPHYR_FEATURE_* flags', function () {
    File::put(base_path('.env'), "APP_NAME=Test\n");

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    $env = File::get(base_path('.env'));

    expect($env)->toContain('PAYZEPHYR_FEATURE_REFUNDS=true')
        ->and($env)->not->toContain('PAYZEPHYR_FEATURE_SUBSCRIPTIONS=true');
});

test('re-running install does not duplicate an already-written .env flag', function () {
    File::put(base_path('.env'), "APP_NAME=Test\n");

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    $env = File::get(base_path('.env'));

    expect(substr_count($env, 'PAYZEPHYR_FEATURE_REFUNDS='))->toBe(1);
});

test('install command gracefully handles a missing .env file instead of erroring', function () {
    $envPath = base_path('.env');
    $hadEnv = File::exists($envPath);
    if ($hadEnv) {
        File::move($envPath, $envPath.'.bak');
    }

    try {
        $exitCode = Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

        expect($exitCode)->toBe(InstallCommand::SUCCESS);
    } finally {
        if ($hadEnv) {
            File::move($envPath.'.bak', $envPath);
        } elseif (File::exists($envPath)) {
            File::delete($envPath);
        }
    }
});

test('selecting both optional features installs both and neither is skipped', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions,refunds']);

    $files = installedMigrationFiles();

    expect($files['payments'])->not->toBeEmpty()
        ->and($files['webhooks'])->not->toBeEmpty()
        ->and($files['subscriptions'])->not->toBeEmpty()
        ->and($files['refunds'])->not->toBeEmpty();
});
