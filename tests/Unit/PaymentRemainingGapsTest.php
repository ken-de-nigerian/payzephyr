<?php

use Illuminate\Http\Request;
use KenDeNigerian\PayZephyr\Exceptions\InvalidConfigurationException;
use KenDeNigerian\PayZephyr\Payment;
use KenDeNigerian\PayZephyr\PaymentManager;
use KenDeNigerian\PayZephyr\SubscriptionQuery;

test('getRateLimitKey falls back to an ip-based key when request is bound and there is no auth user or email', function () {
    $request = Request::create('/charge', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.5']);
    app()->instance('request', $request);

    $manager = new PaymentManager;
    $payment = new Payment($manager);

    try {
        $payment->amount(10000)->charge();
        $this->fail('Expected InvalidConfigurationException to be thrown.');
    } catch (InvalidConfigurationException $e) {
        expect($e->getMessage())->toContain('Callback URL is required');
    } finally {
        app()->forgetInstance('request');
    }
});

test('subscriptions returns a new SubscriptionQuery instance', function () {
    $manager = new PaymentManager;
    $payment = new Payment($manager);

    $result = $payment->subscriptions();

    expect($result)->toBeInstanceOf(SubscriptionQuery::class);
});
