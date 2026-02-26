import { createFromReadableStream } from "react-server-dom-webpack/client.edge";
import { renderToReadableStream } from "react-dom/server";
import { readFileSync, existsSync } from "node:fs";
import { join, dirname, basename } from "node:path";
import { PhpCallbackClient } from "./php-callback";

interface LayoutEntry {
  component: string;
  props: Record<string, unknown>;
}

const bundlePath = process.env.BUN_RSC_BUNDLE;

if (!bundlePath) {
  throw new Error("BUN_RSC_BUNDLE environment variable is not set");
}

const rscModule = await import(bundlePath);

if (typeof rscModule.renderRsc !== "function") {
  throw new Error(
    "RSC bundle does not export a renderRsc function. Rebuild with: bun run build:rsc"
  );
}

const bundleDir = dirname(bundlePath);

// ─── Load manifests (if they exist) ─────────────────────────────────────────

const clientManifestPath = join(bundleDir, "client-manifest.json");
const ssrManifestPath = join(bundleDir, "ssr-manifest.json");
const browserChunksPath = join(bundleDir, "browser-chunks.json");

let clientManifest: Record<string, unknown> | null = null;
let ssrManifest: {
  moduleMap: Record<string, Record<string, { id: string; chunks: string[]; name: string }>>;
  moduleLoading: null;
  serverModuleMap: Record<string, unknown>;
} | null = null;
let browserChunks: string[] = [];

if (existsSync(clientManifestPath)) {
  clientManifest = JSON.parse(readFileSync(clientManifestPath, "utf-8"));
  console.error("[rsc-handler] Loaded client manifest");
}

if (existsSync(ssrManifestPath)) {
  ssrManifest = JSON.parse(readFileSync(ssrManifestPath, "utf-8"));
  console.error("[rsc-handler] Loaded SSR manifest");
}

if (existsSync(browserChunksPath)) {
  browserChunks = JSON.parse(readFileSync(browserChunksPath, "utf-8"));
  console.error(`[rsc-handler] Browser chunks: ${browserChunks.join(", ")}`);
}

// ─── Shim __webpack_require__ for SSR client component resolution ───────────

// Pre-import all SSR client bundles so __webpack_require__ can resolve them
const ssrClientDir = join(bundleDir, "client");
const ssrModules: Record<string, unknown> = {};

if (ssrManifest) {
  for (const moduleId of Object.keys(ssrManifest.moduleMap)) {
    // moduleId is like "./Counter.tsx" or "lara-bun/Link.tsx"
    // SSR bundle uses basename only: client/Counter.js, client/Link.js
    const name = basename(moduleId).replace(/\.(tsx|ts|jsx|js)$/, "");
    const ssrBundlePath = join(ssrClientDir, `${name}.js`);

    if (existsSync(ssrBundlePath)) {
      try {
        ssrModules[moduleId] = await import(ssrBundlePath);
      } catch (err) {
        console.error(
          `[rsc-handler] Failed to import SSR bundle for ${moduleId}:`,
          err instanceof Error ? err.message : String(err)
        );
      }
    } else {
      console.error(
        `[rsc-handler] SSR bundle not found: ${ssrBundlePath}`
      );
    }
  }
}

// Set up globals for react-server-dom-webpack client
(globalThis as any).__webpack_require__ = function (moduleId: string) {
  const mod = ssrModules[moduleId];
  if (!mod) {
    throw new Error(
      `[rsc-handler] __webpack_require__: module not found: "${moduleId}"`
    );
  }
  return mod;
};

(globalThis as any).__webpack_chunk_load__ = function () {
  return Promise.resolve();
};

// ─── Fallback manifest for server-only mode ─────────────────────────────────

const emptyManifest = {
  serverConsumerManifest: {
    moduleMap: {},
    moduleLoading: null,
    serverModuleMap: {},
  },
};

// ─── Stream Handler (SPA navigation) ─────────────────────────────────────────

/**
 * Returns the raw Flight ReadableStream for SPA navigation.
 * PHP streams this directly to the browser with chunked transfer encoding.
 * The browser pipes response.body into createFromReadableStream() for
 * progressive rendering.
 */
export async function handleRscStream(
  component: string,
  props: Record<string, unknown>,
  callbackSocket?: string | null,
  layouts: LayoutEntry[] = []
): Promise<{ stream: ReadableStream; clientChunks: string[] }> {
  let client: PhpCallbackClient | null = null;

  if (callbackSocket) {
    client = new PhpCallbackClient();
    await client.connect(callbackSocket);
    (globalThis as any).php = client.call.bind(client);
  }

  const flightStream: ReadableStream = clientManifest
    ? rscModule.renderRscStream(component, props, clientManifest, layouts)
    : rscModule.renderRscStream(component, props, layouts);

  // Wrap the stream to clean up the callback client when done
  if (client) {
    const reader = flightStream.getReader();
    const wrappedStream = new ReadableStream({
      async pull(controller) {
        const { done, value } = await reader.read();
        if (done) {
          controller.close();
          client!.disconnect();
          delete (globalThis as any).php;
        } else {
          controller.enqueue(value);
        }
      },
      cancel() {
        reader.cancel();
        client!.disconnect();
        delete (globalThis as any).php;
      },
    });
    return { stream: wrappedStream, clientChunks: browserChunks };
  }

  return { stream: flightStream, clientChunks: browserChunks };
}

// ─── HTML Stream Handler (initial page load with Suspense streaming) ────────

/**
 * Returns an HTML ReadableStream for initial page loads with Suspense support.
 * Unlike handleRsc, this does NOT await allReady — React streams the shell
 * HTML immediately (with Suspense fallbacks), then injects completion scripts
 * as async components resolve.
 *
 * Also returns a Promise for the full Flight payload (resolves when all
 * async content is ready), needed for client-side hydration.
 */
export async function handleRscHtmlStream(
  component: string,
  props: Record<string, unknown>,
  callbackSocket?: string | null,
  layouts: LayoutEntry[] = []
): Promise<{ htmlStream: ReadableStream; rscPayloadPromise: Promise<string>; clientChunks: string[] }> {
  let client: PhpCallbackClient | null = null;

  if (callbackSocket) {
    client = new PhpCallbackClient();
    await client.connect(callbackSocket);
    (globalThis as any).php = client.call.bind(client);
  }

  // Render Flight as a stream (progressive — Suspense boundaries emit lazily)
  const flightStream: ReadableStream = clientManifest
    ? rscModule.renderRscStream(component, props, clientManifest, layouts)
    : rscModule.renderRscStream(component, props, layouts);

  // Tee: one branch for HTML SSR, one to collect the full Flight payload
  const [flightForHtml, flightForPayload] = flightStream.tee();

  // Collect the full Flight payload string (resolves when all content is ready)
  const rscPayloadPromise = new Response(flightForPayload).text();

  // Deserialize Flight → React tree (resolves once shell is parsed)
  const consumerManifest = ssrManifest
    ? { serverConsumerManifest: ssrManifest }
    : emptyManifest;

  const reactTree = await createFromReadableStream(flightForHtml, consumerManifest);

  // Render React tree to HTML stream — DO NOT await allReady.
  // React sends the shell (with Suspense fallbacks) immediately, then injects
  // <template> + <script> completion tags as async content resolves.
  const htmlStream = await renderToReadableStream(reactTree);

  // Wrap to clean up callback client when HTML stream closes
  if (client) {
    const reader = htmlStream.getReader();
    const wrappedStream = new ReadableStream({
      async pull(controller) {
        const { done, value } = await reader.read();
        if (done) {
          controller.close();
          // Flight payload should also be done by now
          await rscPayloadPromise.catch(() => {});
          client!.disconnect();
          delete (globalThis as any).php;
        } else {
          controller.enqueue(value);
        }
      },
      cancel() {
        reader.cancel();
        client!.disconnect();
        delete (globalThis as any).php;
      },
    });
    return { htmlStream: wrappedStream, rscPayloadPromise, clientChunks: browserChunks };
  }

  return { htmlStream, rscPayloadPromise, clientChunks: browserChunks };
}

// ─── Handler (buffered, non-streaming) ───────────────────────────────────────

export async function handleRsc(
  component: string,
  props: Record<string, unknown>,
  callbackSocket?: string | null,
  layouts: LayoutEntry[] = []
): Promise<{ body: string; rscPayload: string; clientChunks: string[] }> {
  // Create per-render callback client if a callback socket is provided
  let client: PhpCallbackClient | null = null;

  if (callbackSocket) {
    client = new PhpCallbackClient();
    await client.connect(callbackSocket);
    (globalThis as any).php = client.call.bind(client);
  }

  try {
    // Step 1: Render component to RSC Flight payload
    const rscPayload: string = clientManifest
      ? await rscModule.renderRsc(component, props, clientManifest, layouts)
      : await rscModule.renderRsc(component, props, layouts);

    // Step 2: Deserialize Flight payload into React element tree
    const flightStream = new ReadableStream({
      start(controller) {
        controller.enqueue(new TextEncoder().encode(rscPayload));
        controller.close();
      },
    });

    const consumerManifest = ssrManifest
      ? { serverConsumerManifest: ssrManifest }
      : emptyManifest;

    const reactTree = await createFromReadableStream(
      flightStream,
      consumerManifest
    );

    // Step 3: Render React elements to HTML
    const htmlStream = await renderToReadableStream(reactTree);
    await htmlStream.allReady;

    const body = await new Response(htmlStream).text();

    return { body, rscPayload, clientChunks: browserChunks };
  } finally {
    if (client) {
      client.disconnect();
      delete (globalThis as any).php;
    }
  }
}
