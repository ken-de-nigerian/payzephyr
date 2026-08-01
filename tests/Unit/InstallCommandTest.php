<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use KenDeNigerian\PayZephyr\Console\InstallCommand;

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

test('install command publishes migrations', function () {
    Artisan::call('payzephyr:install', ['--no-interaction' => true]);

    $migrationsPath = database_path('migrations');
    $migrationFiles = glob($migrationsPath.'/*_create_payment_transactions_table.php');

    expect($migrationFiles)->not->toBeEmpty();
});

test('install command runs migrations when the user confirms the interactive prompt', function () {
    // Covers the elseif ($this->confirm(...)) branch, which the other tests
    // never reach because they always pass --no-interaction.
    $this->artisan('payzephyr:install')
        ->expectsConfirmation('Run migrations now?', 'yes')
        ->assertExitCode(0);
});

test('install command skips migrations when the user declines the interactive prompt', function () {
    $this->artisan('payzephyr:install')
        ->expectsConfirmation('Run migrations now?', 'no')
        ->assertExitCode(0);
});
