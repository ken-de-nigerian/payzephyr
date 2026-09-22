<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Console;

use Illuminate\Console\Command;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use ReflectionClass;

/**
 * Regenerate the parts of the documentation that describe which providers
 * exist and what each one supports.
 *
 * The provider list had been maintained by hand, and it rotted every time a
 * driver was added: "8 providers" survived into a release where there were
 * nine, and the sweep that fixed it left "nine" behind in two chapters and
 * "ten" in a third. Names and capabilities are facts about the code, so they
 * are read from the code.
 *
 * The source of truth is src/Drivers: a driver's own $name property, and the
 * capability interfaces it implements. Nothing here is configurable, because a
 * second place to configure it would be a second place to get it wrong.
 *
 * `--check` is the CI mode. It writes nothing and fails if the committed
 * documentation differs from what the drivers imply, so a new provider cannot
 * be merged with the docs left behind.
 */
final class GenerateDocsCommand extends Command
{
    protected $signature = 'payzephyr:docs
                            {--check : Write nothing and fail if the committed docs are out of date}';

    protected $description = 'Regenerate the provider matrix and provider list from the drivers on disk';

    /**
     * The package root the documents are resolved against.
     *
     * Defaults to the installed package. It is injectable so the command can be
     * pointed at a copy - which is how its own failure paths are tested, since
     * exercising "the markers are missing" against the real README would mean
     * damaging the repository to prove the guard works.
     */
    public function __construct(private ?string $basePath = null)
    {
        parent::__construct();
    }

    private const MATRIX_START = '<!-- generated:provider-matrix:start -->';

    private const MATRIX_END = '<!-- generated:provider-matrix:end -->';

    private const LIST_START = '<!-- generated:provider-list:start -->';

    private const LIST_END = '<!-- generated:provider-list:end -->';

    public function handle(): int
    {
        $providers = $this->discoverProviders();

        if ($providers === []) {
            $this->components->error('No drivers found in src/Drivers. Refusing to write an empty provider list.');

            return self::FAILURE;
        }

        $targets = [
            $this->packagePath('docs/providers.md') => [
                self::MATRIX_START => $this->renderMatrix($providers),
                self::MATRIX_END => null,
            ],
            $this->packagePath('README.md') => [
                self::LIST_START => $this->renderList($providers),
                self::LIST_END => null,
            ],
        ];

        $drifted = [];
        $written = [];

        foreach ($targets as $path => $blocks) {
            $markers = array_keys($blocks);
            $replacement = $blocks[$markers[0]];

            $original = (string) file_get_contents($path);
            $updated = $this->replaceBlock($original, $markers[0], $markers[1], $replacement);

            if ($updated === null) {
                $this->components->error(sprintf(
                    'Missing %s / %s markers in %s.',
                    $markers[0],
                    $markers[1],
                    basename($path),
                ));

                return self::FAILURE;
            }

            if ($updated === $original) {
                continue;
            }

            if ($this->option('check')) {
                $drifted[] = basename($path);

                continue;
            }

            file_put_contents($path, $updated);
            $written[] = basename($path);
        }

        if ($this->option('check')) {
            if ($drifted !== []) {
                $this->components->error(
                    'Documentation is out of date with src/Drivers: '.implode(', ', $drifted)
                    .'. Run `php artisan payzephyr:docs` and commit the result.'
                );

                return self::FAILURE;
            }

            $this->components->info(sprintf(
                'Documentation matches the %d drivers on disk.',
                count($providers),
            ));

            return self::SUCCESS;
        }

        if ($written === []) {
            $this->components->info('Documentation already up to date.');

            return self::SUCCESS;
        }

        $this->components->info('Regenerated: '.implode(', ', $written));

        return self::SUCCESS;
    }

    /**
     * Every bundled driver, with the capabilities its interfaces declare.
     *
     * Read by reflection without instantiating anything: a driver's constructor
     * validates credentials, and this has to work with no credentials present.
     *
     * @return array<int, array{name: string, label: string, subscriptions: bool, refunds: bool}>
     */
    private function discoverProviders(): array
    {
        $providers = [];

        foreach (glob($this->packagePath('src/Drivers/*Driver.php')) ?: [] as $file) {
            $short = basename($file, '.php');

            if ($short === 'AbstractDriver') {
                continue;
            }

            $class = 'KenDeNigerian\\PayZephyr\\Drivers\\'.$short;

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            $name = $reflection->getDefaultProperties()['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $providers[] = [
                'name' => $name,
                'label' => str_replace('Driver', '', $short),
                'subscriptions' => $reflection->implementsInterface(SupportsSubscriptionsInterface::class),
                'refunds' => $reflection->implementsInterface(SupportsRefundsInterface::class),
            ];
        }

        // Ordered so the generated block is stable across filesystems: the ones
        // that do the most first, then alphabetically.
        usort($providers, function (array $a, array $b): int {
            $weight = fn (array $p): int => ($p['subscriptions'] ? 2 : 0) + ($p['refunds'] ? 1 : 0);

            return [$weight($b), $a['label']] <=> [$weight($a), $b['label']];
        });

        return $providers;
    }

    /**
     * @param  array<int, array{label: string, subscriptions: bool, refunds: bool}>  $providers
     */
    private function renderMatrix(array $providers): string
    {
        $rows = ['| Provider | Subscriptions | Refunds |', '|---|---|---|'];

        foreach ($providers as $provider) {
            $rows[] = sprintf(
                '| %s | %s | %s |',
                $provider['label'],
                $provider['subscriptions'] ? '✅' : '❌',
                $provider['refunds'] ? '✅' : '❌',
            );
        }

        return implode("\n", $rows);
    }

    /**
     * Names, never a count. A count is the thing that goes stale.
     *
     * @param  array<int, array{label: string}>  $providers
     */
    private function renderList(array $providers): string
    {
        // Alphabetical here, rather than the matrix's capability order: this is
        // a sentence, and ordering it by what each provider supports would leak
        // that ranking into prose where it reads as a judgement.
        $labels = array_column($providers, 'label');
        sort($labels);

        $last = array_pop($labels);

        return '**Currently supported providers:** '.implode(', ', $labels).', and '.$last.'.';
    }

    private function replaceBlock(string $subject, string $start, string $end, string $replacement): ?string
    {
        $from = strpos($subject, $start);
        $to = strpos($subject, $end);

        if ($from === false || $to === false || $to < $from) {
            return null;
        }

        // Match the document's own line endings. Without this, generating into
        // a CRLF checkout emits LF, every regenerated block differs from itself
        // on the next run, and --check reports drift that no amount of
        // regenerating can resolve.
        $eol = str_contains($subject, "\r\n") ? "\r\n" : "\n";
        $replacement = str_replace("\n", $eol, str_replace("\r\n", "\n", $replacement));

        $before = substr($subject, 0, $from + strlen($start));
        $after = substr($subject, $to);

        return $before.$eol.$replacement.$eol.$after;
    }

    private function packagePath(string $relative): string
    {
        $root = $this->basePath ?? dirname(__DIR__, 2);

        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
