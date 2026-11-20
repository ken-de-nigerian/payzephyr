<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\DataObjects\ResolvedAccountDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubaccountDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubaccountRequestDTO;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Exceptions\SubaccountException;

/**
 * A Paystack driver answering with `$responses`, its requests recorded in `$history`.
 *
 * @param  array<int, mixed>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function paystackWith(array $responses, array &$history = []): PaystackDriver
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $driver = new PaystackDriver(['secret_key' => 'sk_test_xxx', 'base_url' => 'https://api.paystack.co', 'currencies' => ['NGN']]);
    $driver->setClient(new Client(['handler' => $stack]));

    return $driver;
}

function paystackJson(array $body, int $status = 200): Response
{
    return new Response($status, [], (string) json_encode($body));
}

function networkDown(): ConnectException
{
    return new ConnectException('Connection refused', new Request('GET', 'https://api.paystack.co'));
}

function subaccountRequest(array $overrides = []): SubaccountRequestDTO
{
    return SubaccountRequestDTO::fromArray([
        'business_name' => 'Demo Store',
        'bank_code' => '058',
        'account_number' => '0123456789',
        'percentage_charge' => 10,
        ...$overrides,
    ]);
}

/** @return array<string, mixed> */
function subaccountData(array $overrides = []): array
{
    return [
        'subaccount_code' => 'ACCT_abc',
        'business_name' => 'Demo Store',
        'account_number' => '0123456789',
        'settlement_bank' => 'Guaranty Trust Bank',
        'account_name' => 'DEMO STORE LTD',
        'percentage_charge' => 10,
        'active' => true,
        ...$overrides,
    ];
}

describe('banks', function (): void {
    test('reads every page of active banks for the currency', function (): void {
        $history = [];
        $driver = paystackWith([
            paystackJson(['status' => true, 'data' => [
                ['name' => 'Access Bank', 'code' => '044', 'currency' => 'NGN', 'active' => true],
                ['name' => 'Closed Bank', 'code' => '999', 'active' => false],
                ['name' => 'No Code Bank'],
            ], 'meta' => ['next' => 'cursor_2']]),
            paystackJson(['status' => true, 'data' => [['name' => 'Guaranty Trust Bank', 'code' => '058']], 'meta' => ['next' => null]]),
        ], $history);

        $banks = $driver->listBanks('NGN');

        expect(array_map(fn ($bank): array => $bank->toArray(), $banks))->toBe([
            ['name' => 'Access Bank', 'code' => '044', 'currency' => 'NGN'],
            ['name' => 'Guaranty Trust Bank', 'code' => '058', 'currency' => 'NGN'],
        ])
            ->and($history)->toHaveCount(2)
            ->and($history[0]['request']->getUri()->getQuery())->toBe('currency=NGN&perPage=100&use_cursor=true')
            ->and($history[1]['request']->getUri()->getQuery())->toContain('next=cursor_2');
    });

    test('stops after the page limit even if Paystack keeps offering more', function (): void {
        $history = [];
        $page = paystackJson(['status' => true, 'data' => [['name' => 'A', 'code' => '1']], 'meta' => ['next' => 'again']]);
        $driver = paystackWith(array_fill(0, 25, $page), $history);

        expect($driver->listBanks('NGN'))->toHaveCount(20)
            ->and($history)->toHaveCount(20);
    });

    test('an empty cursor ends the list', function (): void {
        $history = [];
        $driver = paystackWith([paystackJson(['status' => true, 'data' => [], 'meta' => ['next' => '']]), paystackJson(['status' => true, 'data' => []])], $history);

        expect($driver->listBanks('NGN'))->toBe([])->and($history)->toHaveCount(1);
    });

    test('Paystack refusing, or the network failing, is a SubaccountException', function (): void {
        expect(fn (): array => paystackWith([paystackJson(['status' => false, 'message' => 'Invalid currency'])])->listBanks('XYZ'))
            ->toThrow(SubaccountException::class, 'Invalid currency')
            ->and(fn (): array => paystackWith([paystackJson(['status' => false])])->listBanks('NGN'))
            ->toThrow(SubaccountException::class, 'Failed to list Paystack banks')
            ->and(fn (): array => paystackWith([networkDown()])->listBanks('NGN'))
            ->toThrow(SubaccountException::class, 'Failed to list banks: Unable to connect to payment provider');
    });
});

describe('resolving an account', function (): void {
    test('returns the name the account is registered to', function (): void {
        $history = [];
        $driver = paystackWith([paystackJson(['status' => true, 'data' => ['account_number' => '0123456789', 'account_name' => 'ADA OBI']])], $history);

        $account = $driver->resolveAccount('0123456789', '058');

        expect($account->toArray())->toBe(['account_number' => '0123456789', 'account_name' => 'ADA OBI', 'bank_code' => '058'])
            ->and($history[0]['request']->getUri()->getPath())->toBe('/bank/resolve')
            ->and($history[0]['request']->getUri()->getQuery())->toBe('account_number=0123456789&bank_code=058');
    });

    test('an unknown account, a missing name or a network failure is a SubaccountException', function (): void {
        expect(fn (): ResolvedAccountDTO => paystackWith([paystackJson(['status' => false, 'message' => 'Could not resolve account name'], 422)])->resolveAccount('1', '058'))
            ->toThrow(SubaccountException::class)
            ->and(fn (): ResolvedAccountDTO => paystackWith([paystackJson(['status' => false])])->resolveAccount('1', '058'))
            ->toThrow(SubaccountException::class, 'Could not resolve the account number')
            ->and(fn (): ResolvedAccountDTO => paystackWith([paystackJson(['status' => true, 'data' => []])])->resolveAccount('1', '058'))
            ->toThrow(SubaccountException::class)
            ->and(fn (): ResolvedAccountDTO => paystackWith([networkDown()])->resolveAccount('1', '058'))
            ->toThrow(SubaccountException::class, 'Failed to resolve account: Unable to connect to payment provider');
    });
});

describe('subaccounts', function (): void {
    test('creates one, sending the bank code, account and default percentage', function (): void {
        $history = [];
        $driver = paystackWith([paystackJson(['status' => true, 'data' => subaccountData()])], $history);

        $subaccount = $driver->createSubaccount(subaccountRequest(['description' => 'Vendor payouts', 'contact_email' => 'owner@demo.test', 'contact_name' => 'Ada', 'contact_phone' => '+2348012345678', 'metadata' => ['store' => 7]]));

        expect($subaccount->toArray())->toBe([
            'code' => 'ACCT_abc',
            'business_name' => 'Demo Store',
            'account_number' => '0123456789',
            'bank_name' => 'Guaranty Trust Bank',
            'account_name' => 'DEMO STORE LTD',
            'percentage_charge' => 10.0,
            'active' => true,
            'provider' => 'paystack',
        ])
            ->and($history[0]['request']->getMethod())->toBe('POST')
            ->and(json_decode((string) $history[0]['request']->getBody(), true))->toBe([
                'business_name' => 'Demo Store',
                'settlement_bank' => '058',
                'account_number' => '0123456789',
                'percentage_charge' => 10,
                'description' => 'Vendor payouts',
                'primary_contact_email' => 'owner@demo.test',
                'primary_contact_name' => 'Ada',
                'primary_contact_phone' => '+2348012345678',
                'metadata' => '{"store":7}',
            ]);
    });

    test('leaves out what isn\'t set', function (): void {
        $history = [];
        paystackWith([paystackJson(['status' => true, 'data' => subaccountData()])], $history)->createSubaccount(subaccountRequest());

        expect(json_decode((string) $history[0]['request']->getBody(), true))->toBe([
            'business_name' => 'Demo Store',
            'settlement_bank' => '058',
            'account_number' => '0123456789',
            'percentage_charge' => 10,
        ]);
    });

    test('reads sensible defaults from a sparse answer', function (): void {
        $subaccount = paystackWith([paystackJson(['status' => true, 'data' => ['subaccount_code' => 'ACCT_x']])])->createSubaccount(subaccountRequest());

        expect($subaccount->businessName)->toBe('')
            ->and($subaccount->accountNumber)->toBe('')
            ->and($subaccount->bankName)->toBeNull()
            ->and($subaccount->accountName)->toBeNull()
            ->and($subaccount->percentageCharge)->toBe(0.0)
            ->and($subaccount->active)->toBeTrue();
    });

    test('updates one by its code', function (): void {
        $history = [];
        $driver = paystackWith([paystackJson(['status' => true, 'data' => subaccountData(['percentage_charge' => 5, 'active' => false])])], $history);

        $subaccount = $driver->updateSubaccount('ACCT/abc', subaccountRequest(['percentage_charge' => 5]));

        expect($subaccount->percentageCharge)->toBe(5.0)
            ->and($subaccount->active)->toBeFalse()
            ->and($history[0]['request']->getMethod())->toBe('PUT')
            ->and($history[0]['request']->getUri()->getPath())->toBe('/subaccount/ACCT%2Fabc');
    });

    test('fetches one by its code', function (): void {
        $history = [];
        $subaccount = paystackWith([paystackJson(['status' => true, 'data' => subaccountData()])], $history)->fetchSubaccount('ACCT_abc');

        expect($subaccount->code)->toBe('ACCT_abc')
            ->and($history[0]['request']->getMethod())->toBe('GET')
            ->and($history[0]['request']->getUri()->getPath())->toBe('/subaccount/ACCT_abc');
    });

    test('Paystack refusing, a missing code or the network failing is a SubaccountException', function (): void {
        expect(fn (): SubaccountDTO => paystackWith([paystackJson(['status' => false, 'message' => 'Account number is invalid'])])->createSubaccount(subaccountRequest()))
            ->toThrow(SubaccountException::class, 'Account number is invalid')
            ->and(fn (): SubaccountDTO => paystackWith([paystackJson(['status' => false])])->updateSubaccount('ACCT_abc', subaccountRequest()))
            ->toThrow(SubaccountException::class, 'Failed to update Paystack subaccount')
            ->and(fn (): SubaccountDTO => paystackWith([paystackJson(['status' => true, 'data' => []])])->createSubaccount(subaccountRequest()))
            ->toThrow(SubaccountException::class)
            ->and(fn (): SubaccountDTO => paystackWith([networkDown()])->createSubaccount(subaccountRequest()))
            ->toThrow(SubaccountException::class, 'Failed to create subaccount: Unable to connect to payment provider')
            ->and(fn (): SubaccountDTO => paystackWith([paystackJson(['status' => false])])->fetchSubaccount('ACCT_abc'))
            ->toThrow(SubaccountException::class, 'Failed to fetch Paystack subaccount')
            ->and(fn (): SubaccountDTO => paystackWith([networkDown()])->fetchSubaccount('ACCT_abc'))
            ->toThrow(SubaccountException::class, 'Failed to fetch subaccount: Unable to connect to payment provider');
    });
});

describe('failures', function (): void {
    /**
     * Each operation, failing on the network: the message it logs, what it logs with it, and
     * the exception it throws.
     */
    test('a network failure is logged, and wrapped with the cause kept', function (string $operation, string $logged, array $context, string $thrown): void {
        $channel = Mockery::spy();
        Log::shouldReceive('channel')->with('payments')->andReturn($channel);
        $driver = paystackWith([networkDown()]);

        try {
            match ($operation) {
                'banks' => $driver->listBanks('NGN'),
                'resolve' => $driver->resolveAccount('0123456789', '058'),
                'create' => $driver->createSubaccount(subaccountRequest()),
                'update' => $driver->updateSubaccount('ACCT_abc', subaccountRequest()),
                'fetch' => $driver->fetchSubaccount('ACCT_abc'),
            };
            $this->fail('The operation should have failed.');
        } catch (SubaccountException $e) {
            expect($e->getMessage())->toStartWith($thrown)
                ->and($e->getCode())->toBe(0)
                ->and($e->getPrevious())->not->toBeNull();
        }

        $channel->shouldHaveReceived('error')->with("[paystack] $logged", Mockery::on(
            fn (array $sent): bool => array_diff_key($sent, ['error' => true]) === $context && is_string($sent['error']),
        ));
    })->with([
        'banks' => ['banks', 'Failed to list banks', [], 'Failed to list banks: '],
        'resolve' => ['resolve', 'Failed to resolve account', ['bank_code' => '058'], 'Failed to resolve account: '],
        'create' => ['create', 'Failed to create subaccount', [], 'Failed to create subaccount: '],
        'update' => ['update', 'Failed to update subaccount', [], 'Failed to update subaccount: '],
        'fetch' => ['fetch', 'Failed to fetch subaccount', ['subaccount' => 'ACCT_abc'], 'Failed to fetch subaccount: '],
    ]);

    test('creating and updating a subaccount are logged', function (): void {
        $channel = Mockery::spy();
        Log::shouldReceive('channel')->with('payments')->andReturn($channel);
        $answer = paystackJson(['status' => true, 'data' => subaccountData()]);
        $driver = paystackWith([$answer, clone $answer]);

        $driver->createSubaccount(subaccountRequest());
        $driver->updateSubaccount('ACCT_abc', subaccountRequest());

        $channel->shouldHaveReceived('info')->with('[paystack] Subaccount created', ['subaccount' => 'ACCT_abc'])->once();
        $channel->shouldHaveReceived('info')->with('[paystack] Subaccount updated', ['subaccount' => 'ACCT_abc'])->once();
    });

    test('an answer without a status is a refusal', function (): void {
        expect(fn (): SubaccountDTO => paystackWith([paystackJson(['data' => subaccountData()])])->fetchSubaccount('ACCT_abc'))
            ->toThrow(SubaccountException::class, 'Failed to fetch Paystack subaccount');
    });
});
