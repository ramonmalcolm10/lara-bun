import { createFromReadableStream } from "react-server-dom-webpack/client.edge";
import { renderToReadableStream } from "react-dom/server";
import { readFileSync, existsSync } from "node:fs";
import { join, dirname } from "node:path";
import { PhpCallbackClient } from "./php-callback";

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
    // moduleId is like "./Counter.tsx" — SSR bundle is at client/Counter.js
    const name = moduleId
      .replace(/^\.\//, "")
      .replace(/\.(tsx|ts|jsx|js)$/, "");
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

// ─── Handler ────────────────────────────────────────────────────────────────

export async function handleRsc(
  component: string,
  props: Record<string, unknown>,
  callbackSocket?: string | null
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
      ? await rscModule.renderRsc(component, props, clientManifest)
      : await rscModule.renderRsc(component, props);

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
