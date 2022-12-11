<?php

namespace Cinch\Command;

use Cinch\Project\ProjectRepository;
use Exception;

class AddEnvironmentCommandHandler implements CommandHandler
{
    public function __construct(
        private readonly DataStoreFactory $dataStoreFactory,
        private readonly ProjectRepository $projectRepository)
    {
    }

    /**
     * @throws Exception
     */
    public function handle(AddEnvironmentCommand $c): void
    {
        /* fails if $name exists */
        $c->project->addEnvironment($c->name, $c->environment);

        /* test connection */
        $this->dataStoreFactory->createSession($c->environment->targetDsn)->close();

        /* fails if history exists. can't share history between environments or projects */
        $history = $this->dataStoreFactory->createHistory($c->environment);
        $history->create();

        try {
            $this->projectRepository->update($c->project);
        }
        catch (Exception $e) {
            ignoreException($history->delete(...));
            throw $e;
        }
    }
}