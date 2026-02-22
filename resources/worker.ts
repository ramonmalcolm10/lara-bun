import { unlinkSync } from "node:fs";
import { join, resolve } from "node:path";

type MessageHandler = (args: Record<string, unknown>) => unknown;

const functions: Record<string, MessageHandler> = {};

interface IncomingMessage {
  type: "ping" | "call" | "list" | "shutdown";
  requestId: string;
  function?: string;
  args?: Record<string, unknown>;
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
        if (name === "default" || typeof fn !== "function") continue;

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
  const { type, requestId } = message;

  switch (type) {
    case "ping":
      return JSON.stringify({ type: "pong", requestId });

    case "call": {
      const fn = functions[message.function ?? ""];
      if (!fn) {
        return JSON.stringify({
          requestId,
          error: `Function "${message.function}" not found. Available: ${Object.keys(functions).join(", ")}`,
        });
      }
      try {
        const result = await fn(message.args ?? {});
        return JSON.stringify({ requestId, result });
      } catch (err) {
        return JSON.stringify({
          requestId,
          error: err instanceof Error ? err.message : String(err),
        });
      }
    }

    case "list":
      return JSON.stringify({ requestId, result: Object.keys(functions) });

    case "shutdown":
      log("Shutting down");
      setTimeout(() => process.exit(0), 100);
      return JSON.stringify({ requestId, result: "ok" });

    default:
      return JSON.stringify({ requestId, error: `Unknown message type: "${type}"` });
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

// Clean up stale socket file
try {
  unlinkSync(socketPath);
} catch {
  // File doesn't exist, that's fine
}

const server = Bun.listen({
  unix: socketPath,
  socket: {
    async data(socket, rawData) {
      const text = Buffer.from(rawData).toString("utf-8");
      const lines = text.split("\n").filter((l) => l.trim());

      for (const line of lines) {
        try {
          const message = JSON.parse(line) as IncomingMessage;
          const response = await handleMessage(message);
          socket.write(response + "\n");
        } catch (err) {
          log("Failed to parse message:", line, err);
          socket.write(JSON.stringify({ error: "Invalid JSON" }) + "\n");
        }
      }
    },
    open() {},
    close() {},
    error(_, err) {
      log("Socket error:", err.message);
    },
  },
});

log(`Listening on ${socketPath}`);
log(`Discovered ${Object.keys(functions).length} functions: ${Object.keys(functions).join(", ")}`);

// Graceful shutdown
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
