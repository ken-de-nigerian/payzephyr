<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr;

use KenDeNigerian\PayZephyr\Contracts\SupportsSubaccountsInterface;
use KenDeNigerian\PayZephyr\DataObjects\BankDTO;
use KenDeNigerian\PayZephyr\DataObjects\ResolvedAccountDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubaccountDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubaccountRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\DriverNotFoundException;
use KenDeNigerian\PayZephyr\Exceptions\SubaccountException;

/**
 * Subaccounts and bank lookups, through Payment::subaccounts():
 *
 *     $banks = Payment::subaccounts()->with('paystack')->banks('NGN');
 *     $account = Payment::subaccounts()->resolveAccount('0123456789', '058');
 *     $subaccount = Payment::subaccounts()->create(new SubaccountRequestDTO(...));
 *     Payment::amount(15000)->split($subaccount->code, platformFee: 750)->...
 *
 * Uses the default provider unless with()/using() names one; a provider that
 * cannot hold subaccounts is refused with a SubaccountException.
 */
final class Subaccounts
{
    private ?string $provider = null;

    public function __construct(private readonly PaymentManager $manager) {}

    public function with(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * Alias for with()
     */
    public function using(string $provider): self
    {
        return $this->with($provider);
    }

    /**
     * @return list<BankDTO>
     *
     * @throws SubaccountException|DriverNotFoundException
     */
    public function banks(string $currency = 'NGN'): array
    {
        return $this->driver()->listBanks(strtoupper($currency));
    }

    /**
     * @throws SubaccountException|DriverNotFoundException
     */
    public function resolveAccount(string $accountNumber, string $bankCode): ResolvedAccountDTO
    {
        return $this->driver()->resolveAccount($accountNumber, $bankCode);
    }

    /**
     * @param  SubaccountRequestDTO|array<array-key, mixed>  $request
     *
     * @throws SubaccountException|DriverNotFoundException
     */
    public function create(SubaccountRequestDTO|array $request): SubaccountDTO
    {
        return $this->driver()->createSubaccount($request instanceof SubaccountRequestDTO ? $request : SubaccountRequestDTO::fromArray($request));
    }

    /**
     * @param  SubaccountRequestDTO|array<array-key, mixed>  $request
     *
     * @throws SubaccountException|DriverNotFoundException
     */
    public function update(string $code, SubaccountRequestDTO|array $request): SubaccountDTO
    {
        return $this->driver()->updateSubaccount($code, $request instanceof SubaccountRequestDTO ? $request : SubaccountRequestDTO::fromArray($request));
    }

    /**
     * @throws SubaccountException|DriverNotFoundException
     */
    public function find(string $code): SubaccountDTO
    {
        return $this->driver()->fetchSubaccount($code);
    }

    /**
     * @throws SubaccountException|DriverNotFoundException
     */
    private function driver(): SupportsSubaccountsInterface
    {
        $driver = $this->manager->driver($this->provider);

        if (! $driver instanceof SupportsSubaccountsInterface) {
            throw new SubaccountException("Provider [{$driver->getName()}] does not support subaccounts");
        }

        return $driver;
    }
}
