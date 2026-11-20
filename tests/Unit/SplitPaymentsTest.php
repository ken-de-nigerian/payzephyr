<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Exceptions\ProviderException;
use KenDeNigerian\PayZephyr\Payment;
use KenDeNigerian\PayZephyr\Tests\Fixtures\SplitRecordingDriver;
use KenDeNigerian\PayZephyr\Tests\Fixtures\SplittingRecordingDriver;

/*
 * Split payments: a charge that settles to a seller's subaccount while the
 * platform keeps a fee. The one property that matters most is pinned here: a
 * split charge never reaches a provider that cannot split it, so a seller's
 * money cannot silently settle into the platform's own account.
 */
/**
 * A Paystack driver whose requests are recorded.
 *
 * @param  array<int, array<string, mixed>>  $history
 */
function recordingPaystack(array &$history): PaystackDriver
{
    $mock = new MockHandler([new Response(200, [], (string) json_encode([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'x'],
    ]))]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $driver = new PaystackDriver(['secret_key' => 'sk_test_xxx', 'base_url' => 'https://api.paystack.co', 'currencies' => ['NGN']]);
    $driver->setClient(new Client(['handler' => $stack]));

    return $driver;
}

/**
 * The JSON body of the one request recorded.
 *
 * @param  array<int, array<string, mixed>>  $history
 * @return array<string, mixed>
 */
function sentBody(array $history): array
{
    /** @var array<string, mixed> */
    return json_decode((string) $history[0]['request']->getBody(), true);
}

function splitCharge(array $split, float $amount = 15000.0): ChargeRequestDTO
{
    return new ChargeRequestDTO(amount: $amount, currency: 'NGN', email: 'a@b.test', reference: 'REF_1', callbackUrl: 'https://example.test/cb', split: $split);
}

describe('building a split charge', function (): void {
    test('split() sends the subaccount, the platform fee and who bears the fees', function (): void {
        $driver = new SplittingRecordingDriver('splitter');
        $payment = new Payment(SplittingRecordingDriver::manager(['splitter' => $driver], 'splitter'));

        $payment->amount(15000)->email('a@b.test')->callback('https://example.test/cb')->split('ACCT_1', 750.0, 'platform')->charge();

        expect($driver->charged?->split)->toBe(['subaccount' => 'ACCT_1', 'platform_fee' => 750.0, 'bearer' => 'platform']);
    });

    test('split() without a fee leaves the fee to the subaccount, and the seller bears the fees by default', function (): void {
        $driver = new SplittingRecordingDriver('splitter');
        $payment = new Payment(SplittingRecordingDriver::manager(['splitter' => $driver], 'splitter'));

        $payment->amount(15000)->email('a@b.test')->callback('https://example.test/cb')->split('ACCT_1')->charge();

        expect($driver->charged?->split)->toBe(['subaccount' => 'ACCT_1', 'bearer' => 'subaccount']);
    });

    test('splitCode() sends a split the provider already holds', function (): void {
        $driver = new SplittingRecordingDriver('splitter');
        $payment = new Payment(SplittingRecordingDriver::manager(['splitter' => $driver], 'splitter'));

        $payment->amount(15000)->email('a@b.test')->callback('https://example.test/cb')->splitCode('SPL_9')->charge();

        expect($driver->charged?->split)->toBe(['split_code' => 'SPL_9']);
    });
});

describe('validating a split', function (): void {
    test('rejects what cannot be a split', function (array $split, string $message): void {
        expect(fn (): ChargeRequestDTO => splitCharge($split))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'empty subaccount' => [['subaccount' => ' '], 'Split subaccount must be a non-empty string'],
        'subaccount not a string' => [['subaccount' => 12], 'Split subaccount must be a non-empty string'],
        'empty split code' => [['split_code' => ''], 'Split code must be a non-empty string'],
        'split code not a string' => [['split_code' => ['SPL']], 'Split code must be a non-empty string'],
        'negative fee' => [['subaccount' => 'ACCT_1', 'platform_fee' => -1.0], 'Platform fee must be zero or more'],
        'fee not a number' => [['subaccount' => 'ACCT_1', 'platform_fee' => '10'], 'Platform fee must be zero or more'],
        'fee equal to the amount' => [['subaccount' => 'ACCT_1', 'platform_fee' => 15000.0], 'Platform fee must be less than the amount'],
        'fee above the amount' => [['subaccount' => 'ACCT_1', 'platform_fee' => 15001], 'Platform fee must be less than the amount'],
        'unknown bearer' => [['subaccount' => 'ACCT_1', 'bearer' => 'customer'], 'Split bearer must be one of: subaccount, platform'],
    ]);

    test('accepts a fee of zero, an integer fee and either bearer', function (): void {
        expect(splitCharge(['subaccount' => 'ACCT_1', 'platform_fee' => 0.0, 'bearer' => 'platform'])->hasSplit())->toBeTrue()
            ->and(splitCharge(['subaccount' => 'ACCT_1', 'platform_fee' => 100, 'bearer' => 'subaccount'])->hasSplit())->toBeTrue();
    });

    test('a provider\'s own split format passes through unchecked', function (): void {
        $native = ['type' => 'flat', 'bearer_type' => 'account', 'subaccounts' => [['subaccount' => 'ACCT_1', 'share' => 500]]];

        expect(splitCharge($native)->split)->toBe($native);
    });

    test('hasSplit() is false without a split or with an empty one', function (): void {
        expect((new ChargeRequestDTO(100.0, 'NGN', 'a@b.test'))->hasSplit())->toBeFalse()
            ->and((new ChargeRequestDTO(100.0, 'NGN', 'a@b.test', split: []))->hasSplit())->toBeFalse();
    });
});

describe('Paystack', function (): void {
    test('sends the subaccount, the fee in kobo and the main account as bearer', function (): void {
        $history = [];
        recordingPaystack($history)->charge(splitCharge(['subaccount' => 'ACCT_1', 'platform_fee' => 750.25, 'bearer' => 'platform']));

        expect(sentBody($history))->toMatchArray(['subaccount' => 'ACCT_1', 'transaction_charge' => 75025, 'bearer' => 'account', 'amount' => 1500000]);
    });

    test('the fee is rounded to the nearest kobo, as a whole number', function (float $fee, int $kobo): void {
        $history = [];
        recordingPaystack($history)->charge(splitCharge(['subaccount' => 'ACCT_1', 'platform_fee' => $fee]));

        expect(sentBody($history)['transaction_charge'])->toBe($kobo);
    })->with([
        // 19.99 * 100 is 1998.9999999999998 in floating point; 0.07 * 100 is 7.000000000000001.
        'just under a whole kobo' => [19.99, 1999],
        'just over a whole kobo' => [0.07, 7],
    ]);

    test('a fee of zero is still sent, so the subaccount\'s default percentage never applies', function (): void {
        $history = [];
        recordingPaystack($history)->charge(splitCharge(['subaccount' => 'ACCT_1', 'platform_fee' => 0.0]));

        expect(sentBody($history))->toHaveKey('transaction_charge', 0)
            ->and(sentBody($history)['bearer'])->toBe('subaccount');
    });

    test('without a fee, no transaction charge is sent', function (): void {
        $history = [];
        recordingPaystack($history)->charge(splitCharge(['subaccount' => 'ACCT_1']));

        expect(sentBody($history))->not->toHaveKey('transaction_charge')
            ->and(sentBody($history)['subaccount'])->toBe('ACCT_1');
    });

    test('sends a split code', function (): void {
        $history = [];
        recordingPaystack($history)->charge(splitCharge(['split_code' => 'SPL_9']));

        expect(sentBody($history))->toHaveKey('split_code', 'SPL_9')->not->toHaveKey('subaccount')->not->toHaveKey('split');
    });

    test('sends Paystack\'s own dynamic split as it is', function (): void {
        $native = ['type' => 'flat', 'bearer_type' => 'account', 'subaccounts' => [['subaccount' => 'ACCT_1', 'share' => 500]]];
        $history = [];
        recordingPaystack($history)->charge(splitCharge($native));

        expect(sentBody($history)['split'])->toBe($native);
    });

    test('a charge without a split sends nothing about one', function (): void {
        $history = [];
        recordingPaystack($history)->charge(new ChargeRequestDTO(100.0, 'NGN', 'a@b.test', 'REF_2', 'https://example.test/cb'));

        expect(sentBody($history))->not->toHaveKeys(['subaccount', 'split_code', 'split', 'transaction_charge', 'bearer']);
    });
});

describe('the fallback chain', function (): void {
    test('a split charge skips a provider that cannot split it and goes to one that can', function (): void {
        $plain = new SplitRecordingDriver('plain');
        $splitter = new SplittingRecordingDriver('splitter');
        $manager = SplittingRecordingDriver::manager(['plain' => $plain, 'splitter' => $splitter], 'plain', 'splitter');

        $response = $manager->chargeWithFallback(splitCharge(['subaccount' => 'ACCT_1', 'platform_fee' => 100.0]));

        expect($response->provider)->toBe('splitter')
            ->and($plain->charged)->toBeNull()
            ->and($splitter->charged?->hasSplit())->toBeTrue();
    });

    test('with no provider able to split, the charge fails and says why', function (): void {
        $plain = new SplitRecordingDriver('plain');
        $manager = SplittingRecordingDriver::manager(['plain' => $plain], 'plain');

        try {
            $manager->chargeWithFallback(splitCharge(['subaccount' => 'ACCT_1']));
            $this->fail('The split charge should have been refused.');
        } catch (ProviderException $e) {
            expect($e->getContext()['exceptions'])->toBe(['plain' => 'Provider [plain] does not support split payments'])
                ->and($plain->charged)->toBeNull();
        }
    });

    test('a charge without a split still goes to a provider that cannot split', function (): void {
        $plain = new SplitRecordingDriver('plain');
        $manager = SplittingRecordingDriver::manager(['plain' => $plain], 'plain');

        expect($manager->chargeWithFallback(new ChargeRequestDTO(100.0, 'NGN', 'a@b.test', null, 'https://example.test/cb'))->provider)->toBe('plain');
    });
});
