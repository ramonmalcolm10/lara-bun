"use client";

import {
  type AnchorHTMLAttributes,
  type MouseEvent,
  useCallback,
  useEffect,
  useRef,
} from "react";

type PrefetchStrategy = "hover" | "mount" | "click" | "none" | boolean;

interface LinkProps extends Omit<AnchorHTMLAttributes<HTMLAnchorElement>, "href"> {
  href: string;
  prefetch?: PrefetchStrategy;
  cacheFor?: number;
  replace?: boolean;
  preserveScroll?: boolean;
}

function isExternalUrl(url: string): boolean {
  try {
    const parsed = new URL(url, window.location.origin);
    return parsed.origin !== window.location.origin;
  } catch {
    return false;
  }
}

function shouldInterceptClick(e: MouseEvent<HTMLAnchorElement>): boolean {
  return (
    !e.defaultPrevented &&
    e.button === 0 &&
    !e.metaKey &&
    !e.ctrlKey &&
    !e.shiftKey &&
    !e.altKey
  );
}

export default function Link({
  href,
  prefetch: prefetchProp = "hover",
  cacheFor,
  replace = false,
  preserveScroll = false,
  children,
  onClick,
  onMouseEnter,
  ...rest
}: LinkProps) {
  // Normalize boolean: true → "hover", false → "none"
  const prefetchStrategy = prefetchProp === true
    ? "hover"
    : prefetchProp === false
      ? "none"
      : prefetchProp;

  const prefetchedRef = useRef(false);

  const doPrefetch = useCallback(() => {
    if (prefetchedRef.current || isExternalUrl(href)) {
      return;
    }

    prefetchedRef.current = true;
    const fn = (window as any).__rsc_prefetch;
    fn?.(href, cacheFor);
  }, [href, cacheFor]);

  useEffect(() => {
    if (prefetchStrategy === "mount") {
      doPrefetch();
    }
  }, [prefetchStrategy, doPrefetch]);

  const handleClick = useCallback(
    (e: MouseEvent<HTMLAnchorElement>) => {
      onClick?.(e);

      if (e.defaultPrevented) {
        return;
      }

      const target = (e.currentTarget as HTMLAnchorElement).target;
      if (target && target !== "_self") {
        return;
      }

      if (!shouldInterceptClick(e) || isExternalUrl(href)) {
        return;
      }

      e.preventDefault();

      const nav = (window as any).__rsc_navigate;
      nav?.(href, { replace, preserveScroll });
    },
    [href, replace, preserveScroll, onClick]
  );

  const handleMouseEnter = useCallback(
    (e: MouseEvent<HTMLAnchorElement>) => {
      onMouseEnter?.(e);

      if (prefetchStrategy === "hover" || prefetchStrategy === "click") {
        doPrefetch();
      }
    },
    [prefetchStrategy, doPrefetch, onMouseEnter]
  );

  return (
    <a href={href} onClick={handleClick} onMouseEnter={handleMouseEnter} {...rest}>
      {children}
    </a>
  );
}
