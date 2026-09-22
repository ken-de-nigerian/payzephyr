<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Console\GenerateDocsCommand;

/**
 * The provider list and capability matrix are generated from src/Drivers.
 *
 * Belt and braces alongside the CI step: the same check runs here, so drift
 * fails the suite even for someone who never looks at a workflow file.
 */
function docsPath(string $relative): string
{
    return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

test('the committed documentation matches the drivers on disk', function () {
    $this->artisan('payzephyr:docs', ['--check' => true])->assertSuccessful();
});

test('the generated blocks are delimited in both documents', function () {
    // Without the markers the command cannot know what to replace, and fails
    // rather than guessing - so their presence is part of the contract.
    expect((string) file_get_contents(docsPath('docs/providers.md')))
        ->toContain('<!-- generated:provider-matrix:start -->')
        ->toContain('<!-- generated:provider-matrix:end -->');

    expect((string) file_get_contents(docsPath('README.md')))
        ->toContain('<!-- generated:provider-list:start -->')
        ->toContain('<!-- generated:provider-list:end -->');
});

test('the matrix is derived from the capability interfaces, not from a list', function () {
    $command = new GenerateDocsCommand;
    $discover = (new ReflectionClass($command))->getMethod('discoverProviders');
    $discover->setAccessible(true);

    /** @var array<int, array{name: string, label: string, subscriptions: bool, refunds: bool}> $providers */
    $providers = $discover->invoke($command);

    $drivers = glob(docsPath('src/Drivers/*Driver.php')) ?: [];

    expect($providers)->toHaveCount(count($drivers) - 1); // less AbstractDriver

    foreach ($providers as $provider) {
        $class = 'KenDeNigerian\\PayZephyr\\Drivers\\'.$provider['label'].'Driver';

        expect($provider['subscriptions'])->toBe(
            in_array(
                KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface::class,
                class_implements($class) ?: [],
                true,
            )
        )->and($provider['refunds'])->toBe(
            in_array(
                KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface::class,
                class_implements($class) ?: [],
                true,
            )
        );
    }
});

test('the provider list names providers and never counts them', function () {
    $command = new GenerateDocsCommand;
    $reflection = new ReflectionClass($command);

    $discover = $reflection->getMethod('discoverProviders');
    $discover->setAccessible(true);
    $render = $reflection->getMethod('renderList');
    $render->setAccessible(true);

    $line = (string) $render->invoke($command, $discover->invoke($command));

    expect($line)->toStartWith('**Currently supported providers:**')
        // The whole point of generating it. A number is what went stale three
        // times; names do not.
        ->and($line)->not->toMatch('/\b(two|three|four|five|six|seven|eight|nine|ten|eleven|\d+)\s+providers?\b/i');
});

test('a driver with no name of its own is left out rather than rendered blank', function () {
    // Defensive: AbstractDriver has no $name, and neither would a half-written
    // driver. An empty row in the matrix would be worse than an absent one.
    $command = new GenerateDocsCommand;
    $discover = (new ReflectionClass($command))->getMethod('discoverProviders');
    $discover->setAccessible(true);

    foreach ($discover->invoke($command) as $provider) {
        expect($provider['name'])->not->toBe('')
            ->and($provider['label'])->not->toBe('');
    }
});

test('generating is idempotent and respects the document line endings', function () {
    // The first version of this command always emitted LF. In a CRLF checkout
    // that meant every regenerated block differed from itself on the next run,
    // so --check reported drift no amount of regenerating could resolve, and
    // regenerating mixed line endings into the file.
    $command = new GenerateDocsCommand;
    $replace = (new ReflectionClass($command))->getMethod('replaceBlock');
    $replace->setAccessible(true);

    $crlf = "intro\r\n<!-- s -->\r\nold\r\n<!-- e -->\r\ntail\r\n";
    $out = (string) $replace->invoke($command, $crlf, '<!-- s -->', '<!-- e -->', "a\nb");

    expect($out)->toBe("intro\r\n<!-- s -->\r\na\r\nb\r\n<!-- e -->\r\ntail\r\n")
        // Running it again over its own output must change nothing.
        ->and((string) $replace->invoke($command, $out, '<!-- s -->', '<!-- e -->', "a\nb"))->toBe($out)
        ->and($out)->not->toContain("\n\n");

    $lf = "intro\n<!-- s -->\nold\n<!-- e -->\ntail\n";

    expect((string) $replace->invoke($command, $lf, '<!-- s -->', '<!-- e -->', "a\nb"))
        ->toBe("intro\n<!-- s -->\na\nb\n<!-- e -->\ntail\n")
        ->and((string) $replace->invoke($command, $lf, '<!-- s -->', '<!-- e -->', "a\nb"))
        ->not->toContain("\r");
});

test('a document without the markers fails instead of guessing where to write', function () {
    $command = new GenerateDocsCommand;
    $replace = (new ReflectionClass($command))->getMethod('replaceBlock');
    $replace->setAccessible(true);

    expect($replace->invoke($command, "no markers here\n", '<!-- s -->', '<!-- e -->', 'x'))->toBeNull()
        // End before start is malformed too, and must not silently produce a
        // document with the block inside out.
        ->and($replace->invoke($command, "<!-- e -->\n<!-- s -->\n", '<!-- s -->', '<!-- e -->', 'x'))->toBeNull();
});

/**
 * Run the command against a throwaway copy of the package, so its failure paths
 * can be exercised without damaging the real README to prove a guard works.
 */
function runDocsCommandIn(string $root, array $arguments = []): array
{
    $command = new GenerateDocsCommand($root);
    $command->setLaravel(app());

    $output = new Symfony\Component\Console\Output\BufferedOutput;
    $status = $command->run(
        new Symfony\Component\Console\Input\ArrayInput($arguments, $command->getDefinition()),
        $output,
    );

    return [$status, $output->fetch()];
}

function fixtureRoot(bool $withMarkers = true, bool $withDrivers = true): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pz_docs_'.bin2hex(random_bytes(6));
    mkdir($root.DIRECTORY_SEPARATOR.'docs', 0777, true);
    mkdir($root.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Drivers', 0777, true);

    if ($withDrivers) {
        // The command resolves classes from the real namespace, so the fixture
        // only needs file names that match the shipped drivers.
        foreach (glob(dirname(__DIR__, 2).'/src/Drivers/*Driver.php') as $driver) {
            touch($root.'/src/Drivers/'.basename($driver));
        }
    }

    $matrix = $withMarkers
        ? "## Matrix\n<!-- generated:provider-matrix:start -->\nstale\n<!-- generated:provider-matrix:end -->\n"
        : "## Matrix\nno markers at all\n";
    $list = $withMarkers
        ? "# Readme\n<!-- generated:provider-list:start -->\nstale\n<!-- generated:provider-list:end -->\n"
        : "# Readme\nno markers at all\n";

    file_put_contents($root.'/docs/providers.md', $matrix);
    file_put_contents($root.'/README.md', $list);

    return $root;
}

test('the command writes both blocks and reports what it regenerated', function () {
    $root = fixtureRoot();

    [$status, $output] = runDocsCommandIn($root);

    expect($status)->toBe(0)
        ->and($output)->toContain('Regenerated')
        ->and(file_get_contents($root.'/docs/providers.md'))
        ->toContain('| Provider | Subscriptions | Refunds |')
        ->not->toContain('stale')
        ->and(file_get_contents($root.'/README.md'))
        ->toContain('**Currently supported providers:**')
        ->not->toContain('stale');

    // Second run changes nothing and says so.
    [$status, $output] = runDocsCommandIn($root);

    expect($status)->toBe(0)->and($output)->toContain('already up to date');
});

test('check mode fails on stale documents without writing to them', function () {
    $root = fixtureRoot();
    $before = file_get_contents($root.'/docs/providers.md');

    [$status, $output] = runDocsCommandIn($root, ['--check' => true]);

    expect($status)->toBe(1)
        ->and($output)->toContain('out of date')
        // The point of check mode: it must not "fix" anything.
        ->and(file_get_contents($root.'/docs/providers.md'))->toBe($before);
});

test('check mode passes once the documents have been regenerated', function () {
    $root = fixtureRoot();
    runDocsCommandIn($root);

    [$status, $output] = runDocsCommandIn($root, ['--check' => true]);

    expect($status)->toBe(0)->and($output)->toContain('Documentation matches');
});

test('a document missing its markers is refused, not rewritten from scratch', function () {
    $root = fixtureRoot(withMarkers: false);
    $before = file_get_contents($root.'/docs/providers.md');

    [$status, $output] = runDocsCommandIn($root);

    expect($status)->toBe(1)
        ->and($output)->toContain('Missing')
        ->and(file_get_contents($root.'/docs/providers.md'))->toBe($before);
});

test('finding no drivers is refused rather than publishing an empty provider list', function () {
    // A build that somehow presents no drivers must not generate a README
    // announcing that PayZephyr supports nothing.
    $root = fixtureRoot(withDrivers: false);

    [$status, $output] = runDocsCommandIn($root);

    expect($status)->toBe(1)
        ->and($output)->toContain('No drivers found')
        ->and(file_get_contents($root.'/README.md'))->toContain('stale');
});
