<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use KenDeNigerian\PayZephyr\Drivers\FlutterwaveDriver;
use KenDeNigerian\PayZephyr\Drivers\MollieDriver;
use KenDeNigerian\PayZephyr\Drivers\PaddleDriver;
use KenDeNigerian\PayZephyr\Drivers\PaystackDriver;
use KenDeNigerian\PayZephyr\Drivers\StripeDriver;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;

/*
 * A credential that is not a non-empty string is missing.
 *
 * The drivers checked their config with empty(), which a non-string passes -
 * `PAYSTACK_SECRET_KEY=true` reaches the config as boolean true - and then
 * read it as a string, found none, and signed with an empty key. A webhook
 * signed with an empty key is one anyone can produce.
 */

test('a credential that is not a string is refused when the driver is built', function (string $driver, array $config, string $message): void {
    expect(fn (): object => new $driver($config))->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'paystack secret true' => [PaystackDriver::class, ['secret_key' => true], 'Paystack secret key is required'],
    'stripe secret as array' => [StripeDriver::class, ['secret_key' => ['sk_test']], 'Stripe secret key is required'],
    'mollie key blank' => [MollieDriver::class, ['api_key' => ''], 'Mollie API key is required'],
]);

test('a stripe webhook is never verified against an empty secret', function (): void {
    $driver = new StripeDriver(['secret_key' => 'sk_test', 'webhook_secret' => true]);
    $body = '{"id":"evt_1","type":"charge.succeeded"}';
    $t = time();

    // What a forger can compute when the secret is ''.
    $forged = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, '');

    expect($driver->validateWebhook(['stripe-signature' => [$forged]], $body))->toBeFalse();
});

test('a mollie webhook secret that is not a string sends verification to the api, not to an empty key', function (): void {
    $driver = new MollieDriver(['api_key' => 'test_key', 'webhook_secret' => true]);
    $body = '{"resource":"event","id":"event_1","type":"hook.ping"}';

    expect($driver->requiresAsyncVerification())->toBeTrue()
        ->and($driver->validateWebhook(['x-mollie-signature' => [hash_hmac('sha256', $body, '')]], $body))->toBeFalse();
});

test('a flutterwave webhook is rejected without a secret hash, never checked against the secret key', function (?string $secret): void {
    // FLUTTERWAVE_WEBHOOK_SECRET= in an .env file reads as '', not null.
    $channel = Mockery::spy();
    Log::shouldReceive('channel')->andReturn($channel);
    $driver = new FlutterwaveDriver(['secret_key' => 'FLWSECK_TEST-1', 'webhook_secret' => $secret]);

    expect($driver->validateWebhook(['verif-hash' => ['FLWSECK_TEST-1']], '{}'))->toBeFalse()
        ->and($driver->validateWebhook(['verif-hash' => ['']], '{}'))->toBeFalse();

    $channel->shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === '[flutterwave] Webhook rejected: no Secret Hash configured');
})->with(['blank' => [''], 'unset' => [null]]);

test('a flutterwave webhook carrying the configured secret hash is accepted', function (): void {
    $driver = new FlutterwaveDriver(['secret_key' => 'FLWSECK_TEST-1', 'webhook_secret' => 'my-secret-hash']);

    expect($driver->validateWebhook(['verif-hash' => ['my-secret-hash']], '{}'))->toBeTrue()
        ->and($driver->validateWebhook(['verif-hash' => ['FLWSECK_TEST-1']], '{}'))->toBeFalse();
});

test('a paddle delivery signed for a rotated secret is accepted whichever h1 matches', function (): void {
    $driver = new PaddleDriver(['api_key' => 'pdl_key', 'webhook_secret' => 'pdl_ntfset_new', 'currencies' => ['USD']]);
    $body = '{"event_id":"evt_1","event_type":"transaction.completed"}';
    $ts = (string) time();
    $old = hash_hmac('sha256', $ts.':'.$body, 'pdl_ntfset_old');
    $new = hash_hmac('sha256', $ts.':'.$body, 'pdl_ntfset_new');

    expect($driver->validateWebhook(['paddle-signature' => ["ts=$ts;h1=$new;h1=$old"]], $body))->toBeTrue()
        ->and($driver->validateWebhook(['paddle-signature' => ["ts=$ts;h1=$old;h1=$new"]], $body))->toBeTrue()
        ->and($driver->validateWebhook(['paddle-signature' => ["ts=$ts;h1=$old"]], $body))->toBeFalse()
        ->and($driver->validateWebhook(['paddle-signature' => ["ts=$ts;v1=$new"]], $body))->toBeFalse();
});
