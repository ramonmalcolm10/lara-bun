<?php

use LaraBun\Rsc\Attributes\Authenticated;
use LaraBun\Rsc\Attributes\Can;
use LaraBun\Rsc\Attributes\Middleware;

test('Authenticated defaults to null guard', function () {
    $attr = new Authenticated;

    expect($attr->guard)->toBeNull();
});

test('Authenticated accepts custom guard', function () {
    $attr = new Authenticated('api');

    expect($attr->guard)->toBe('api');
});

test('Can stores ability and model', function () {
    $attr = new Can('edit', 'App\Models\Post');

    expect($attr->ability)->toBe('edit')
        ->and($attr->model)->toBe('App\Models\Post');
});

test('Can stores ability without model', function () {
    $attr = new Can('admin');

    expect($attr->ability)->toBe('admin')
        ->and($attr->model)->toBeNull();
});

test('Middleware stores variadic arguments', function () {
    $attr = new Middleware('auth', 'verified', 'throttle:60,1');

    expect($attr->middleware)->toBe(['auth', 'verified', 'throttle:60,1']);
});

test('Middleware stores single argument', function () {
    $attr = new Middleware('auth');

    expect($attr->middleware)->toBe(['auth']);
});

test('Middleware stores empty when no arguments', function () {
    $attr = new Middleware;

    expect($attr->middleware)->toBe([]);
});
