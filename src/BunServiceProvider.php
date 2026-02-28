<?php

namespace RamonMalcolm\LaraBun;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use RamonMalcolm\LaraBun\Console\BunServeCommand;
use RamonMalcolm\LaraBun\Console\RscActionManifestCommand;
use RamonMalcolm\LaraBun\Console\RscPrerenderCommand;
use RamonMalcolm\LaraBun\Http\Middleware\ServeStaticRsc;
use RamonMalcolm\LaraBun\Rsc\CallableRegistry;
use RamonMalcolm\LaraBun\Rsc\RscActionController;

class BunServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bun.php', 'bun');

        $this->app->singleton(BunBridge::class);

        Route::macro('staticPaths', function (array $paths) {
            $this->defaults['_static_paths'] = $paths;

            return $this;
        });

        $this->app->singleton(CallableRegistry::class, function ($app) {
            $registry = new CallableRegistry($app);

            foreach (config('bun.rsc.callables', []) as $name => $callable) {
                $registry->register($name, $callable);
            }

            $callablesDir = config('bun.rsc.callables_dir');

            if ($callablesDir !== null) {
                $registry->discoverFrom($callablesDir);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        if (config('bun.ssr.enabled') && interface_exists(\Inertia\Ssr\Gateway::class)) {
            $this->app->singleton(\Inertia\Ssr\Gateway::class, Ssr\BunSsrGateway::class);
        }

        if (config('bun.rsc.enabled')) {
            Route::post('/_rsc/action', RscActionController::class)
                ->middleware('web');

            $this->app['router']->aliasMiddleware('rsc.static', ServeStaticRsc::class);

            if (file_exists(base_path('routes/rsc-static.php'))) {
                Route::middleware(ServeStaticRsc::class)
                    ->group(base_path('routes/rsc-static.php'));
            }
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lara-bun');
        $this->registerBladeDirectives();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bun.php' => config_path('bun.php'),
            ], 'lara-bun-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/lara-bun'),
            ], 'lara-bun-views');

            $this->commands([
                BunServeCommand::class,
                RscActionManifestCommand::class,
                RscPrerenderCommand::class,
            ]);
        }
    }

    private function registerBladeDirectives(): void
    {
        Blade::directive('rscScripts', function (string $expression) {
            return "<?php echo \RamonMalcolm\LaraBun\BunServiceProvider::renderRscScripts({$expression}); ?>";
        });
    }

    /**
     * Render the inline script block and module tags needed to hydrate RSC client components.
     *
     * @param  string  $rscPayload  The Flight payload string
     * @param  string[]  $clientChunks  Browser JS chunk URLs
     */
    public static function renderRscScripts(string $rscPayload, array $clientChunks): HtmlString
    {
        if ($clientChunks === []) {
            return new HtmlString('');
        }

        $encodedPayload = json_encode($rscPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $chunkTags = '';
        foreach ($clientChunks as $chunk) {
            $escaped = e($chunk);
            $chunkTags .= "\n    <script type=\"module\" src=\"{$escaped}\"></script>";
        }

        return new HtmlString(<<<HTML
    <script>
        window.__RSC_PAYLOAD__ = {$encodedPayload};
        window.__RSC_MODULES__ = {};
        window.__webpack_require__ = function(id) { return window.__RSC_MODULES__[id]; };
        window.__webpack_chunk_load__ = function() { return Promise.resolve(); };
    </script>{$chunkTags}
HTML);
    }
}
