/**
 * Call a PHP function from a React Server Component during RSC rendering.
 *
 * The function name must be registered in config/bun.php under rsc.callables,
 * or auto-discovered from rsc.callables_dir (e.g. "UserCallable.getUser").
 */
declare function php<T = unknown>(functionName: string, args?: Record<string, unknown>): Promise<T>;
