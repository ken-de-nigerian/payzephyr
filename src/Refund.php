<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use KenDeNigerian\PayZephyr\Contracts\SupportsRefundsInterface;
use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\PaymentException;
use KenDeNigerian\PayZephyr\Exceptions\RefundException;
use KenDeNigerian\PayZephyr\Services\RefundValidator;
use Throwable;

final class Refund
{
    /**
     * How long the in-flight refund lock (see refund()) is held before it
     * expires on its own - a safety net so a crashed/killed process that
     * never reaches the finally block doesn't block that transaction's
     * refunds forever. Comfortably above any realistic provider API call.
     */
    private const LOCK_TTL_SECONDS = 60;

    protected PaymentManager $manager;

    /** @var array<string, mixed> */
    protected array $data = [];

    /** @var array<int, string> */
    protected array $providers = [];

    public function __construct(PaymentManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Set the provider(s) to use
     *
     * @param  string|array<int, string>  $providers
     */
    public function with(string|array $providers): self
    {
        $this->providers = is_array($providers) ? $providers : [$providers];

        return $this;
    }

    /**
     * Alias for with()
     *
     * @param  string|array<int, string>  $providers
     */
    public function using(string|array $providers): self
    {
        return $this->with($providers);
    }

    /** Set the original transaction reference to refund. */
    public function transaction(string $transactionReference): self
    {
        $this->data['transaction_reference'] = $transactionReference;

        return $this;
    }

    /** Amount to refund. Omit for a full refund of the original charge. */
    public function amount(float $amount): self
    {
        $this->data['amount'] = $amount;

        return $this;
    }

    /**
     * Currency of the refund amount. Some providers (Square, PayPal, Mollie)
     * require this to be sent explicitly rather than inferring it from the
     * original transaction - omit it and the driver falls back to the
     * provider's first configured currency, which is only correct for
     * single-currency merchants.
     */
    public function currency(string $currency): self
    {
        $this->data['currency'] = strtoupper($currency);

        return $this;
    }

    public function reason(string $reason): self
    {
        $this->data['reason'] = $reason;

        return $this;
    }

    /** @param array<string, mixed> $metadata */
    public function metadata(array $metadata): self
    {
        $this->data['metadata'] = $metadata;

        return $this;
    }

    /** If null, a UUID is generated automatically. */
    public function idempotency(?string $key = null): self
    {
        $this->data['idempotency_key'] = $key ?? Str::uuid()->toString();

        return $this;
    }

    /** Issue the refund. */
    public function refund(): RefundResponseDTO
    {
        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsRefundsInterface)) {
            throw new PaymentException("Provider [{$this->getProviderName()}] does not support refunds");
        }

        $request = RefundRequestDTO::fromArray($this->data);

        $config = app('payments.config') ?? config('payments', []);
        if ($config['refunds']['validation']['enabled'] ?? true) {
            app(RefundValidator::class)->validateRefund($request);
        }

        $preventDuplicates = $config['refunds']['prevent_duplicates'] ?? true;
        $lockKey = $this->inFlightLockKey($request->transactionReference);

        if ($preventDuplicates && ! Cache::add($lockKey, true, self::LOCK_TTL_SECONDS)) {
            throw new RefundException(
                "A refund is already in progress for transaction $request->transactionReference. ".
                'Wait for it to resolve before submitting another.'
            );
        }

        try {
            $response = $driver->refund($request);
        } catch (Throwable $e) {
            if ($preventDuplicates && $e instanceof RefundException && $e->isAmbiguousProviderOutcome()) {
                throw new RefundException(
                    "The refund for transaction $request->transactionReference timed out or lost its response before ".
                    'its outcome could be confirmed. PayZephyr has not retried it, since the provider may already have '.
                    'processed it - doing so could refund the customer twice. Reconcile with the provider (or '.
                    'Refund::fetch()) before attempting another refund.',
                    0,
                    $e
                );
            }

            if ($preventDuplicates) {
                Cache::forget($lockKey);
            }

            throw $e;
        }

        if ($preventDuplicates) {
            Cache::forget($lockKey);
        }

        return $response;
    }

    private function inFlightLockKey(string $transactionReference): string
    {
        return 'payzephyr:refund:inflight:'.$transactionReference;
    }

    /** Fetch details of a previously issued refund. */
    public function fetch(string $refundReference): RefundResponseDTO
    {
        $driver = $this->getDriver();

        if (! ($driver instanceof SupportsRefundsInterface)) {
            throw new PaymentException("Provider [{$this->getProviderName()}] does not support refunds");
        }

        return $driver->fetchRefund($refundReference);
    }

    protected function getDriver(): Contracts\DriverInterface
    {
        $providerName = $this->getProviderName();

        return $this->manager->driver($providerName);
    }

    protected function getProviderName(): string
    {
        if (! empty($this->providers)) {
            return $this->providers[0];
        }

        return $this->manager->getDefaultDriver();
    }
}
