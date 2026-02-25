<?php

namespace RamonMalcolm\LaraBun\Console;

use Illuminate\Console\Command;

class BunServeCommand extends Command
{
    protected $signature = 'bun:serve {--socket= : Path to the Unix socket}';

    protected $description = 'Start the Bun bridge server';

    /** @var array<int, resource> */
    private array $processes = [];

    /** @var array<int, string> */
    private array $socketPaths = [];

    public function handle(): int
    {
        $baseSocketPath = $this->option('socket') ?? config('bun.socket_path', '/tmp/bun-bridge.sock');
        $functionsDir = config('bun.functions_dir', resource_path('bun'));
        $workerCount = max(1, (int) config('bun.workers', 1));
        $workerPath = realpath(__DIR__.'/../../resources/worker.ts');

        if ($workerPath === false) {
            $this->error('Worker not found in package resources');

            return self::FAILURE;
        }

        $hasFunctionsDir = is_dir($functionsDir);

        $bunPath = $this->findBun();

        if ($bunPath === null) {
            $this->error('Bun executable not found. Install it via: curl -fsSL https://bun.sh/install | bash');

            return self::FAILURE;
        }

        $ssrBundle = config('bun.ssr.enabled')
            ? $this->detectSsrBundle()
            : null;

        $rscBundle = config('bun.rsc.enabled')
            ? $this->detectRscBundle()
            : null;

        $entryPoints = collect(config('bun.entry_points', []))
            ->when($ssrBundle, fn ($collection, $bundle) => $collection->push($bundle))
            ->filter()
            ->unique()
            ->implode(',');

        if (! $hasFunctionsDir && $entryPoints === '') {
            $this->error('Nothing to serve. Create a functions directory at: '.$functionsDir);
            $this->error('Or enable SSR via BUN_SSR_ENABLED=true in your .env file.');

            return self::FAILURE;
        }

        if ($workerCount === 1) {
            return $this->serveSingle($baseSocketPath, $functionsDir, $hasFunctionsDir, $entryPoints, $workerPath, $bunPath, $rscBundle);
        }

        return $this->serveMultiple($baseSocketPath, $functionsDir, $hasFunctionsDir, $entryPoints, $workerPath, $bunPath, $workerCount, $rscBundle);
    }

    private function serveSingle(
        string $socketPath,
        string $functionsDir,
        bool $hasFunctionsDir,
        string $entryPoints,
        string $workerPath,
        string $bunPath,
        ?string $rscBundle = null,
    ): int {
        $this->info("Starting Bun bridge on {$socketPath}");
        $this->outputConfig($functionsDir, $hasFunctionsDir, $entryPoints, $workerPath, $bunPath);

        $env = $this->buildWorkerEnv($socketPath, $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);

        $process = proc_open(
            [$bunPath, 'run', $workerPath],
            [
                0 => STDIN,
                1 => STDERR,
                2 => STDERR,
            ],
            $pipes,
            base_path(),
            $env,
        );

        if (! is_resource($process)) {
            $this->error('Failed to start Bun process');

            return self::FAILURE;
        }

        $status = proc_close($process);

        return $status === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function serveMultiple(
        string $baseSocketPath,
        string $functionsDir,
        bool $hasFunctionsDir,
        string $entryPoints,
        string $workerPath,
        string $bunPath,
        int $workerCount,
        ?string $rscBundle = null,
    ): int {
        $base = preg_replace('/\.sock$/', '', $baseSocketPath);

        for ($i = 0; $i < $workerCount; $i++) {
            $this->socketPaths[$i] = "{$base}-{$i}.sock";
        }

        $this->info("Starting Bun bridge with {$workerCount} workers");
        $this->outputConfig($functionsDir, $hasFunctionsDir, $entryPoints, $workerPath, $bunPath);

        foreach ($this->socketPaths as $i => $socketPath) {
            $this->line("  Worker {$i}: {$socketPath}");
        }

        $this->newLine();

        $this->trapSignals();

        for ($i = 0; $i < $workerCount; $i++) {
            $process = $this->spawnWorker($bunPath, $workerPath, $this->socketPaths[$i], $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);

            if ($process === null) {
                $this->error("Failed to start worker {$i}");
                $this->shutdownAll();

                return self::FAILURE;
            }

            $this->processes[$i] = $process;
        }

        return $this->monitorProcesses($bunPath, $workerPath, $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);
    }

    /**
     * @return resource|null
     */
    private function spawnWorker(
        string $bunPath,
        string $workerPath,
        string $socketPath,
        string $functionsDir,
        bool $hasFunctionsDir,
        string $entryPoints,
        ?string $rscBundle = null,
    ) {
        $env = $this->buildWorkerEnv($socketPath, $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);

        $process = proc_open(
            [$bunPath, 'run', $workerPath],
            [
                0 => ['pipe', 'r'],
                1 => STDERR,
                2 => STDERR,
            ],
            $pipes,
            base_path(),
            $env,
        );

        if (is_resource($process)) {
            fclose($pipes[0]);

            return $process;
        }

        return null;
    }

    private function monitorProcesses(
        string $bunPath,
        string $workerPath,
        string $functionsDir,
        bool $hasFunctionsDir,
        string $entryPoints,
        ?string $rscBundle = null,
    ): int {
        while (true) {
            pcntl_signal_dispatch();

            if ($this->processes === []) {
                return self::SUCCESS;
            }

            foreach ($this->processes as $i => $process) {
                $status = proc_get_status($process);

                if ($status['running']) {
                    continue;
                }

                proc_close($process);

                if ($status['exitcode'] !== 0) {
                    $this->warn("Worker {$i} exited with code {$status['exitcode']}, restarting...");

                    $newProcess = $this->spawnWorker($bunPath, $workerPath, $this->socketPaths[$i], $functionsDir, $hasFunctionsDir, $entryPoints, $rscBundle);

                    if ($newProcess === null) {
                        $this->error("Failed to restart worker {$i}, shutting down");
                        unset($this->processes[$i]);
                        $this->shutdownAll();

                        return self::FAILURE;
                    }

                    $this->processes[$i] = $newProcess;
                } else {
                    unset($this->processes[$i]);
                }
            }

            usleep(100_000); // 100ms
        }
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            $this->newLine();
            $this->info('Shutting down all workers...');
            $this->shutdownAll();
            exit(0);
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    private function shutdownAll(): void
    {
        foreach ($this->processes as $i => $process) {
            $status = proc_get_status($process);

            if ($status['running']) {
                posix_kill($status['pid'], SIGTERM);
            }

            proc_close($process);
            unset($this->processes[$i]);
        }

        foreach ($this->socketPaths as $socketPath) {
            if (file_exists($socketPath)) {
                @unlink($socketPath);
            }
        }
    }

    private function outputConfig(string $functionsDir, bool $hasFunctionsDir, string $entryPoints, string $workerPath, string $bunPath): void
    {
        if ($hasFunctionsDir) {
            $this->line("Functions: {$functionsDir}");
        } else {
            $this->warn('Functions directory not found — skipping function discovery.');
        }

        if ($entryPoints !== '') {
            $this->line("Entry points: {$entryPoints}");
        }

        $this->line("Worker: {$workerPath}");
        $this->line("Using: {$bunPath}");
        $this->line('Press Ctrl+C to stop');
    }

    /**
     * @return array<string, string>
     */
    private function buildWorkerEnv(string $socketPath, string $functionsDir, bool $hasFunctionsDir, string $entryPoints, ?string $rscBundle = null): array
    {
        $env = [
            'BUN_BRIDGE_SOCKET' => $socketPath,
        ];

        if ($hasFunctionsDir) {
            $env['BUN_BRIDGE_FUNCTIONS_DIR'] = $functionsDir;
        }

        if ($entryPoints !== '') {
            $env['BUN_BRIDGE_ENTRY_POINTS'] = $entryPoints;
        }

        if ($rscBundle !== null) {
            $env['BUN_RSC_BUNDLE'] = $rscBundle;
        }

        return $env;
    }

    private function detectRscBundle(): ?string
    {
        $configured = config('bun.rsc.bundle');

        if ($configured && file_exists($configured)) {
            return $configured;
        }

        $this->warn('RSC bundle not found. Run: bun run build:rsc');

        return null;
    }

    private function detectSsrBundle(): ?string
    {
        return collect([
            config('inertia.ssr.bundle'),
            base_path('bootstrap/ssr/ssr.mjs'),
            base_path('bootstrap/ssr/ssr.js'),
        ])->filter()->first(fn (string $path) => file_exists($path));
    }

    private function findBun(): ?string
    {
        $candidates = [
            '/opt/homebrew/bin/bun',
            '/usr/local/bin/bun',
            ($_SERVER['HOME'] ?? '').'/.bun/bin/bun',
        ];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        $which = trim((string) shell_exec('which bun 2>/dev/null'));

        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        return null;
    }
}
