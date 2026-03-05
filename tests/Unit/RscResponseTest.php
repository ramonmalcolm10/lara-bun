<?php

use LaraBun\Rsc\RscResponse;

test('getComponent returns the component name', function () {
    $response = new RscResponse('Dashboard');

    expect($response->getComponent())->toBe('Dashboard');
});

test('getProps returns the props', function () {
    $response = new RscResponse('Dashboard', ['userId' => 1, 'role' => 'admin']);

    expect($response->getProps())->toBe(['userId' => 1, 'role' => 'admin']);
});

test('getProps returns empty array when no props', function () {
    $response = new RscResponse('Page');

    expect($response->getProps())->toBe([]);
});

test('getLayouts returns layouts', function () {
    $response = new RscResponse('Dashboard');
    $response->layout('AppLayout', ['title' => 'App']);
    $response->layout('DashLayout');

    expect($response->getLayouts())->toBe([
        ['component' => 'AppLayout', 'props' => ['title' => 'App']],
        ['component' => 'DashLayout', 'props' => []],
    ]);
});

test('getLayouts returns empty array when no layouts', function () {
    $response = new RscResponse('Page');

    expect($response->getLayouts())->toBe([]);
});

test('withViewData adds view data', function () {
    $response = new RscResponse('Page');
    $response->withViewData('title', 'My Page');
    $response->withViewData('description', 'A test page');

    expect($response->getViewData())->toBe([
        'title' => 'My Page',
        'description' => 'A test page',
    ]);
});

test('getViewData returns empty array when no view data', function () {
    $response = new RscResponse('Page');

    expect($response->getViewData())->toBe([]);
});

test('rootView sets root view', function () {
    $response = new RscResponse('Page');
    $result = $response->rootView('custom-view');

    expect($result)->toBe($response);

    $rootView = (new ReflectionProperty($response, 'rootView'))->getValue($response);
    expect($rootView)->toBe('custom-view');
});

test('rootView defaults to null', function () {
    $response = new RscResponse('Page');

    $rootView = (new ReflectionProperty($response, 'rootView'))->getValue($response);
    expect($rootView)->toBeNull();
});

test('version sets explicit version', function () {
    $response = new RscResponse('Page');
    $result = $response->version('v1.2.3');

    expect($result)->toBe($response);
    expect($response->getVersion())->toBe('v1.2.3');
});

test('getVersion returns explicit version when set', function () {
    $response = new RscResponse('Page');
    $response->version('custom-v1');

    expect($response->getVersion())->toBe('custom-v1');
});

test('withViewData is chainable', function () {
    $response = new RscResponse('Page');

    $result = $response
        ->withViewData('title', 'Test')
        ->withViewData('meta', 'description');

    expect($result)->toBe($response);
});

test('withViewData overwrites existing key', function () {
    $response = new RscResponse('Page');
    $response->withViewData('title', 'First');
    $response->withViewData('title', 'Second');

    expect($response->getViewData())->toBe(['title' => 'Second']);
});

test('status sets and returns the status code', function () {
    $response = new RscResponse('Page');
    $response->status(404);

    expect($response->getStatusCode())->toBe(404);
});

test('default status is 200', function () {
    $response = new RscResponse('Page');

    expect($response->getStatusCode())->toBe(200);
});

test('status is chainable', function () {
    $response = new RscResponse('Page');

    $result = $response->status(500);

    expect($result)->toBe($response);
});
