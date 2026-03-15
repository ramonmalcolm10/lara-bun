<?php

namespace LaraBun\Rsc;

use Illuminate\Contracts\Support\Responsable;
use LaraBun\BunBridge;
use LaraBun\BunServiceProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RscResponse implements Responsable
{
    protected ?string $rootView = null;

    /** @var array<string, mixed> */
    protected array $viewData = [];

    protected ?string $version = null;

    protected int $statusCode = 200;

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

    public function status(int $status): static
    {
        $this->statusCode = $status;

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     */
    public function toResponse($request): \Symfony\Component\HttpFoundation\Response
    {
        $version = $this->version ?? $this->resolveVersion();

        if ($request->hasHeader(Header::X_RSC) || $request->hasHeader(Header::X_RSC_ACTION)) {
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

        // First yield is always {clientChunks, metadata} — read it eagerly
        // so we can set proper headers on the StreamedResponse object.
        $meta = $generator->current();
        $clientChunks = $meta['clientChunks'] ?? [];

        // Apply page metadata as viewData defaults (route.php viewData takes precedence)
        $this->applyMetadataDefaults($meta['metadata'] ?? null);

        $headers = [
            'Content-Type' => 'text/x-component',
            Header::X_RSC_CHUNKS => json_encode($clientChunks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            Header::X_RSC_VERSION => $version,
            'X-Accel-Buffering' => 'no',
        ];

        if (isset($this->viewData['title'])) {
            $headers[Header::X_RSC_TITLE] = rawurlencode($this->viewData['title']);
        }

        $metaData = $this->extractMetadata();

        if ($metaData !== []) {
            $headers[Header::X_RSC_META] = json_encode($metaData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

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
        }, $this->statusCode, $headers);
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

        // First yield: {clientChunks: [...], metadata: {...}}
        $meta = $generator->current();
        $clientChunks = $meta['clientChunks'] ?? [];

        // Apply page metadata as viewData defaults (route.php viewData takes precedence)
        $this->applyMetadataDefaults($meta['metadata'] ?? null);

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

        // Inject metadata tags into <head> automatically — no blade changes needed
        $metaTags = $this->buildMetaTags();

        if ($metaTags !== '' && stripos($shellHead, '</head>') !== false) {
            $shellHead = str_ireplace('</head>', $metaTags."\n</head>", $shellHead);
        }

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
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG);

            $tail = str_replace(
                [$initialMarker, $scriptsMarker],
                [$initialJson, BunServiceProvider::renderRscScripts($rscPayload, $clientChunks)],
                $shellTail,
            );

            echo $tail;
            flush();
        }, $this->statusCode, [
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

    /**
     * Apply metadata from the RSC bundle as viewData defaults.
     * Existing viewData (from route.php) takes precedence.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    protected function applyMetadataDefaults(?array $metadata): void
    {
        if ($metadata === null) {
            return;
        }

        foreach ($metadata as $key => $value) {
            if (! isset($this->viewData[$key])) {
                $this->viewData[$key] = $value;
            }
        }
    }

    /**
     * Build HTML meta tags from viewData metadata keys.
     *
     * Recognises 'title' (→ <title>), 'description' (→ <meta name="description">),
     * and any 'og:*' / 'twitter:*' keys (→ <meta property="..."> / <meta name="...">).
     */
    protected function buildMetaTags(): string
    {
        $tags = [];

        if (isset($this->viewData['title'])) {
            $tags[] = '    <title>'.e($this->viewData['title']).'</title>';
        }

        $metaKeys = ['description', 'author', 'robots'];

        foreach ($metaKeys as $key) {
            if (isset($this->viewData[$key]) && is_string($this->viewData[$key])) {
                $tags[] = '    <meta name="'.e($key).'" content="'.e($this->viewData[$key]).'">';
            }
        }

        // Keywords can be a string or array — join arrays with commas
        if (isset($this->viewData['keywords'])) {
            $keywords = is_array($this->viewData['keywords'])
                ? implode(', ', $this->viewData['keywords'])
                : $this->viewData['keywords'];
            $tags[] = '    <meta name="keywords" content="'.e($keywords).'">';
        }

        foreach ($this->viewData as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'og:')) {
                $tags[] = '    <meta property="'.e($key).'" content="'.e($value).'">';
            } elseif (str_starts_with($key, 'twitter:')) {
                $tags[] = '    <meta name="'.e($key).'" content="'.e($value).'">';
            }
        }

        return implode("\n", $tags);
    }

    /**
     * Extract metadata keys from viewData for the X-RSC-Meta header.
     *
     * @return array<string, string>
     */
    protected function extractMetadata(): array
    {
        $metadata = [];
        $metaKeys = ['title', 'description', 'author', 'robots'];

        foreach ($metaKeys as $key) {
            if (isset($this->viewData[$key]) && is_string($this->viewData[$key])) {
                $metadata[$key] = $this->viewData[$key];
            }
        }

        if (isset($this->viewData['keywords'])) {
            $metadata['keywords'] = is_array($this->viewData['keywords'])
                ? implode(', ', $this->viewData['keywords'])
                : $this->viewData['keywords'];
        }

        foreach ($this->viewData as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'og:') || str_starts_with($key, 'twitter:')) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
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
