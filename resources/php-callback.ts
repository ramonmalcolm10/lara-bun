/**
 * PHP callback client for RSC rendering.
 *
 * Each render gets its own PhpCallbackClient instance with isolated state,
 * preventing cross-request interference under concurrent or multi-worker loads.
 *
 * Uses the same 4-byte BE length-prefix + JSON frame protocol as the main bridge.
 */

import type { Socket } from "bun";
import { ServerValidationError } from "./errors";

type SocketLike = Socket<undefined>;

export class PhpCallbackClient {
  private connection: SocketLike | null = null;
  private pendingCallbacks = new Map<string, {
    resolve: (value: unknown) => void;
    reject: (reason: Error) => void;
  }>();
  private callbackIdCounter = 0;
  private receiveBuffer = Buffer.alloc(0);

  async connect(socketPath: string): Promise<void> {
    this.receiveBuffer = Buffer.alloc(0);

    const self = this;

    this.connection = await Bun.connect({
      unix: socketPath,
      socket: {
        data(_socket, rawData) {
          self.receiveBuffer = self.receiveBuffer.length > 0
            ? Buffer.concat([self.receiveBuffer, Buffer.from(rawData)])
            : Buffer.from(rawData);

          while (self.receiveBuffer.length >= 4) {
            const frameLength = self.receiveBuffer.readUInt32BE(0);

            if (frameLength <= 0 || frameLength > 10 * 1024 * 1024) {
              console.error("[php-callback] Invalid frame length:", frameLength);
              self.receiveBuffer = Buffer.alloc(0);
              return;
            }

            if (self.receiveBuffer.length < 4 + frameLength) {
              break;
            }

            const json = self.receiveBuffer.subarray(4, 4 + frameLength).toString("utf-8");
            self.receiveBuffer = self.receiveBuffer.subarray(4 + frameLength);

            try {
              const response = JSON.parse(json);
              const id = response.id as string;
              const pending = self.pendingCallbacks.get(id);

              if (pending) {
                self.pendingCallbacks.delete(id);

                if (response.validation_errors) {
                  pending.reject(new ServerValidationError(
                    response.error ?? "Validation failed",
                    response.validation_errors
                  ));
                } else if (response.error) {
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
          for (const [, pending] of self.pendingCallbacks) {
            pending.reject(new Error("PHP callback connection closed"));
          }
          self.pendingCallbacks.clear();
          self.connection = null;
        },
        error(_, err) {
          console.error("[php-callback] Socket error:", err.message);
        },
      },
    });
  }

  disconnect(): void {
    if (this.connection) {
      this.connection.end();
      this.connection = null;
    }

    for (const [, pending] of this.pendingCallbacks) {
      pending.reject(new Error("PHP callback disconnected"));
    }

    this.pendingCallbacks.clear();
    this.receiveBuffer = Buffer.alloc(0);
  }

  call<T = unknown>(functionName: string, args?: Record<string, unknown>): Promise<T> {
    if (!this.connection) {
      return Promise.reject(new Error("PHP callback not connected. Is callbackSocket configured?"));
    }

    const id = `cb_${++this.callbackIdCounter}`;

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
    this.connection.write(frame);

    return new Promise<T>((resolve, reject) => {
      this.pendingCallbacks.set(id, {
        resolve: resolve as (value: unknown) => void,
        reject,
      });
    });
  }
}
