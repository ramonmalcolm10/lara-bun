/**
 * Initializes the RSC SPA application.
 *
 * Called from the generated hydration entry. Sets up:
 * - Module registration for __webpack_require__
 * - Window globals for Link.tsx communication
 * - Initial hydration from Flight payload
 * - Navigation handler for subsequent SPA transitions
 * - Popstate listener for browser back/forward
 *
 * This file is the single import site for react-server-dom-webpack/client.browser
 * to avoid duplicate bundling by the Bun bundler.
 */
import { createFromReadableStream } from "react-server-dom-webpack/client.browser";
import { hydrateRoot } from "react-dom/client";
import {
  setVersion,
  setNavigateHandler,
  setDeserializer,
  navigate,
  prefetch,
} from "./navigate";

declare global {
  interface Window {
    __RSC_PAYLOAD__: string;
    __RSC_INITIAL__: { url: string; component: string; version: string };
    __RSC_MODULES__: Record<string, unknown>;
    __webpack_require__: (id: string) => unknown;
    __webpack_chunk_load__: () => Promise<void>;
    __rsc_navigate: typeof navigate;
    __rsc_prefetch: typeof prefetch;
  }
}

export function createRscApp(
  container: HTMLElement,
  initialModules: Record<string, unknown>
): void {
  // Register all client component modules
  for (const [id, mod] of Object.entries(initialModules)) {
    window.__RSC_MODULES__[id] = mod;
  }

  // Inject the Flight deserializer into navigate.ts so it uses
  // the same react-server-dom-webpack instance as initial hydration
  setDeserializer(createFromReadableStream as any);

  // Expose navigation globals for Link.tsx (cross-build-graph communication)
  window.__rsc_navigate = navigate;
  window.__rsc_prefetch = prefetch;

  // Read initial state
  const initial = window.__RSC_INITIAL__;
  if (initial?.version) {
    setVersion(initial.version);
  }

  // Deserialize the initial Flight payload
  const rscPayload = window.__RSC_PAYLOAD__;
  if (!rscPayload) {
    return;
  }

  const stream = new ReadableStream({
    start(controller) {
      controller.enqueue(new TextEncoder().encode(rscPayload));
      controller.close();
    },
  });

  const rootPromise = createFromReadableStream(stream, {
    callServer: async () => {
      throw new Error("Server actions not supported");
    },
  });

  Promise.resolve(rootPromise).then((reactTree: any) => {
    const root = hydrateRoot(container, reactTree);

    // Wire up SPA navigation: subsequent navigations re-render the root
    setNavigateHandler((newTree: any) => {
      root.render(newTree);
    });

    // Handle browser back/forward
    window.addEventListener("popstate", (event) => {
      const url = event.state?.rscUrl ?? window.location.pathname + window.location.search;
      navigate(url, { replace: true });
    });

    // Set initial history state
    history.replaceState(
      { rscUrl: initial?.url ?? window.location.pathname + window.location.search },
      ""
    );
  });
}
