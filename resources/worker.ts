import { unlinkSync } from "node:fs";
import { join, resolve } from "node:path";
import { ServerAuthenticationError, ServerAuthorizationError, ServerRedirectError, ServerValidationError } from "./errors";

type MessageHandler = (args: Record<string, unknown>) => unknown;

const functions: Record<string, MessageHandler> = {};

interface LayoutEntry {
  component: string;
  props: Record<string, unknown>;
}

interface IncomingMessage {
  type: "ping" | "call" | "list" | "ssr" | "rsc" | "rsc-stream" | "rsc-html-stream" | "rsc-action";
  function?: string;
  args?: Record<string, unknown>;
  page?: Record<string, unknown>;
  component?: string;
  props?: Record<string, unknown>;
  layouts?: LayoutEntry[];
  callbackSocket?: string;
  actionId?: string;
  body?: string;
  contentType?: string;
}

function log(...args: unknown[]): void {
  console.error("[bun-bridge]", ...args);
}

async function discoverFunctions(dir: string): Promise<void> {
  const glob = new Bun.Glob("**/*.{ts,js}");

  for await (const path of glob.scan(dir)) {
    const mod = await import(join(dir, path));

    for (const [name, fn] of Object.entries(mod)) {
      if (typeof fn !== "function") continue;

      if (name in functions) {
        log(`Warning: duplicate function "${name}" from ${path}, skipping`);
        continue;
      }

      functions[name] = fn as MessageHandler;
    }
  }
}

async function loadEntryPoints(): Promise<void> {
  const raw = process.env.BUN_BRIDGE_ENTRY_POINTS ?? "";
  const paths = raw.split(",").map((p) => p.trim()).filter(Boolean);

  for (const entryPath of paths) {
    const absolute = resolve(entryPath);

    try {
      const mod = await import(absolute);

      for (const [name, fn] of Object.entries(mod)) {
        if (name === "default" || typeof fn !== "function" || name.length <= 2) continue;

        if (name in functions) {
          log(`Warning: duplicate function "${name}" from ${entryPath}, skipping`);
          continue;
        }

        functions[name] = fn as MessageHandler;
      }

      if (typeof mod.default === "function" && !("default" in functions)) {
        const baseName = absolute.split("/").pop()?.replace(/\.[^.]+$/, "") ?? "default";
        const fnName = baseName in functions ? "default" : baseName;

        if (fnName in functions) {
          log(`Warning: duplicate function "${fnName}" from ${entryPath}, skipping`);
        } else {
          functions[fnName] = mod.default as MessageHandler;
        }
      }
    } catch (err) {
      log(`Failed to load entry point "${entryPath}":`, err instanceof Error ? err.message : String(err));
    }
  }
}

async function handleMessage(message: IncomingMessage): Promise<string> {
  switch (message.type) {
    case "ping":
      return '{"type":"pong"}';

    case "call": {
      const fn = functions[message.function ?? ""];
      if (!fn) {
        return JSON.stringify({
          error: `Function "${message.function}" not found. Available: ${Object.keys(functions).join(", ")}`,
        });
      }
      try {
        const result = await fn(message.args ?? {});
        return JSON.stringify({ result });
      } catch (err) {
        return JSON.stringify({
          error: err instanceof Error ? err.message : String(err),
        });
      }
    }

    case "ssr": {
      const page = message.page;
      if (!page) {
        return '{"error":"Missing page in SSR message"}';
      }

      const renderFn = functions["render"];
      if (!renderFn) {
        return '{"error":"SSR render function not found. Ensure the SSR bundle is loaded."}';
      }

      try {
        const result = await renderFn(page);
        return JSON.stringify({ result });
      } catch (err) {
        return JSON.stringify({
          error: err instanceof Error ? err.message : String(err),
        });
      }
    }

    case "rsc": {
      if (!rscHandler) {
        return '{"error":"RSC not enabled. Set BUN_RSC_ENABLED=true and run: bun run build:rsc"}';
      }
      if (!message.component) {
        return '{"error":"Missing component in RSC message"}';
      }
      try {
        const result = await rscHandler.handleRsc(
          message.component,
          message.props ?? {},
          message.callbackSocket ?? null,
          message.layouts ?? []
        );
        return JSON.stringify({ result });
      } catch (err) {
        return JSON.stringify({
          error: err instanceof Error ? err.message : String(err),
        });
      }
    }

    case "list":
      return JSON.stringify({ result: Object.keys(functions) });

    default:
      return JSON.stringify({ error: `Unknown message type: "${message.type}"` });
  }
}

const functionsDir = process.env.BUN_BRIDGE_FUNCTIONS_DIR;
const socketPath = process.env.BUN_BRIDGE_SOCKET ?? "/tmp/bun-bridge.sock";

if (functionsDir) {
  await discoverFunctions(functionsDir);
}

await loadEntryPoints();

// Load RSC handler if a bundle is configured
type RscHandlerModule = {
  handleRsc: (
    component: string,
    props: Record<string, unknown>,
    callbackSocket?: string | null,
    layouts?: LayoutEntry[]
  ) => Promise<{ body: string; rscPayload: string; clientChunks: string[]; usedDynamicApis: boolean }>;
  handleRscStream: (
    component: string,
    props: Record<string, unknown>,
    callbackSocket?: string | null,
    layouts?: LayoutEntry[]
  ) => Promise<{ stream: ReadableStream; clientChunks: string[] }>;
  handleRscHtmlStream: (
    component: string,
    props: Record<string, unknown>,
    callbackSocket?: string | null,
    layouts?: LayoutEntry[]
  ) => Promise<{ htmlStream: ReadableStream; rscPayloadPromise: Promise<string>; clientChunks: string[] }>;
  handleAction: (
    actionId: string,
    body: string,
    contentType: string,
    callbackSocket?: string | null
  ) => Promise<{ stream: ReadableStream }>;
};

let rscHandler: RscHandlerModule | null = null;

if (process.env.BUN_RSC_BUNDLE) {
  try {
    rscHandler = (await import("./rsc-handler")) as RscHandlerModule;
    log("RSC handler loaded");
  } catch (err) {
    log(
      "Failed to load RSC handler:",
      err instanceof Error ? err.message : String(err)
    );
  }
}

if (Object.keys(functions).length === 0 && !rscHandler) {
  log("No functions discovered. Provide a functions directory or entry points.");
  process.exit(1);
}

/**
 * Handles rsc-stream messages.
 *
 * Writes Flight data frames back on the main Bun.listen handler socket
 * (same path as SSR responses). The drain handler on Bun.listen handles
 * backpressure for large payloads. Runs via setTimeout so writes are not
 * corked by the data handler's async callback buffering.
 */
async function handleRscStreamMessage(
  mainSocket: SocketLike,
  message: IncomingMessage
): Promise<void> {
  if (!rscHandler) {
    writeFrame(mainSocket, '{"error":"RSC not enabled"}');
    return;
  }
  if (!message.component) {
    writeFrame(mainSocket, '{"error":"Missing component in RSC message"}');
    return;
  }

  try {
    const { stream, clientChunks } = await rscHandler.handleRscStream(
      message.component,
      message.props ?? {},
      message.callbackSocket ?? null,
      message.layouts ?? []
    );

    writeFrame(mainSocket, JSON.stringify({ type: "stream-start", clientChunks }));
    await Bun.sleep(0);

    const reader = stream.getReader();
    const decoder = new TextDecoder();

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      const text = typeof value === "string"
        ? value
        : decoder.decode(value, { stream: true });
      writeFrame(mainSocket, JSON.stringify({ type: "stream-chunk", data: text }));
      await Bun.sleep(0);
    }

    writeFrame(mainSocket, '{"type":"stream-end"}');
  } catch (err) {
    const errorJson = JSON.stringify({ error: err instanceof Error ? err.message : String(err) });
    try {
      writeFrame(mainSocket, errorJson);
    } catch {
      // Best effort
    }
  }
}

/**
 * Handles rsc-html-stream messages for initial page loads with Suspense.
 *
 * Writes HTML + Flight payload frames back on the main Bun.listen handler
 * socket (same path as SSR responses). The drain handler on Bun.listen
 * handles backpressure for large payloads.
 */
async function handleRscHtmlStreamMessage(
  mainSocket: SocketLike,
  message: IncomingMessage
): Promise<void> {
  if (!rscHandler) {
    writeFrame(mainSocket, '{"error":"RSC not enabled"}');
    return;
  }
  if (!message.component) {
    writeFrame(mainSocket, '{"error":"Missing component in RSC message"}');
    return;
  }

  try {
    const { htmlStream, rscPayloadPromise, clientChunks } =
      await rscHandler.handleRscHtmlStream(
        message.component,
        message.props ?? {},
        message.callbackSocket ?? null,
        message.layouts ?? []
      );

    writeFrame(mainSocket, JSON.stringify({ type: "html-start", clientChunks }));
    await Bun.sleep(0);

    const reader = htmlStream.getReader();
    const decoder = new TextDecoder();

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      const text = typeof value === "string"
        ? value
        : decoder.decode(value, { stream: true });
      writeFrame(mainSocket, JSON.stringify({ type: "html-chunk", data: text }));
      await Bun.sleep(0);
    }

    const rscPayload = await rscPayloadPromise;
    writeFrame(mainSocket, JSON.stringify({ type: "html-end", rscPayload }));
  } catch (err) {
    const errorJson = JSON.stringify({ error: err instanceof Error ? err.message : String(err) });
    try {
      writeFrame(mainSocket, errorJson);
    } catch {
      // Best effort
    }
  }
}

/**
 * Handles rsc-action messages (server action calls).
 *
 * Same streaming pattern as handleRscStreamMessage — writes Flight
 * data frames back on the main socket with action-specific frame types.
 */
async function handleRscActionMessage(
  mainSocket: SocketLike,
  message: IncomingMessage
): Promise<void> {
  if (!rscHandler) {
    writeFrame(mainSocket, '{"error":"RSC not enabled"}');
    return;
  }
  if (!message.actionId) {
    writeFrame(mainSocket, '{"error":"Missing actionId in rsc-action message"}');
    return;
  }

  try {
    const { stream } = await rscHandler.handleAction(
      message.actionId,
      message.body ?? "",
      message.contentType ?? "text/plain",
      message.callbackSocket ?? null
    );

    writeFrame(mainSocket, '{"type":"action-start"}');
    await Bun.sleep(0);

    const reader = stream.getReader();
    const decoder = new TextDecoder();

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      const text = typeof value === "string"
        ? value
        : decoder.decode(value, { stream: true });
      writeFrame(mainSocket, JSON.stringify({ type: "action-chunk", data: text }));
      await Bun.sleep(0);
    }

    writeFrame(mainSocket, '{"type":"action-end"}');
  } catch (err) {
    let errorJson: string;
    if (err instanceof ServerAuthenticationError) {
      errorJson = JSON.stringify({
        unauthenticated: true,
        error: err.message,
      });
    } else if (err instanceof ServerAuthorizationError) {
      errorJson = JSON.stringify({
        unauthorized: true,
        error: err.message,
      });
    } else if (err instanceof ServerRedirectError) {
      errorJson = JSON.stringify({
        redirect: err.location,
      });
    } else if (err instanceof ServerValidationError) {
      errorJson = JSON.stringify({
        error: err.message,
        validation_errors: err.errors,
      });
    } else {
      errorJson = JSON.stringify({
        error: err instanceof Error ? err.message : String(err),
      });
    }
    try {
      writeFrame(mainSocket, errorJson);
    } catch {
      // Best effort
    }
  }
}

try {
  unlinkSync(socketPath);
} catch {
  // File doesn't exist
}

type SocketLike = { write(data: string | Uint8Array): number; flush(): void };

const pendingWriteBuffers = new Map<unknown, Buffer>();
const socketBuffers = new Map<unknown, Buffer>();

function drainSocket(socket: SocketLike): void {
  const pending = pendingWriteBuffers.get(socket);
  if (!pending) return;

  const written = socket.write(pending);
  if (written < pending.length) {
    pendingWriteBuffers.set(socket, pending.subarray(written));
  } else {
    pendingWriteBuffers.delete(socket);
  }
}

function writeFrame(socket: SocketLike, json: string): void {
  const payload = Buffer.from(json, "utf-8");
  const header = Buffer.alloc(4);
  header.writeUInt32BE(payload.length, 0);

  const frame = Buffer.concat([header, payload]);

  // If there are pending writes from a previous partial write,
  // queue this frame behind them to maintain frame ordering.
  // Writing directly would interleave with pending data on the wire.
  const existing = pendingWriteBuffers.get(socket);
  if (existing) {
    pendingWriteBuffers.set(socket, Buffer.concat([existing, frame]));
    return;
  }

  const written = socket.write(frame);
  if (written < frame.length) {
    pendingWriteBuffers.set(socket, frame.subarray(written));
  }
}

const MAX_FRAME_SIZE = parseInt(process.env.BUN_MAX_FRAME_SIZE || "1048576", 10); // 1MB default

const server = Bun.listen({
  unix: socketPath,
  socket: {
    async data(socket, rawData) {
      let buf = socketBuffers.get(socket);
      buf = buf ? Buffer.concat([buf, Buffer.from(rawData)]) : Buffer.from(rawData);

      while (buf.length >= 4) {
        const frameLength = buf.readUInt32BE(0);

        if (frameLength <= 0 || frameLength > MAX_FRAME_SIZE) {
          log("Invalid frame length:", frameLength);
          socketBuffers.delete(socket);
          return;
        }

        if (buf.length < 4 + frameLength) {
          break;
        }

        const jsonBytes = buf.subarray(4, 4 + frameLength);
        buf = buf.subarray(4 + frameLength);

        try {
          const message = JSON.parse(jsonBytes.toString("utf-8")) as IncomingMessage;

          if (message.type === "rsc-stream" || message.type === "rsc-html-stream" || message.type === "rsc-action") {
            // Run streaming outside the data handler so socket writes
            // are not corked by Bun's async callback buffering.
            const handler = message.type === "rsc-html-stream"
              ? handleRscHtmlStreamMessage
              : message.type === "rsc-action"
                ? handleRscActionMessage
                : handleRscStreamMessage;
            setTimeout(() => handler(socket, message), 0);
          } else {
            const response = await handleMessage(message);
            writeFrame(socket, response);
          }
        } catch (err) {
          log("Failed to parse message:", err);
          writeFrame(socket, '{"error":"Invalid JSON"}');
        }
      }

      if (buf.length > 0) {
        socketBuffers.set(socket, buf);
      } else {
        socketBuffers.delete(socket);
      }
    },
    drain(socket) {
      drainSocket(socket);
    },
    open() {},
    close(socket) {
      pendingWriteBuffers.delete(socket);
      socketBuffers.delete(socket);
    },
    error(_, err) {
      log("Socket error:", err.message);
    },
  },
});

log(`Listening on ${socketPath}`);
log(`Discovered ${Object.keys(functions).length} functions: ${Object.keys(functions).join(", ")}`);

function shutdown(signal: string): void {
  log(`Received ${signal}, shutting down`);
  server.stop();
  try {
    unlinkSync(socketPath);
  } catch {}
  process.exit(0);
}

process.on("SIGINT", () => shutdown("SIGINT"));
process.on("SIGTERM", () => shutdown("SIGTERM"));

// Prevent unhandled errors from crashing the worker process.
// These can occur during socket cleanup (e.g., callback socket closed by PHP)
// or from deferred React rendering microtasks.
process.on("uncaughtException", (err) => {
  log("Uncaught exception (worker kept alive):", err.message);
});

process.on("unhandledRejection", (reason) => {
  log("Unhandled rejection (worker kept alive):", reason instanceof Error ? reason.message : String(reason));
});
