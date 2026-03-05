<?php

use LaraBun\Rsc\PageScanner;

beforeEach(function () {
    $this->appDir = sys_get_temp_dir().'/rsc-test-'.uniqid();
    mkdir($this->appDir, 0755, true);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->appDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }

    rmdir($this->appDir);
});

function createFile(string $base, string $path): void
{
    $full = $base.'/'.$path;
    $dir = dirname($full);

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($full, '// test file');
}

test('discovers root page.tsx', function () {
    createFile($this->appDir, 'page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    expect($scanner->getPages())->toHaveCount(1);

    $page = $scanner->getPages()[0];

    expect($page->componentName)->toBe('app/page')
        ->and($page->urlPattern)->toBe('/')
        ->and($page->isDynamic)->toBeFalse();
});

test('discovers nested page', function () {
    createFile($this->appDir, 'about/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    expect($scanner->getPages())->toHaveCount(1);

    $page = $scanner->getPages()[0];

    expect($page->componentName)->toBe('app/about/page')
        ->and($page->urlPattern)->toBe('about');
});

test('discovers dynamic segment', function () {
    createFile($this->appDir, 'docs/[slug]/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $page = $scanner->getPages()[0];

    expect($page->componentName)->toBe('app/docs/[slug]/page')
        ->and($page->urlPattern)->toBe('docs/{slug}')
        ->and($page->isDynamic)->toBeTrue();
});

test('discovers catch-all segment', function () {
    createFile($this->appDir, 'blog/[...path]/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $page = $scanner->getPages()[0];

    expect($page->componentName)->toBe('app/blog/[...path]/page')
        ->and($page->urlPattern)->toBe('blog/{path}')
        ->and($page->isDynamic)->toBeTrue();
});

test('strips route groups from URL', function () {
    createFile($this->appDir, '(marketing)/pricing/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $page = $scanner->getPages()[0];

    expect($page->urlPattern)->toBe('pricing');
});

test('ignores non-page files', function () {
    createFile($this->appDir, 'page.tsx');
    createFile($this->appDir, 'docs/sidebar.tsx');
    createFile($this->appDir, 'components/NavBar.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    expect($scanner->getPages())->toHaveCount(1);
});

test('collects layouts outermost first', function () {
    createFile($this->appDir, 'layout.tsx');
    createFile($this->appDir, 'docs/layout.tsx');
    createFile($this->appDir, 'docs/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $page = $scanner->getPages()[0];

    expect($page->layouts)->toBe(['app/layout', 'app/docs/layout']);
});

test('collects root layout for root page', function () {
    createFile($this->appDir, 'layout.tsx');
    createFile($this->appDir, 'page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $page = $scanner->getPages()[0];

    expect($page->layouts)->toBe(['app/layout']);
});

test('finds sibling route.php', function () {
    createFile($this->appDir, 'docs/[slug]/page.tsx');
    createFile($this->appDir, 'docs/[slug]/route.php');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $page = $scanner->getPages()[0];

    expect($page->routeConfigPath)->toEndWith('docs/[slug]/route.php');
});

test('collects ancestor directory config paths', function () {
    createFile($this->appDir, 'docs/route.php');
    createFile($this->appDir, 'docs/[slug]/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $page = $scanner->getPages()[0];

    expect($page->directoryConfigPaths)->toHaveCount(1)
        ->and($page->directoryConfigPaths[0])->toEndWith('docs/route.php');
});

test('supports multiple page extensions', function () {
    createFile($this->appDir, 'page.jsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    expect($scanner->getPages())->toHaveCount(1);
    expect($scanner->getPages()[0]->componentName)->toBe('app/page');
});

test('discovers multiple pages', function () {
    createFile($this->appDir, 'page.tsx');
    createFile($this->appDir, 'about/page.tsx');
    createFile($this->appDir, 'docs/page.tsx');
    createFile($this->appDir, 'docs/[slug]/page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    expect($scanner->getPages())->toHaveCount(4);
});

test('sorts pages by URL pattern', function () {
    createFile($this->appDir, 'docs/page.tsx');
    createFile($this->appDir, 'about/page.tsx');
    createFile($this->appDir, 'page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    $urls = array_map(fn ($p) => $p->urlPattern, $scanner->getPages());

    expect($urls)->toBe(['/', 'about', 'docs']);
});

test('page without layouts has empty layouts array', function () {
    createFile($this->appDir, 'page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    expect($scanner->getPages()[0]->layouts)->toBe([]);
});

test('page without route.php has null routeConfigPath', function () {
    createFile($this->appDir, 'page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    expect($scanner->getPages()[0]->routeConfigPath)->toBeNull();
});

test('page without ancestor configs has empty directoryConfigPaths', function () {
    createFile($this->appDir, 'page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();

    expect($scanner->getPages()[0]->directoryConfigPaths)->toBe([]);
});

test('scan can be called multiple times and resets', function () {
    createFile($this->appDir, 'page.tsx');

    $scanner = new PageScanner($this->appDir);
    $scanner->scan();
    expect($scanner->getPages())->toHaveCount(1);

    createFile($this->appDir, 'about/page.tsx');

    $scanner->scan();
    expect($scanner->getPages())->toHaveCount(2);
});
