<?php

namespace CrazyGoat\WorkermanBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'workerman', description: 'cmd for workerman bundle')]
class WorkermanCommand extends Command
{
    private string $runtime;
    private string $phpCmd;

    public function __construct()
    {
        parent::__construct();
        $this->runtime = 'APP_RUNTIME=CrazyGoat\\\\WorkermanBundle\\\\Runtime';
        $this->phpCmd = 'php ./public/index.php';
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'Action: start|stop|restart|reload|status|connections')
            ->addOption('daemon', 'd', InputOption::VALUE_NONE, 'Run in daemon mode')
            ->addOption('grace', 'g', InputOption::VALUE_NONE, 'Gracefully operate')
        ;
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        if (!in_array($action, ['start', 'stop', 'restart', 'reload', 'status', 'connections'])) {
            $io->error(sprintf("Invalid action %s \n", $action));
            return Command::FAILURE;
        }
        $cmd = sprintf('%s %s %s', $this->runtime, $this->phpCmd, $action);
        if ($input->getOption('daemon')) {
            $cmd .= ' -d';
        }
        if ($input->getOption('grace')) {
            $cmd .= ' -g';
        }
        passthru($cmd, $returnCode);
        if ($returnCode !== 0) {
            $io->error(sprintf("Failed to execute cmd %s with exit code %s \n", $cmd, $returnCode,));
            return Command::FAILURE;
        } else {
            $io->success(sprintf("Workerman Http Server $action success. \n"));
            return Command::SUCCESS;
        }
    }
}
