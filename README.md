# Lara Bun

A Laravel-to-Bun bridge that lets you call JavaScript/TypeScript functions from PHP over Unix sockets.

## Requirements

- PHP 8.2+ with the `sockets` extension
- Laravel 11, 12, or 13
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
| `ssr(array $page): array` | Render an Inertia page via SSR |
| `rsc(string $component, array $props = []): array` | Render a React Server Component to HTML. Returns `{body, rscPayload, clientChunks}` |
| `list(): array` | List all discovered function names |
| `ping(): bool` | Check if the Bun bridge is running |
| `disconnect(): void` | Close all socket connections |

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
    'workers' => (int) env('BUN_WORKERS', 1),
];
```

| Option | Env Variable | Default | Description |
|--------|-------------|---------|-------------|
| `socket_path` | `BUN_BRIDGE_SOCKET` | `/tmp/bun-bridge.sock` | Base path for the Unix socket(s) |
| `functions_dir` | `BUN_BRIDGE_FUNCTIONS_DIR` | `resources/bun` | Directory to scan for functions |
| `workers` | `BUN_WORKERS` | `1` | Number of Bun worker processes |
| `ssr.enabled` | `BUN_SSR_ENABLED` | `false` | Enable Bun-based Inertia SSR |
| `rsc.enabled` | `BUN_RSC_ENABLED` | `false` | Enable React Server Components rendering |
| `rsc.bundle` | `BUN_RSC_BUNDLE` | `bootstrap/rsc/entry.rsc.js` | Path to the pre-built RSC bundle |
| `rsc.source_dir` | `BUN_RSC_SOURCE_DIR` | `resources/js/rsc` | Directory containing RSC component files |
| `rsc.callables` | — | `[]` | Explicit mapping of names to PHP callables for `php()` |
| `rsc.callables_dir` | — | `null` | Directory to auto-discover PHP callables from |
| `rsc.callback_timeout` | — | `5` | Timeout in seconds for callback socket operations |
| `entry_points` | `BUN_BRIDGE_ENTRY_POINTS` | `[]` | Comma-separated paths to additional JS/TS bundles |

## Multi-Worker Support

By default, Lara Bun runs a single Bun process. Under concurrent load, `renderToString()` blocks the event loop and requests queue up sequentially. Multi-worker mode spawns N independent Bun processes on separate Unix sockets, with PHP round-robining across them for parallel rendering.

```env
BUN_WORKERS=4
```

```bash
php artisan bun:serve
# Starting Bun bridge with 4 workers
#   Worker 0: /tmp/bun-bridge-0.sock
#   Worker 1: /tmp/bun-bridge-1.sock
#   Worker 2: /tmp/bun-bridge-2.sock
#   Worker 3: /tmp/bun-bridge-3.sock
```

Each worker is a fully isolated Bun process. If a worker crashes, it is automatically restarted. Requests that hit an unavailable worker fail over to the next one.

### Socket naming

| Workers | Socket path(s) |
|---------|----------------|
| 1 | `/tmp/bun-bridge.sock` |
| N | `/tmp/bun-bridge-0.sock` ... `/tmp/bun-bridge-{N-1}.sock` |

### Recommended workers

A good starting point is matching your Octane worker count, or the number of CPU cores available for SSR rendering.

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

## React Server Components

Lara Bun can render React Server Components (RSC) to HTML via the Unix socket. Async server components run in Bun, fetch data server-side, and return fully rendered HTML with zero client JavaScript.

### 1. Create server components

Place your server components in `resources/js/rsc/` (configurable via `rsc.source_dir`). Each file's default export becomes a callable component, using the filename as the component name:

```tsx
// resources/js/rsc/Greeting.tsx → callable as "Greeting"
export default async function Greeting({ name }: { name: string }) {
  return <h1>Hello, {name}!</h1>;
}
```

```tsx
// resources/js/rsc/user-profile.tsx → callable as "user-profile"
export default async function UserProfile({ id }: { id: number }) {
  const user = await fetch(`https://api.example.com/users/${id}`).then(r => r.json());
  return <div>{user.name}</div>;
}
```

### 2. Build the RSC bundle

The package includes a build script that auto-discovers all components in your `rsc/` directory, generates the entry file, and builds the bundle:

```bash
bun vendor/ramonmalcolm10/lara-bun/resources/build-rsc.ts
```

Or with custom paths:

```bash
bun vendor/ramonmalcolm10/lara-bun/resources/build-rsc.ts resources/js/rsc bootstrap/rsc
```

This produces `bootstrap/rsc/entry.rsc.js`. Files prefixed with `_`, or containing `.test.`/`.spec.` are excluded.

### 3. Enable RSC

Add to your `.env`:

```env
BUN_RSC_ENABLED=true
```

### 4. Call from PHP

```php
use RamonMalcolm\LaraBun\BunBridge;

$result = $bridge->rsc('Greeting', ['name' => 'World']);

// $result['body']       → rendered HTML string
// $result['rscPayload'] → React Flight payload for hydration
```

### How RSC rendering works

```
PHP: $bridge->rsc('Component', $props)
  → Unix socket → worker.ts "rsc" handler
    → rsc-handler.ts loads pre-built RSC bundle
    → renderRsc() → Flight payload (serialized React tree)
    → createFromReadableStream() → deserialize into React elements
    → renderToReadableStream() → HTML string
  ← returns { body, rscPayload }
```

The Flight protocol serializes the React component tree (including async components) into a streamable format. The handler deserializes it back into React elements and renders them to HTML. Both the rendered HTML and the raw Flight payload are returned, allowing you to use the HTML directly or hydrate on the client if needed.

### Client components (`"use client"`)

Server components are non-interactive by default. To add interactivity, create a component with `"use client"` at the top and import it from a server component:

```tsx
// resources/js/rsc/Counter.tsx — client component
"use client";

import { useState } from "react";

export default function Counter() {
  const [count, setCount] = useState(0);
  return <button onClick={() => setCount(c => c + 1)}>Count: {count}</button>;
}
```

```tsx
// resources/js/rsc/Dashboard.tsx — server component
import Counter from "./Counter";

export default async function Dashboard() {
  return (
    <div>
      <h1>Dashboard</h1>
      <Counter />
    </div>
  );
}
```

The build script (`build-rsc.ts`) automatically detects `"use client"` files and generates:
- **Server bundle** — client imports become Flight-serializable proxies
- **SSR bundles** — for server-side HTML rendering of client components
- **Browser bundles** — for client-side hydration

### Hydrating client components

Use the `@rscScripts` Blade directive to inject the hydration scripts:

```blade
<div id="rsc-root">{!! $body !!}</div>

@rscScripts($rscPayload, $clientChunks)
```

The directive renders the Flight payload and module script tags needed for `react-server-dom-webpack` to hydrate client component boundaries in the browser.

### Calling PHP from server components (`php()`)

Server components can call PHP functions directly during rendering — no HTTP requests needed. Calls go over a dedicated Unix socket back to the PHP process, execute Eloquent queries or service methods, and return results inline.

#### 1. Register callables

**Option A: Explicit mapping** in `config/bun.php`:

```php
'rsc' => [
    'callables' => [
        'getUser' => [App\Rsc\Callables\UserCallable::class, 'getUser'],
        'getPosts' => App\Rsc\Callables\PostCallable::class, // invokable
    ],
],
```

**Option B: Auto-discover from a directory:**

```php
'rsc' => [
    'callables_dir' => app_path('Rsc/Callables'),
],
```

Auto-discovered names follow the pattern `ClassName.methodName`. For example, a class `UserCallable` with a `getUser` method becomes callable as `UserCallable.getUser`. Invokable classes (`__invoke`) are registered as just `ClassName`. Explicit registrations take precedence.

#### 2. Create a callable class

```php
// app/Rsc/Callables/UserCallable.php
namespace App\Rsc\Callables;

use App\Models\User;

class UserCallable
{
    public function getUser(array $args): array
    {
        return User::findOrFail($args['id'])->toArray();
    }
}
```

Callables are resolved through the container, so constructor injection works.

#### 3. Call from a server component

```tsx
// resources/js/rsc/Dashboard.tsx
export default async function Dashboard({ userId }: { userId: number }) {
  const user = await php('UserCallable.getUser', { id: userId });
  return <div>{user.name}</div>;
}
```

The `php()` function is available as a global during RSC rendering. Add the type reference for editor support:

```tsx
/// <reference path="../../../vendor/ramonmalcolm10/lara-bun/resources/php.d.ts" />
```

#### How callbacks work

```
PHP                                          Bun
────                                         ────
1. Create temp callback socket
   /tmp/rsc-cb-{random}.sock

2. Send RSC request with callbackSocket ───> Receive request, connect to callback socket

3. socket_select() loop                      Component calls php('getUser', {id:1})
   monitoring main + callback sockets  <──── Send callback request on callback socket
   Execute PHP callable via registry
   Send response back on callback    ──────> Receive response, resume rendering

   ... repeat for more callbacks ...

4. select() fires on main socket     <────── Render complete, send final result
   Return {body, rscPayload, clientChunks}
```

Each render creates a unique callback socket path, so concurrent Octane requests don't interfere. Both sides clean up sockets in `finally` blocks.

## Development Server

When using `php artisan serve`, enable multiple workers so streaming responses don't block concurrent requests:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload
```

PHP's built-in server is single-threaded by default. Without multiple workers, navigating away from a page that is still streaming (e.g. Suspense fallbacks resolving) will block until the stream completes. This is only a development concern — production servers (nginx + php-fpm, Octane, Herd) handle concurrent requests natively.

## Laravel Octane

If you're using Laravel Octane, add `BunBridge` to the `warm` array in `config/octane.php` to keep the socket connection alive across requests:

```php
'warm' => [
    ...Octane::defaultServicesToWarm(),
    \RamonMalcolm\LaraBun\BunBridge::class,
],
```

## Performance

Benchmarked against Inertia's default HTTP SSR (`php artisan inertia:start-ssr --runtime=bun`) with 100 iterations, no warmup:

| | Avg | Min | Max | PHP Memory |
|---|---|---|---|---|
| **Lara Bun (Unix Socket)** | **2.39ms** | **1.73ms** | **4.75ms** | +0MB |
| Inertia HTTP SSR (Bun) | 3.36ms | 2.32ms | 19.47ms | +12.5MB |

**~30% faster** with zero additional PHP memory overhead. Unix sockets skip the TCP stack entirely — communication is just memory copies in the kernel.

### Worker memory

Each Bun worker uses **~10MB RSS** under load. Memory plateaus at ~10MB under load, then GC kicks in and drops it back down to ~3MB. No memory leak — Bun's JavaScriptCore garbage collector is cleaning up properly. After 16,000 SSR renders the process is using less memory than when it started.

| Workers | Memory |
|---|---|
| 1 | ~10MB |
| 4 | ~40MB |

## How It Works

1. `bun:serve` starts one or more Bun processes, each with a bundled TypeScript worker
2. Each worker scans your functions directory and registers all exported functions
3. PHP communicates with Bun over Unix sockets using length-prefixed binary frames
4. The `BunBridge` singleton maintains persistent socket connections and round-robins across workers

## Support

If this saved you time, consider supporting the project:

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-yellow?logo=buy-me-a-coffee&logoColor=white)](https://buymeacoffee.com/ramonmalcolm)

## License

MIT
