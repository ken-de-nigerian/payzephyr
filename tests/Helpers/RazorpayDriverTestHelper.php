<?php

namespace Tests\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use KenDeNigerian\PayZephyr\Drivers\RazorpayDriver;

class RazorpayDriverTestHelper
{
    /**
     * A RazorpayDriver whose HTTP client replays $responses and records every
     * request it sends into $history.
     *
     * @param  array<int, mixed>  $responses
     * @param  array<int, array<string, mixed>>|null  $history
     * @param  array<string, mixed>  $config
     */
    public static function driver(array $responses = [], ?array &$history = null, array $config = []): RazorpayDriver
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $driver = new RazorpayDriver(array_merge([
            'key_id' => 'rzp_test_key',
            'key_secret' => 'test_key_secret',
            'webhook_secret' => 'test_webhook_secret',
            'base_url' => 'https://api.razorpay.com',
            'currencies' => ['INR', 'JPY', 'KWD'],
        ], $config));
        $driver->setClient(new Client(['handler' => $stack]));

        return $driver;
    }

    /**
     * A paid Payment Link, shaped like GET /v1/payment_links/{id}.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function paymentLink(array $overrides = []): array
    {
        return array_merge([
            'id' => 'plink_Abc123',
            'amount' => 49950,
            'amount_paid' => 49950,
            'currency' => 'INR',
            'customer' => ['email' => 'buyer@example.com', 'contact' => '+919000090000', 'name' => 'Test Buyer'],
            'notes' => ['payzephyr_reference' => 'ORDER_1001', 'order_id' => '1001'],
            'payments' => [[
                'amount' => 49950,
                'created_at' => 1757000000,
                'method' => 'upi',
                'payment_id' => 'pay_Abc123',
                'status' => 'captured',
            ]],
            'reference_id' => 'ORDER_1001',
            'short_url' => 'https://rzp.io/i/abc123',
            'status' => 'paid',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentLinkWebhook(string $event = 'payment_link.paid', ?int $createdAt = null): array
    {
        return [
            'entity' => 'event',
            'account_id' => 'acc_Test123',
            'event' => $event,
            'contains' => ['payment_link', 'order', 'payment'],
            'payload' => [
                'payment_link' => ['entity' => self::paymentLink()],
                'payment' => ['entity' => [
                    'id' => 'pay_Abc123',
                    'amount' => 49950,
                    'currency' => 'INR',
                    'status' => 'captured',
                    'method' => 'upi',
                ]],
            ],
            'created_at' => $createdAt ?? time(),
        ];
    }

    /**
     * @param  array<string, mixed>  $notes
     * @return array<string, mixed>
     */
    public static function refundWebhook(string $event, string $refundId, string $status, array $notes = ['payzephyr_reference' => 'ORDER_1001']): array
    {
        return [
            'entity' => 'event',
            'account_id' => 'acc_Test123',
            'event' => $event,
            'contains' => ['refund', 'payment'],
            'payload' => [
                'refund' => ['entity' => [
                    'id' => $refundId,
                    'entity' => 'refund',
                    'amount' => 49950,
                    'currency' => 'INR',
                    'payment_id' => 'pay_Abc123',
                    'notes' => $notes,
                    'status' => $status,
                ]],
                'payment' => ['entity' => [
                    'id' => 'pay_Abc123',
                    'status' => 'refunded',
                    'method' => 'upi',
                ]],
            ],
            'created_at' => time(),
        ];
    }
}
