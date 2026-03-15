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

// Load the SSR module map (moduleId -> filename) if available
const ssrModuleMapPath = join(bundleDir, "ssr-module-map.json");
const ssrModuleFileMap: Record<string, string> = existsSync(ssrModuleMapPath)
  ? JSON.parse(readFileSync(ssrModuleMapPath, "utf-8"))
  : {};

if (ssrManifest) {
  for (const moduleId of Object.keys(ssrManifest.moduleMap)) {
    // Use the module map if available, otherwise fall back to basename
    const fileName = ssrModuleFileMap[moduleId]
      ?? basename(moduleId).replace(/\.(tsx|ts|jsx|js|mjs|cjs)$/, "") + ".js";
    const ssrBundlePath = join(ssrClientDir, fileName);

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

// Register server action modules as stubs for SSR reference resolution.
// Actions aren't called during SSR — they're serialized as references for the browser.
const actionManifestPath = join(bundleDir, "action-manifest.json");

if (existsSync(actionManifestPath)) {
  const actionManifest: Record<string, string[]> = JSON.parse(
    readFileSync(actionManifestPath, "utf-8")
  );

  for (const [moduleId, exports] of Object.entries(actionManifest)) {
    const stub: Record<string, Function> = {};

    for (const name of exports) {
      stub[name] = () => {
        throw new Error(`Server action ${moduleId}#${name} cannot be called during SSR`);
      };
    }

    ssrModules[moduleId] = stub;
  }

  console.error(`[rsc-handler] Registered ${Object.keys(actionManifest).length} action module(s) for SSR`);
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

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Set up a per-render callback client and install globalThis.php.
 * Returns a cleanup function that only removes globalThis.php if it's
 * still pointing to THIS render's function (prevents race conditions
 * when a refresh/new request overwrites it while an old render cleans up).
 */
function installPhp(client: PhpCallbackClient): () => void {
  const phpFn = client.call.bind(client);
  (globalThis as any).php = phpFn;

  return () => {
    try { client.disconnect(); } catch {}
    if ((globalThis as any).php === phpFn) {
      delete (globalThis as any).php;
    }
  };
}

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
  let cleanup: (() => void) | null = null;

  if (callbackSocket) {
    const client = new PhpCallbackClient();
    await client.connect(callbackSocket);
    cleanup = installPhp(client);
  }

  const flightStream: ReadableStream = clientManifest
    ? rscModule.renderRscStream(component, props, clientManifest, layouts)
    : rscModule.renderRscStream(component, props, layouts);

  // Wrap the stream to clean up the callback client when done
  if (cleanup) {
    const cleanupFn = cleanup;
    const reader = flightStream.getReader();
    const wrappedStream = new ReadableStream({
      async pull(controller) {
        const { done, value } = await reader.read();
        if (done) {
          controller.close();
          cleanupFn();
        } else {
          controller.enqueue(value);
        }
      },
      cancel() {
        reader.cancel();
        cleanupFn();
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
): Promise<{
  htmlStream: ReadableStream;
  rscPayloadPromise: Promise<string>;
  clientChunks: string[];
  flushCallbacks?: () => void;
}> {
  let cleanup: (() => void) | null = null;
  let flushCallbacks: (() => void) | undefined;

  if (callbackSocket) {
    // Connect immediately so PHP's callback acceptance doesn't block,
    // but defer php() call execution to enable Suspense streaming.
    // React sees unresolved Promises, hits Suspense boundaries, and emits
    // fallback HTML first. After the first HTML chunk, flushCallbacks()
    // sends queued calls to PHP and results stream in progressively.
    const client = new PhpCallbackClient();
    await client.connect(callbackSocket);

    const pendingCalls: Array<{
      fn: string;
      args: unknown[];
      resolve: (value: unknown) => void;
      reject: (reason: Error) => void;
    }> = [];
    let flushed = false;

    const flush = () => {
      if (flushed) return;
      flushed = true;
      for (const call of pendingCalls) {
        client.call(call.fn, ...call.args).then(call.resolve, call.reject);
      }
      pendingCalls.length = 0;
    };

    // Auto-flush after 100ms to prevent deadlock when no Suspense boundary
    // exists. Once flushed, php() calls execute directly without queueing.
    const autoFlushTimer = setTimeout(flush, 100);

    const deferredPhpFn = (functionName: string, ...args: unknown[]): Promise<unknown> => {
      if (flushed) {
        return client.call(functionName, ...args);
      }
      return new Promise((resolve, reject) => {
        pendingCalls.push({ fn: functionName, args, resolve, reject });
      });
    };
    (globalThis as any).php = deferredPhpFn;

    flushCallbacks = () => {
      clearTimeout(autoFlushTimer);
      flush();
    };

    cleanup = () => {
      clearTimeout(autoFlushTimer);
      flush();
      try { client.disconnect(); } catch {}
      if ((globalThis as any).php === deferredPhpFn) {
        delete (globalThis as any).php;
      }
    };
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
  if (cleanup) {
    const cleanupFn = cleanup;
    const reader = htmlStream.getReader();
    const wrappedStream = new ReadableStream({
      async pull(controller) {
        const { done, value } = await reader.read();
        if (done) {
          controller.close();
          await rscPayloadPromise.catch(() => {});
          cleanupFn();
        } else {
          controller.enqueue(value);
        }
      },
      cancel() {
        reader.cancel();
        cleanupFn();
      },
    });
    return { htmlStream: wrappedStream, rscPayloadPromise, clientChunks: browserChunks, flushCallbacks };
  }

  return { htmlStream, rscPayloadPromise, clientChunks: browserChunks, flushCallbacks };
}

// ─── Action Handler (server actions) ──────────────────────────────────────────

/**
 * Handles a server action call.
 * Decodes client-encoded arguments, executes the action function, and
 * returns the result wrapped in a Flight payload stream.
 */
export async function handleAction(
  actionId: string,
  body: string,
  contentType: string,
  callbackSocket?: string | null
): Promise<{ stream: ReadableStream }> {
  if (typeof rscModule.getServerAction !== "function") {
    throw new Error("No server actions registered. Rebuild with: bun run build:rsc");
  }

  // actionId format: "moduleId#exportName" (e.g., "./actions.ts#addTodo")
  const hashIndex = actionId.indexOf("#");

  if (hashIndex === -1) {
    throw new Error(`Invalid action ID format: "${actionId}" (expected "moduleId#exportName")`);
  }

  const moduleId = actionId.slice(0, hashIndex);
  const exportName = actionId.slice(hashIndex + 1);

  const actionFn = rscModule.getServerAction(moduleId, exportName);

  if (!actionFn) {
    throw new Error(`Unknown server action: "${actionId}"`);
  }

  let cleanup: (() => void) | null = null;

  if (callbackSocket) {
    const client = new PhpCallbackClient();
    await client.connect(callbackSocket);
    cleanup = installPhp(client);
  }

  try {
    // Reconstruct the original body format for decodeReply.
    // The client serializes FormData to raw bytes and sends with an opaque
    // content-type to prevent PHP from consuming the body. We use the real
    // content-type header to reconstruct FormData when needed.
    let decodable: string | FormData;

    if (contentType.includes("multipart/form-data")) {
      const response = new Response(body, {
        headers: { "Content-Type": contentType },
      });
      decodable = await response.formData();
    } else {
      decodable = body;
    }

    const args = await rscModule.decodeReply(decodable);
    const result = await actionFn(...(args as unknown[]));

    const stream = rscModule.renderActionStream(result, clientManifest ?? {});

    // Wrap to clean up callback client when stream closes
    if (cleanup) {
      const cleanupFn = cleanup;
      const reader = stream.getReader();
      const wrappedStream = new ReadableStream({
        async pull(controller) {
          const { done, value } = await reader.read();
          if (done) {
            controller.close();
            cleanupFn();
          } else {
            controller.enqueue(value);
          }
        },
        cancel() {
          reader.cancel();
          cleanupFn();
        },
      });
      return { stream: wrappedStream };
    }

    return { stream };
  } catch (err) {
    cleanup?.();
    throw err;
  }
}

// ─── Handler (buffered, non-streaming) ───────────────────────────────────────

export async function handleRsc(
  component: string,
  props: Record<string, unknown>,
  callbackSocket?: string | null,
  layouts: LayoutEntry[] = []
): Promise<{ body: string; rscPayload: string; clientChunks: string[]; usedDynamicApis: boolean }> {
  // Create per-render callback client if a callback socket is provided
  let cleanup: (() => void) | null = null;
  let usedDynamicApis = false;

  if (callbackSocket) {
    const client = new PhpCallbackClient();
    await client.connect(callbackSocket);
    const originalCall = client.call.bind(client);

    const phpFn = (...args: unknown[]) => {
      usedDynamicApis = true;
      return originalCall(...args);
    };
    (globalThis as any).php = phpFn;

    cleanup = () => {
      try { client.disconnect(); } catch {}
      if ((globalThis as any).php === phpFn) {
        delete (globalThis as any).php;
      }
    };
  }

  // Track dynamic API usage during render.
  // Any call to these APIs during prerender marks the page as dynamic,
  // similar to how Next.js detects dynamic rendering at build time.
  const originalFetch = globalThis.fetch;
  const originalMathRandom = Math.random;
  const OriginalDate = globalThis.Date;
  const originalRandomUUID = crypto.randomUUID.bind(crypto);
  const originalGetRandomValues = crypto.getRandomValues.bind(crypto);

  const markDynamic = () => { usedDynamicApis = true; };

  globalThis.fetch = ((...args: Parameters<typeof fetch>) => {
    markDynamic();
    return originalFetch(...args);
  }) as typeof fetch;

  Math.random = () => {
    markDynamic();
    return originalMathRandom();
  };

  // Proxy Date to detect new Date() (no args), Date() as function, and Date.now()
  globalThis.Date = new Proxy(OriginalDate, {
    construct(target, args) {
      if (args.length === 0) markDynamic();
      return Reflect.construct(target, args);
    },
    apply(target, thisArg, args) {
      // Date() called without new always returns current time string
      markDynamic();
      return Reflect.apply(target, thisArg, args);
    },
    get(target, prop, receiver) {
      if (prop === "now") {
        return () => { markDynamic(); return OriginalDate.now(); };
      }
      return Reflect.get(target, prop, receiver);
    },
  }) as DateConstructor;

  crypto.randomUUID = () => {
    markDynamic();
    return originalRandomUUID();
  };

  crypto.getRandomValues = <T extends ArrayBufferView | null>(array: T): T => {
    markDynamic();
    return originalGetRandomValues(array);
  };

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

    return { body, rscPayload, clientChunks: browserChunks, usedDynamicApis };
  } finally {
    globalThis.fetch = originalFetch;
    Math.random = originalMathRandom;
    globalThis.Date = OriginalDate;
    crypto.randomUUID = originalRandomUUID;
    crypto.getRandomValues = originalGetRandomValues;
    cleanup?.();
  }
}
