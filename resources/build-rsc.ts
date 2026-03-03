/**
 * Auto-discovers React Server Components, detects "use client" files,
 * and builds server + SSR + browser bundles with manifest generation.
 *
 * Usage:
 *   bun <this-script> [source-dir] [out-dir]
 *
 * Defaults:
 *   source-dir: resources/js/rsc
 *   out-dir:    bootstrap/rsc
 */

import { join, basename, resolve } from "node:path";
import { mkdirSync, writeFileSync, readFileSync, existsSync, rmSync } from "node:fs";
import type { BunPlugin } from "bun";

const sourceDir = process.argv[2] ?? join(process.cwd(), "resources/js/rsc");
const outDir = process.argv[3] ?? join(process.cwd(), "bootstrap/rsc");
const clientOutDir = join(outDir, "client");
const browserOutDir = join(process.cwd(), "public/build/rsc");

// Resolve package directory for alias plugin and package client components
const packageDir = process.env.LARA_BUN_PACKAGE_DIR
  ?? resolve(join(import.meta.dir, ".."));
const packageJsDir = join(packageDir, "resources/js");

const glob = new Bun.Glob("**/*.{tsx,ts,jsx,js}");

interface ComponentInfo {
  name: string;
  importAlias: string;
  relativePath: string;
  absolutePath: string;
  isClient: boolean;
}

const serverComponents: ComponentInfo[] = [];
const clientComponents: ComponentInfo[] = [];
let aliasIndex = 0;

function isClientFile(filePath: string): boolean {
  try {
    const content = readFileSync(filePath, "utf-8");
    const firstLine = content.split("\n")[0].trim();
    return firstLine === '"use client";' || firstLine === "'use client';";
  } catch {
    return false;
  }
}

function isServerActionFile(filePath: string): boolean {
  try {
    const content = readFileSync(filePath, "utf-8");
    const firstLine = content.split("\n")[0].trim();
    return firstLine === '"use server";' || firstLine === "'use server';";
  } catch {
    return false;
  }
}

interface ActionFileInfo {
  importAlias: string;
  relativePath: string;
  absolutePath: string;
  exports: string[];
}

const actionFiles: ActionFileInfo[] = [];

// ─── Auto-generate server actions from PHP config ───────────────────────────

const generatedActionsPath = join(sourceDir, "server-actions.generated.ts");

try {
  const proc = Bun.spawn(
    ["php", "artisan", "rsc:action-manifest", "--no-interaction"],
    { cwd: process.cwd(), stdout: "pipe", stderr: "pipe" }
  );

  const output = await new Response(proc.stdout).text();
  const exitCode = await proc.exited;

  if (exitCode === 0) {
    const actionMap: Record<string, string> = JSON.parse(output.trim());
    const entries = Object.entries(actionMap);

    if (entries.length > 0) {
      const lines = [
        `"use server";`,
        `// @generated — do not edit. Auto-discovered from app/Rsc/Actions/`,
        ``,
      ];

      for (const [jsName, phpCallable] of entries) {
        lines.push(
          `export async function ${jsName}(...args: unknown[]) {`,
          `  return await (globalThis as any).php("${phpCallable}", ...args);`,
          `}`,
          ``
        );
      }

      writeFileSync(generatedActionsPath, lines.join("\n"));
      console.log(`Generated: ${generatedActionsPath} (${entries.length} action(s))`);
    } else if (existsSync(generatedActionsPath)) {
      rmSync(generatedActionsPath);
      console.log(`Removed stale: ${generatedActionsPath}`);
    }
  } else {
    const stderr = await new Response(proc.stderr).text();
    console.warn(`Warning: rsc:action-manifest failed (exit ${exitCode}). Skipping action generation.`);
    if (stderr.trim()) {
      console.warn(stderr.trim());
    }
  }
} catch (err) {
  console.warn("Warning: Could not run rsc:action-manifest. Skipping action generation.", err);
}

// ─── Discover User Components ───────────────────────────────────────────────

for await (const path of glob.scan(sourceDir)) {
  if (
    path.startsWith("entry.") ||
    path.includes(".test.") ||
    path.includes(".spec.")
  ) {
    continue;
  }

  // Skip _ prefixed files only outside app/ (existing convention)
  if (basename(path).startsWith("_") && !path.startsWith("app/")) {
    continue;
  }

  const absolutePath = resolve(sourceDir, path);

  // Server action files are NOT components — handle them separately
  if (isServerActionFile(absolutePath)) {
    const mod = await import(absolutePath);
    const exports = Object.entries(mod)
      .filter(([, v]) => typeof v === "function")
      .map(([name]) => name);

    if (exports.length > 0) {
      actionFiles.push({
        importAlias: `_A${actionFiles.length}`,
        relativePath: `./${path}`,
        absolutePath,
        exports,
      });
    }

    continue;
  }

  const name = path.startsWith("app/")
    ? path.replace(/\.(tsx|ts|jsx|js)$/, "")
    : basename(path).replace(/\.(tsx|ts|jsx|js)$/, "");
  const info: ComponentInfo = {
    name,
    importAlias: `_C${aliasIndex++}`,
    relativePath: `./${path}`,
    absolutePath,
    isClient: isClientFile(absolutePath),
  };

  if (info.isClient) {
    clientComponents.push(info);
  } else {
    serverComponents.push(info);
  }
}

// ─── Discover Package Client Components ─────────────────────────────────────

// Scan the package's resources/js/ directory for "use client" files.
// Package client components get moduleId prefix "lara-bun/" (e.g., "lara-bun/Link.tsx")
const packageClientComponents: ComponentInfo[] = [];

if (existsSync(packageJsDir)) {
  for await (const path of glob.scan(packageJsDir)) {
    if (
      path.startsWith("entry.") ||
      path.includes(".test.") ||
      path.includes(".spec.") ||
      path.startsWith("_")
    ) {
      continue;
    }

    const absolutePath = resolve(packageJsDir, path);

    if (!isClientFile(absolutePath)) {
      continue;
    }

    const name = basename(path).replace(/\.(tsx|ts|jsx|js)$/, "");
    const info: ComponentInfo = {
      name,
      importAlias: `_C${aliasIndex++}`,
      relativePath: `lara-bun/${path}`,
      absolutePath,
      isClient: true,
    };

    packageClientComponents.push(info);
    clientComponents.push(info);
  }
}

const allComponents = [...serverComponents, ...clientComponents];

if (allComponents.length === 0) {
  console.error(`No RSC components found in: ${sourceDir}`);
  console.error("Create component files (e.g. Dashboard.tsx or user-profile.tsx)");
  process.exit(1);
}

console.log(`Found ${serverComponents.length} server component(s):`);
serverComponents.forEach((c) => console.log(`  ${c.name} ← ${c.relativePath}`));

if (clientComponents.length > 0) {
  console.log(`Found ${clientComponents.length} client component(s):`);
  clientComponents.forEach((c) => console.log(`  ${c.name} ← ${c.relativePath}`));
}

if (actionFiles.length > 0) {
  console.log(`Found ${actionFiles.length} server action file(s):`);
  actionFiles.forEach((a) => console.log(`  ${a.relativePath} → ${a.exports.join(", ")}`));
}

// Build a set of absolute paths for client files (for the plugin to intercept)
const clientAbsolutePaths = new Set(clientComponents.map((c) => c.absolutePath));

// Map from moduleId (used in manifests) to component info
// moduleId is the relative path like "./Counter.tsx" or "lara-bun/Link.tsx"
const clientModuleIds = new Map<string, ComponentInfo>();
for (const c of clientComponents) {
  clientModuleIds.set(c.relativePath, c);
}

// ─── Server Build ───────────────────────────────────────────────────────────

// Plugin that intercepts imports of "use client" files and replaces them
// with client module proxies for Flight serialization
const useClientPlugin: BunPlugin = {
  name: "use-client-proxy",
  setup(build) {
    // Create a filter that matches absolute paths of client components
    for (const absPath of clientAbsolutePaths) {
      const escaped = absPath.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
      build.onLoad({ filter: new RegExp(`^${escaped}$`) }, (args) => {
        // Find the component info for this path
        const comp = clientComponents.find((c) => c.absolutePath === args.path);
        if (!comp) return undefined;

        const moduleId = comp.relativePath;
        return {
          contents: `
import { createClientModuleProxy } from "react-server-dom-webpack/server.edge";
export default createClientModuleProxy("${moduleId}");
`,
          loader: "js",
        };
      });
    }
  },
};

// Package alias plugin — resolves "lara-bun/*" imports to the package directory
// so server components can `import Link from 'lara-bun/Link'`
const packageAliasPlugin: BunPlugin = {
  name: "lara-bun-alias",
  setup(build) {
    build.onResolve({ filter: /^lara-bun\// }, (args) => {
      const subPath = args.path.replace(/^lara-bun\//, "");

      // Try exact path first, then with extensions
      const candidates = [
        join(packageJsDir, subPath),
        join(packageJsDir, `${subPath}.tsx`),
        join(packageJsDir, `${subPath}.ts`),
        join(packageJsDir, `${subPath}.jsx`),
        join(packageJsDir, `${subPath}.js`),
      ];

      for (const candidate of candidates) {
        if (existsSync(candidate)) {
          return { path: candidate };
        }
      }

      return undefined;
    });
  },
};

const serverPlugins: BunPlugin[] = [packageAliasPlugin];
if (clientComponents.length > 0) {
  serverPlugins.push(useClientPlugin);
}

// Generate server entry that imports all components (client ones will be proxied)
// Only import user-space server/client components — package client components
// are resolved through the alias plugin when referenced from server components
const userComponents = allComponents.filter(
  (c) => !c.relativePath.startsWith("lara-bun/")
);

const serverImports = userComponents
  .map((c) => `import ${c.importAlias} from "${c.absolutePath}";`)
  .join("\n");

const serverComponentMap = userComponents
  .map((c) => `  "${c.name}": ${c.importAlias},`)
  .join("\n");

const clientManifestParam =
  clientComponents.length > 0
    ? "clientManifest: Record<string, unknown>"
    : "";
const clientManifestArg =
  clientComponents.length > 0 ? "clientManifest" : "null";

const actionImports = actionFiles
  .map((a) => `import * as ${a.importAlias} from "${a.absolutePath}";`)
  .join("\n");

const actionRegistrations = actionFiles
  .map(
    (a) => `for (const [name, fn] of Object.entries(${a.importAlias})) {
  if (typeof fn === "function") {
    registerServerReference(fn, "${a.relativePath}", name);
  }
}`
  )
  .join("\n");

const actionMapEntries = actionFiles
  .map((a) => `  "${a.relativePath}": ${a.importAlias},`)
  .join("\n");

const hasActions = actionFiles.length > 0;

const flightImports = hasActions
  ? "import { renderToReadableStream, registerServerReference, decodeReply as _decodeReply } from \"react-server-dom-webpack/server.edge\";"
  : "import { renderToReadableStream } from \"react-server-dom-webpack/server.edge\";";

const actionReExports = hasActions
  ? `\n// Re-export for rsc-handler (which cannot import server.edge directly)\nexport const decodeReply = _decodeReply;\nexport const renderActionStream = renderToReadableStream;\n`
  : "";

const entrySource = `// Auto-generated by lara-bun build-rsc — do not edit
${flightImports}
import { createElement } from "react";
${serverImports}
${hasActions ? actionImports : ''}
${actionReExports}

interface LayoutEntry {
  component: string;
  props: Record<string, unknown>;
}

const components: Record<string, React.ComponentType<any>> = {
${serverComponentMap}
};
${hasActions ? `
${actionRegistrations}

const actions: Record<string, Record<string, Function>> = {
${actionMapEntries}
};

export function getServerAction(moduleId: string, name: string): Function | undefined {
  return (actions[moduleId] as any)?.[name];
}
` : ''}
function buildElement(
  component: string,
  props: Record<string, unknown>,
  layouts: LayoutEntry[]
): React.ReactElement {
  const Component = components[component];

  if (!Component) {
    throw new Error(
      \`Unknown RSC component: "\${component}". Available: \${Object.keys(components).join(", ")}\`
    );
  }

  let element = createElement(Component, props);

  // Wrap in layouts: layouts[0] is outermost, layouts[last] is innermost
  for (let i = layouts.length - 1; i >= 0; i--) {
    const Layout = components[layouts[i].component];
    if (!Layout) {
      throw new Error(
        \`Unknown layout component: "\${layouts[i].component}". Available: \${Object.keys(components).join(", ")}\`
      );
    }
    element = createElement(Layout, { ...layouts[i].props, children: element });
  }

  return element;
}

export async function renderRsc(
  component: string,
  props: Record<string, unknown>,
  ${clientManifestParam ? `${clientManifestParam},` : ""}
  layouts: LayoutEntry[] = []
): Promise<string> {
  const element = buildElement(component, props, layouts);
  const stream = renderToReadableStream(element, ${clientManifestArg});

  return await new Response(stream).text();
}

export function renderRscStream(
  component: string,
  props: Record<string, unknown>,
  ${clientManifestParam ? `${clientManifestParam},` : ""}
  layouts: LayoutEntry[] = []
): ReadableStream {
  const element = buildElement(component, props, layouts);
  return renderToReadableStream(element, ${clientManifestArg});
}
`;

// Clean output directories to prevent stale hashed files
rmSync(browserOutDir, { recursive: true, force: true });
rmSync(outDir, { recursive: true, force: true });
mkdirSync(outDir, { recursive: true });

const entryPath = join(outDir, "entry.rsc.tsx");
writeFileSync(entryPath, entrySource);
console.log(`Generated: ${entryPath}`);

const serverResult = await Bun.build({
  entrypoints: [entryPath],
  outdir: outDir,
  target: "bun",
  conditions: ["react-server"],
  plugins: serverPlugins,
  define: {
    "process.env.NODE_ENV": '"production"',
  },
});

if (!serverResult.success) {
  console.error("Server build failed:");
  serverResult.logs.forEach((log) => console.error(log));
  process.exit(1);
}

console.log(`Built server bundle: ${join(outDir, "entry.rsc.js")}`);

// ─── Action Manifest ────────────────────────────────────────────────────────

if (actionFiles.length > 0) {
  const actionManifest: Record<string, string[]> = {};

  for (const a of actionFiles) {
    actionManifest[a.relativePath] = a.exports;
  }

  writeFileSync(
    join(outDir, "action-manifest.json"),
    JSON.stringify(actionManifest, null, 2)
  );
  console.log(`Generated: ${join(outDir, "action-manifest.json")}`);
}

// ─── Client Builds + Manifests ──────────────────────────────────────────────

if (clientComponents.length === 0) {
  console.log("No client components — skipping client builds and manifests.");
  process.exit(0);
}

// a) SSR client build — builds client components for server-side HTML rendering
mkdirSync(clientOutDir, { recursive: true });

const ssrResult = await Bun.build({
  entrypoints: clientComponents.map((c) => c.absolutePath),
  outdir: clientOutDir,
  target: "bun",
  naming: "[name].[ext]",
  plugins: [packageAliasPlugin],
  external: ["react", "react-dom"],
  define: {
    "process.env.NODE_ENV": '"production"',
  },
});

if (!ssrResult.success) {
  console.error("SSR client build failed:");
  ssrResult.logs.forEach((log) => console.error(log));
  process.exit(1);
}

console.log(`Built SSR client bundles: ${clientOutDir}/`);

// b) Browser client build — builds client components + hydration entry for browser
mkdirSync(browserOutDir, { recursive: true });

// Generate a hydration entry that imports createRscApp and all client components
const createRscAppPath = join(packageJsDir, "createRscApp.ts");

const hydrateImports = clientComponents
  .map(
    (c, i) =>
      `import * as _M${i} from "${c.absolutePath}";`
  )
  .join("\n");

const hydrateModuleMap = clientComponents
  .map((c, i) => `  "${c.relativePath}": _M${i},`)
  .join("\n");

const hydrateEntrySource = `// Auto-generated hydration entry — do not edit
// __webpack_require__ and __webpack_chunk_load__ are pre-defined in the
// inline <script> block rendered by @rscScripts so they exist before this
// ES module initializes (ES module imports are hoisted above module body code).
import { createRscApp } from "${createRscAppPath}";
${hydrateImports}

const modules: Record<string, unknown> = {
${hydrateModuleMap}
};

const container = document.getElementById("rsc-root");
if (container) {
  createRscApp(container, modules);
}
`;

const hydrateEntryPath = join(outDir, "entry.hydrate.tsx");
writeFileSync(hydrateEntryPath, hydrateEntrySource);

// Plugin that intercepts imports of "use server" files in the browser build
// and replaces them with createServerReference stubs that call through the
// Flight action protocol instead of executing server code in the browser.
const useServerPlugin: BunPlugin = {
  name: "use-server-browser-stub",
  setup(build) {
    for (const action of actionFiles) {
      const escaped = action.absolutePath.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
      build.onLoad({ filter: new RegExp(`^${escaped}$`) }, () => {
        const navigatePath = join(packageJsDir, "navigate.ts");
        const exports = action.exports
          .map(
            (name) =>
              `export const ${name} = createServerReference("${action.relativePath}#${name}", callServer);`
          )
          .join("\n");

        return {
          contents: `
import { createServerReference } from "react-server-dom-webpack/client.browser";
import { getCallServer } from "${navigatePath}";
function callServer(id, args) { return getCallServer()(id, args); }
${exports}
`,
          loader: "js",
        };
      });
    }
  },
};

const browserPlugins: BunPlugin[] = [packageAliasPlugin];
if (actionFiles.length > 0) {
  browserPlugins.push(useServerPlugin);
}

const browserResult = await Bun.build({
  entrypoints: [hydrateEntryPath],
  outdir: browserOutDir,
  target: "browser",
  format: "esm",
  splitting: true,
  minify: true,
  naming: "[name]-[hash].[ext]",
  plugins: browserPlugins,
  define: {
    "process.env.NODE_ENV": '"production"',
  },
});

if (!browserResult.success) {
  console.error("Browser client build failed:");
  browserResult.logs.forEach((log) => console.error(log));
  process.exit(1);
}

// Collect browser output file paths (relative to public/)
const browserChunks: string[] = [];
for (const output of browserResult.outputs) {
  const relativePath = output.path.replace(
    join(process.cwd(), "public"),
    ""
  );
  browserChunks.push(relativePath);
}

console.log(`Built browser bundles: ${browserOutDir}/`);
browserChunks.forEach((c) => console.log(`  ${c}`));

// c) Generate manifests

// Client manifest — used by server during Flight serialization
// Maps moduleId -> { id, chunks, name }
// The moduleId matches what createClientModuleProxy was called with
// The id is what __webpack_require__ will be called with on the SSR/browser side
const clientManifest: Record<
  string,
  { id: string; chunks: string[]; name: string }
> = {};

for (const c of clientComponents) {
  clientManifest[c.relativePath] = {
    id: c.relativePath,
    chunks: [],
    name: "default",
  };
}

writeFileSync(
  join(outDir, "client-manifest.json"),
  JSON.stringify(clientManifest, null, 2)
);
console.log(`Generated: ${join(outDir, "client-manifest.json")}`);

// SSR manifest — used by rsc-handler during createFromReadableStream
// Structure: { moduleMap: { [moduleId]: { [exportName]: { id, chunks, name } } }, moduleLoading, serverModuleMap }
const ssrModuleMap: Record<
  string,
  Record<string, { id: string; chunks: string[]; name: string }>
> = {};

for (const c of clientComponents) {
  ssrModuleMap[c.relativePath] = {
    "default": {
      id: c.relativePath,
      chunks: [],
      name: "default",
    },
  };
}

// Server module map — used by SSR to resolve server action references in Flight payloads.
// Format is flat: { [moduleId]: { id, chunks } }. The export name comes from the
// "#exportName" suffix in the reference ID, not from the map structure.
const serverModuleMap: Record<string, { id: string; chunks: string[] }> = {};

for (const a of actionFiles) {
  serverModuleMap[a.relativePath] = {
    id: a.relativePath,
    chunks: [],
  };
}

const ssrManifest = {
  moduleMap: ssrModuleMap,
  moduleLoading: null,
  serverModuleMap,
};

writeFileSync(
  join(outDir, "ssr-manifest.json"),
  JSON.stringify(ssrManifest, null, 2)
);
console.log(`Generated: ${join(outDir, "ssr-manifest.json")}`);

// Browser chunks manifest — used by PHP to inject script tags
writeFileSync(
  join(outDir, "browser-chunks.json"),
  JSON.stringify(browserChunks, null, 2)
);
console.log(`Generated: ${join(outDir, "browser-chunks.json")}`);
