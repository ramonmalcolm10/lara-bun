<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use LaraBun\Http\Middleware\ServeStaticRsc;
use LaraBun\Rsc\Header;

beforeEach(function () {
    $this->staticDir = sys_get_temp_dir().'/rsc-static-test-'.uniqid();
    mkdir($this->staticDir, 0755, true);
    Config::set('bun.rsc.static_path', $this->staticDir);

    Route::get('/test-page', fn () => 'dynamic content')
        ->middleware(ServeStaticRsc::class);

    Route::get('/nested/page', fn () => 'dynamic nested')
        ->middleware(ServeStaticRsc::class);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->staticDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }

    rmdir($this->staticDir);
});

test('serves pre-rendered flight file on RSC request', function () {
    file_put_contents($this->staticDir.'/test-page.flight', 'flight-payload-data');
    file_put_contents($this->staticDir.'/test-page.meta.json', json_encode([
        'clientChunks' => ['/build/rsc/chunk-1.js'],
        'version' => 'abc123',
    ]));

    $response = $this->get('/test-page', [
        Header::X_RSC => 'true',
    ]);

    $response->assertStatus(200)
        ->assertHeader(Header::X_RSC_VERSION, 'abc123');

    $contentType = $response->headers->get('Content-Type');
    expect($contentType)->toContain('text/x-component');

    expect($response->getContent())->toBe('flight-payload-data');
});

test('serves pre-rendered html file on non-RSC request', function () {
    file_put_contents($this->staticDir.'/test-page.html', '<html><body>Static Page</body></html>');

    $response = $this->get('/test-page');

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

    expect($response->getContent())->toBe('<html><body>Static Page</body></html>');
});

test('falls through when no static flight file exists for RSC request', function () {
    $response = $this->get('/test-page', [
        Header::X_RSC => 'true',
    ]);

    $response->assertStatus(200);
    expect($response->getContent())->toBe('dynamic content');
});

test('falls through when no static html file exists for non-RSC request', function () {
    $response = $this->get('/test-page');

    $response->assertStatus(200);
    expect($response->getContent())->toBe('dynamic content');
});

test('includes client chunks header from meta.json', function () {
    file_put_contents($this->staticDir.'/test-page.flight', 'payload');
    file_put_contents($this->staticDir.'/test-page.meta.json', json_encode([
        'clientChunks' => ['/build/rsc/chunk-a.js', '/build/rsc/chunk-b.js'],
        'version' => 'v1',
    ]));

    $response = $this->get('/test-page', [
        Header::X_RSC => 'true',
    ]);

    $chunks = json_decode($response->headers->get(Header::X_RSC_CHUNKS), true);
    expect($chunks)->toBe(['/build/rsc/chunk-a.js', '/build/rsc/chunk-b.js']);
});

test('includes title header when present in meta', function () {
    file_put_contents($this->staticDir.'/test-page.flight', 'payload');
    file_put_contents($this->staticDir.'/test-page.meta.json', json_encode([
        'clientChunks' => [],
        'version' => 'v1',
        'title' => 'My Page Title',
    ]));

    $response = $this->get('/test-page', [
        Header::X_RSC => 'true',
    ]);

    expect($response->headers->get(Header::X_RSC_TITLE))->toBe(rawurlencode('My Page Title'));
});

test('omits title header when not in meta', function () {
    file_put_contents($this->staticDir.'/test-page.flight', 'payload');
    file_put_contents($this->staticDir.'/test-page.meta.json', json_encode([
        'clientChunks' => [],
        'version' => 'v1',
    ]));

    $response = $this->get('/test-page', [
        Header::X_RSC => 'true',
    ]);

    expect($response->headers->has(Header::X_RSC_TITLE))->toBeFalse();
});

test('serves nested static pages', function () {
    mkdir($this->staticDir.'/nested', 0755, true);
    file_put_contents($this->staticDir.'/nested/page.flight', 'nested-flight');
    file_put_contents($this->staticDir.'/nested/page.meta.json', json_encode([
        'clientChunks' => [],
        'version' => 'v2',
    ]));

    $response = $this->get('/nested/page', [
        Header::X_RSC => 'true',
    ]);

    $response->assertStatus(200);
    expect($response->getContent())->toBe('nested-flight');
});

test('requires both flight and meta files to serve static RSC', function () {
    // Only flight file, no meta - should fall through
    file_put_contents($this->staticDir.'/test-page.flight', 'payload');

    $response = $this->get('/test-page', [
        Header::X_RSC => 'true',
    ]);

    expect($response->getContent())->toBe('dynamic content');
});

test('serves index for root path', function () {
    Route::get('/', fn () => 'dynamic root')
        ->middleware(ServeStaticRsc::class);

    file_put_contents($this->staticDir.'/index.html', '<html>Static Root</html>');

    $response = $this->get('/');

    $response->assertStatus(200);
    expect($response->getContent())->toBe('<html>Static Root</html>');
});
