/**
 * Core SPA navigation engine for RSC.
 *
 * Uses module-level state (singleton in the browser bundle).
 * The Flight deserializer is injected by createRscApp to avoid
 * duplicate bundling of react-server-dom-webpack.
 */

type ReactNode = unknown;
type Deserializer = (stream: ReadableStream, options: Record<string, unknown>) => Promise<ReactNode>;

interface CacheEntry {
  tree: Promise<ReactNode>;
  expiresAt: number;
}

let version = "";
let onNavigate: ((tree: ReactNode) => void) | null = null;
let flightDeserializer: Deserializer | null = null;
const cache = new Map<string, CacheEntry>();

const DEFAULT_PREFETCH_TTL = 30_000;

export function setVersion(v: string): void {
  version = v;
}

export function setNavigateHandler(fn: (tree: ReactNode) => void): void {
  onNavigate = fn;
}

export function setDeserializer(fn: Deserializer): void {
  flightDeserializer = fn;
}

function fetchRscPayload(url: string): Promise<Response> {
  return fetch(url, {
    headers: {
      "X-RSC": "true",
      "X-RSC-Version": version,
    },
  }).then((response) => {
    if (response.status === 409) {
      const location = response.headers.get("X-RSC-Location");
      window.location.href = location ?? url;
      throw new Error("Version mismatch — full reload triggered");
    }

    if (!response.ok) {
      // Server returned an error — fall back to full page navigation
      // instead of trying to parse HTML as Flight payload
      window.location.href = url;
      throw new Error(`RSC navigation failed: ${response.status}`);
    }

    return response;
  });
}

function deserializeResponse(response: Response): Promise<ReactNode> {
  const chunksHeader = response.headers.get("X-RSC-Chunks");

  if (chunksHeader) {
    try {
      const chunks: string[] = JSON.parse(chunksHeader);
      const existingScripts = new Set(
        Array.from(document.querySelectorAll<HTMLScriptElement>("script[src]"))
          .map((s) => s.src)
      );

      for (const chunk of chunks) {
        const absoluteUrl = new URL(chunk, window.location.origin).href;
        if (!existingScripts.has(absoluteUrl)) {
          const script = document.createElement("script");
          script.type = "module";
          script.src = chunk;
          document.head.appendChild(script);
        }
      }
    } catch {
      // Ignore malformed chunks header
    }
  }

  return flightDeserializer!(response.body!, {
    callServer: async () => {
      throw new Error("Server actions not supported");
    },
  });
}

export async function navigate(
  url: string,
  opts?: { replace?: boolean }
): Promise<void> {
  const cached = cache.get(url);
  let treePromise: Promise<ReactNode>;

  if (cached && cached.expiresAt > Date.now()) {
    treePromise = cached.tree;
    cache.delete(url);
  } else {
    cache.delete(url);
    const response = await fetchRscPayload(url);
    treePromise = deserializeResponse(response);
  }

  const tree = await treePromise;

  if (opts?.replace) {
    history.replaceState({ rscUrl: url }, "", url);
  } else {
    history.pushState({ rscUrl: url }, "", url);
  }

  onNavigate?.(tree);
}

export function prefetch(url: string, cacheForMs?: number): void {
  const ttl = cacheForMs ?? DEFAULT_PREFETCH_TTL;
  const existing = cache.get(url);

  if (existing && existing.expiresAt > Date.now()) {
    return;
  }

  const tree = fetchRscPayload(url).then((response) =>
    deserializeResponse(response)
  );

  cache.set(url, {
    tree,
    expiresAt: Date.now() + ttl,
  });
}
