<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Services;

use KenDeNigerian\PayZephyr\Contracts\ProviderDetectorInterface;
use KenDeNigerian\PayZephyr\Support\PackageConfig;
use KenDeNigerian\PayZephyr\Support\Payload;

final class ProviderDetector implements ProviderDetectorInterface
{
    /** @var array<string, string> */
    protected array $prefixes = [];

    public function __construct()
    {
        $this->prefixes = $this->loadPrefixesFromConfig();
    }

    /**
     * @return array<string, string>
     */
    protected function loadPrefixesFromConfig(): array
    {
        $prefixes = [];

        foreach (PackageConfig::read()->array('providers') as $providerName => $providerConfig) {
            $provider = Payload::of($providerConfig);
            $providerName = (string) $providerName;
            $prefix = $provider->string('reference_prefix') ?? $provider->string('driver') ?? $providerName;
            $prefixes[strtoupper($prefix)] = $providerName;
        }

        return $prefixes;
    }

    public function detectFromReference(string $reference): ?string
    {
        $upperReference = strtoupper($reference);

        foreach ($this->prefixes as $prefix => $provider) {
            if (str_starts_with($upperReference, $prefix.'_')) {
                return $provider;
            }
        }

        return null;
    }

    public function registerPrefix(string $prefix, string $provider): self
    {
        $this->prefixes[strtoupper($prefix)] = $provider;

        return $this;
    }

    public function getPrefixes(): array
    {
        return $this->prefixes;
    }
}
