# Split Payments & Subaccounts

A marketplace takes payments for its sellers. With a split payment the money does not pass through your account at all: the provider settles it straight to the **seller's subaccount** (their own bank account), and keeps your **platform fee** for you on the way. You never hold sellers' money, and you never have to pay it out.

This chapter covers both halves: creating subaccounts for your sellers, and splitting charges to them.

> **Provider support.** Split payments and subaccounts are supported on **Paystack**. A split charge is never sent to a provider that cannot split it (see [Safety](#safety-a-split-is-never-dropped)).

## 1. Create a subaccount for each seller

A subaccount is a seller's settlement account at the provider. Create it once, when the seller connects their bank account, and keep its code: every split charge to that seller uses it.

First, offer the banks and confirm the account number belongs to the seller:

```php
use KenDeNigerian\PayZephyr\Facades\Payment;

$banks = Payment::subaccounts()->with('paystack')->banks('NGN');
// list<BankDTO>: $bank->name, $bank->code

$account = Payment::subaccounts()->resolveAccount('0123456789', '058');
// ResolvedAccountDTO: $account->accountName is the name the bank has for it
```

Show the seller `accountName` before you save anything: it is the simplest protection against a mistyped account number paying someone else.

Then create the subaccount:

```php
use KenDeNigerian\PayZephyr\DataObjects\SubaccountRequestDTO;

$subaccount = Payment::subaccounts()->create(new SubaccountRequestDTO(
    businessName: 'Ada Couture',
    bankCode: '058',
    accountNumber: '0123456789',
    percentageCharge: 5.0,        // your default share of each split charge, 0 to 100
    contactEmail: 'ada@example.com',
));

$subaccount->code; // "ACCT_..." - store it with the seller
```

`percentageCharge` is only a default: a charge's own platform fee (below) takes precedence over it. `update($code, ...)` changes a subaccount (a new bank account, a new default percentage) and `find($code)` reads one back. Both also accept an array with snake_case keys (`business_name`, `bank_code`, `account_number`, `percentage_charge`, ...).

## 2. Split a charge

Add `split()` to any charge:

```php
return Payment::amount(15000)
    ->email($customer->email)
    ->callback(route('payment.callback'))
    ->split($seller->subaccount_code, platformFee: 750.00, bearer: 'subaccount')
    ->redirect();
```

| Argument | Meaning |
|---|---|
| `subaccount` | The seller's subaccount code |
| `platformFee` | What you keep, in the major unit like `amount()` (750.00 of 15,000.00). Omit it to use the subaccount's default `percentageCharge`. `0` keeps nothing |
| `bearer` | Who pays the provider's processing fees: `'subaccount'` (the seller, the default) or `'platform'` (you) |

The customer pays 15,000.00; the seller receives 15,000.00 less your 750.00 (and, as the bearer, the provider's fee); you receive 750.00.

A split the provider already holds - for example one sharing a charge between several subaccounts - is used by its code:

```php
Payment::amount(15000)->email($email)->callback($url)->splitCode('SPL_xxx')->redirect();
```

PayZephyr validates its own split arguments before anything is sent: a fee below zero, a fee as large as the amount, an empty subaccount or an unknown bearer is an `InvalidArgumentException`. A provider's own split format, passed as the `split` key of `ChargeRequestDTO::fromArray()`, is sent to that provider unchanged.

Everything else is an ordinary charge: verification, webhooks, idempotency, tracing and refunds work exactly as they do without a split.

## Safety: a split is never dropped

A split charge sent to a provider that cannot split it would not fail - it would succeed, and the seller's money would settle into your own account. So PayZephyr never sends one there:

- In a [fallback chain](providers.md#automatic-fallback), providers that cannot split are skipped for split charges (traced as `PROVIDER_SKIPPED` with reason `split_unsupported`), and the next one that can is used.
- When none can, the charge fails with a `ProviderException` whose context names each provider and `does not support split payments`.

A provider supports split payments when its driver implements `SupportsSplitPaymentsInterface`, and subaccounts when it implements `SupportsSubaccountsInterface`. `Payment::subaccounts()` refuses a provider without it with a `SubaccountException`.

## Paystack specifics

- `platformFee` is sent as `transaction_charge` in kobo, and is sent even when it is `0` (Paystack would otherwise apply the subaccount's default percentage).
- `bearer: 'platform'` is Paystack's `account` (the main account); `'subaccount'` is `subaccount`.
- `banks()` reads every page of `GET /bank` for the currency (inactive banks are left out); `resolveAccount()` is `GET /bank/resolve`.
- Refunds of a split charge follow Paystack's rules for split transactions; see [Refunds](refunds.md).

## Next steps

- [Refunds](refunds.md): refunding a charge, split or not
- [Multiple Providers](providers.md): which providers support what
