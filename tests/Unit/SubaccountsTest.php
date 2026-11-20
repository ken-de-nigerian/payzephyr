<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Contracts\SupportsSubaccountsInterface;
use KenDeNigerian\PayZephyr\DataObjects\BankDTO;
use KenDeNigerian\PayZephyr\DataObjects\ResolvedAccountDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubaccountDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubaccountRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\SubaccountException;
use KenDeNigerian\PayZephyr\Payment;
use KenDeNigerian\PayZephyr\Subaccounts;
use KenDeNigerian\PayZephyr\Tests\Fixtures\SplitRecordingDriver;
use KenDeNigerian\PayZephyr\Tests\Fixtures\SplittingRecordingDriver;

/**
 * A driver that holds subaccounts, recording what it was asked.
 */
final class SubaccountRecordingDriver extends SplitRecordingDriver implements SupportsSubaccountsInterface
{
    /** @var list<array{0: string, 1: mixed}> */
    public array $calls = [];

    public function listBanks(string $currency): array
    {
        $this->calls[] = ['banks', $currency];

        return [new BankDTO('Access Bank', '044', $currency)];
    }

    public function resolveAccount(string $accountNumber, string $bankCode): ResolvedAccountDTO
    {
        $this->calls[] = ['resolve', [$accountNumber, $bankCode]];

        return new ResolvedAccountDTO($accountNumber, 'ADA OBI', $bankCode);
    }

    public function createSubaccount(SubaccountRequestDTO $request): SubaccountDTO
    {
        $this->calls[] = ['create', $request];

        return new SubaccountDTO('ACCT_new', $request->businessName, $request->accountNumber);
    }

    public function updateSubaccount(string $code, SubaccountRequestDTO $request): SubaccountDTO
    {
        $this->calls[] = ['update', [$code, $request]];

        return new SubaccountDTO($code, $request->businessName, $request->accountNumber);
    }

    public function fetchSubaccount(string $code): SubaccountDTO
    {
        $this->calls[] = ['fetch', $code];

        return new SubaccountDTO($code, 'Demo Store', '0123456789');
    }
}

$fields = ['business_name' => 'Demo Store', 'bank_code' => '044', 'account_number' => '0123456789'];

test('Payment::subaccounts() reaches the default provider', function () use ($fields): void {
    $driver = new SubaccountRecordingDriver('holder');
    $subaccounts = (new Payment(SplittingRecordingDriver::manager(['holder' => $driver], 'holder')))->subaccounts();

    expect($subaccounts->banks('ngn')[0]->code)->toBe('044')
        ->and($subaccounts->resolveAccount('0123456789', '044')->accountName)->toBe('ADA OBI')
        ->and($subaccounts->create($fields)->code)->toBe('ACCT_new')
        ->and($subaccounts->create(new SubaccountRequestDTO('Typed', '044', '0123456789'))->businessName)->toBe('Typed')
        ->and($subaccounts->update('ACCT_1', new SubaccountRequestDTO('Renamed', '044', '0123456789'))->businessName)->toBe('Renamed')
        ->and($subaccounts->find('ACCT_1')->code)->toBe('ACCT_1')
        ->and(array_column($driver->calls, 0))->toBe(['banks', 'resolve', 'create', 'create', 'update', 'fetch'])
        ->and($driver->calls[0][1])->toBe('NGN');
});

test('with() and using() choose the provider', function () use ($fields): void {
    $default = new SubaccountRecordingDriver('default');
    $other = new SubaccountRecordingDriver('other');
    $manager = SplittingRecordingDriver::manager(['default' => $default, 'other' => $other], 'default');

    (new Subaccounts($manager))->with('other')->banks();
    (new Subaccounts($manager))->using('other')->update('ACCT_1', $fields);

    expect($default->calls)->toBe([])
        ->and(array_column($other->calls, 0))->toBe(['banks', 'update'])
        ->and($other->calls[0][1])->toBe('NGN')
        ->and($other->calls[1][1][1])->toBeInstanceOf(SubaccountRequestDTO::class);
});

test('a provider that cannot hold subaccounts is refused', function (): void {
    $manager = SplittingRecordingDriver::manager(['plain' => new SplitRecordingDriver('plain')], 'plain');

    expect(fn (): array => (new Subaccounts($manager))->banks())->toThrow(SubaccountException::class, 'Provider [plain] does not support subaccounts');
});

describe('a subaccount request', function (): void {
    test('rejects what cannot be a subaccount', function (array $fields, string $message): void {
        expect(fn (): SubaccountRequestDTO => SubaccountRequestDTO::fromArray([...['business_name' => 'Demo Store', 'bank_code' => '044', 'account_number' => '0123456789'], ...$fields]))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'no business name' => [['business_name' => ' '], 'Business name is required'],
        'no bank code' => [['bank_code' => ''], 'Bank code is required'],
        'a blank bank code' => [['bank_code' => '  '], 'Bank code is required'],
        'letters in the account number' => [['account_number' => '01234abcde'], 'Account number must be 6 to 20 digits'],
        'too short an account number' => [['account_number' => '12345'], 'Account number must be 6 to 20 digits'],
        'too long an account number' => [['account_number' => str_repeat('1', 21)], 'Account number must be 6 to 20 digits'],
        'negative percentage' => [['percentage_charge' => -1], 'Percentage charge must be between 0 and 100'],
        'percentage above 100' => [['percentage_charge' => 100.5], 'Percentage charge must be between 0 and 100'],
        'bad contact email' => [['contact_email' => 'not-an-email'], 'Invalid contact email address'],
    ]);

    test('accepts the edges and reads every field', function (): void {
        $request = SubaccountRequestDTO::fromArray([
            'business_name' => 'Demo Store',
            'bank_code' => '044',
            'account_number' => '123456',
            'percentage_charge' => 100,
            'description' => 'Payouts',
            'contact_email' => 'owner@demo.test',
            'contact_name' => 'Ada',
            'contact_phone' => '+2348012345678',
            'metadata' => ['store' => 7],
        ]);

        expect($request->percentageCharge)->toBe(100.0)
            ->and($request->description)->toBe('Payouts')
            ->and($request->contactEmail)->toBe('owner@demo.test')
            ->and($request->contactName)->toBe('Ada')
            ->and($request->contactPhone)->toBe('+2348012345678')
            ->and($request->metadata)->toBe(['store' => 7])
            ->and(SubaccountRequestDTO::fromArray(['business_name' => 'A', 'bank_code' => '1', 'account_number' => str_repeat('9', 20), 'percentage_charge' => 0])->percentageCharge)->toBe(0.0);
    });

    test('a missing business name, bank code or account number is refused', function (string $missing, string $message): void {
        $fields = ['business_name' => 'Demo Store', 'bank_code' => '044', 'account_number' => '0123456789'];
        unset($fields[$missing]);

        expect(fn (): SubaccountRequestDTO => SubaccountRequestDTO::fromArray($fields))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        ['business_name', 'Business name is required'],
        ['bank_code', 'Bank code is required'],
        ['account_number', 'Account number is required'],
    ]);

    test('built directly, the percentage defaults to zero', function (): void {
        expect((new SubaccountRequestDTO('A', '1', '123456'))->percentageCharge)->toBe(0.0);
    });

    test('defaults the percentage to zero and the metadata to empty', function (): void {
        $request = SubaccountRequestDTO::fromArray(['business_name' => 'A', 'bank_code' => '1', 'account_number' => '123456']);

        expect($request->percentageCharge)->toBe(0.0)->and($request->metadata)->toBe([])->and($request->contactEmail)->toBeNull();
    });
});
