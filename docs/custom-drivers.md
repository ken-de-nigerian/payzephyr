# Custom Drivers

PayZephyr supports several payment providers out of the box, but you're not limited to them. Every provider PayZephyr ships with is built the same way you'd build your own: a "driver" class that translates PayZephyr's unified API into that specific provider's HTTP API. This chapter walks through building one.

## When you'd actually need this

Only if you need a payment provider PayZephyr doesn't already support (see [Multiple Providers](providers.md) for the current list). If you're just trying to customize behavior *within* an existing provider, you probably don't need a custom driver; check [Advanced Usage](advanced-usage.md) first.

## The contract every driver fulfills

Every driver implements `DriverInterface`, and in practice extends `AbstractDriver`, which handles the shared plumbing (HTTP client setup, logging, currency checking, reference generation) so your driver only has to implement the parts that are genuinely specific to your provider:

```php
abstract class AbstractDriver implements DriverInterface
{
    abstract protected function validateConfig(): void;
    abstract protected function getDefaultHeaders(): array;

    // You must also implement, from DriverInterface:
    public function charge(ChargeRequestDTO $request): ChargeResponseDTO { ... }
    public function verify(string $reference): VerificationResponseDTO { ... }
    public function validateWebhook(array $headers, string $body): bool { ... }
    public function healthCheck(): bool { ... }

    // AbstractDriver already provides sensible defaults for these -
    // override only if your provider's webhook payload shape needs it:
    public function extractWebhookReference(array $payload): ?string { ... }
    public function extractWebhookStatus(array $payload): string { ... }
    public function extractWebhookChannel(array $payload): ?string { ... }
    public function resolveVerificationId(string $reference, string $providerId): string { ... }
}
```

## Building one: a driver for a fictional provider "Acmepay"

Let's say Acmepay has a REST API: `POST /v1/charges` to start a payment, `GET /v1/charges/{id}` to check its status, and it signs webhooks with an HMAC-SHA256 header called `X-Acmepay-Signature`.

### 1. Config validation

```php
namespace App\PaymentDrivers;

use KenDeNigerian\PayZephyr\Drivers\AbstractDriver;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;

final class AcmepayDriver extends AbstractDriver
{
    protected string $name = 'acmepay';

    protected function validateConfig(): void
    {
        if (empty($this->config['secret_key'])) {
            throw new InvalidConfigurationException('Acmepay secret key is required');
        }
    }

    protected function getDefaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->config['secret_key'],
            'Content-Type' => 'application/json',
        ];
    }
}
```

`$this->config` is whatever array you configure for this provider in `config/payments.php` (see step 5). `validateConfig()` runs automatically before any request, so a missing key fails fast with a clear message rather than surfacing as a confusing API error later.

### 2. Charging

```php
use KenDeNigerian\PayZephyr\DataObjects\ChargeRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\ChargeException;

public function charge(ChargeRequestDTO $request): ChargeResponseDTO
{
    $this->setCurrentRequest($request); // enables idempotency-key handling, if set

    try {
        $reference = $request->reference ?? $this->generateReference('ACME');

        $response = $this->makeRequest('POST', '/v1/charges', [
            'json' => [
                'amount' => $request->getAmountInMinorUnits(),
                'currency' => $request->currency,
                'email' => $request->email,
                'reference' => $reference,
                'redirect_url' => $request->callbackUrl,
            ],
        ]);

        $data = $this->parseResponse($response);

        if (($data['status'] ?? null) !== 'ok') {
            throw new ChargeException($data['message'] ?? 'Failed to initialize Acmepay charge');
        }

        return new ChargeResponseDTO(
            reference: $reference,
            authorizationUrl: $data['checkout_url'],
            accessCode: $data['id'],
            status: 'pending',
            metadata: $request->metadata,
            provider: $this->getName(),
        );
    } catch (ChargeException $e) {
        throw $e;
    } catch (\Throwable $e) {
        throw new ChargeException('Payment initialization failed: '.$e->getMessage(), 0, $e);
    } finally {
        $this->clearCurrentRequest();
    }
}
```

`makeRequest()` and `parseResponse()` are helpers `AbstractDriver` gives you: `makeRequest()` wraps Guzzle, automatically attaches an idempotency header if one was set via `->idempotency()`, and wraps network failures in a `ChargeException` with useful context; `parseResponse()` just JSON-decodes the response body. `generateReference()` produces a reasonably unique reference string if the caller didn't supply their own.

### 3. Verification

```php
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;
use KenDeNigerian\PayZephyr\Exceptions\VerificationException;

public function verify(string $reference): VerificationResponseDTO
{
    try {
        $response = $this->makeRequest('GET', "/v1/charges/{$reference}");
        $data = $this->parseResponse($response);

        return new VerificationResponseDTO(
            reference: $data['reference'],
            status: $this->normalizeStatus($data['status']), // maps Acmepay's status words to success/failed/pending/cancelled
            amount: $data['amount'] / 100,
            currency: $data['currency'],
            paidAt: $data['paid_at'] ?? null,
            provider: $this->getName(),
        );
    } catch (\Throwable $e) {
        throw new VerificationException('Payment verification failed: '.$e->getMessage(), 0, $e);
    }
}
```

`normalizeStatus()` (provided by `AbstractDriver`) is what turns a provider's own status vocabulary into PayZephyr's normalized `success`/`failed`/`pending`/`cancelled` set; see [Payment Verification](verification.md#why-status-is-a-normalized-string-not-each-providers-raw-value). Override it if Acmepay's status words don't map cleanly onto the defaults.

### 4. Webhook validation

```php
public function validateWebhook(array $headers, string $body): bool
{
    $signature = $headers['x-acmepay-signature'][0] ?? null;

    if (! $signature) {
        return false;
    }

    $expected = hash_hmac('sha256', $body, $this->config['webhook_secret']);

    // hash_equals(), not === - see Security for why this matters
    if (! hash_equals($expected, $signature)) {
        return false;
    }

    $payload = json_decode($body, true) ?? [];

    return $this->validateWebhookTimestamp($payload); // replay-attack protection, from HasWebhookValidation
}
```

Always use `hash_equals()` for the comparison, never `===`; see [Security](security.md#webhook-signature-verification) for why. `validateWebhookTimestamp()` is inherited from a shared trait and handles replay protection automatically, provided your payload has a recognizable timestamp field. Check `AbstractDriver`'s existing drivers in PayZephyr's source for the exact field names it checks by default, and override `extractWebhookTimestamp()` if Acmepay nests its timestamp somewhere non-standard.

### 5. Registering the driver

Add it to `config/payments.php` like any other provider, pointing `driver_class` at your class:

```php
'providers' => [
    // ...existing providers...

    'acmepay' => [
        'driver' => 'acmepay',
        'driver_class' => \App\PaymentDrivers\AcmepayDriver::class,
        'secret_key' => env('ACMEPAY_SECRET_KEY'),
        'webhook_secret' => env('ACMEPAY_WEBHOOK_SECRET'),
        'base_url' => env('ACMEPAY_BASE_URL', 'https://api.acmepay.com'),
        'currencies' => ['USD'],
        'enabled' => env('ACMEPAY_ENABLED', false),
    ],
],
```

From here, it works exactly like every built-in provider:

```php
Payment::amount(50.00)->email('customer@example.com')->with('acmepay')->redirect();
```

## Testing your driver

Exactly the same technique as testing anything else built on PayZephyr; see [Testing](testing.md#the-actual-mechanism-inject-a-mock-guzzle-client). Inject a mocked Guzzle client via `setClient()` and assert your driver builds the right request and correctly interprets the provider's response shape.

## Adding refunds and subscriptions

Your driver can charge and verify. Adding refunds or subscriptions is the next step, and each is
opt-in: you add an interface, and PayZephyr starts routing that feature to your driver. Skip
either one and PayZephyr tells your users clearly that this provider does not support it,
instead of failing in a confusing way.

[Extending a Driver](extending-drivers.md) covers both in full: every method you must write,
every field of every DTO, complete worked examples, the error-handling rules, and what to test.

## Next steps

- [Extending a Driver](extending-drivers.md): add refunds and subscriptions to the driver you just built
- [Advanced Usage](advanced-usage.md): direct driver access, useful while debugging a custom driver
- [Architecture](architecture.md): how drivers fit into PayZephyr's layers overall
