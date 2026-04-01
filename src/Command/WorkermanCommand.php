<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Command;

use CrazyGoat\WorkermanBundle\ConfigLoader;
use CrazyGoat\WorkermanBundle\KernelFactory;
use CrazyGoat\WorkermanBundle\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Workerman\Worker;

#[AsCommand(name: 'workerman:server', description: 'Manage the Workerman server')]
class WorkermanCommand extends Command
{
    private const ALLOWED_ACTIONS = ['start', 'stop', 'restart', 'reload', 'status', 'connections'];

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'action',
            InputArgument::REQUIRED,
            'Action: ' . implode('|', self::ALLOWED_ACTIONS),
            suggestedValues: self::ALLOWED_ACTIONS,
        )
            ->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run in daemon mode')
            ->addOption('grace', 'g', InputOption::VALUE_NONE, 'Gracefully operate')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        if (!\is_string($action) || !\in_array($action, self::ALLOWED_ACTIONS, true)) {
            $io->error(\sprintf('Invalid action. Allowed: %s', implode(', ', self::ALLOWED_ACTIONS)));

            return Command::FAILURE;
        }

        return match ($action) {
            'start' => $this->executeStart($input, $io),
            'stop' => $this->executeStop($input, $io),
            'restart' => $this->executeRestart($input, $io),
            'reload' => $this->executeReload($input, $io),
            'status' => $this->executeStatus($io),
            default => $this->executeConnections($io),
        };
    }

    private function executeStart(InputInterface $input, SymfonyStyle $io): int
    {
        $config = $this->loadConfig();
        $masterPid = $this->getMasterPid($config);

        if ($this->isMasterRunning($masterPid)) {
            $io->warning('Workerman is already running.');

            return Command::FAILURE;
        }

        Worker::$command = $this->buildCommand($input);
        $this->resetSignalHandlers();

        $runner = new Runner($this->createKernelFactory());

        return $runner->run();
    }

    private function executeStop(InputInterface $input, SymfonyStyle $io): int
    {
        $config = $this->loadConfig();
        $masterPid = $this->getMasterPid($config);

        if (!$this->isMasterRunning($masterPid)) {
            $io->warning('Workerman is not running.');

            return Command::FAILURE;
        }

        $graceful = $input->getOption('grace') === true;
        $signal = $graceful ? \SIGQUIT : \SIGINT;
        $stopTimeout = $this->getStopTimeout($config);

        $io->text(\sprintf(
            'Workerman is %s...',
            $graceful ? 'gracefully stopping' : 'stopping',
        ));

        posix_kill($masterPid, $signal);

        if ($this->waitForProcessToStop($masterPid, $stopTimeout, $graceful)) {
            $io->success('Workerman stopped successfully.');

            return Command::SUCCESS;
        }

        $io->error('Workerman stop failed (timeout).');

        return Command::FAILURE;
    }

    private function executeRestart(InputInterface $input, SymfonyStyle $io): int
    {
        $config = $this->loadConfig();
        $masterPid = $this->getMasterPid($config);

        if ($this->isMasterRunning($masterPid)) {
            $graceful = $input->getOption('grace') === true;
            $signal = $graceful ? \SIGQUIT : \SIGINT;
            $stopTimeout = $this->getStopTimeout($config);

            $io->text(\sprintf(
                'Workerman is %s...',
                $graceful ? 'gracefully stopping' : 'stopping',
            ));

            posix_kill($masterPid, $signal);

            if (!$this->waitForProcessToStop($masterPid, $stopTimeout, $graceful)) {
                $io->error('Workerman stop failed (timeout). Cannot restart.');

                return Command::FAILURE;
            }

            $io->text('Workerman stopped. Restarting...');
        }

        Worker::$command = $this->buildCommand($input);
        $this->resetSignalHandlers();

        $runner = new Runner($this->createKernelFactory());

        return $runner->run();
    }

    private function executeReload(InputInterface $input, SymfonyStyle $io): int
    {
        $config = $this->loadConfig();
        $masterPid = $this->getMasterPid($config);

        if (!$this->isMasterRunning($masterPid)) {
            $io->warning('Workerman is not running.');

            return Command::FAILURE;
        }

        $graceful = $input->getOption('grace') === true;
        $signal = $graceful ? \SIGUSR2 : \SIGUSR1;

        posix_kill($masterPid, $signal);

        $io->success(\sprintf(
            'Workerman %s signal sent.',
            $graceful ? 'graceful reload' : 'reload',
        ));

        return Command::SUCCESS;
    }

    private function executeStatus(SymfonyStyle $io): int
    {
        $config = $this->loadConfig();
        $masterPid = $this->getMasterPid($config);

        if (!$this->isMasterRunning($masterPid)) {
            $io->warning('Workerman is not running.');

            return Command::FAILURE;
        }

        $statusFile = $this->getStatusFilePath($config);

        // Send SIGIOT to master — it tells all workers to write their status to the statistics file.
        posix_kill($masterPid, \SIGIOT);
        sleep(1);

        if (is_readable($statusFile)) {
            $lines = file($statusFile, \FILE_IGNORE_NEW_LINES);
            if (\is_array($lines) && $lines !== []) {
                // First line is serialized worker info (internal data) — skip it.
                unset($lines[0]);
                $output = implode("\n", $lines);
                if ($output !== '') {
                    $io->text($output);
                }
            }
            @unlink($statusFile);
        } else {
            $io->warning('No status data available.');
        }

        return Command::SUCCESS;
    }

    private function executeConnections(SymfonyStyle $io): int
    {
        $config = $this->loadConfig();
        $masterPid = $this->getMasterPid($config);

        if (!$this->isMasterRunning($masterPid)) {
            $io->warning('Workerman is not running.');

            return Command::FAILURE;
        }

        $connectionsFile = $this->getStatusFilePath($config) . '.connection';

        // Send SIGIO to master — it tells all workers to write their connection data.
        posix_kill($masterPid, \SIGIO);
        usleep(500_000);

        if (is_readable($connectionsFile)) {
            $content = file_get_contents($connectionsFile);
            if (\is_string($content) && $content !== '') {
                $io->text($content);
            }
            @unlink($connectionsFile);
        } else {
            $io->warning('No connection data available.');
        }

        return Command::SUCCESS;
    }

    /**
     * Wait for a process to stop, with proper zombie detection.
     *
     * Unlike posix_kill($pid, 0) which returns true for zombie processes,
     * this method checks /proc/$pid/status to detect zombies and considers
     * them as stopped.
     */
    private function waitForProcessToStop(int $pid, int $stopTimeout, bool $graceful): bool
    {
        $timeout = $stopTimeout + 3;
        $startTime = time();

        while (true) {
            if (!$this->isProcessAlive($pid)) {
                return true;
            }

            // For non-graceful stop, enforce timeout.
            if (!$graceful && (time() - $startTime) >= $timeout) {
                return false;
            }

            // For graceful stop, wait indefinitely (same as Workerman's behavior).
            usleep(10_000);
        }
    }

    /**
     * Check if a process is truly alive (not a zombie).
     *
     * posix_kill($pid, 0) returns true for zombie processes, which causes
     * false positives when checking if the master has stopped. This method
     * reads /proc/$pid/status to detect zombies.
     */
    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // First check: can we signal the process at all?
        if (!posix_kill($pid, 0)) {
            return false;
        }

        // Second check: is it a zombie? Zombies respond to posix_kill($pid, 0)
        // but are effectively dead — they're just waiting to be reaped by their parent.
        $statusFile = "/proc/{$pid}/status";
        if (is_readable($statusFile)) {
            $status = file_get_contents($statusFile);
            if (\is_string($status) && preg_match('/^State:\s+Z/m', $status)) {
                return false; // Zombie — effectively dead.
            }
        }

        return true;
    }

    /**
     * Check if the master process is running.
     */
    private function isMasterRunning(int $masterPid): bool
    {
        if ($masterPid <= 0) {
            return false;
        }

        if (!$this->isProcessAlive($masterPid)) {
            return false;
        }

        // Verify it's actually a Workerman/PHP process (not a recycled PID).
        $cmdline = "/proc/{$masterPid}/cmdline";
        if (is_readable($cmdline)) {
            $content = file_get_contents($cmdline);
            if (\is_string($content) && $content !== '') {
                return str_contains($content, 'WorkerMan') || str_contains($content, 'php');
            }
        }

        return true;
    }

    /**
     * Read the master PID from the PID file.
     *
     * @param array<string, mixed> $config
     */
    private function getMasterPid(array $config): int
    {
        $pidFile = $config['pid_file'] ?? '';

        if (!\is_string($pidFile) || $pidFile === '' || !is_file($pidFile)) {
            return 0;
        }

        $content = file_get_contents($pidFile);

        return \is_string($content) ? (int) $content : 0;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function getStopTimeout(array $config): int
    {
        $timeout = $config['stop_timeout'] ?? 2;

        return \is_int($timeout) ? $timeout : 2;
    }

    /**
     * Load the Workerman configuration from the cached config.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $cacheDir = $this->kernel->getContainer()->getParameter('kernel.cache_dir');

        if (!\is_string($cacheDir)) {
            throw new \RuntimeException('kernel.cache_dir parameter must be a string');
        }

        $configLoader = new ConfigLoader(
            projectDir: $this->kernel->getProjectDir(),
            cacheDir: $cacheDir,
            isDebug: $this->kernel->isDebug(),
        );

        /** @var array<string, mixed> */
        return $configLoader->getWorkermanConfig();
    }

    /**
     * Derive the status file path from the PID file path.
     *
     * This must match the path set in Runner::run() via Worker::$statusFile.
     *
     * @param array<string, mixed> $config
     */
    private function getStatusFilePath(array $config): string
    {
        $pidFile = $config['pid_file'] ?? '';

        if (!\is_string($pidFile)) {
            return '';
        }

        return preg_replace('/\.pid$/', '.status', $pidFile) ?? $pidFile;
    }

    private function buildCommand(InputInterface $input): string
    {
        $action = $input->getArgument('action');
        $command = \is_string($action) ? $action : '';

        if ($input->getOption('daemon')) {
            $command .= ' -d';
        }
        if ($input->getOption('grace')) {
            $command .= ' -g';
        }

        return $command;
    }

    private function resetSignalHandlers(): void
    {
        $signals = [\SIGINT, \SIGTERM, \SIGHUP, \SIGTSTP, \SIGQUIT, \SIGUSR1, \SIGUSR2, \SIGIOT, \SIGIO, \SIGPIPE];
        foreach ($signals as $signal) {
            pcntl_signal($signal, \SIG_DFL);
        }
    }

    private function createKernelFactory(): KernelFactory
    {
        return new KernelFactory(
            fn(...$args): \Symfony\Component\HttpKernel\KernelInterface => $this->kernel,
            [],
        );
    }
}
