<?php

namespace RamonMalcolm\LaraBun\Rsc;

use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class PageScanner
{
    /** @var list<PageDefinition> */
    protected array $pages = [];

    public function __construct(
        protected string $appDir,
    ) {}

    public function scan(): void
    {
        $this->pages = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->appDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! preg_match('/^page\.(tsx|ts|jsx|js)$/', $file->getFilename())) {
                continue;
            }

            $this->pages[] = $this->buildDefinition($file);
        }

        usort($this->pages, fn (PageDefinition $a, PageDefinition $b) => strcmp($a->urlPattern, $b->urlPattern));
    }

    /**
     * @return list<PageDefinition>
     */
    public function getPages(): array
    {
        return $this->pages;
    }

    protected function buildDefinition(SplFileInfo $file): PageDefinition
    {
        $pageDir = dirname($file->getRealPath());
        $relativePath = $this->relativePath($file->getRealPath());
        $relativeDir = dirname($relativePath);

        if ($relativeDir === '.') {
            $relativeDir = '';
        }

        $componentName = 'app/'.preg_replace('/\.(tsx|ts|jsx|js)$/', '', $relativePath);
        $segments = $relativeDir !== '' ? explode('/', $relativeDir) : [];

        $urlSegments = [];
        foreach ($segments as $segment) {
            // Strip route groups: (groupName) → no URL segment
            if (preg_match('/^\(.*\)$/', $segment)) {
                continue;
            }

            $urlSegments[] = $this->convertSegment($segment);
        }

        $urlPattern = $urlSegments !== [] ? implode('/', $urlSegments) : '/';
        $isDynamic = (bool) preg_match('/\{[^}]+\}/', $urlPattern);
        $layouts = $this->collectLayouts($pageDir);
        $routeConfigPath = $this->findRouteConfig($pageDir);
        $directoryConfigPaths = $this->collectDirectoryConfigs($pageDir);

        return new PageDefinition(
            componentName: $componentName,
            urlPattern: $urlPattern,
            layouts: $layouts,
            isDynamic: $isDynamic,
            routeConfigPath: $routeConfigPath,
            directoryConfigPaths: $directoryConfigPaths,
        );
    }

    /**
     * Convert a directory segment to a Laravel route parameter.
     *
     * [slug] → {slug}
     * [...path] → {path}
     * about → about
     */
    protected function convertSegment(string $segment): string
    {
        // Catch-all: [...param]
        if (preg_match('/^\[\.\.\.(\w+)\]$/', $segment, $matches)) {
            return '{'.$matches[1].'}';
        }

        // Dynamic: [param]
        if (preg_match('/^\[(\w+)\]$/', $segment, $matches)) {
            return '{'.$matches[1].'}';
        }

        return $segment;
    }

    /**
     * Walk up from page directory to app root, collecting layout files.
     * Returns outermost-first (app/layout before app/docs/layout).
     *
     * @return list<string>
     */
    protected function collectLayouts(string $pageDir): array
    {
        $layouts = [];
        $current = $pageDir;
        $appDirReal = realpath($this->appDir);

        while (true) {
            $layout = $this->findLayout($current);

            if ($layout !== null) {
                $layoutRelative = $this->relativePath($layout);
                $componentName = 'app/'.preg_replace('/\.(tsx|ts|jsx|js)$/', '', $layoutRelative);
                $layouts[] = $componentName;
            }

            if (realpath($current) === $appDirReal) {
                break;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        // Reverse so outermost (root) layout is first
        return array_reverse($layouts);
    }

    protected function findLayout(string $dir): ?string
    {
        foreach (['tsx', 'ts', 'jsx', 'js'] as $ext) {
            $path = $dir.'/layout.'.$ext;

            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Find route.php in the same directory as the page file.
     */
    protected function findRouteConfig(string $pageDir): ?string
    {
        $path = $pageDir.'/route.php';

        return file_exists($path) ? $path : null;
    }

    /**
     * Walk up from page directory, collecting ancestor route.php files
     * (excluding the page's own route.php). Outermost first.
     *
     * @return list<string>
     */
    protected function collectDirectoryConfigs(string $pageDir): array
    {
        $configs = [];
        $current = dirname($pageDir);
        $appDirReal = realpath($this->appDir);

        while (true) {
            $configPath = $current.'/route.php';

            if (file_exists($configPath)) {
                $configs[] = $configPath;
            }

            if (realpath($current) === $appDirReal) {
                break;
            }

            $parent = dirname($current);

            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        // Reverse so outermost ancestor is first
        return array_reverse($configs);
    }

    /**
     * Get relative path from app directory.
     */
    protected function relativePath(string $absolutePath): string
    {
        $appDirReal = realpath($this->appDir).'/';

        return Str::after($absolutePath, $appDirReal);
    }
}
