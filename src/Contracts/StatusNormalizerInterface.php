<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

/**
 * Status normalizer interface.
 */
interface StatusNormalizerInterface
{
    /**
     * Normalize status.
     */
    public function normalize(string $status, ?string $provider = null): string;

    /**
     * Register provider mappings.
     *
     * @param  array<string, array<string>>  $mappings
     */
    public function registerProviderMappings(string $provider, array $mappings): self;

    /**
     * Get provider mappings.
     *
     * @return array<string, array<string, string>>
     */
    public function getProviderMappings(): array;

    /**
     * Get default mappings.
     *
     * @return array<string, array<string>>
     */
    public function getDefaultMappings(): array;
}
