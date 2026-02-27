"use client";

import { useSyncExternalStore } from "react";

let currentPathname = typeof window !== "undefined" ? window.location.pathname : "/";
const listeners = new Set<() => void>();

function notify(): void {
  currentPathname = window.location.pathname;
  listeners.forEach((fn) => fn());
}

if (typeof window !== "undefined") {
  window.addEventListener("rsc-navigate", notify);
  window.addEventListener("popstate", notify);
}

function subscribe(callback: () => void): () => void {
  listeners.add(callback);
  return () => listeners.delete(callback);
}

function getSnapshot(): string {
  return currentPathname;
}

function getServerSnapshot(): string {
  return "/";
}

export function usePathname(): string {
  return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
