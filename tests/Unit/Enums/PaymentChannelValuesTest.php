<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Enums\PaymentChannel;

test('payment channel enum provides labels for every case', function () {
    expect(PaymentChannel::DIGITAL_WALLET->label())->toBe('Digital Wallet')
        ->and(PaymentChannel::PAYPAL->label())->toBe('PayPal')
        ->and(PaymentChannel::BANK_ACCOUNT->label())->toBe('Bank Account');
});

test('payment channel values method returns the raw string values in declaration order', function () {
    expect(PaymentChannel::values())->toBe([
        'card',
        'bank_transfer',
        'ussd',
        'mobile_money',
        'qr_code',
        'digital_wallet',
        'paypal',
        'bank_account',
    ]);
});
