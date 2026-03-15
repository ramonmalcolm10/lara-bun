/**
 * Call a PHP function from a React Server Component during RSC rendering.
 *
 * The function name must be registered in config/bun.php under rsc.callables,
 * or auto-discovered from rsc.callables_dir (e.g. "UserCallable.getUser").
 */
declare function php<T = unknown>(functionName: string, args?: Record<string, unknown>): Promise<T>;

/**
 * Page metadata for RSC pages.
 *
 * @example
 * ```tsx
 * export const metadata: Metadata = {
 *   title: 'My Page',
 *   description: 'Page description',
 *   keywords: ['react', 'laravel'],
 * };
 * ```
 */
interface Metadata {
  title?: string;
  description?: string;
  keywords?: string | string[];
  author?: string;
  robots?: string;
  'og:title'?: string;
  'og:description'?: string;
  'og:image'?: string;
  'og:url'?: string;
  'og:type'?: string;
  'og:site_name'?: string;
  'twitter:card'?: string;
  'twitter:title'?: string;
  'twitter:description'?: string;
  'twitter:image'?: string;
  'twitter:site'?: string;
  [key: string]: string | string[] | undefined;
}

type GenerateMetadata<P = Record<string, string>> = (params: P) => Metadata | Promise<Metadata>;
