<?php

use KenDeNigerian\PayZephyr\Enums\PaymentChannel;
use KenDeNigerian\PayZephyr\Services\ChannelMapper;

test('channel mapper mapToSquare maps card channel correctly', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([PaymentChannel::CARD->value], 'square');

    expect($result)->toBe(['CARD']);
});

test('channel mapper mapToSquare maps bank transfer to OTHER', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([PaymentChannel::BANK_TRANSFER->value], 'square');

    expect($result)->toBe(['OTHER']);
});

test('channel mapper mapToSquare filters invalid channels', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['CARD', 'INVALID_CHANNEL'], 'square');

    expect($result)->toBe(['CARD']);
});

test('channel mapper mapToSquare accepts valid Square payment methods', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['CARD', 'CASH', 'SQUARE_GIFT_CARD'], 'square');

    expect($result)->toContain('CARD', 'CASH', 'SQUARE_GIFT_CARD');
});

test('channel mapper mapToSquare handles case insensitive input', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'Bank_Transfer'], 'square');

    expect($result)->toContain('CARD', 'OTHER');
});

test('channel mapper mapToOpay maps all unified channels', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([
        PaymentChannel::CARD->value,
        PaymentChannel::BANK_TRANSFER->value,
        PaymentChannel::USSD->value,
        PaymentChannel::MOBILE_MONEY->value,
        PaymentChannel::QR_CODE->value,
    ], 'opay');

    expect($result)->toContain('CARD', 'BANK_ACCOUNT', 'OPAY_ACCOUNT', 'OPAY_QRCODE');
});

test('channel mapper mapToOpay maps card to CARD', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([PaymentChannel::CARD->value], 'opay');

    expect($result)->toBe(['CARD']);
});

test('channel mapper mapToOpay maps bank transfer to BANK_ACCOUNT', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([PaymentChannel::BANK_TRANSFER->value], 'opay');

    expect($result)->toBe(['BANK_ACCOUNT']);
});

test('channel mapper mapToOpay maps ussd to OPAY_ACCOUNT', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([PaymentChannel::USSD->value], 'opay');

    expect($result)->toBe(['OPAY_ACCOUNT']);
});

test('channel mapper mapToOpay maps mobile money to OPAY_ACCOUNT', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([PaymentChannel::MOBILE_MONEY->value], 'opay');

    expect($result)->toBe(['OPAY_ACCOUNT']);
});

test('channel mapper mapToOpay maps qr code to OPAY_QRCODE', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels([PaymentChannel::QR_CODE->value], 'opay');

    expect($result)->toBe(['OPAY_QRCODE']);
});

test('channel mapper mapToOpay filters invalid channels', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['card', 'invalid_channel'], 'opay');

    expect($result)->toBe(['CARD']);
});

test('channel mapper mapToOpay returns null for unmapped channels', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['unknown_channel'], 'opay');

    expect($result)->toBe([]);
});

test('channel mapper mapToOpay accepts valid OPay options', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['CARD', 'BALANCE', 'OTHERS'], 'opay');

    expect($result)->toContain('CARD')
        ->and($result)->toContain('BALANCE')
        ->and($result)->toContain('OTHERS');
});

test('channel mapper mapToOpay handles case insensitive input', function () {
    $mapper = new ChannelMapper;

    $result = $mapper->mapChannels(['Card', 'BANK_TRANSFER', 'Qr_Code'], 'opay');

    expect($result)->toContain('CARD', 'BANK_ACCOUNT', 'OPAY_QRCODE');
});
