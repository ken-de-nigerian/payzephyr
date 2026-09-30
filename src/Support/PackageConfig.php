<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Support;

/**
 * The package's configuration, as a typed reader.
 *
 * PayZephyr reads its config as `app('payments.config') ?? config('payments')`
 * in many places - the container binding first, so a test or an application
 * can swap the whole array, then the config repository. What comes back is
 * `mixed` all the way down, and a key set to the wrong kind of value in
 * someone's config file should read as absent, not fail wherever it is used.
 */
final class PackageConfig
{
    public static function read(): Payload
    {
        return Payload::of(app('payments.config') ?? config('payments', []));
    }
}
