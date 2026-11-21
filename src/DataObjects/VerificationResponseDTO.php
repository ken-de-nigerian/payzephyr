<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use KenDeNigerian\PayZephyr\Support\Payload;
use KenDeNigerian\PayZephyr\Traits\NormalizesMetadata;
use Throwable;

final readonly class VerificationResponseDTO
{
    use NormalizesMetadata;

    /**
     * `amount` is what the customer was charged. Where the provider reports them, `requestedAmount`
     * is what the merchant asked for and `fees` the provider's fee (both major units): they differ
     * from `amount` when the account passes the fee on to the customer (Paystack's "charge the
     * customer"), so check what was asked for against `requestedAmount`. Null when not reported.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<array-key, mixed>|null  $customer
     */
    public function __construct(
        public string $reference,
        public string $status,
        public float $amount,
        public string $currency,
        public ?string $paidAt = null,
        public array $metadata = [],
        public ?string $provider = null,
        public ?string $channel = null,
        public ?string $cardType = null,
        public ?string $bank = null,
        public ?array $customer = null,
        public ?string $authorizationCode = null,
        public ?float $requestedAmount = null,
        public ?float $fees = null,
    ) {}

    /**
     * What the merchant asked for: the provider's requested amount where it reports one, else the
     * amount charged.
     */
    public function amountRequested(): float
    {
        return $this->requestedAmount ?? $this->amount;
    }

    protected function getNormalizedStatus(): string
    {
        try {
            if (function_exists('app')) {
                $normalizer = app(StatusNormalizer::class);

                return $normalizer->normalize($this->status, $this->provider);
            }
        } catch (Throwable) {
        }

        return StatusNormalizer::normalizeStatic($this->status);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): VerificationResponseDTO
    {
        $input = new Payload($data);

        return new self(
            reference: $input->string('reference') ?? '',
            status: $input->string('status') ?? 'unknown',
            amount: $input->float('amount') ?? 0.0,
            currency: strtoupper($input->string('currency') ?? ''),
            paidAt: $input->string('paid_at'),
            metadata: self::normalizeMetadata($input->get('metadata')),
            provider: $input->string('provider'),
            channel: $input->string('channel'),
            cardType: $input->string('card_type'),
            bank: $input->string('bank'),
            customer: $input->arrayOrNull('customer'),
            authorizationCode: $input->string('authorization_code'),
            requestedAmount: $input->float('requested_amount'),
            fees: $input->float('fees'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paid_at' => $this->paidAt,
            'metadata' => $this->metadata,
            'provider' => $this->provider,
            'channel' => $this->channel,
            'card_type' => $this->cardType,
            'bank' => $this->bank,
            'customer' => $this->customer,
            'authorization_code' => $this->authorizationCode,
            'requested_amount' => $this->requestedAmount,
            'fees' => $this->fees,
        ];
    }

    public function isSuccessful(): bool
    {
        $normalizedStatus = $this->getNormalizedStatus();
        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isSuccessful() ?? false;
    }

    public function isFailed(): bool
    {
        $normalizedStatus = $this->getNormalizedStatus();
        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isFailed() ?? false;
    }

    public function isPending(): bool
    {
        $normalizedStatus = $this->getNormalizedStatus();
        $status = PaymentStatus::tryFromString($normalizedStatus);

        return $status?->isPending() ?? false;
    }
}
