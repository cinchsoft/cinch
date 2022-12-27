<?php

namespace Cinch\Command;

use Cinch\History\Change;
use Cinch\History\HistoryFactory;
use Cinch\MigrationStore\MigrationStoreFactory;
use Cinch\Project\ProjectRepository;
use Exception;

class RemoveMigrationHandler extends Handler
{
    public function __construct(
        private readonly MigrationStoreFactory $migrationStoreFactory,
        private readonly HistoryFactory $historyFactory,
        private readonly ProjectRepository $projectRepository)
    {
    }

    /**
     * @throws Exception
     */
    public function handle(RemoveMigration $c): void
    {
        $project = $this->projectRepository->get($c->projectId);

        $changes = $this->historyFactory
            ->create($project->getEnvironmentMap()->get($c->envName))
            ->getChangeView()
            ->getMostRecentChanges([$c->path]);

        /** @var Change $change */
        if ($change = array_shift($changes))
            throw new \RuntimeException(sprintf(
                "cannot remove migration '%s': last deployed '%s', status '%s', policy '%s', tag '%s'",
                $c->path,
                $change->deployedAt->format('Y-m-d H:i:s.uP'),
                $change->status->value,
                $change->migratePolicy->value,
                $change->tag->value,
            ));

        $dsn = $project->getMigrationStoreDsn();
        $this->addTask(new Task\RemoveMigration($dsn, $c->path, $this->migrationStoreFactory))->runTasks();
    }
}