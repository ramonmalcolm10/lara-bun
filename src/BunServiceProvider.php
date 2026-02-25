<?php

namespace RamonMalcolm\LaraBun;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ServiceProvider;
use RamonMalcolm\LaraBun\Console\BunServeCommand;

class BunServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bun.php', 'bun');

        $this->app->singleton(BunBridge::class);
    }

    public function boot(): void
    {
        if (config('bun.ssr.enabled') && interface_exists(\Inertia\Ssr\Gateway::class)) {
            $this->app->singleton(\Inertia\Ssr\Gateway::class, Ssr\BunSsrGateway::class);
        }

        $this->registerBladeDirectives();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bun.php' => config_path('bun.php'),
            ], 'lara-bun-config');

            $this->commands([
                BunServeCommand::class,
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
