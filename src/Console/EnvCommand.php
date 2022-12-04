<?php

namespace Cinch\Console;

use Cinch\Project\ProjectRepository;
use Cinch\Services\AddEnvironment;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('env', 'Adds a new environment')]
class EnvCommand extends AbstractCommand
{
    public function __construct(
        private readonly AddEnvironment $addEnvironment,
        private readonly ProjectRepository $projectRepository)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setHelp('This does cool stuff')
            ->addProjectArgument()
            ->addArgument('name', InputArgument::REQUIRED, 'Environment name')
            ->addEnvironmentOptions();
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->projectRepository->get($this->projectId);
        $name = $input->getArgument('name');
        $environment = $this->getEnvironmentFromInput($input, $project->getName());

        $this->logger->info("adding environment $name to project {$project->getName()}");
        $this->addEnvironment->execute($project, $name, $environment);

        return self::SUCCESS;
    }

    public function handleSignal(int $signal): never
    {
        echo "delete project\n";
        parent::handleSignal($signal);
    }
}