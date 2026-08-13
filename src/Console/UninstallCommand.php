<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Uninstalls PayZephyr: drops the tables it owns, removes its published
 * migration files, and clears the .env feature flags it wrote - and nothing
 * else. Deliberately conservative (see Features::core()/optional() for the
 * single source of truth on what PayZephyr actually owns):
 *
 * - Never touches config/payments.php: it may contain developer
 *   customization, and Laravel has no "unpublish" concept for vendor:publish
 *   output, so removing it automatically is a judgment call this command
 *   does not make on the developer's behalf.
 * - Never removes a feature that was never installed (see isInstalled()).
 * - Never runs without either an explicit interactive confirmation
 *   (including typing "UNINSTALL") or --force in a non-interactive
 *   environment - see confirmDestruction().
 */
final class UninstallCommand extends Command
{
    protected $signature = 'payzephyr:uninstall
        {--force : Skip the confirmation prompt (required to run in a non-interactive environment)}
        {--features= : Comma-separated list of optional features to remove, e.g. --features=refunds. Omit to remove everything PayZephyr owns, including core.}';

    protected $description = 'Uninstall PayZephyr: drop its tables and remove its published migrations (destructive)';

    public function handle(): int
    {
        try {
            $resources = $this->resolveTargetResources();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $installed = array_filter($resources, fn (array $resource) => $this->isInstalled($resource));

        if ($installed === []) {
            $this->info('PayZephyr does not appear to be installed (no matching migrations were found) - nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && $this->option('no-interaction')) {
            $this->error('Refusing to uninstall in a non-interactive environment without --force. This is a destructive operation - pass --force to confirm.');

            return self::FAILURE;
        }

        $this->printWarning($installed);

        if (! $this->option('force') && ! $this->confirmDestruction()) {
            $this->comment('Uninstall cancelled - nothing was removed.');

            return self::SUCCESS;
        }

        if (! $this->option('no-interaction')) {
            intro('Uninstalling PayZephyr');
        } else {
            $this->info('Uninstalling PayZephyr...');
        }

        $failed = [];
        foreach ($installed as $resource) {
            if ($this->removeResource($resource)) {
                $this->info("✓ Removed {$resource['label']}");
            } else {
                $failed[] = $resource['label'];
            }
        }

        $this->newLine();

        if ($failed !== []) {
            $this->error('Uninstall finished with errors removing: '.implode(', ', $failed).'. See the messages above for details.');

            return self::FAILURE;
        }

        $this->comment('config/payments.php was left in place - delete it manually if you no longer need it.');

        if (! $this->option('no-interaction')) {
            outro('PayZephyr uninstall complete.');
        } else {
            $this->info('PayZephyr uninstall complete.');
        }

        return self::SUCCESS;
    }

    /**
     * Resolve which PayZephyr-owned resources this run targets: every
     * optional feature named in --features=, or (with no --features=) every
     * core and optional resource PayZephyr owns.
     *
     * --features= only ever accepts optional feature names (validated
     * against the same Features registry the installer uses) - core
     * (payments/webhooks) can only be removed via a full, no-flag uninstall,
     * since selectively dropping core while leaving optional feature tables
     * behind is not a state PayZephyr is designed to leave an app in.
     *
     * @return array<string, array{label: string, migrationPattern: string, tableConfigKey: string, defaultTable: string, envVar: ?string}>
     *
     * @throws InvalidArgumentException
     */
    private function resolveTargetResources(): array
    {
        if ($this->option('features') !== null) {
            $keys = Features::parseList((string) $this->option('features'));

            $resources = [];
            foreach ($keys as $key) {
                $resources[$key] = $this->describeResource(Features::get($key));
            }

            return $resources;
        }

        $resources = array_map(function ($feature) {
            return $this->describeResource($feature);
        }, Features::core());

        foreach (Features::optional() as $key => $feature) {
            $resources[$key] = $this->describeResource($feature);
        }

        return $resources;
    }

    /**
     * @param  array{label: string, migrationPattern: string, tableConfigKey: string, defaultTable: string, envVar?: string}  $feature
     * @return array{label: string, migrationPattern: string, tableConfigKey: string, defaultTable: string, envVar: ?string}
     */
    private function describeResource(array $feature): array
    {
        return [
            'label' => $feature['label'],
            'migrationPattern' => $feature['migrationPattern'],
            'tableConfigKey' => $feature['tableConfigKey'],
            'defaultTable' => $feature['defaultTable'],
            'envVar' => $feature['envVar'] ?? null,
        ];
    }

    /**
     * Ground truth for "is this resource actually installed": the same
     * migration-file-existence check InstallCommand uses, so uninstall never
     * attempts to touch a table that was never published in the first
     * place - see Phase 13.5, feature-aware uninstall.
     *
     * @param  array{migrationPattern: string}  $resource
     */
    private function isInstalled(array $resource): bool
    {
        return glob(database_path('migrations/'.$resource['migrationPattern'])) !== [];
    }

    /**
     * @param  array<string, array{label: string, tableConfigKey: string, defaultTable: string}>  $installed
     */
    private function printWarning(array $installed): void
    {
        $tables = array_map(
            fn (array $resource) => config('payments.'.$resource['tableConfigKey'], $resource['defaultTable']),
            $installed
        );
        $labels = array_map(fn (array $resource) => $resource['label'], $installed);

        $message = "This will permanently drop the following PayZephyr table(s) and all data in them:\n"
            .implode("\n", array_map(fn (string $label, string $table) => "  - $label ($table)", $labels, $tables))
            ."\n\nThis cannot be undone. config/payments.php and any other application data are not affected.";

        if ($this->option('no-interaction')) {
            $this->warn($message);

            return;
        }

        warning($message);
    }

    private function confirmDestruction(): bool
    {
        if (! confirm('Are you sure you want to continue? This cannot be undone.', default: false)) {
            return false;
        }

        return text('Type "UNINSTALL" (in capitals) to confirm') === 'UNINSTALL';
    }

    /**
     * @param  array{label: string, migrationPattern: string, tableConfigKey: string, defaultTable: string, envVar: ?string}  $resource
     */
    private function removeResource(array $resource): bool
    {
        try {
            $tableName = config('payments.'.$resource['tableConfigKey'], $resource['defaultTable']);
            Schema::dropIfExists($tableName);

            foreach (glob(database_path('migrations/'.$resource['migrationPattern'])) ?: [] as $file) {
                $this->forgetMigration($file);
                File::delete($file);
            }

            if ($resource['envVar'] !== null) {
                $this->clearEnvironmentFlag($resource['envVar']);
            }

            return true;
        } catch (Throwable $e) {
            $this->error("  Failed to remove {$resource['label']}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Remove $file's tracking row from Laravel's migration repository (the
     * "migrations" table), so that if this feature's migration file is
     * republished later (reinstall), `php artisan migrate` sees it as
     * pending again rather than silently skipping it as already-run.
     *
     * A no-op if the migration repository table doesn't exist yet (e.g.
     * `php artisan migrate` was never run against this app).
     */
    private function forgetMigration(string $file): void
    {
        $repository = $this->laravel['migrator']->getRepository();

        if (! $repository->repositoryExists()) {
            return;
        }

        $repository->delete((object) ['migration' => basename($file, '.php')]);
    }

    /**
     * Set PAYZEPHYR_FEATURE_* to false in .env for a removed feature -
     * mirrors InstallCommand::updateEnvironmentFlags()'s additive-only
     * philosophy, but in reverse. A no-op, not an error, if there's no .env
     * file or the flag was never written to begin with.
     */
    private function clearEnvironmentFlag(string $envVar): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            return;
        }

        $contents = File::get($envPath);

        if (preg_match('/^'.preg_quote($envVar, '/').'=/m', $contents)) {
            $contents = preg_replace('/^'.preg_quote($envVar, '/').'=.*$/m', "$envVar=false", $contents);
            File::put($envPath, $contents);
        }
    }
}
