<?php

use LaraBun\Rsc\RscResponse;

test('layout method returns the response instance for chaining', function () {
    $response = new RscResponse('Dashboard');

    $result = $response->layout('AppLayout');

    expect($result)->toBe($response);
});

test('layout method accepts props', function () {
    $response = new RscResponse('Dashboard');

    $result = $response->layout('AppLayout', ['title' => 'My App']);

    expect($result)->toBe($response);
});

test('multiple layouts can be chained', function () {
    $response = new RscResponse('Dashboard');

    $result = $response
        ->layout('AppLayout')
        ->layout('DashboardLayout');

    expect($result)->toBe($response);
});

test('duplicate layout names are ignored', function () {
    $response = new RscResponse('Dashboard');

    $response
        ->layout('AppLayout', ['title' => 'First'])
        ->layout('AppLayout', ['title' => 'Second']);

    $layouts = (new ReflectionProperty($response, 'layouts'))->getValue($response);

    expect($layouts)->toHaveCount(1)
        ->and($layouts[0]['component'])->toBe('AppLayout')
        ->and($layouts[0]['props'])->toBe(['title' => 'First']);
});

test('layouts are stored in order with correct structure', function () {
    $response = new RscResponse('Dashboard');

    $response
        ->layout('AppLayout', ['title' => 'App'])
        ->layout('DashboardLayout');

    $layouts = (new ReflectionProperty($response, 'layouts'))->getValue($response);

    expect($layouts)->toBe([
        ['component' => 'AppLayout', 'props' => ['title' => 'App']],
        ['component' => 'DashboardLayout', 'props' => []],
    ]);
});

test('layout can be combined with other chainable methods', function () {
    $response = new RscResponse('Dashboard', ['userId' => 1]);

    $result = $response
        ->layout('AppLayout')
        ->version('abc123');

    expect($result)->toBeInstanceOf(RscResponse::class);
});

test('layouts default to empty array', function () {
    $response = new RscResponse('Dashboard');

    $layouts = (new ReflectionProperty($response, 'layouts'))->getValue($response);

    expect($layouts)->toBe([]);
});

test('layout props default to empty array', function () {
    $response = new RscResponse('Dashboard');

    $response->layout('AppLayout');

    $layouts = (new ReflectionProperty($response, 'layouts'))->getValue($response);

    expect($layouts[0]['props'])->toBe([]);
});
