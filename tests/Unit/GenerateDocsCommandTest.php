<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Console\GenerateDocsCommand;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\Contracts\SupportsSubscriptionsInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

test('the committed documentation matches the drivers on disk', function (): void {
    $this->artisan('payzephyr:docs', ['--check' => true])->assertSuccessful();
});

test('the generated blocks are delimited in both documents', function (): void {
    // Without the markers the command cannot know what to replace, and fails
    // rather than guessing - so their presence is part of the contract.
    expect((string) file_get_contents(docsPath('docs/providers.md')))
        ->toContain('<!-- generated:provider-matrix:start -->')
        ->toContain('<!-- generated:provider-matrix:end -->');

    expect((string) file_get_contents(docsPath('README.md')))
        ->toContain('<!-- generated:provider-list:start -->')
        ->toContain('<!-- generated:provider-list:end -->');
});

test('the matrix is derived from the capability interfaces, not from a list', function (): void {
    $command = new GenerateDocsCommand;
    $discover = (new ReflectionClass($command))->getMethod('discoverProviders');

    /** @var array<int, array{name: string, label: string, subscriptions: bool, refunds: bool}> $providers */
    $providers = $discover->invoke($command);

    $drivers = glob(docsPath('src/Drivers/*Driver.php')) ?: [];

    expect($providers)->toHaveCount(count($drivers) - 1); // less AbstractDriver

    foreach ($providers as $provider) {
        $class = 'KenDeNigerian\\PayZephyr\\Drivers\\'.$provider['label'].'Driver';

        expect($provider['subscriptions'])->toBe(
            in_array(
                SupportsSubscriptionsInterface::class,
                class_implements($class) ?: [],
                true,
            )
        )->and($provider['refunds'])->toBe(
            in_array(
                SupportsRefundsInterface::class,
                class_implements($class) ?: [],
                true,
            )
        );
    }
});

test('the provider list names providers and never counts them', function (): void {
    $command = new GenerateDocsCommand;
    $reflection = new ReflectionClass($command);

    $discover = $reflection->getMethod('discoverProviders');
    $render = $reflection->getMethod('renderList');

    $line = (string) $render->invoke($command, $discover->invoke($command));

    expect($line)->toStartWith('**Currently supported providers:**')
        // The whole point of generating it. A number is what went stale three
        // times; names do not.
        ->and($line)->not->toMatch('/\b(two|three|four|five|six|seven|eight|nine|ten|eleven|\d+)\s+providers?\b/i');
});

test('a driver with no name of its own is left out rather than rendered blank', function (): void {
    // Defensive: AbstractDriver has no $name, and neither would a half-written
    // driver. An empty row in the matrix would be worse than an absent one.
    $command = new GenerateDocsCommand;
    $discover = (new ReflectionClass($command))->getMethod('discoverProviders');

    foreach ($discover->invoke($command) as $provider) {
        expect($provider['name'])->not->toBe('')
            ->and($provider['label'])->not->toBe('');
    }
});

test('generating is idempotent and respects the document line endings', function (): void {
    // The first version of this command always emitted LF. In a CRLF checkout
    // that meant every regenerated block differed from itself on the next run,
    // so --check reported drift no amount of regenerating could resolve, and
    // regenerating mixed line endings into the file.
    $command = new GenerateDocsCommand;
    $replace = (new ReflectionClass($command))->getMethod('replaceBlock');

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

test('a document without the markers fails instead of guessing where to write', function (): void {
    $command = new GenerateDocsCommand;
    $replace = (new ReflectionClass($command))->getMethod('replaceBlock');

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

    $output = new BufferedOutput;
    $status = $command->run(
        new ArrayInput($arguments, $command->getDefinition()),
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

test('the command writes both blocks and reports what it regenerated', function (): void {
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

test('check mode fails on stale documents without writing to them', function (): void {
    $root = fixtureRoot();
    $before = file_get_contents($root.'/docs/providers.md');

    [$status, $output] = runDocsCommandIn($root, ['--check' => true]);

    expect($status)->toBe(1)
        ->and($output)->toContain('out of date')
        // The point of check mode: it must not "fix" anything.
        ->and(file_get_contents($root.'/docs/providers.md'))->toBe($before);
});

test('check mode passes once the documents have been regenerated', function (): void {
    $root = fixtureRoot();
    runDocsCommandIn($root);

    [$status, $output] = runDocsCommandIn($root, ['--check' => true]);

    expect($status)->toBe(0)->and($output)->toContain('Documentation matches');
});

test('a document missing its markers is refused, not rewritten from scratch', function (): void {
    $root = fixtureRoot(withMarkers: false);
    $before = file_get_contents($root.'/docs/providers.md');

    [$status, $output] = runDocsCommandIn($root);

    expect($status)->toBe(1)
        ->and($output)->toContain('Missing')
        ->and(file_get_contents($root.'/docs/providers.md'))->toBe($before);
});

test('finding no drivers is refused rather than publishing an empty provider list', function (): void {
    // A build that somehow presents no drivers must not generate a README
    // announcing that PayZephyr supports nothing.
    $root = fixtureRoot(withDrivers: false);

    [$status, $output] = runDocsCommandIn($root);

    expect($status)->toBe(1)
        ->and($output)->toContain('No drivers found')
        ->and(file_get_contents($root.'/README.md'))->toContain('stale');
});

test('discovery skips files in src/Drivers that are not concrete, named drivers', function (): void {
    // Whatever else lands in src/Drivers - a stray file, an intermediate
    // abstract base, a driver still missing its name - must not produce a
    // row in the published provider matrix.
    eval('namespace KenDeNigerian\PayZephyr\Drivers; abstract class DocsFixtureBaseDriver extends AbstractDriver {}');
    eval('namespace KenDeNigerian\PayZephyr\Drivers; final class DocsFixtureUnnamedDriver extends DocsFixtureBaseDriver {
        protected function validateConfig(): void {}
        protected function getDefaultHeaders(): array { return []; }
        public function charge(\KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO $r): \KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO { throw new \LogicException; }
        public function verify(string $r): \KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO { throw new \LogicException; }
        public function validateWebhook(array $h, string $b): bool { return false; }
        public function healthCheck(): bool { return false; }
    }');

    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payzephyr-docs-'.bin2hex(random_bytes(4));
    mkdir($root.'/src/Drivers', 0777, true);

    try {
        foreach (['PaystackDriver', 'NoSuchClassDriver', 'DocsFixtureBaseDriver', 'DocsFixtureUnnamedDriver', 'AbstractDriver'] as $short) {
            touch($root."/src/Drivers/$short.php");
        }

        $command = new GenerateDocsCommand($root);
        $discover = new ReflectionMethod($command, 'discoverProviders');

        expect(array_column($discover->invoke($command), 'name'))->toBe(['paystack']);
    } finally {
        array_map(unlink(...), glob($root.'/src/Drivers/*.php') ?: []);
        rmdir($root.'/src/Drivers');
        rmdir($root.'/src');
        rmdir($root);
    }
});
