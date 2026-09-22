<?php

declare(strict_types=1);

/**
 * The documentation is checked against the code, not against the last person
 * who remembered to update it.
 *
 * Provider counts are the recurring failure. "Supports 8 providers" was true
 * once, then Paddle landed, then Razorpay, and every sentence carrying a number
 * had to be found and rewritten by hand - twice, and the second pass still left
 * "nine" in two files. Names and a generated matrix do not rot that way;
 * numbers do. So a number is a build failure.
 */
function liveDocs(): array
{
    $root = dirname(__DIR__, 2);
    $files = [$root.DIRECTORY_SEPARATOR.'README.md'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.'docs')
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'md') {
            continue;
        }

        $path = $file->getPathname();

        // Architecture decision records and dated release audits are history.
        // "5 of 9 providers" in ADR-0001 was true when that decision was made,
        // and rewriting it to match today would destroy the record's value.
        if (str_contains($path, 'CHANGELOG')
            || str_contains($path, 'RELEASE_AUDIT')
            || str_contains($path, DIRECTORY_SEPARATOR.'adr'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $files[] = $path;
    }

    return $files;
}

function bundledProviders(): array
{
    $names = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Drivers/*Driver.php') as $driver) {
        $name = basename($driver, 'Driver.php');

        if ($name !== 'Abstract') {
            $names[] = strtolower($name);
        }
    }

    sort($names);

    return $names;
}

test('no live document states how many providers there are', function () {
    $offenders = [];
    $pattern = '/\b(two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|\d+)\s+'
        .'(?:of\s+the\s+\w+\s+)?(?:supported\s+|bundled\s+)?(?:payment\s+)?providers?\b/i';

    foreach (liveDocs() as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
            if (preg_match($pattern, $line, $match)) {
                $offenders[] = basename($file).':'.($number + 1).' -> "'.trim($match[0]).'"';
            }
        }
    }

    expect($offenders)->toBe([], "Name the providers or link the matrix in docs/providers.md instead of counting them:\n  ".implode("\n  ", $offenders));
});

test('every bundled provider appears in the provider matrix', function () {
    $matrix = (string) file_get_contents(dirname(__DIR__, 2).'/docs/providers.md');

    // The matrix is the single reference the prose points at, so a driver that
    // is missing from it is invisible no matter how complete the code is.
    $missing = array_values(array_filter(
        bundledProviders(),
        fn (string $provider) => ! str_contains(strtolower($matrix), '| '.$provider.' |')
    ));

    expect($missing)->toBe([], 'Missing from the matrix in docs/providers.md: '.implode(', ', $missing));
});

test('the README names every bundled provider', function () {
    $readme = strtolower((string) file_get_contents(dirname(__DIR__, 2).'/README.md'));

    $missing = array_values(array_filter(
        bundledProviders(),
        fn (string $provider) => ! str_contains($readme, $provider)
    ));

    expect($missing)->toBe([], 'The README does not mention: '.implode(', ', $missing));
});

test('every provider in the matrix is a driver that actually exists', function () {
    // The opposite drift: a provider documented after it was removed, or
    // announced before it shipped.
    $matrix = (string) file_get_contents(dirname(__DIR__, 2).'/docs/providers.md');
    preg_match_all('/^\| ([A-Za-z][A-Za-z ]*?) \| (?:✅|❌) \| (?:✅|❌) \|/m', $matrix, $rows);

    $documented = array_map(fn (string $name) => strtolower(trim($name)), $rows[1]);

    expect($documented)->not->toBeEmpty()
        ->and(array_diff($documented, bundledProviders()))->toBe([]);
});

test('every exception the package can throw is in the catalogue', function () {
    // RefundException went undocumented through ten drivers' worth of refund
    // support, which is how a caller ends up not knowing that an ambiguous
    // refund must not be retried.
    $catalogue = (string) file_get_contents(dirname(__DIR__, 2).'/docs/error-handling.md');

    $undocumented = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Exceptions/*.php') as $exception) {
        $name = basename($exception, '.php');

        if (! str_contains($catalogue, $name)) {
            $undocumented[] = $name;
        }
    }

    expect($undocumented)->toBe([], 'Missing from docs/error-handling.md: '.implode(', ', $undocumented));
});

test('every event the package dispatches is in the events reference', function () {
    $reference = (string) file_get_contents(dirname(__DIR__, 2).'/docs/events.md');

    $undocumented = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Events/*.php') as $event) {
        $name = basename($event, '.php');

        if (! str_contains($reference, $name)) {
            $undocumented[] = $name;
        }
    }

    expect($undocumented)->toBe([], 'Missing from docs/events.md: '.implode(', ', $undocumented));
});

test('every queued job is described in the queue chapter', function () {
    // A job the application has to run a worker for, but does not know exists,
    // is a promise the documentation failed to keep.
    $chapter = (string) file_get_contents(dirname(__DIR__, 2).'/docs/queues.md');

    $undocumented = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Jobs/*.php') as $job) {
        $name = basename($job, '.php');

        if (! str_contains($chapter, $name)) {
            $undocumented[] = $name;
        }
    }

    expect($undocumented)->toBe([], 'Missing from docs/queues.md: '.implode(', ', $undocumented));
});

test('every artisan command named in the docs actually exists', function () {
    // The production checklist tells an operator to schedule a command. If the
    // name is wrong they find out at 3am, or never. One wrong name reached the
    // docs during this audit - written by the audit itself.
    $signatures = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Console/*.php') as $command) {
        $source = (string) file_get_contents($command);

        if (preg_match("/protected \\\$signature = '([a-z0-9:_-]+)/", $source, $match)) {
            $signatures[] = $match[1];
        }
    }

    expect($signatures)->not->toBeEmpty();

    $named = [];

    foreach (liveDocs() as $file) {
        preg_match_all('/payzephyr:[a-z0-9:_-]+/', (string) file_get_contents($file), $found);

        foreach ($found[0] as $name) {
            $named[$name][] = basename($file);
        }
    }

    $ghosts = [];

    foreach ($named as $name => $files) {
        if (! in_array($name, $signatures, true)) {
            $ghosts[] = $name.' ('.implode(', ', array_unique($files)).')';
        }
    }

    expect($ghosts)->toBe([], 'Commands named in the docs that do not exist: '.implode('; ', $ghosts));
});

test('no documented link points at a file or anchor that does not exist', function () {
    $broken = [];

    foreach (liveDocs() as $file) {
        $body = (string) file_get_contents($file);
        preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $body, $links);

        foreach ($links[1] as $target) {
            if (str_starts_with($target, 'http') || str_starts_with($target, 'mailto')) {
                continue;
            }

            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);

            $resolved = $path === ''
                ? $file
                : realpath(dirname($file).DIRECTORY_SEPARATOR.$path);

            if ($resolved === false || ! file_exists($resolved)) {
                $broken[] = basename($file).' -> '.$target.' (no such file)';

                continue;
            }

            if ($anchor === null || $anchor === '') {
                continue;
            }

            preg_match_all('/^#{1,6} +(.*)$/m', (string) file_get_contents($resolved), $headings);

            $slugs = array_map(static function (string $heading): string {
                $slug = strtolower(str_replace('`', '', trim($heading)));
                $slug = preg_replace('/[^a-z0-9 \-]/', '', $slug);

                return str_replace(' ', '-', (string) $slug);
            }, $headings[1]);

            if (! in_array(strtolower($anchor), $slugs, true)) {
                $broken[] = basename($file).' -> '.$target.' (no such anchor)';
            }
        }
    }

    expect($broken)->toBe([], "Broken documentation links:\n  ".implode("\n  ", $broken));
});

test('every environment variable the config ships is documented somewhere', function () {
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/config/payments.php');
    preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", $config, $matches);

    $prose = '';
    foreach (liveDocs() as $file) {
        $prose .= file_get_contents($file);
    }

    $undocumented = array_values(array_unique(array_filter(
        $matches[1],
        fn (string $variable) => ! str_contains($prose, $variable)
    )));

    sort($undocumented);

    expect($undocumented)->toBe([], "Shipped but documented nowhere:\n  ".implode("\n  ", $undocumented));
});
