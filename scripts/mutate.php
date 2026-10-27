<?php

declare(strict_types=1);

/*
 * Run Pest's mutation testing over the classes ADR-0003 holds to 100%.
 *
 * The classes are listed here and nowhere else, in shards that CI runs side
 * by side: one after another they took over half an hour, and the gate held
 * every pull request for that long. The shards are balanced by size and how
 * many tests cover each class. AbstractDriver is alone, since every test
 * covers it and each of its mutations runs the most tests.
 *
 *   php scripts/mutate.php           every class in one run (`composer mutation`)
 *   php scripts/mutate.php drivers-1 one shard's classes
 *   php scripts/mutate.php --shards  the shard names as a JSON list
 *
 * The workflow builds its matrix from --shards, so a shard added here runs in
 * CI without editing the workflow, and no class can be left out of both.
 *
 * Each run patches the plugin first (scripts/patch-pest-mutate.php) and holds
 * its classes to --min=100, so a shard passes or fails exactly as the whole
 * run would for those classes.
 */

$namespace = 'KenDeNigerian\\PayZephyr\\';

/** @var array<string, list<string>> $shards */
$shards = [
    'abstract-driver' => [
        'Drivers\\AbstractDriver',
    ],
    'webhooks' => [
        'Traits\\HasWebhookValidation',
        'Jobs\\ProcessWebhook',
        'Http\\Requests\\WebhookRequest',
        'Http\\Controllers\\WebhookController',
    ],
    'drivers-1' => [
        'Drivers\\SquareDriver',
        'Drivers\\StripeDriver',
        'Drivers\\FlutterwaveDriver',
    ],
    'drivers-2' => [
        'Drivers\\PayPalDriver',
        'Drivers\\RazorpayDriver',
        'Drivers\\PaystackDriver',
    ],
    'drivers-3' => [
        'Drivers\\MollieDriver',
        'Drivers\\OPayDriver',
        'Drivers\\MonnifyDriver',
        'Drivers\\PaddleDriver',
    ],
];

$argument = $argv[1] ?? null;

if ($argument === '--shards') {
    echo json_encode(array_keys($shards), JSON_THROW_ON_ERROR), "\n";

    exit(0);
}

if ($argument !== null && ! array_key_exists($argument, $shards)) {
    fwrite(STDERR, "mutate: unknown shard '$argument'; the shards are ".implode(', ', array_keys($shards)).".\n");

    exit(1);
}

$classes = $argument === null ? array_merge(...array_values($shards)) : $shards[$argument];
$classes = array_map(static fn (string $class): string => $namespace.$class, $classes);

$root = dirname(__DIR__);
$php = escapeshellarg(PHP_BINARY);

passthru($php.' '.escapeshellarg($root.'/scripts/patch-pest-mutate.php'), $status);

if ($status !== 0) {
    exit($status);
}

passthru(
    $php.' -d pcov.enabled=1 '.escapeshellarg($root.'/vendor/bin/pest')
    .' --mutate --parallel --min=100 --class='.escapeshellarg(implode(',', $classes)),
    $status,
);

exit($status);
