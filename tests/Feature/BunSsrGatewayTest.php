<?php

use LaraBun\BunBridge;
use LaraBun\Ssr\BunSsrGateway;

test('returns Response on successful dispatch', function () {
    $bridge = Mockery::mock(BunBridge::class);
    $bridge->shouldReceive('ssr')
        ->once()
        ->with(['component' => 'Home', 'props' => []])
        ->andReturn([
            'head' => ['<title>Home</title>', '<meta name="description" content="test">'],
            'body' => '<div id="app">Home Page</div>',
        ]);

    $gateway = new BunSsrGateway($bridge);
    $response = $gateway->dispatch(['component' => 'Home', 'props' => []]);

    expect($response)->not->toBeNull()
        ->and($response->head)->toContain('<title>Home</title>')
        ->and($response->body)->toBe('<div id="app">Home Page</div>');
});

test('returns null when head is missing from result', function () {
    $bridge = Mockery::mock(BunBridge::class);
    $bridge->shouldReceive('ssr')
        ->once()
        ->andReturn(['body' => '<div>No Head</div>']);

    $gateway = new BunSsrGateway($bridge);
    $response = $gateway->dispatch(['component' => 'Page']);

    expect($response)->toBeNull();
});

test('returns null when body is missing from result', function () {
    $bridge = Mockery::mock(BunBridge::class);
    $bridge->shouldReceive('ssr')
        ->once()
        ->andReturn(['head' => ['<title>Test</title>']]);

    $gateway = new BunSsrGateway($bridge);
    $response = $gateway->dispatch(['component' => 'Page']);

    expect($response)->toBeNull();
});

test('returns null on exception', function () {
    $bridge = Mockery::mock(BunBridge::class);
    $bridge->shouldReceive('ssr')
        ->once()
        ->andThrow(new RuntimeException('Connection refused'));

    $gateway = new BunSsrGateway($bridge);
    $response = $gateway->dispatch(['component' => 'Page']);

    expect($response)->toBeNull();
});
