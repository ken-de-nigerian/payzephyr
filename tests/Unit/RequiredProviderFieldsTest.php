<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;

/**
 * The guards that stop a provider's silence becoming a number.
 *
 * Every driver used to map provider responses with `?? 0` and `?? 'USD'`
 * style defaults. On any installation that does not promote warnings to
 * exceptions, a response missing its amount produced `(float) null` - zero -
 * and PayZephyr reported a *verified payment worth nothing*, or a refund of
 * nothing, as fact. These helpers are why that can no longer happen, so the
 * failing branch of each one is the thing worth testing.
 */
function requiredFieldsDriver(): object
{
    return new class(['currencies' => ['NGN']]) extends AbstractDriver
    {
        protected string $name = 'fieldtest';

        protected function validateConfig(): void {}

        protected function getDefaultHeaders(): array
        {
            return [];
        }

        public function charge(ChargeRequestDTO $request): ChargeResponseDTO
        {
            return new ChargeResponseDTO('', '', '', 'pending');
        }

        public function verify(string $reference): VerificationResponseDTO
        {
            return new VerificationResponseDTO('', 'pending', 0, '');
        }

        public function validateWebhook(array $headers, string $body): bool
        {
            return true;
        }

        public function healthCheck(): bool
        {
            return true;
        }

        public function getSupportedCurrencies(): array
        {
            return ['NGN'];
        }

        /** @param array<string, mixed> $data */
        public function field(array $data, string $key): mixed
        {
            return $this->requireField($data, $key, 'verify');
        }

        /** @param array<string, mixed> $data */
        public function str(array $data, string $key): string
        {
            return $this->requireString($data, $key, 'verify');
        }

        /** @param array<string, mixed> $data */
        public function money(array $data, string $key): float
        {
            return $this->requireAmount($data, $key, 'verify');
        }

        public function moneyValue(mixed $value): float
        {
            return $this->requireAmountValue($value, 'amount', 'verify');
        }

        /**
         * @param  array<string, mixed>  $data
         * @return array<string, mixed>
         */
        public function arr(array $data, string $key): array
        {
            return $this->requireArray($data, $key, 'verify');
        }
    };
}

// ---------------------------------------------------------------------------
// Present values pass straight through
// ---------------------------------------------------------------------------

test('a present field is returned unchanged', function () {
    expect(requiredFieldsDriver()->field(['reference' => 'PZ_1'], 'reference'))->toBe('PZ_1');
});

test('a numeric string is accepted as a string, because providers send both', function () {
    expect(requiredFieldsDriver()->str(['currency' => 'NGN'], 'currency'))->toBe('NGN')
        ->and(requiredFieldsDriver()->str(['code' => 200], 'code'))->toBe('200');
});

test('an amount is returned as a float whether it arrived as one or not', function () {
    expect(requiredFieldsDriver()->money(['amount' => 20000], 'amount'))->toBe(20000.0)
        ->and(requiredFieldsDriver()->money(['amount' => '199.99'], 'amount'))->toBe(199.99);
});

test('a genuine zero amount is still allowed through', function () {
    // The guard is against *absence*, not against zero. A provider that
    // reports a zero-value authorization is telling us something real.
    expect(requiredFieldsDriver()->money(['amount' => 0], 'amount'))->toBe(0.0);
});

test('a nested array is returned for mapping', function () {
    expect(requiredFieldsDriver()->arr(['amount' => ['total' => 1]], 'amount'))->toBe(['total' => 1]);
});

// ---------------------------------------------------------------------------
// Absence is refused, loudly and by name
// ---------------------------------------------------------------------------

test('a missing field names the provider, the field and the operation', function () {
    expect(fn () => requiredFieldsDriver()->field([], 'reference'))
        ->toThrow(ChargeException::class, 'omitted the required field [reference]');
});

test('a null field is treated as missing', function () {
    // Providers send explicit nulls as readily as they omit keys, and the
    // consequence is identical.
    expect(fn () => requiredFieldsDriver()->field(['reference' => null], 'reference'))
        ->toThrow(ChargeException::class, 'omitted the required field [reference]');
});

test('a missing field warns that the request may still have been accepted', function () {
    // The conservative reading. The provider call already happened, so the
    // caller must not assume nothing occurred just because mapping failed.
    expect(fn () => requiredFieldsDriver()->field([], 'reference'))
        ->toThrow(ChargeException::class, 'verify before retrying');
});

test('the failure carries machine-readable context', function () {
    try {
        requiredFieldsDriver()->field([], 'reference');
    } catch (ChargeException $e) {
        expect($e->getContext())->toBe([
            'provider' => 'fieldtest',
            'missing_field' => 'reference',
            'operation' => 'verify',
        ]);

        return;
    }

    $this->fail('Expected a ChargeException.');
});

test('a non-string value where a string is required is refused', function () {
    expect(fn () => requiredFieldsDriver()->str(['currency' => ['NGN']], 'currency'))
        ->toThrow(ChargeException::class, 'returned a non-string value for [currency]');
});

test('a missing amount is refused rather than defaulted to zero', function () {
    // The single most important assertion in this file.
    expect(fn () => requiredFieldsDriver()->money([], 'amount'))
        ->toThrow(ChargeException::class, 'omitted the required field [amount]');
});

test('a non-numeric amount is refused', function () {
    expect(fn () => requiredFieldsDriver()->money(['amount' => 'not money'], 'amount'))
        ->toThrow(ChargeException::class, 'returned a non-numeric amount for [amount]');
});

test('an absent amount value is refused, for providers that return objects', function () {
    // The Stripe path: the value is read off an SDK object before it can be
    // checked, so the guard has to accept the value rather than the array.
    expect(fn () => requiredFieldsDriver()->moneyValue(null))
        ->toThrow(ChargeException::class, 'omitted the amount [amount]');
});

test('an absent amount value explains why zero is not an acceptable substitute', function () {
    expect(fn () => requiredFieldsDriver()->moneyValue(null))
        ->toThrow(ChargeException::class, 'Reporting this as a zero-value payment would be worse than failing');
});

test('a non-numeric amount value is refused', function () {
    expect(fn () => requiredFieldsDriver()->moneyValue('not money'))
        ->toThrow(ChargeException::class, 'returned a non-numeric amount for [amount]');
});

test('a scalar where a nested block is required is refused', function () {
    // OPay returns `amount` as {total, currency}. A fixture that passed it as
    // a scalar produced an amount of 0.0 and a test that still passed, because
    // nothing asserted the amount.
    expect(fn () => requiredFieldsDriver()->arr(['amount' => 20000], 'amount'))
        ->toThrow(ChargeException::class, 'returned a non-array value for [amount]');
});

test('a missing nested block is refused', function () {
    expect(fn () => requiredFieldsDriver()->arr([], 'amount_money'))
        ->toThrow(ChargeException::class, 'omitted the required field [amount_money]');
});
