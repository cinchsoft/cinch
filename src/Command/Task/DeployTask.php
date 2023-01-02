<?php

namespace Cinch\Command\Task;

use Cinch\Command\Task;
use Cinch\Command\TaskAttribute;
use Cinch\Database\Session;
use Cinch\History\Change;
use Cinch\History\ChangeStatus;
use Cinch\History\Deployment;
use Cinch\History\DeploymentCommand;
use Cinch\MigrationStore\Migration;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

#[TaskAttribute('this is', 'not used')]
class DeployTask extends Task
{
    /**
     * @throws Exception
     */
    public function __construct(
        private readonly Migration $migration,
        private readonly ChangeStatus $status,
        private readonly Session $target,
        private readonly Deployment $deployment)
    {
        parent::__construct();

        if ($this->deployment->getCommand() == DeploymentCommand::ROLLBACK)
            $name = 'rolling back script';
        else
            $name = 'migrating script (' . $this->migration->getScript()->getMigratePolicy()->value . ')';

        $this->setName($name);
        $this->setDescription($this->migration->getPath());
    }

    protected function doRun(): void
    {
        $addedChange = false;
        $isSingleTransactionMode = $this->deployment->isSingleTransactionMode();

        try {
            if (!$isSingleTransactionMode)
                $this->target->beginTransaction();

            $command = $this->deployment->getCommand()->value;
            $this->migration->getScript()->$command($this->target);
            $addedChange = $this->addChange($this->status, $this->migration);

            if (!$isSingleTransactionMode)
                $this->target->commit();
        }
        catch (Exception $e) {
            if (!$isSingleTransactionMode) {
                silent_call($this->target->rollBack(...));
                if ($addedChange)
                    silent_call($this->deployment->removeChange(...), $this->migration->getPath());
            }

            throw $e;
        }
    }

    protected function doUndo(): void
    {
    }

    /**
     * @throws Exception
     */
    private function addChange(ChangeStatus $status, Migration $migration): bool
    {
        $script = $migration->getScript();

        $this->deployment->addChange(new Change(
            $migration->getPath(),
            $this->deployment->getTag(),
            $script->getMigratePolicy(),
            $status,
            $script->getAuthor(),
            $migration->getChecksum(),
            $script->getDescription(),
            $script->getLabels(),
            $script->getAuthoredAt(),
            new DateTimeImmutable(timezone: new DateTimeZone('UTC'))
        ));

        return true;
    }
}