<?php

namespace RamonMalcolm\LaraBun\Console;

use Illuminate\Console\Command;

class BunServeCommand extends Command
{
    protected $signature = 'bun:serve {--socket= : Path to the Unix socket}';

    protected $description = 'Start the Bun bridge server';

    public function handle(): int
    {
        $socketPath = $this->option('socket') ?? config('bun.socket_path', '/tmp/bun-bridge.sock');
        $functionsDir = config('bun.functions_dir', resource_path('bun'));
        $workerPath = realpath(__DIR__ . '/../../resources/worker.ts');

        if ($workerPath === false) {
            $this->error('Worker not found in package resources');

            return self::FAILURE;
        }

        if (! is_dir($functionsDir)) {
            $this->error("Functions directory not found at: {$functionsDir}");

            return self::FAILURE;
        }

        $bunPath = $this->findBun();

        if ($bunPath === null) {
            $this->error('Bun executable not found. Install it via: curl -fsSL https://bun.sh/install | bash');

            return self::FAILURE;
        }

        $ssrBundle = config('bun.ssr.enabled')
            ? $this->detectSsrBundle()
            : null;

        $entryPoints = collect(config('bun.entry_points', []))
            ->when($ssrBundle, fn ($collection, $bundle) => $collection->push($bundle))
            ->filter()
            ->unique()
            ->implode(',');

        $this->info("Starting Bun bridge on {$socketPath}");
        $this->line("Functions: {$functionsDir}");

        if ($entryPoints !== '') {
            $this->line("Entry points: {$entryPoints}");
        }

        $this->line("Worker: {$workerPath}");
        $this->line("Using: {$bunPath}");
        $this->line('Press Ctrl+C to stop');
        $this->newLine();

        $env = [
            'BUN_BRIDGE_SOCKET' => $socketPath,
            'BUN_BRIDGE_FUNCTIONS_DIR' => $functionsDir,
        ];

        if ($entryPoints !== '') {
            $env['BUN_BRIDGE_ENTRY_POINTS'] = $entryPoints;
        }

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
            ($_SERVER['HOME'] ?? '') . '/.bun/bin/bun',
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
