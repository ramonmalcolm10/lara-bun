/**
 * PHP callback client for RSC rendering.
 *
 * Connects to a PHP-hosted Unix socket and sends callback requests
 * using the same 4-byte BE length-prefix + JSON frame protocol as the main bridge.
 */

import type { Socket } from "bun";

type SocketLike = Socket<undefined>;

let connection: SocketLike | null = null;
let pendingCallbacks = new Map<string, {
  resolve: (value: unknown) => void;
  reject: (reason: Error) => void;
}>();
let callbackIdCounter = 0;
let receiveBuffer = Buffer.alloc(0);

export async function connectCallback(socketPath: string): Promise<void> {
  receiveBuffer = Buffer.alloc(0);

  connection = await Bun.connect({
    unix: socketPath,
    socket: {
      data(_socket, rawData) {
        receiveBuffer = receiveBuffer.length > 0
          ? Buffer.concat([receiveBuffer, Buffer.from(rawData)])
          : Buffer.from(rawData);

        // Process complete frames
        while (receiveBuffer.length >= 4) {
          const frameLength = receiveBuffer.readUInt32BE(0);

          if (frameLength <= 0 || frameLength > 10 * 1024 * 1024) {
            console.error("[php-callback] Invalid frame length:", frameLength);
            receiveBuffer = Buffer.alloc(0);
            return;
          }

          if (receiveBuffer.length < 4 + frameLength) {
            break;
          }

          const json = receiveBuffer.subarray(4, 4 + frameLength).toString("utf-8");
          receiveBuffer = receiveBuffer.subarray(4 + frameLength);

          try {
            const response = JSON.parse(json);
            const id = response.id as string;
            const pending = pendingCallbacks.get(id);

            if (pending) {
              pendingCallbacks.delete(id);

              if (response.error) {
                pending.reject(new Error(response.error));
              } else {
                pending.resolve(response.result);
              }
            }
          } catch (err) {
            console.error("[php-callback] Failed to parse response:", err);
          }
        }
      },
      open() {},
      close() {
        // Reject all pending callbacks
        for (const [, pending] of pendingCallbacks) {
          pending.reject(new Error("PHP callback connection closed"));
        }
        pendingCallbacks.clear();
        connection = null;
      },
      error(_, err) {
        console.error("[php-callback] Socket error:", err.message);
      },
    },
  });
}

export function disconnectCallback(): void {
  if (connection) {
    connection.end();
    connection = null;
  }

  for (const [, pending] of pendingCallbacks) {
    pending.reject(new Error("PHP callback disconnected"));
  }

  pendingCallbacks.clear();
  receiveBuffer = Buffer.alloc(0);
}

export function php<T = unknown>(functionName: string, args?: Record<string, unknown>): Promise<T> {
  if (!connection) {
    return Promise.reject(new Error("PHP callback not connected. Is callbackSocket configured?"));
  }

  const id = `cb_${++callbackIdCounter}`;

  const json = JSON.stringify({
    type: "callback",
    id,
    function: functionName,
    args: args ?? {},
  });

  const payload = Buffer.from(json, "utf-8");
  const header = Buffer.alloc(4);
  header.writeUInt32BE(payload.length, 0);

  const frame = Buffer.concat([header, payload]);
  connection.write(frame);

  return new Promise<T>((resolve, reject) => {
    pendingCallbacks.set(id, {
      resolve: resolve as (value: unknown) => void,
      reject,
    });
  });
}
