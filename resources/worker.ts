import { unlinkSync } from "node:fs";
import { join, resolve } from "node:path";

type MessageHandler = (args: Record<string, unknown>) => unknown;

const functions: Record<string, MessageHandler> = {};

interface IncomingMessage {
  type: "ping" | "call" | "list" | "ssr";
  function?: string;
  args?: Record<string, unknown>;
  page?: Record<string, unknown>;
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

if (Object.keys(functions).length === 0) {
  log("No functions discovered. Provide a functions directory or entry points.");
  process.exit(1);
}

try {
  unlinkSync(socketPath);
} catch {
  // File doesn't exist
}

type SocketLike = { write(data: string | Uint8Array): number };

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
  const written = socket.write(frame);

  if (written < frame.length) {
    const existing = pendingWriteBuffers.get(socket) ?? Buffer.alloc(0);
    pendingWriteBuffers.set(socket, Buffer.concat([existing, frame.subarray(written)]));
  }
}

const server = Bun.listen({
  unix: socketPath,
  socket: {
    async data(socket, rawData) {
      let buf = socketBuffers.get(socket);
      buf = buf ? Buffer.concat([buf, Buffer.from(rawData)]) : Buffer.from(rawData);

      while (buf.length >= 4) {
        const frameLength = buf.readUInt32BE(0);

        if (frameLength <= 0 || frameLength > 10 * 1024 * 1024) {
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
          const response = await handleMessage(message);
          writeFrame(socket, response);
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
