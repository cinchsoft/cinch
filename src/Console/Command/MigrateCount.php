<?php

namespace Cinch\Console\Command;

use Cinch\Command\MigrateOptions;
use Cinch\Common\Author;
use Cinch\Component\Assert\Assert;
use Cinch\Console\Command;
use Cinch\History\DeploymentTag;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('migrate:count', 'Migrate the next count migrations')]
class MigrateCount extends Command
{
    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = (int) Assert::digit($input->getArgument('count'), 'count argument');

        $this->executeCommand(new \Cinch\Command\Migrate(
            $this->projectId,
            new DeploymentTag($input->getArgument('tag')),
            new Author($input->getOption('deployer') ?: system_user()),
            new MigrateOptions($count),
            $this->envName
        ), 'Migrating the next $count migration(s)');

        return self::SUCCESS;
    }

    protected function configure()
    {
        $this
            ->addProjectArgument()
            ->addArgument('number', InputArgument::REQUIRED, 'The number of eligible migrations to migrate')
            ->addOptionByName('deployer')
            ->addOptionByName('tag')
            ->addOptionByName('env')
            ->setHelp(<<<HELP
This will deploy the next <info><number></> eligible migrations. They are selected based on the sorting 
policy of the migration store's directory configuration. 

<code-comment># limit to the first 4 eligible migrations</>
<code>cinch migrate project-name 4 --tag=hotfix-72631<</>
HELP
            );
    }
}