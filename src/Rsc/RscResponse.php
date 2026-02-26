<?php

namespace RamonMalcolm\LaraBun\Rsc;

use Illuminate\Contracts\Support\Responsable;
use RamonMalcolm\LaraBun\BunBridge;
use RamonMalcolm\LaraBun\BunServiceProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RscResponse implements Responsable
{
    protected ?string $rootView = null;

    /** @var array<string, mixed> */
    protected array $viewData = [];

    protected ?string $version = null;

    /** @var list<array{component: string, props: array<string, mixed>}> */
    protected array $layouts = [];

    /**
     * @param  array<string, mixed>  $props
     */
    public function __construct(
        protected string $component,
        protected array $props = [],
    ) {}

    /**
     * Wrap the page in a layout component. Layouts are ordered outermost-first:
     * `->layout('A')->layout('B')` produces `<A><B><Page /></B></A>`.
     *
     * Duplicate component names are ignored — first registration wins.
     *
     * @param  array<string, mixed>  $props
     */
    public function layout(string $component, array $props = []): static
    {
        foreach ($this->layouts as $existing) {
            if ($existing['component'] === $component) {
                return $this;
            }
        }

        $this->layouts[] = ['component' => $component, 'props' => $props];

        return $this;
    }

    public function rootView(string $rootView): static
    {
        $this->rootView = $rootView;

        return $this;
    }

    public function withViewData(string $key, mixed $value): static
    {
        $this->viewData[$key] = $value;

        return $this;
    }

    public function version(string $version): static
    {
        $this->version = $version;

        return $this;
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     */
    public function toResponse($request): \Symfony\Component\HttpFoundation\Response
    {
        $version = $this->version ?? $this->resolveVersion();

        if ($request->hasHeader(Header::X_RSC)) {
            return $this->toStreamedRscResponse($version);
        }

        return $this->toStreamedHtmlResponse($version, $request);
    }

    /**
     * Stream the raw Flight payload for SPA navigation.
     * Uses chunked transfer encoding so React can progressively render
     * as Flight bytes arrive via createFromReadableStream(response.body).
     */
    protected function toStreamedRscResponse(string $version): StreamedResponse
    {
        $bridge = app(BunBridge::class);
        $generator = $bridge->rscStream($this->component, $this->props, $this->layouts);

        // First yield is always the clientChunks array — read it eagerly
        // so we can set proper headers on the StreamedResponse object.
        $clientChunks = $generator->current();

        $headers = [
            'Content-Type' => 'text/x-component',
            Header::X_RSC_CHUNKS => json_encode($clientChunks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            Header::X_RSC_VERSION => $version,
            'X-Accel-Buffering' => 'no',
        ];

        return new StreamedResponse(function () use ($generator): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $generator->next();

            while ($generator->valid()) {
                echo $generator->current();
                flush();
                $generator->next();
            }
        }, 200, $headers);
    }

    /**
     * Stream HTML for initial page loads with Suspense support.
     *
     * React renders the shell (with Suspense fallbacks) immediately.
     * As async components resolve, React injects completion <template> +
     * <script> tags that swap in the resolved content. The browser handles
     * this automatically — no client JS needed for the initial swap.
     */
    protected function toStreamedHtmlResponse(string $version, \Illuminate\Http\Request $request): StreamedResponse
    {
        $bridge = app(BunBridge::class);
        $generator = $bridge->rscHtmlStream($this->component, $this->props, $this->layouts);

        // First yield: {clientChunks: [...]}
        $meta = $generator->current();
        $clientChunks = $meta['clientChunks'] ?? [];

        $url = $request->getRequestUri();
        $component = $this->component;

        return new StreamedResponse(function () use ($generator, $version, $url, $component, $clientChunks): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            // HTML head — send immediately so the browser starts parsing
            echo '<!DOCTYPE html><html><head>';
            echo '<meta charset="utf-8">';
            echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
            echo '<style>*{margin:0;box-sizing:border-box}html,body{height:100%;background:#09090b;color:#fafafa;font-family:system-ui,-apple-system,sans-serif}</style>';
            echo '</head><body>';
            echo '<div id="rsc-root">';
            flush();

            // Stream HTML body chunks from Bun (shell + Suspense completions)
            $generator->next();
            $rscPayload = '';

            while ($generator->valid()) {
                $value = $generator->current();

                if (is_array($value) && isset($value['rscPayload'])) {
                    $rscPayload = $value['rscPayload'];
                    $generator->next();

                    continue;
                }

                echo $value;
                flush();
                $generator->next();
            }

            echo '</div>';

            // RSC initial state for SPA navigation
            $initialJson = json_encode([
                'url' => $url,
                'component' => $component,
                'version' => $version,
            ], JSON_THROW_ON_ERROR);

            echo "<script>window.__RSC_INITIAL__ = {$initialJson};</script>";

            // RSC payload + client component scripts for hydration
            echo BunServiceProvider::renderRscScripts($rscPayload, $clientChunks);

            echo '</body></html>';
            flush();
        }, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected function resolveVersion(): string
    {
        $buildDir = public_path('build/rsc');

        if (! is_dir($buildDir)) {
            return '';
        }

        $mtime = 0;

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($buildDir)) as $file) {
            if ($file->isFile() && $file->getMTime() > $mtime) {
                $mtime = $file->getMTime();
            }
        }

        return md5((string) $mtime);
    }
}
