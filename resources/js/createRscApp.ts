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
import { createFromReadableStream, encodeReply } from "react-server-dom-webpack/client.browser";
import { hydrateRoot } from "react-dom/client";
import {
  setVersion,
  setNavigateHandler,
  setDeserializer,
  setCallServer,
  navigate,
  prefetch,
} from "./navigate";
import { ServerValidationError, ServerAuthorizationError } from "./errors";

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

  // Server action caller — encodes args, POSTs to the action endpoint,
  // and deserializes the Flight response
  async function callServer(id: string, args: unknown[]): Promise<unknown> {
    const encoded = await encodeReply(args);

    // When encodeReply returns FormData (e.g. form submissions), the browser
    // would send it as multipart/form-data which PHP auto-consumes from
    // php://input. We serialize to raw bytes with an opaque content-type
    // so PHP passes the body through untouched, and send the real
    // content-type in a custom header for Bun's decodeReply.
    let rawBody: BodyInit;
    let realContentType: string;

    if (encoded instanceof FormData) {
      const tmp = new Response(encoded);
      rawBody = await tmp.arrayBuffer();
      realContentType = tmp.headers.get("content-type")!;
    } else {
      rawBody = encoded;
      realContentType = "text/plain;charset=UTF-8";
    }

    const response = await fetch("/_rsc/action", {
      method: "POST",
      headers: {
        "X-RSC-Action": id,
        "X-RSC-Content-Type": realContentType,
        "Content-Type": "application/octet-stream",
        "X-XSRF-TOKEN": decodeURIComponent(
          document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ""
        ),
      },
      body: rawBody,
    });

    // Unified redirect — covers 401 (auth) and intentional redirects
    const redirect = response.headers.get("X-RSC-Redirect");
    if (redirect) {
      window.__rsc_navigate(redirect);
      return;
    }

    if (!response.ok) {
      if (response.status === 403) {
        const body = await response.json();
        throw new ServerAuthorizationError(body.message ?? "This action is unauthorized.");
      }
      if (response.status === 422) {
        const body = await response.json();
        throw new ServerValidationError(
          body.message ?? "Validation failed",
          body.errors ?? {}
        );
      }
      throw new Error(`Server action failed: ${response.status}`);
    }

    return createFromReadableStream(response.body!, { callServer });
  }

  // Inject the Flight deserializer into navigate.ts so it uses
  // the same react-server-dom-webpack instance as initial hydration
  setDeserializer(createFromReadableStream as any);
  setCallServer(callServer);

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

  const rootPromise = createFromReadableStream(stream, { callServer });

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
