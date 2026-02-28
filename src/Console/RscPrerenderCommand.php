<?php

namespace RamonMalcolm\LaraBun\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use RamonMalcolm\LaraBun\BunBridge;
use RamonMalcolm\LaraBun\BunServiceProvider;
use RamonMalcolm\LaraBun\Http\Middleware\ServeStaticRsc;
use RamonMalcolm\LaraBun\Rsc\RscResponse;
use Symfony\Component\Process\Process;

class RscPrerenderCommand extends Command
{
    protected $signature = 'rsc:prerender {--clean : Remove existing static files before generating}';

    protected $description = 'Pre-render RSC pages as static HTML and Flight payloads';

    public function handle(): int
    {
        if (! config('bun.rsc.enabled')) {
            $this->error('RSC is not enabled. Set BUN_RSC_ENABLED=true in your .env.');

            return self::FAILURE;
        }

        $outputPath = config('bun.rsc.static_path', storage_path('framework/rsc-static'));

        if ($this->option('clean') && is_dir($outputPath)) {
            File::deleteDirectory($outputPath);
            $this->info('Cleaned existing static files.');
        }

        $routes = $this->discoverStaticRoutes();

        if ($routes->isEmpty()) {
            $this->warn('No routes found with the ServeStaticRsc middleware.');

            return self::SUCCESS;
        }

        $bunProcess = $this->ensureBunWorker();

        if ($bunProcess === false) {
            return self::FAILURE;
        }

        $urls = $routes->flatMap(fn (Route $route) => $this->resolveUrls($route));
        $this->info("Pre-rendering {$urls->count()} page(s)...");
        $rendered = 0;

        foreach ($routes as $route) {
            foreach ($this->resolveUrls($route) as $url) {
                try {
                    $this->prerenderUrl($url, $route, $outputPath);
                    $rendered++;
                } catch (\Throwable $e) {
                    $this->error("  Failed {$url}: {$e->getMessage()}");
                }
            }
        }

        if ($bunProcess instanceof Process) {
            $bunProcess->stop(5);
            $this->info('Bun worker stopped.');
        }

        $this->info("Pre-rendered {$rendered} page(s) to {$outputPath}");

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Route>
     */
    private function discoverStaticRoutes(): \Illuminate\Support\Collection
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(function (Route $route) {
                $middleware = $route->gatherMiddleware();

                return in_array(ServeStaticRsc::class, $middleware, true)
                    || in_array('rsc.static', $middleware, true);
            })
            ->filter(fn (Route $route) => in_array('GET', $route->methods()));
    }

    /**
     * @return list<string>
     */
    private function resolveUrls(Route $route): array
    {
        $uri = $route->uri();

        if (! str_contains($uri, '{')) {
            return ['/'.ltrim($uri, '/')];
        }

        $staticPaths = $route->defaults['_static_paths'] ?? null;

        if ($staticPaths === null) {
            $this->warn("  Skipping {$uri} — no staticPaths() defined for parameterized route.");

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

    private function prerenderUrl(string $url, Route $route, string $outputPath): void
    {
        $this->line("  {$url}");

        $rscResponse = $this->resolveRscResponse($route, $url);

        if (! $rscResponse instanceof RscResponse) {
            $this->warn("  Skipping {$url} — route does not return an RscResponse.");

            return;
        }

        $result = app(BunBridge::class)->rsc(
            $rscResponse->getComponent(),
            $rscResponse->getProps(),
            $rscResponse->getLayouts(),
        );

        $version = $rscResponse->getVersion();
        $html = $this->buildHtmlPage($url, $rscResponse->getComponent(), $version, $result, $rscResponse);

        $path = trim($url, '/') ?: 'index';
        File::ensureDirectoryExists(dirname("{$outputPath}/{$path}.html"));

        File::put("{$outputPath}/{$path}.html", $html);
        File::put("{$outputPath}/{$path}.flight", $result['rscPayload']);
        File::put("{$outputPath}/{$path}.meta.json", json_encode([
            'clientChunks' => $result['clientChunks'],
            'version' => $version,
        ], JSON_THROW_ON_ERROR));
    }

    private function resolveRscResponse(Route $route, string $url): mixed
    {
        $params = $this->extractParams($route, $url);
        $action = $route->getAction('uses');

        return app()->call($action, $params);
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

    /**
     * @param  array{body: string, rscPayload: string, clientChunks: string[]}  $result
     */
    private function buildHtmlPage(string $url, string $component, string $version, array $result, RscResponse $rscResponse): string
    {
        $initialJson = json_encode([
            'url' => $url,
            'component' => $component,
            'version' => $version,
        ], JSON_THROW_ON_ERROR);

        $scripts = BunServiceProvider::renderRscScripts($result['rscPayload'], $result['clientChunks']);
        $rootView = config('bun.rsc.root_view', 'lara-bun::rsc-app');

        return view($rootView, [
            'body' => $result['body'],
            'initialJson' => $initialJson,
            'scripts' => $scripts,
        ])->render();
    }

    /**
     * @return Process|null|false Process if started, null if already running, false on failure
     */
    private function ensureBunWorker(): Process|null|false
    {
        $socketPath = config('bun.socket_path', '/tmp/bun-bridge.sock');

        if (file_exists($socketPath)) {
            try {
                app(BunBridge::class)->ping();
                $this->info('Bun worker already running.');

                return null;
            } catch (\Throwable) {
                @unlink($socketPath);
            }
        }

        $this->info('Starting Bun worker...');

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
                    $this->info('Bun worker ready.');

                    return $process;
                } catch (\Throwable) {
                    // Socket exists but not ready yet
                }
            }

            if (! $process->isRunning()) {
                $this->error('Bun worker failed to start.');

                return false;
            }
        }

        $this->error('Bun worker did not become ready within '.$maxWait.' seconds.');
        $process->stop(5);

        return false;
    }
}
