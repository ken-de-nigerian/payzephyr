<?php

use KenDeNigerian\PayZephyr\Services\ChannelMapper;

test('mapFromProvider returns null for an empty provider method', function () {
    $mapper = new ChannelMapper;

    expect($mapper->mapFromProvider('', 'paystack'))->toBeNull();
});
