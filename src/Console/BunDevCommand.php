<?php

namespace LaraBun\Console;

use Illuminate\Console\Command;

class BunDevCommand extends Command
{
    protected $signature = 'bun:dev {--socket= : Path to the Unix socket}';

    protected $description = 'Start the build watcher and Bun worker for development';

    /** @var \Symfony\Component\Process\Process|null */
    private $buildProcess = null;

    /** @var \Symfony\Component\Process\Process|null */
    private $serveProcess = null;

    public function handle(): int
    {
        $bunPath = $this->findBun();

        if ($bunPath === null) {
            $this->error('Bun executable not found. Install it via: curl -fsSL https://bun.sh/install | bash');

            return self::FAILURE;
        }

        $buildScript = $this->getBuildScript();

        if (! file_exists($buildScript)) {
            $this->error("Build script not found: {$buildScript}");

            return self::FAILURE;
        }

        $this->trapSignals();

        // Step 1: Run initial build
        $this->info('Running initial build...');
        $this->newLine();

        $initialBuild = new \Symfony\Component\Process\Process(
            [$bunPath, $buildScript],
            base_path(),
        );
        $initialBuild->setTimeout(120);
        $initialBuild->run(fn ($type, $buffer) => $this->output->write($buffer));

        if (! $initialBuild->isSuccessful()) {
            $this->warn('Initial build failed — starting watcher anyway so you can fix errors.');
            $this->newLine();
        }

        // Step 2: Start build watcher in background
        $this->buildProcess = new \Symfony\Component\Process\Process(
            [$bunPath, '--watch', $buildScript],
            base_path(),
        );
        $this->buildProcess->setTimeout(null);
        $this->buildProcess->start(fn ($type, $buffer) => $this->output->write($buffer));

        $this->info('Build watcher started.');

        // Step 3: Start bun:serve --watch via Artisan
        $socketOption = $this->option('socket') ? ['--socket='.$this->option('socket')] : [];

        $this->serveProcess = new \Symfony\Component\Process\Process(
            ['php', 'artisan', 'bun:serve', '--watch', ...$socketOption],
            base_path(),
        );
        $this->serveProcess->setTimeout(null);
        $this->serveProcess->start(fn ($type, $buffer) => $this->output->write($buffer));

        $this->newLine();
        $this->info('Development server started. Press Ctrl+C to stop.');
        $this->newLine();

        // Step 4: Monitor both processes
        while ($this->buildProcess->isRunning() || $this->serveProcess->isRunning()) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // If build watcher dies, stop everything
            if (! $this->buildProcess->isRunning() && $this->serveProcess->isRunning()) {
                $this->error('Build watcher stopped unexpectedly.');
                $this->shutdown();

                return self::FAILURE;
            }

            // If serve process dies, stop everything
            if (! $this->serveProcess->isRunning() && $this->buildProcess->isRunning()) {
                $this->error('Bun worker stopped unexpectedly.');
                $this->shutdown();

                return self::FAILURE;
            }

            usleep(200_000); // 200ms
        }

        return self::SUCCESS;
    }

    private function shutdown(): void
    {
        if ($this->serveProcess?->isRunning()) {
            $this->serveProcess->stop(5);
        }

        if ($this->buildProcess?->isRunning()) {
            $this->buildProcess->stop(5);
        }
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $handler = function (): void {
            $this->newLine();
            $this->info('Shutting down...');
            $this->shutdown();
            exit(0);
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    private function getBuildScript(): string
    {
        $vendorPath = base_path('vendor/larabun/lara-bun/resources/build-rsc.ts');

        if (file_exists($vendorPath)) {
            return $vendorPath;
        }

        $packagePath = dirname(__DIR__, 2).'/resources/build-rsc.ts';

        if (file_exists($packagePath)) {
            return $packagePath;
        }

        return $vendorPath;
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
