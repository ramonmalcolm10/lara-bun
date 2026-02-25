import { createFromReadableStream } from "react-server-dom-webpack/client";
import { renderToReadableStream } from "react-dom/server";

const bundlePath = process.env.BUN_RSC_BUNDLE;

if (!bundlePath) {
  throw new Error("BUN_RSC_BUNDLE environment variable is not set");
}

const rscModule = await import(bundlePath);

if (typeof rscModule.renderRsc !== "function") {
  throw new Error(
    "RSC bundle does not export a renderRsc function. Rebuild with: bun run build:rsc"
  );
}

const emptyManifest = {
  serverConsumerManifest: {
    moduleMap: {},
    moduleLoading: null,
    serverModuleMap: {},
  },
};

export async function handleRsc(
  component: string,
  props: Record<string, unknown>
): Promise<{ body: string; rscPayload: string }> {
  // Step 1: Render component to RSC Flight payload
  const rscPayload: string = await rscModule.renderRsc(component, props);

  // Step 2: Deserialize Flight payload into React element tree
  const flightStream = new ReadableStream({
    start(controller) {
      controller.enqueue(new TextEncoder().encode(rscPayload));
      controller.close();
    },
  });

  const reactTree = await createFromReadableStream(
    flightStream,
    emptyManifest
  );

  // Step 3: Render React elements to HTML
  const htmlStream = await renderToReadableStream(reactTree);
  await htmlStream.allReady;

  const body = await new Response(htmlStream).text();

  return { body, rscPayload };
}
