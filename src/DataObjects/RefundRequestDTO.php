<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\DataObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;
use KenDeNigerian\PayZephyr\Constants\PaymentConstants;
use KenDeNigerian\PayZephyr\Support\Payload;
use KenDeNigerian\PayZephyr\Traits\NormalizesMetadata;

final readonly class RefundRequestDTO
{
    use NormalizesMetadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $transactionReference,
        public ?float $amount = null,
        public ?string $currency = null,
        public ?string $reason = null,
        public array $metadata = [],
        public ?string $idempotencyKey = null,
    ) {
        $this->validate();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validate(): void
    {
        if ($this->transactionReference === '') {
            throw new InvalidArgumentException('Transaction reference is required');
        }

        if ($this->amount !== null && $this->amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero');
        }

        if ($this->amount !== null && $this->amount > 999999999.99) {
            throw new InvalidArgumentException('Refund amount exceeds maximum allowed value');
        }

        if ($this->currency !== null && (strlen($this->currency) !== 3 || ! ctype_alpha($this->currency))) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO code');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $input = new Payload($data);
        $idempotencyKey = self::generateIdempotencyKey();

        if ($input->has('idempotency_key')) {
            // Anything that is not a string or a number cannot be a key.
            $key = $input->string('idempotency_key') ?? '';
            if (strlen($key) > PaymentConstants::MAX_REFERENCE_LENGTH || ! preg_match('/^[a-zA-Z0-9_-]+$/', $key)) {
                throw new InvalidArgumentException('Invalid idempotency key format. Must be alphanumeric with dashes/underscores and max '.PaymentConstants::MAX_REFERENCE_LENGTH.' characters.');
            }
            $idempotencyKey = $key;
        }

        $amount = $input->float('amount');
        $currency = $input->string('currency');

        return new self(
            transactionReference: $input->string('transaction_reference') ?? '',
            amount: $amount === null ? null : round($amount, 2),
            currency: $currency === null ? null : strtoupper($currency),
            reason: $input->string('reason'),
            metadata: self::normalizeMetadata($data['metadata'] ?? null),
            idempotencyKey: $idempotencyKey,
        );
    }

    protected static function generateIdempotencyKey(): string
    {
        return Str::uuid()->toString();
    }

    public function getAmountInMinorUnits(): ?int
    {
        return $this->amount === null ? null : (int) round($this->amount * 100);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transaction_reference' => $this->transactionReference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
        ];
    }
}
