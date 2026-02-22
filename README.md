# Lara Bun

A Laravel-to-Bun bridge that lets you call JavaScript/TypeScript functions from PHP over Unix sockets.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- [Bun](https://bun.sh) runtime

## Installation

```bash
composer require ramonmalcolm10/lara-bun
```

The service provider and `Bun` facade are auto-discovered.

## Setup

### 1. Create a functions directory

Place your TypeScript or JavaScript functions in `resources/bun/`:

```
resources/bun/
  greet.ts
  math.ts
```

Each exported function becomes callable from PHP:

```ts
// resources/bun/greet.ts
export function greet({ name }: { name: string }) {
  return `Hello, ${name}!`;
}
```

### 2. Start the bridge

```bash
php artisan bun:serve
```

This starts a Bun process that listens on a Unix socket, auto-discovers all `.ts`/`.js` files in your functions directory, and waits for calls from PHP.

## Usage

### Via dependency injection

```php
use RamonMalcolm\LaraBun\BunBridge;

class MyController extends Controller
{
    public function index(BunBridge $bridge)
    {
        $greeting = $bridge->call('greet', ['name' => 'World']);

        return view('welcome', ['greeting' => $greeting]);
    }
}
```

### Via the Bun facade

```php
use RamonMalcolm\LaraBun\Facades\Bun;

$greeting = Bun::call('greet', ['name' => 'World']);

$available = Bun::list();

$isRunning = Bun::ping();
```

### API

| Method | Description |
|--------|-------------|
| `call(string $function, array $args = []): mixed` | Call a Bun function by name |
| `list(): array` | List all discovered function names |
| `ping(): bool` | Check if the Bun bridge is running |
| `disconnect(): void` | Close the socket connection |

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=lara-bun-config
```

This creates `config/bun.php`:

```php
return [
    'socket_path' => env('BUN_BRIDGE_SOCKET', '/tmp/bun-bridge.sock'),
    'functions_dir' => env('BUN_BRIDGE_FUNCTIONS_DIR', resource_path('bun')),
];
```

| Option | Env Variable | Default | Description |
|--------|-------------|---------|-------------|
| `socket_path` | `BUN_BRIDGE_SOCKET` | `/tmp/bun-bridge.sock` | Path to the Unix socket |
| `functions_dir` | `BUN_BRIDGE_FUNCTIONS_DIR` | `resources/bun` | Directory to scan for functions |
| `ssr.enabled` | `BUN_SSR_ENABLED` | `false` | Enable Bun-based Inertia SSR |
| `entry_points` | `BUN_BRIDGE_ENTRY_POINTS` | `[]` | Comma-separated paths to additional JS/TS bundles |

## Artisan Command

```bash
# Start with default settings
php artisan bun:serve

# Override socket path
php artisan bun:serve --socket=/tmp/my-socket.sock
```

## Inertia SSR

Lara Bun can handle Inertia server-side rendering through the Unix socket instead of running a separate Node HTTP server.

### 1. Update your SSR entry point

The standard Inertia SSR entry point uses `createServer()` to start an HTTP server. For Lara Bun, export a `render` function instead:

**React** (`resources/js/ssr.jsx`):

```jsx
import { createInertiaApp } from '@inertiajs/react'
import ReactDOMServer from 'react-dom/server'

export async function render(page) {
    return createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true })
            return pages[`./Pages/${name}.jsx`]
        },
        setup: ({ App, props }) => <App {...props} />,
    })
}
```

**Vue** (`resources/js/ssr.js`):

```js
import { createInertiaApp } from '@inertiajs/vue3'
import { renderToString } from 'vue/server-renderer'
import { createSSRApp, h } from 'vue'

export async function render(page) {
    return createInertiaApp({
        page,
        render: renderToString,
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
            return pages[`./Pages/${name}.vue`]
        },
        setup({ App, props, plugin }) {
            return createSSRApp({ render: () => h(App, props) }).use(plugin)
        },
    })
}
```

**Svelte** (`resources/js/ssr.js`):

```js
import { createInertiaApp } from '@inertiajs/svelte'
import { render as svelteRender } from 'svelte/server'

export async function render(page) {
    return createInertiaApp({
        page,
        resolve: name => {
            const pages = import.meta.glob('./Pages/**/*.svelte', { eager: true })
            return pages[`./Pages/${name}.svelte`]
        },
        setup({ App, props }) {
            return svelteRender(App, { props })
        },
    })
}
```

The key difference: no `createServer()` import — just export the `render` function directly.

### 2. Build the SSR bundle

```bash
bun run build
```

This produces `bootstrap/ssr/ssr.js`.

### 3. Enable Bun SSR

Add to your `.env`:

```env
BUN_SSR_ENABLED=true
```

The bundle path is read from Inertia's `inertia.ssr.bundle` config. If Inertia isn't configured, it falls back to `bootstrap/ssr/ssr.mjs`.

### 4. Start the bridge

```bash
php artisan bun:serve
```

The command automatically passes the SSR bundle (along with any other configured entry points) to the Bun worker. When `inertiajs/inertia-laravel` is installed, the `Inertia\Ssr\Gateway` binding is automatically replaced with `BunSsrGateway`, which routes render calls through the Unix socket.

No separate SSR server is needed — `bun:serve` handles both your custom functions and Inertia SSR.

### Custom entry points

You can also load arbitrary JS/TS bundles beyond the SSR bundle using the `BUN_BRIDGE_ENTRY_POINTS` env var (comma-separated paths):

```env
BUN_BRIDGE_ENTRY_POINTS=dist/my-bundle.js,dist/another.js
```

Each exported function from these files becomes callable via `BunBridge::call()`.

## Laravel Octane

If you're using Laravel Octane, add `BunBridge` to the `warm` array in `config/octane.php` to keep the socket connection alive across requests:

```php
'warm' => [
    ...Octane::defaultServicesToWarm(),
    \RamonMalcolm\LaraBun\BunBridge::class,
],
```

## How It Works

1. `bun:serve` starts a Bun process with a bundled TypeScript worker
2. The worker scans your functions directory and registers all exported functions
3. PHP communicates with Bun over a Unix socket using newline-delimited JSON
4. The `BunBridge` singleton maintains a persistent socket connection for fast, repeated calls

## License

MIT
