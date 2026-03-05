<?php

use LaraBun\Rsc\RscResponse;

test('rsc helper creates RscResponse', function () {
    $response = rsc('Dashboard');

    expect($response)->toBeInstanceOf(RscResponse::class)
        ->and($response->getComponent())->toBe('Dashboard')
        ->and($response->getProps())->toBe([]);
});

test('rsc helper accepts props', function () {
    $response = rsc('Dashboard', ['userId' => 42]);

    expect($response->getComponent())->toBe('Dashboard')
        ->and($response->getProps())->toBe(['userId' => 42]);
});

test('rsc helper returns chainable response', function () {
    $response = rsc('Page')
        ->layout('AppLayout')
        ->withViewData('title', 'Test');

    expect($response)->toBeInstanceOf(RscResponse::class)
        ->and($response->getLayouts())->toHaveCount(1)
        ->and($response->getViewData())->toBe(['title' => 'Test']);
});
