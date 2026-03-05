<?php

use Illuminate\Support\Facades\Route;
use LaraBun\Rsc\Header;
use LaraBun\Rsc\RscMiddleware;

test('returns 409 on version mismatch for GET RSC request', function () {
    Route::get('/test-version', fn () => 'ok')
        ->middleware(RscMiddleware::class);

    $response = $this->get('/test-version', [
        Header::X_RSC => 'true',
        Header::X_RSC_VERSION => 'stale-version-hash',
    ]);

    $response->assertStatus(409)
        ->assertHeader(Header::X_RSC_LOCATION);
});

test('passes through when client version is empty', function () {
    Route::get('/test-empty-version', fn () => 'ok')
        ->middleware(RscMiddleware::class);

    $response = $this->get('/test-empty-version', [
        Header::X_RSC => 'true',
        Header::X_RSC_VERSION => '',
    ]);

    $response->assertStatus(200);
});

test('passes through when no X-RSC header', function () {
    Route::get('/test-no-rsc', fn () => 'ok')
        ->middleware(RscMiddleware::class);

    $response = $this->get('/test-no-rsc');

    $response->assertStatus(200);
});

test('passes through for POST requests even with version mismatch', function () {
    Route::post('/test-post-version', fn () => 'ok')
        ->middleware(RscMiddleware::class);

    $response = $this->post('/test-post-version', [], [
        Header::X_RSC => 'true',
        Header::X_RSC_VERSION => 'stale-version',
    ]);

    $response->assertStatus(200);
});

test('sets Vary X-RSC header on response', function () {
    Route::get('/test-vary', fn () => 'ok')
        ->middleware(RscMiddleware::class);

    $response = $this->get('/test-vary');

    $vary = $response->headers->get('Vary');
    expect($vary)->toContain(Header::X_RSC);
});

test('converts 302 to 303 for PUT request', function () {
    Route::put('/test-put-redirect', fn () => redirect('/other'))
        ->middleware(RscMiddleware::class);

    $response = $this->put('/test-put-redirect');

    $response->assertStatus(303);
});

test('converts 302 to 303 for PATCH request', function () {
    Route::patch('/test-patch-redirect', fn () => redirect('/other'))
        ->middleware(RscMiddleware::class);

    $response = $this->patch('/test-patch-redirect');

    $response->assertStatus(303);
});

test('converts 302 to 303 for DELETE request', function () {
    Route::delete('/test-delete-redirect', fn () => redirect('/other'))
        ->middleware(RscMiddleware::class);

    $response = $this->delete('/test-delete-redirect');

    $response->assertStatus(303);
});

test('does not convert 302 to 303 for GET request', function () {
    Route::get('/test-get-redirect', fn () => redirect('/other'))
        ->middleware(RscMiddleware::class);

    $response = $this->get('/test-get-redirect');

    $response->assertStatus(302);
});

test('does not convert non-302 status codes', function () {
    Route::put('/test-301-redirect', fn () => redirect('/other', 301))
        ->middleware(RscMiddleware::class);

    $response = $this->put('/test-301-redirect');

    $response->assertStatus(301);
});
