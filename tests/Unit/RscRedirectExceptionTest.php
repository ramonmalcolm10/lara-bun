<?php

use LaraBun\Rsc\RscRedirectException;

test('stores location and default status', function () {
    $exception = new RscRedirectException('/posts/123');

    expect($exception->getLocation())->toBe('/posts/123')
        ->and($exception->getStatus())->toBe(302)
        ->and($exception->getMessage())->toBe('Redirect to /posts/123');
});

test('accepts custom status code', function () {
    $exception = new RscRedirectException('/dashboard', 301);

    expect($exception->getLocation())->toBe('/dashboard')
        ->and($exception->getStatus())->toBe(301);
});
