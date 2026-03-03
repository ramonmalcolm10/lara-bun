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

        // Pre-render the Blade shell with placeholders so we can split it
        // into head/tail and stream the RSC body between them.
        $bodyMarker = '<!--__RSC_BODY__-->';
        $initialMarker = '<!--__RSC_INITIAL__-->';
        $scriptsMarker = '<!--__RSC_SCRIPTS__-->';
        $rootView = $this->rootView ?? config('bun.rsc.root_view', 'lara-bun::rsc-app');

        $shell = view($rootView, [
            ...$this->viewData,
            'body' => $bodyMarker,
            'initialJson' => $initialMarker,
            'scripts' => $scriptsMarker,
        ])->render();

        [$shellHead, $shellTail] = explode($bodyMarker, $shell, 2);

        return new StreamedResponse(function () use ($generator, $version, $url, $component, $clientChunks, $shellHead, $shellTail, $initialMarker, $scriptsMarker): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            // HTML head — send immediately so the browser starts parsing
            echo $shellHead;
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

            // Replace the placeholder scripts/initial in the tail
            $initialJson = json_encode([
                'url' => $url,
                'component' => $component,
                'version' => $version,
            ], JSON_THROW_ON_ERROR);

            $tail = str_replace(
                [$initialMarker, $scriptsMarker],
                [$initialJson, BunServiceProvider::renderRscScripts($rscPayload, $clientChunks)],
                $shellTail,
            );

            echo $tail;
            flush();
        }, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function getComponent(): string
    {
        return $this->component;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProps(): array
    {
        return $this->props;
    }

    /**
     * @return list<array{component: string, props: array<string, mixed>}>
     */
    public function getLayouts(): array
    {
        return $this->layouts;
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        return $this->viewData;
    }

    public function getVersion(): string
    {
        return $this->version ?? $this->resolveVersion();
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
