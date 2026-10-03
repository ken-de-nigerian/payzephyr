<?php

declare(strict_types=1);

/*
 * Make Pest's mutation plugin run the tests it means to run.
 *
 * For each mutation, pest-plugin-mutate (3.x, and 4.x at the time of writing)
 * runs the tests that cover the mutated line, by passing PHPUnit one
 * --filter regex that ORs every covering test's name together. A line covered
 * by several hundred tests - a driver's constructor, a log call on a common
 * path - produces a pattern of 64 KB or more, which PCRE refuses to compile.
 * PHPUnit then matches it against nothing, runs no tests, and the plugin
 * reports the mutation as "untested": a survivor that no test was ever asked
 * about. On Windows the same filter overflows the command line instead, the
 * test process fails, and the mutation is reported as caught: a kill that no
 * test made. Either way the score is wrong, and on Linux it is wrong low.
 *
 * This collapses a filter that long to the covering test classes - more tests
 * than needed, never fewer - so every mutation is tried against what covers
 * it. It also lets the plugin accept absolute Windows paths, which it treats
 * as relative and doubles. Run by `composer mutation` before Pest; it changes
 * nothing when the plugin is already patched, and fails loudly when the
 * plugin's code no longer looks as expected, rather than letting the gate
 * run unpatched.
 */

$root = dirname(__DIR__).'/vendor/pestphp/pest-plugin-mutate/src';
$marker = '// payzephyr: filters collapsed to classes when too long to compile';

$patches = [
    $root.'/MutationTest.php' => [
        "        \$filters = array_unique(\$filters);\n",
        "        \$filters = array_unique(\$filters);\n"
        ."        $marker\n"
        ."        if (strlen(implode('|', \$filters)) > 8000) {\n"
        ."            \$filters = array_values(array_unique(array_map(fn (string \$filter): string => explode('::', \$filter)[0].'::', \$filters)));\n"
        ."        }\n",
    ],
    $root.'/Support/FileFinder.php' => [
        'if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {',
        'if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! file_exists($path)) {',
    ],
];

foreach ($patches as $file => [$search, $replace]) {
    $source = @file_get_contents($file);

    if ($source === false) {
        fwrite(STDERR, "patch-pest-mutate: $file not found - is pestphp/pest-plugin-mutate installed?\n");
        exit(1);
    }

    $source = str_replace("\r\n", "\n", $source);

    if (str_contains($source, $replace)) {
        continue;
    }

    if (substr_count($source, $search) !== 1) {
        fwrite(STDERR, "patch-pest-mutate: $file no longer matches the expected code; review the patch before trusting a mutation score.\n");
        exit(1);
    }

    file_put_contents($file, str_replace($search, $replace, $source));
    echo 'patch-pest-mutate: patched '.basename($file)."\n";
}
