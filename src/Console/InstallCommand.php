<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;

final class InstallCommand extends Command
{
    protected $signature = 'payzephyr:install
        {--force : Overwrite existing published files}
        {--all : Install every optional feature without prompting}
        {--features= : Comma-separated list of optional features to install, e.g. --features=subscriptions,refunds}';

    protected $description = 'Install PayZephyr package';

    /**
     * Execute command.
     */
    public function handle(): int
    {
        if (! $this->option('no-interaction')) {
            intro('PayZephyr Installation');
            note("Let's get your payment infrastructure ready.");
        } else {
            $this->info('Installing PayZephyr...');
        }

        $this->call('vendor:publish', [
            '--tag' => 'payments-config',
            '--force' => $this->option('force'),
        ]);
        $this->info('✓ Configuration file published');

        $this->call('vendor:publish', [
            '--tag' => 'payzephyr-migrations-core',
            '--force' => $this->option('force'),
        ]);
        $this->info('✓ Core migrations published (payment_transactions, webhook_events)');

        try {
            $selection = $this->determineOptionalFeatures();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $alreadyInstalled = $this->installedFeatures();
        $newlySelected = array_values(array_diff($selection, $alreadyInstalled));
        $skippedAlreadyInstalled = array_values(array_intersect($selection, $alreadyInstalled));

        foreach ($newlySelected as $key) {
            $feature = Features::get($key);
            $this->call('vendor:publish', [
                '--tag' => $feature['migrationTag'],
                '--force' => $this->option('force'),
            ]);
            $this->info("✓ {$feature['label']} migration published");
        }

        foreach ($skippedAlreadyInstalled as $key) {
            $this->comment(Features::get($key)['label'].' is already installed - skipping (PayZephyr never re-publishes or removes an installed feature automatically).');
        }

        if (! $this->option('no-interaction')) {
            note($this->buildPreMigrateSummary($alreadyInstalled, $newlySelected));
        }

        $shouldMigrate = ! $this->option('no-interaction') && confirm('Run migrations now?');

        if ($shouldMigrate) {
            $this->call('migrate');
            $this->info('✓ Migrations completed');
        }

        if ($newlySelected !== []) {
            $this->updateEnvironmentFlags($newlySelected);
        }

        $this->printSummary($alreadyInstalled, $newlySelected);

        if (! $this->option('no-interaction')) {
            outro('PayZephyr is ready. Configure your providers in .env to get started.');
        }

        return self::SUCCESS;
    }

    /**
     * A concise "what's about to happen" summary shown before the
     * DB-mutating migrate step - see Phase 12.28 in the installer spec:
     * the developer should know exactly what's about to run before it does.
     *
     * @param  array<int, string>  $alreadyInstalled
     * @param  array<int, string>  $newlySelected
     */
    private function buildPreMigrateSummary(array $alreadyInstalled, array $newlySelected): string
    {
        $lines = ['Features to install:', '  - Payments (core)', '  - Webhooks (core)'];

        foreach (Features::optional() as $key => $feature) {
            $mark = in_array($key, $newlySelected, true) || in_array($key, $alreadyInstalled, true) ? '✓' : '○';
            $lines[] = "  $mark {$feature['label']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve which optional features this run should install, in priority
     * order: --features=, then --all, then an interactive prompt per
     * feature, then (non-interactive with neither flag) none at all - core
     * only, per the explicit requirement that "install everything" must
     * never be the silent non-interactive default.
     *
     * @return array<int, string>
     *
     * @throws InvalidArgumentException
     */
    private function determineOptionalFeatures(): array
    {
        if ($this->option('features') !== null) {
            $selected = Features::parseList((string) $this->option('features'));

            return Features::resolveDependencies($selected);
        }

        if ($this->option('all')) {
            return Features::optionalKeys();
        }

        if ($this->option('no-interaction')) {
            return [];
        }

        $alreadyInstalled = $this->installedFeatures();

        $options = array_map(function ($feature) {
            return "{$feature['label']} - {$feature['description']}";
        }, Features::optional());

        $selected = multiselect(
            label: 'Select the optional features you want to install',
            options: $options,
            default: $alreadyInstalled,
            hint: 'Space to select, Enter to continue. Deselecting an already-installed feature does not remove it.',
        );

        return Features::resolveDependencies($selected);
    }

    /**
     * Ground truth for "is this feature already installed": whether its
     * migration file exists in the app's migrations directory. This is
     * more reliable than trusting an env flag (which could drift or be
     * absent in a fresh test environment) and is what actually determines
     * whether the feature's table exists.
     *
     * @return array<int, string>
     */
    private function installedFeatures(): array
    {
        $installed = [];

        foreach (Features::optional() as $key => $feature) {
            if (glob(database_path('migrations/'.$feature['migrationPattern'])) !== []) {
                $installed[] = $key;
            }
        }

        return $installed;
    }

    /**
     * Append PAYZEPHYR_FEATURE_* flags to .env for newly-enabled features.
     * Additive only: never writes an existing flag back to false, and does
     * nothing (with a warning, not an error) if there's no .env file to
     * write to - e.g. some CI environments publish config purely through
     * environment variables instead.
     *
     * @param  array<int, string>  $newlySelected
     *
     * @throws FileNotFoundException
     */
    private function updateEnvironmentFlags(array $newlySelected): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            $this->comment('No .env file found - set these manually: '.
                implode(', ', array_map(fn (string $k) => Features::get($k)['envVar'].'=true', $newlySelected)));

            return;
        }

        $contents = File::get($envPath);

        foreach ($newlySelected as $key) {
            $envVar = Features::get($key)['envVar'];

            if (preg_match('/^'.preg_quote($envVar, '/').'=/m', $contents)) {
                $contents = preg_replace('/^'.preg_quote($envVar, '/').'=.*$/m', "$envVar=true", $contents);
            } else {
                $contents = rtrim($contents, "\n")."\n$envVar=true\n";
            }
        }

        File::put($envPath, $contents);
    }

    /**
     * @param  array<int, string>  $alreadyInstalled
     * @param  array<int, string>  $newlySelected
     */
    private function printSummary(array $alreadyInstalled, array $newlySelected): void
    {
        $this->newLine();
        $this->info('PayZephyr installed successfully!');

        $this->line('Core (always installed): payments, webhooks');

        if ($newlySelected !== []) {
            $labels = array_map(fn (string $k) => Features::get($k)['label'], $newlySelected);
            $this->line('Newly enabled: '.implode(', ', $labels));
        }

        $stillInstalled = array_values(array_unique([...$alreadyInstalled, ...$newlySelected]));
        $notInstalled = array_values(array_diff(Features::optionalKeys(), $stillInstalled));

        if ($notInstalled !== []) {
            $labels = array_map(fn (string $k) => Features::get($k)['label'], $notInstalled);
            $this->line('Not installed: '.implode(', ', $labels).' (run `php artisan payzephyr:install --features='.implode(',', $notInstalled).'` any time to add them)');
        }

        $this->comment('Please configure your providers in .env');
    }
}
