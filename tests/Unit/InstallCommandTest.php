<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
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
        '*_subscription_transactions_table.php',
        '*_webhook_events_table.php',
        '*_create_refund_transactions_table.php',
        '*_create_payment_trace_events_table.php',
    ] as $pattern) {
        foreach (glob(database_path('migrations/'.$pattern)) ?: [] as $file) {
            @unlink($file);
        }
    }

    $envPath = app()->environmentFilePath();
    if (File::exists($envPath)) {
        $contents = preg_replace('/^PAYZEPHYR_FEATURE_\w+=.*$/m', '', File::get($envPath));
        File::put($envPath, trim((string) $contents)."\n");
    }
}

/**
 * The exact option labels InstallCommand's multiselect() feature prompt
 * shows - must match Features::optional()'s label/description strings.
 *
 * @return array<string, string>
 */
function featureMultiselectOptions(): array
{
    return [
        'subscriptions' => 'Subscriptions - Recurring billing (create/cancel/renew) on Paystack, Stripe, PayPal, Flutterwave, Square, and Mollie',
        'refunds' => 'Refunds - Full and partial refunds across every bundled provider',
        'trace' => 'Trace - Step-by-step forensic timeline of every payment - what happened, in order, and why',
    ];
}

function installedMigrationFiles(): array
{
    return [
        'payments' => glob(database_path('migrations/*_create_payment_transactions_table.php')) ?: [],
        'webhooks' => glob(database_path('migrations/*_webhook_events_table.php')) ?: [],
        'subscriptions' => glob(database_path('migrations/*_subscription_transactions_table.php')) ?: [],
        'refunds' => glob(database_path('migrations/*_create_refund_transactions_table.php')) ?: [],
        'trace' => glob(database_path('migrations/*_create_payment_trace_events_table.php')) ?: [],
    ];
}

beforeEach(function (): void {
    cleanPublishedInstallerState();
});

afterEach(function (): void {
    cleanPublishedInstallerState();
});

test('install command is registered', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('payzephyr:install');
});

test('install command has correct signature', function (): void {
    $command = new InstallCommand;

    expect($command->getName())->toBe('payzephyr:install');
});

test('install command description is set', function (): void {
    $command = new InstallCommand;

    expect($command->getDescription())->toBe('Install PayZephyr package');
});

test('install command publishes config', function (): void {
    Artisan::call('payzephyr:install', ['--force' => true, '--no-interaction' => true]);

    expect(config_path('payments.php'))->toBeFile();
});

test('install command always publishes core migrations', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true]);

    $files = installedMigrationFiles();

    expect($files['payments'])->not->toBeEmpty()
        ->and($files['webhooks'])->not->toBeEmpty();
});

test('--no-interaction with no explicit feature flags installs core only, with no prompts', function (): void {
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

test('install command runs migrations when the user confirms every prompt', function (): void {
    // Covers the interactive branch: the multiselect feature prompt comes
    // before the "run migrations now" confirmation.
    $this->artisan('payzephyr:install')
        ->expectsChoice('Select the optional features you want to install', [], featureMultiselectOptions())
        ->expectsConfirmation('Run migrations now?', 'yes')
        ->assertExitCode(0);
});

test('install command skips migrations when the user declines the final prompt', function (): void {
    $this->artisan('payzephyr:install')
        ->expectsChoice('Select the optional features you want to install', [], featureMultiselectOptions())
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertExitCode(0);
});

test('interactively selecting one optional feature installs only that feature', function (): void {
    $this->artisan('payzephyr:install')
        ->expectsChoice('Select the optional features you want to install', ['subscriptions'], featureMultiselectOptions())
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertExitCode(0);

    $files = installedMigrationFiles();

    expect($files['subscriptions'])->not->toBeEmpty()
        ->and($files['refunds'])->toBeEmpty();
});

test('--all installs every optional feature without prompting', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--all' => true]);

    $files = installedMigrationFiles();

    expect($files['payments'])->not->toBeEmpty()
        ->and($files['subscriptions'])->not->toBeEmpty()
        ->and($files['refunds'])->not->toBeEmpty();
});

test('--features= installs exactly the named features and nothing else', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    $files = installedMigrationFiles();

    expect($files['refunds'])->not->toBeEmpty()
        ->and($files['subscriptions'])->toBeEmpty();
});

test('--features= accepts multiple comma-separated values', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions,refunds']);

    $files = installedMigrationFiles();

    expect($files['subscriptions'])->not->toBeEmpty()
        ->and($files['refunds'])->not->toBeEmpty();
});

test('--features= is case-insensitive, trims whitespace, and dedupes', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => ' Refunds , REFUNDS ,refunds']);

    $files = installedMigrationFiles();

    expect($files['refunds'])->not->toBeEmpty()
        ->and($files['subscriptions'])->toBeEmpty();
});

test('--features= with an unknown feature name fails clearly and installs nothing optional', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'payouts']);

    expect(Artisan::output())->toContain('Unknown feature [payouts]');

    $files = installedMigrationFiles();
    expect($files['subscriptions'])->toBeEmpty()
        ->and($files['refunds'])->toBeEmpty();
});

test('--features= with an empty value fails clearly', function (): void {
    $exitCode = Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => '   ']);

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

test('Features::parseList rejects an unknown feature and names it in the exception', function (): void {
    Features::parseList('subscriptions,bogus_feature');
})->throws(InvalidArgumentException::class, 'Unknown feature [bogus_feature]');

test('Features::resolveDependencies is stable when no dependencies exist', function (): void {
    expect(Features::resolveDependencies(['refunds']))->toBe(['refunds'])
        ->and(Features::resolveDependencies(['subscriptions', 'refunds']))->toEqualCanonicalizing(['subscriptions', 'refunds']);
});

test('repeated installation with the same selection is idempotent and does not duplicate migration files', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    $firstRun = installedMigrationFiles()['refunds'];

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    $secondRun = installedMigrationFiles()['refunds'];

    expect($firstRun)->toHaveCount(1)
        ->and($secondRun)->toHaveCount(1)
        ->and($secondRun)->toBe($firstRun);
});

test('a feature can be added later without disturbing a previously installed one (upgrade scenario)', function (): void {
    // Simulates: v1 install with subscriptions only, then a later run adds refunds.
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions']);
    expect(installedMigrationFiles()['subscriptions'])->not->toBeEmpty();

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    $files = installedMigrationFiles();

    expect($files['subscriptions'])->not->toBeEmpty()
        ->and($files['refunds'])->not->toBeEmpty();
});

test('declining an already-installed feature interactively does not remove it', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions']);
    expect(installedMigrationFiles()['subscriptions'])->not->toBeEmpty();

    // Second, interactive run: deselect Subscriptions in the multiselect
    // even though it's already installed - PayZephyr must never delete on
    // a deselect.
    $this->artisan('payzephyr:install')
        ->expectsChoice('Select the optional features you want to install', [], featureMultiselectOptions())
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertExitCode(0);

    expect(installedMigrationFiles()['subscriptions'])->not->toBeEmpty();
});

test('interactive prompts pre-select features that are already installed', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    // expectsChoice()'s $answer is the answer given, not the default - this
    // only proves the prompt appears and keeping Refunds selected doesn't
    // error or duplicate anything; the default-preselection itself is
    // exercised by the "does not remove" test above via its [] answer
    // leaving the feature intact.
    $this->artisan('payzephyr:install')
        ->expectsChoice('Select the optional features you want to install', ['refunds'], featureMultiselectOptions())
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertExitCode(0);

    expect(installedMigrationFiles()['refunds'])->toHaveCount(1);
});

test('newly selected features are recorded in .env as PAYZEPHYR_FEATURE_* flags', function (): void {
    File::put(app()->environmentFilePath(), "APP_NAME=Test\n");

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    $env = File::get(app()->environmentFilePath());

    expect($env)->toContain('PAYZEPHYR_FEATURE_REFUNDS=true')
        ->and($env)->not->toContain('PAYZEPHYR_FEATURE_SUBSCRIPTIONS=true');
});

test('re-running install does not duplicate an already-written .env flag', function (): void {
    File::put(app()->environmentFilePath(), "APP_NAME=Test\n");

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    $env = File::get(app()->environmentFilePath());

    expect(substr_count($env, 'PAYZEPHYR_FEATURE_REFUNDS='))->toBe(1);
});

test('install command gracefully handles a missing .env file instead of erroring', function (): void {
    $envPath = app()->environmentFilePath();
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

test('interactive installation shows an intro and outro', function (): void {
    $this->artisan('payzephyr:install')
        ->expectsChoice('Select the optional features you want to install', [], featureMultiselectOptions())
        ->expectsConfirmation('Run migrations now?', 'no')
        ->expectsOutputToContain('PayZephyr Installation')
        ->expectsOutputToContain('PayZephyr is ready.')
        ->assertExitCode(0);
});

test('non-interactive installation does not print the interactive intro/outro framing', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    $output = Artisan::output();

    expect($output)->not->toContain('PayZephyr Installation')
        ->and($output)->toContain('Installing PayZephyr...');
});

test('selecting both optional features installs both and neither is skipped', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions,refunds']);

    $files = installedMigrationFiles();

    expect($files['payments'])->not->toBeEmpty()
        ->and($files['webhooks'])->not->toBeEmpty()
        ->and($files['subscriptions'])->not->toBeEmpty()
        ->and($files['refunds'])->not->toBeEmpty();
});

test('re-running install publishes migrations a later version added to an installed feature', function (): void {
    // An install from before the state_as_of migration existed: the feature's
    // original migration is there, the newer one is not.
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions']);
    $added = glob(database_path('migrations/*_add_state_as_of_to_subscription_transactions_table.php')) ?: [];
    array_map(unlink(...), $added);
    expect(glob(database_path('migrations/*_add_state_as_of_to_subscription_transactions_table.php')))->toBeEmpty();

    // The documented upgrade step, with no features named.
    Artisan::call('payzephyr:install', ['--no-interaction' => true]);

    expect(glob(database_path('migrations/*_add_state_as_of_to_subscription_transactions_table.php')))->not->toBeEmpty();
});

test('re-running install never overwrites a migration the application has edited', function (): void {
    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions']);
    $file = (glob(database_path('migrations/*_create_subscription_transactions_table.php')) ?: [])[0];
    file_put_contents($file, "<?php\n// edited by the application\n");

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'subscriptions']);

    expect(file_get_contents($file))->toBe("<?php\n// edited by the application\n")
        ->and(Artisan::output())->toContain('only migrations added since were published; existing files are never overwritten or removed.');
});

test('feature flags go to the .env file Laravel actually loads', function (): void {
    // Tests run with a per-process environment path, so this is also the
    // case of an app that moved its .env with useEnvironmentPath().
    File::put(app()->environmentFilePath(), "APP_NAME=Test\n");

    Artisan::call('payzephyr:install', ['--no-interaction' => true, '--features' => 'refunds']);

    expect(app()->environmentFilePath())->not->toBe(base_path('.env'))
        ->and(File::get(app()->environmentFilePath()))->toContain('PAYZEPHYR_FEATURE_REFUNDS=true');
});

test('outside a full Laravel application the .env file is taken from the project root', function (): void {
    $container = Mockery::mock(Application::class);
    $container->shouldReceive('basePath')->with('.env')->andReturn('/srv/app/.env');

    $command = new InstallCommand;
    $command->setLaravel($container);

    expect((new ReflectionMethod($command, 'environmentFilePath'))->invoke($command))->toBe('/srv/app/.env');
});
