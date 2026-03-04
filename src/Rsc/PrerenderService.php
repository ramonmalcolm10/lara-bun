<?php

namespace LaraBun\Rsc;

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use LaraBun\BunBridge;
use LaraBun\BunServiceProvider;
use Symfony\Component\Process\Process;

class PrerenderService
{
    /**
     * Discover ALL RSC routes (any route with _rsc_component default).
     *
     * @return Collection<int, Route>
     */
    public function discoverRscRoutes(): Collection
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => isset($route->defaults['_rsc_component']))
            ->filter(fn (Route $route) => in_array('GET', $route->methods()));
    }

    /**
     * Resolve the concrete URLs for a given route.
     *
     * Non-parameterized routes return a single URL.
     * Parameterized routes with staticPaths() return expanded URLs.
     * Parameterized routes without staticPaths() return an empty array.
     *
     * @return list<string>
     */
    public function resolveUrls(Route $route): array
    {
        $uri = $route->uri();

        if (! str_contains($uri, '{')) {
            return ['/'.ltrim($uri, '/')];
        }

        $staticPaths = $route->defaults['_static_paths'] ?? null;

        if ($staticPaths === null) {
            return [];
        }

        $paramNames = $route->parameterNames();

        return collect($staticPaths)->map(function ($params) use ($uri, $paramNames) {
            if (is_string($params)) {
                $params = [$paramNames[0] => $params];
            }

            $url = $uri;

            foreach ($params as $key => $value) {
                $url = str_replace(["{{$key}}", "{{$key}?}"], $value, $url);
            }

            return '/'.ltrim($url, '/');
        })->all();
    }

    /**
     * Pre-render a single URL and write static files.
     *
     * @return array{type: string, reason: string|null}
     */
    public function prerenderUrl(string $url, Route $route, string $outputPath, bool $forceStatic = false): array
    {
        $rscResponse = $this->resolveRscResponse($route, $url);

        if (! $rscResponse instanceof RscResponse) {
            return ['type' => 'skipped', 'reason' => 'not an RscResponse'];
        }

        $result = app(BunBridge::class)->rsc(
            $rscResponse->getComponent(),
            $rscResponse->getProps(),
            $rscResponse->getLayouts(),
        );

        if (! $forceStatic && ($result['usedDynamicApis'] ?? false)) {
            return ['type' => 'dynamic', 'reason' => 'usedDynamicApis'];
        }

        $version = $rscResponse->getVersion();
        $html = $this->buildHtmlPage($url, $rscResponse->getComponent(), $version, $result, $rscResponse);

        $path = trim($url, '/') ?: 'index';
        File::ensureDirectoryExists(dirname("{$outputPath}/{$path}.html"));

        File::put("{$outputPath}/{$path}.html", $html);
        File::put("{$outputPath}/{$path}.flight", $result['rscPayload']);
        $meta = [
            'clientChunks' => $result['clientChunks'],
            'version' => $version,
        ];

        $viewData = $rscResponse->getViewData();

        if (isset($viewData['title'])) {
            $meta['title'] = $viewData['title'];
        }

        File::put("{$outputPath}/{$path}.meta.json", json_encode($meta, JSON_THROW_ON_ERROR));

        return ['type' => 'static', 'reason' => null];
    }

    public function resolveRscResponse(Route $route, string $url): mixed
    {
        $params = $this->extractParams($route, $url);

        $request = app('request');
        $request->setRouteResolver(fn () => $route);
        $route->bind($request);

        foreach ($params as $key => $value) {
            $route->setParameter($key, $value);
        }

        $action = $route->getAction('uses');

        return app()->call($action, $params);
    }

    public function isForceStatic(Route $route): bool
    {
        $configPaths = $route->defaults['_rsc_config_paths'] ?? [];

        foreach ($configPaths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $config = require $path;

            if ($config instanceof PageRoute && $config->isForceStatic()) {
                return true;
            }
        }

        return false;
    }

    public function isForceDynamic(Route $route): bool
    {
        $configPaths = $route->defaults['_rsc_config_paths'] ?? [];

        foreach ($configPaths as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $config = require $path;

            if ($config instanceof PageRoute && $config->isDynamic()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{body: string, rscPayload: string, clientChunks: string[]}  $result
     */
    public function buildHtmlPage(string $url, string $component, string $version, array $result, RscResponse $rscResponse): string
    {
        $initialJson = json_encode([
            'url' => $url,
            'component' => $component,
            'version' => $version,
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG);

        $scripts = BunServiceProvider::renderRscScripts($result['rscPayload'], $result['clientChunks']);
        $rootView = config('bun.rsc.root_view', 'lara-bun::rsc-app');

        return view($rootView, [
            ...$rscResponse->getViewData(),
            'body' => $result['body'],
            'initialJson' => $initialJson,
            'scripts' => $scripts,
        ])->render();
    }

    /**
     * Start or connect to the Bun worker process.
     *
     * @return Process|null|false Process if started, null if already running, false on failure
     */
    public function ensureBunWorker(bool $forceRestart = false): Process|null|false
    {
        $socketPath = config('bun.socket_path', '/tmp/bun-bridge.sock');

        if (file_exists($socketPath)) {
            if ($forceRestart) {
                // Kill existing worker so we start fresh with new bundles
                try {
                    app(BunBridge::class)->disconnect();
                } catch (\Throwable) {
                }

                @unlink($socketPath);
            } else {
                try {
                    app(BunBridge::class)->ping();

                    return null;
                } catch (\Throwable) {
                    @unlink($socketPath);
                }
            }
        }

        $process = new Process([PHP_BINARY, base_path('artisan'), 'bun:serve']);
        $process->setTimeout(null);
        $process->start();

        $maxWait = 15;
        $waited = 0;

        while ($waited < $maxWait) {
            usleep(500_000);
            $waited += 0.5;

            if (file_exists($socketPath)) {
                try {
                    app(BunBridge::class)->ping();

                    return $process;
                } catch (\Throwable) {
                    // Socket exists but not ready yet
                }
            }

            if (! $process->isRunning()) {
                return false;
            }
        }

        $process->stop(5);

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function extractParams(Route $route, string $url): array
    {
        $paramNames = $route->parameterNames();

        if (empty($paramNames)) {
            return [];
        }

        $uri = $route->uri();
        $pattern = preg_replace('/\{(\w+)\??\}/', '(?P<$1>[^/]+)', $uri);
        $urlPath = ltrim($url, '/');
        $params = [];

        if (preg_match('#^'.$pattern.'$#', $urlPath, $matches)) {
            foreach ($paramNames as $name) {
                if (isset($matches[$name])) {
                    $params[$name] = $matches[$name];
                }
            }
        }

        return $params;
    }
}
