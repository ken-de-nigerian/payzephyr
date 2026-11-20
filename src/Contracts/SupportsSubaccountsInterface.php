<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

use KenDeNigerian\PayZephyr\DataObjects\BankDTO;
use KenDeNigerian\PayZephyr\DataObjects\ResolvedAccountDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubaccountDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubaccountRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\SubaccountException;

/**
 * Subaccounts: the settlement accounts a split charge pays out to (a seller's
 * bank account), plus the bank lookups needed to create one safely.
 *
 * Not all drivers support subaccounts - drivers implement this interface to
 * indicate support. Reach it through Payment::subaccounts().
 */
interface SupportsSubaccountsInterface
{
    /**
     * The banks accounts can be opened at, for a currency.
     *
     * @return list<BankDTO>
     *
     * @throws SubaccountException
     */
    public function listBanks(string $currency): array;

    /**
     * Look an account number up at a bank: the name it is registered to.
     * Use it to confirm an account before creating a subaccount for it.
     *
     * @throws SubaccountException
     */
    public function resolveAccount(string $accountNumber, string $bankCode): ResolvedAccountDTO;

    /**
     * @throws SubaccountException
     */
    public function createSubaccount(SubaccountRequestDTO $request): SubaccountDTO;

    /**
     * @throws SubaccountException
     */
    public function updateSubaccount(string $code, SubaccountRequestDTO $request): SubaccountDTO;

    /**
     * @throws SubaccountException
     */
    public function fetchSubaccount(string $code): SubaccountDTO;
}
