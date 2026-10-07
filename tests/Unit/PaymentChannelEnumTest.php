<?php

declare(strict_types=1);

use KenDeNigerian\PayZephyr\Enums\PaymentChannel;

test('payment channel enum has all expected values', function (): void {
    expect(PaymentChannel::CARD->value)->toBe('card')
        ->and(PaymentChannel::BANK_TRANSFER->value)->toBe('bank_transfer')
        ->and(PaymentChannel::USSD->value)->toBe('ussd')
        ->and(PaymentChannel::MOBILE_MONEY->value)->toBe('mobile_money')
        ->and(PaymentChannel::QR_CODE->value)->toBe('qr_code');
});

test('payment channel enum provides labels', function (): void {
    expect(PaymentChannel::CARD->label())->toBe('Credit/Debit Card')
        ->and(PaymentChannel::BANK_TRANSFER->label())->toBe('Bank Transfer')
        ->and(PaymentChannel::USSD->label())->toBe('USSD')
        ->and(PaymentChannel::MOBILE_MONEY->label())->toBe('Mobile Money')
        ->and(PaymentChannel::QR_CODE->label())->toBe('QR Code');
});

test('payment channel enum values method returns all values', function (): void {
    $values = PaymentChannel::values();

    expect($values)->toBeArray()
        ->and($values)->toContain('card', 'bank_transfer', 'ussd', 'mobile_money', 'qr_code', 'digital_wallet', 'paypal', 'bank_account')
        ->and(count($values))->toBe(8);
});

test('payment channel enum can be created from value', function (): void {
    $channel = PaymentChannel::from('card');

    expect($channel)->toBe(PaymentChannel::CARD);
});

test('payment channel enum can try from value', function (): void {
    $channel = PaymentChannel::tryFrom('card');
    $invalid = PaymentChannel::tryFrom('invalid');

    expect($channel)->toBe(PaymentChannel::CARD)
        ->and($invalid)->toBeNull();
});
