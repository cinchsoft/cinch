<?php

namespace Cinch\MigrationStore\Script;

use Cinch\Common\Author;
use Cinch\Common\MigratePolicy;
use Cinch\Common\Description;
use DateTimeInterface;
use Cinch\Database\Session;
use Exception;

class SqlMigrateScript extends Script implements CanMigrate
{
    public function __construct(
        private readonly string $migrateSql,
        MigratePolicy $migratePolicy,
        Author $author,
        DateTimeInterface $authoredAt,
        Description $description)
    {
        parent::__construct($migratePolicy, $author, $authoredAt, $description);
    }

    /**
     * @throws Exception
     */
    public function migrate(Session $session): void
    {
        $session->executeStatement($this->migrateSql);
    }
}