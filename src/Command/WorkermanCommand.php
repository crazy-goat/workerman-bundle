<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Command;

use CrazyGoat\WorkermanBundle\Exception\ServerAlreadyRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerNotRunningException;
use CrazyGoat\WorkermanBundle\Exception\ServerStopFailedException;
use CrazyGoat\WorkermanBundle\ServerManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'workerman:server', description: 'Manage the Workerman server')]
final class WorkermanCommand extends Command
{
    public function __construct(
        private readonly ServerManager $serverManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'action',
            InputArgument::REQUIRED,
            'Action: ' . implode('|', ServerAction::values()),
            null,
            ServerAction::values(),
        )
            ->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run in daemon mode')
            ->addOption('grace', 'g', InputOption::VALUE_NONE, 'Gracefully operate')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $allowedActions = 'Invalid action. Allowed: ' . implode(', ', ServerAction::values());

        if (!\is_string($action)) {
            $io->error($allowedActions);

            return Command::FAILURE;
        }

        $serverAction = ServerAction::tryFrom($action);

        if ($serverAction === null) {
            $io->error($allowedActions);

            return Command::FAILURE;
        }

        $daemon = (bool) $input->getOption('daemon');
        $graceful = (bool) $input->getOption('grace');

        try {
            return match ($serverAction) {
                ServerAction::Start => $this->handleStart($io, $daemon, $graceful),
                ServerAction::Stop => $this->handleStop($io, $graceful),
                ServerAction::Restart => $this->handleRestart($io, $daemon, $graceful),
                ServerAction::Reload => $this->handleReload($io, $graceful),
                ServerAction::Status => $this->handleOutput($io, $this->serverManager->getStatus(), 'No status data available.'),
                ServerAction::Connections => $this->handleOutput($io, $this->serverManager->getConnections(), 'No connection data available.'),
            };
        } catch (ServerNotRunningException | ServerAlreadyRunningException | ServerStopFailedException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function handleStart(SymfonyStyle $io, bool $daemon, bool $graceful): int
    {
        $io->text('Workerman is starting...');

        return $this->serverManager->start($daemon, $graceful);
    }

    private function handleStop(SymfonyStyle $io, bool $graceful): int
    {
        $io->text(\sprintf('Workerman is %s...', $graceful ? 'gracefully stopping' : 'stopping'));

        if (!$this->serverManager->stop($graceful)) {
            throw new ServerStopFailedException();
        }

        $io->success('Workerman stopped successfully.');

        return Command::SUCCESS;
    }

    private function handleRestart(SymfonyStyle $io, bool $daemon, bool $graceful): int
    {
        if ($this->serverManager->isRunning()) {
            $io->text(\sprintf('Workerman is %s...', $graceful ? 'gracefully stopping' : 'stopping'));
        }

        $result = $this->serverManager->restart($daemon, $graceful);

        $io->text('Workerman restarted.');

        return $result;
    }

    private function handleReload(SymfonyStyle $io, bool $graceful): int
    {
        $this->serverManager->reload($graceful);

        $io->success(\sprintf('Workerman %s signal sent.', $graceful ? 'graceful reload' : 'reload'));

        return Command::SUCCESS;
    }

    private function handleOutput(SymfonyStyle $io, ?string $content, string $emptyMessage): int
    {
        if ($content !== null) {
            $io->text($content);
        } else {
            $io->warning($emptyMessage);
        }

        return Command::SUCCESS;
    }
}
