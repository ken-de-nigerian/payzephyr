<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use KenDeNigerian\PayZephyr\DataObjects\BankDTO;
use KenDeNigerian\PayZephyr\DataObjects\ResolvedAccountDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubaccountDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubaccountRequestDTO;
use KenDeNigerian\PayZephyr\Exceptions\SubaccountException;
use KenDeNigerian\PayZephyr\Support\Payload;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Subaccount and bank support for PaystackDriver.
 *
 * Banks come from GET /bank (cursor-paged; every page is read), account
 * numbers resolve through GET /bank/resolve, and subaccounts live at
 * /subaccount. A subaccount's `settlement_bank` is the bank's code going in
 * and its name coming back; `percentage_charge` is the default share the
 * main account keeps from each split charge.
 */
trait PaystackSubaccountMethods
{
    /**
     * @return list<BankDTO>
     *
     * @throws SubaccountException
     */
    public function listBanks(string $currency): array
    {
        try {
            $banks = [];
            $cursor = null;
            $pages = 0;

            // At most 20 pages of 100: far more banks than any country has.
            do {
                // A null `next` (the first page) is left out of the query string.
                $query = ['currency' => $currency, 'perPage' => 100, 'use_cursor' => 'true', 'next' => $cursor];
                $body = $this->subaccountResponse($this->makeRequest('GET', '/bank', ['query' => $query]), 'Failed to list Paystack banks');

                foreach ($body->array('data') as $bank) {
                    $entry = Payload::of($bank);
                    $name = $entry->string('name');
                    $code = $entry->string('code');

                    if ($name !== null && $code !== null && $entry->flag(true, 'active')) {
                        $banks[] = new BankDTO(name: $name, code: $code, currency: $currency);
                    }
                }

                $cursor = $body->string('meta', 'next');
                $pages++;
            } while ($cursor !== null && $cursor !== '' && $pages < 20);

            return $banks;
        } catch (SubaccountException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to list banks', ['error' => $e->getMessage()]);

            throw new SubaccountException('Failed to list banks: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SubaccountException
     */
    public function resolveAccount(string $accountNumber, string $bankCode): ResolvedAccountDTO
    {
        try {
            $body = $this->subaccountResponse(
                $this->makeRequest('GET', '/bank/resolve', ['query' => ['account_number' => $accountNumber, 'bank_code' => $bankCode]]),
                'Could not resolve the account number',
            );
            $data = $body->array('data');

            return new ResolvedAccountDTO(
                accountNumber: $accountNumber,
                accountName: $this->requireString($data, 'account_name', 'resolve account'),
                bankCode: $bankCode,
            );
        } catch (SubaccountException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to resolve account', ['bank_code' => $bankCode, 'error' => $e->getMessage()]);

            throw new SubaccountException('Failed to resolve account: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SubaccountException
     */
    public function createSubaccount(SubaccountRequestDTO $request): SubaccountDTO
    {
        return $this->sendSubaccount('POST', '/subaccount', $request, 'create');
    }

    /**
     * @throws SubaccountException
     */
    public function updateSubaccount(string $code, SubaccountRequestDTO $request): SubaccountDTO
    {
        return $this->sendSubaccount('PUT', '/subaccount/'.rawurlencode($code), $request, 'update');
    }

    /**
     * @throws SubaccountException
     */
    public function fetchSubaccount(string $code): SubaccountDTO
    {
        try {
            $body = $this->subaccountResponse($this->makeRequest('GET', '/subaccount/'.rawurlencode($code)), 'Failed to fetch Paystack subaccount');

            return $this->toSubaccount($body->array('data'));
        } catch (SubaccountException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', 'Failed to fetch subaccount', ['subaccount' => $code, 'error' => $e->getMessage()]);

            throw new SubaccountException('Failed to fetch subaccount: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws SubaccountException
     */
    private function sendSubaccount(string $method, string $uri, SubaccountRequestDTO $request, string $operation): SubaccountDTO
    {
        try {
            $payload = array_filter([
                'business_name' => $request->businessName,
                'settlement_bank' => $request->bankCode,
                'account_number' => $request->accountNumber,
                'percentage_charge' => $request->percentageCharge,
                'description' => $request->description,
                'primary_contact_email' => $request->contactEmail,
                'primary_contact_name' => $request->contactName,
                'primary_contact_phone' => $request->contactPhone,
                'metadata' => $request->metadata === [] ? null : json_encode($request->metadata),
            ], fn ($value): bool => $value !== null);

            $body = $this->subaccountResponse($this->makeRequest($method, $uri, ['json' => $payload]), "Failed to $operation Paystack subaccount");
            $subaccount = $this->toSubaccount($body->array('data'));

            $this->log('info', "Subaccount {$operation}d", ['subaccount' => $subaccount->code]);

            return $subaccount;
        } catch (SubaccountException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log('error', "Failed to $operation subaccount", ['error' => $e->getMessage()]);

            throw new SubaccountException("Failed to $operation subaccount: ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws SubaccountException
     */
    private function toSubaccount(array $data): SubaccountDTO
    {
        $subaccount = Payload::of($data);

        return new SubaccountDTO(
            code: $this->requireString($data, 'subaccount_code', 'subaccount'),
            businessName: $subaccount->string('business_name') ?? '',
            accountNumber: $subaccount->string('account_number') ?? '',
            bankName: $subaccount->string('settlement_bank'),
            accountName: $subaccount->string('account_name'),
            percentageCharge: $subaccount->float('percentage_charge') ?? 0.0,
            active: $subaccount->flag(true, 'active'),
            provider: $this->getName(),
        );
    }

    /**
     * The response body, or a SubaccountException carrying Paystack's message.
     *
     * @throws SubaccountException
     */
    private function subaccountResponse(ResponseInterface $response, string $fallbackMessage): Payload
    {
        $body = new Payload($this->parseResponse($response));

        if (! $body->flag(false, 'status')) {
            throw new SubaccountException($body->string('message') ?? $fallbackMessage);
        }

        return $body;
    }
}
