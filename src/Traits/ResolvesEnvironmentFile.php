<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use Illuminate\Foundation\Application as FoundationApplication;

/**
 * The .env file the install and uninstall commands write feature flags to.
 *
 * Laravel loads the file named by environmentFilePath(), which honours
 * useEnvironmentPath() and --env; assuming it sits at base_path('.env') would
 * write flags to a file the application never reads. That method lives on the
 * concrete Foundation application, not the contract, so any other container
 * falls back to the project root.
 */
trait ResolvesEnvironmentFile
{
    protected function environmentFilePath(): string
    {
        return $this->laravel instanceof FoundationApplication
            ? $this->laravel->environmentFilePath()
            : $this->laravel->basePath('.env');
    }
}
