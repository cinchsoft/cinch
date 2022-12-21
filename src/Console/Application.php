<?php

namespace Cinch\Console;

use Cinch\Component\Assert\Assert;
use Cinch\Io;
use Cinch\Project\ProjectName;
use Exception;
use League\Tactician\CommandBus;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Path;
use Throwable;

class Application extends BaseApplication
{
    public function __construct(string $name, private readonly ConsoleIo $io)
    {
        parent::__construct($name);

        $this->setAutoExit(false);
        $this->configureIO($this->io->getInput(), $this->io->getOutput());
        $this->io->getInput()->setInteractive(false);

        /* this dispatcher is only used for console events */
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ConsoleEvents::COMMAND, $this->onConsoleCommand(...));
        $this->setDispatcher($dispatcher);
    }

    /** $input and $output are ignored. This will always use the Io instance passed to constructor.
     * @throws Exception
     */
    public function run(InputInterface $input = null, OutputInterface $output = null): int
    {
        return parent::run($this->io->getInput(), $this->io->getOutput());
    }

    /** Loads environment variables from .env.local if it exists, otherwise .env.prod.
     * @return string the loaded environment: either local or prod
     */
    public function loadEnv(string $envDir): string
    {
        $dotEnv = (new Dotenv())->usePutenv();

        if (($env = getenv('CINCH_ENV')) !== false && $env != 'local' && $env != 'prod')
            $env = false;

        if (!$env) {
            /* prod builds do not include .env.local. So if local exists, use it. */
            $env = file_exists("$envDir/.env.local") ? 'local' : 'prod';
            $dotEnv->populate(['CINCH_ENV' => $env], overrideExistingVars: true);
        }

        $dotEnv->load("$envDir/.env.$env");

        return $env;
    }

    protected function doRenderThrowable(Throwable $e, OutputInterface $output): void
    {
        /* RenderThrowableOutput suppresses previous exceptions */
        parent::doRenderThrowable($e, new RenderThrowableOutput($output));
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = new InputDefinition();
        $default = parent::getDefaultInputDefinition();

        $definition->setArguments($default->getArguments());
        $definition->setOptions([
            new InputOption('working-dir', 'w', InputOption::VALUE_REQUIRED, 'Sets the working directory [default: pwd]'),
            new InputOption('time-zone', 'z', InputOption::VALUE_REQUIRED, 'Sets the time zone for logging and display [default: system]'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Performs all actions and logging without executing [default: off]'),
            ...array_filter($default->getOptions(), fn($o) => $o->getName() != 'no-interaction')
        ]);

        return $definition;
    }

    protected function getDefaultCommands(): array
    {
        return [
            new HelpCommand(), // symfony
            new ListCommand(), // symfony
            new Command\Create(),
            new Command\Env(),
            new Command\EnvAdd(),
            new Command\EnvRemove(),
            new Command\Migrate(),
            new Command\MigrateCount(),
            new Command\MigratePaths(),
            new Command\MigrationAdd(),
            new Command\MigrationRemove()
        ];
    }

    /**
     * @throws Exception
     */
    private function compileContainer(string $projectDir): Container
    {
        $rootDir = dirname(__DIR__, 2);
        $resourceDir = "$rootDir/resources";

        $container = new ContainerBuilder();
        $container->setParameter('cinch.version', getenv('CINCH_VERSION'));
        $container->setParameter('cinch.resource_dir', $resourceDir);
        $container->setParameter('schema.version', getenv('CINCH_SCHEMA_VERSION'));
        $container->setParameter('schema.description', getenv('CINCH_SCHEMA_DESCRIPTION'));
        $container->setParameter('schema.release_date', getenv('CINCH_SCHEMA_RELEASE_DATE'));
        $container->setParameter('twig.auto_reload', getenv('CINCH_ENV') !== 'prod');
        $container->setParameter('twig.debug', $this->io->getOutput()->isDebug());
        $container->setParameter('twig.template_dir', $resourceDir);
        $container->setParameter('project.dir', $projectDir);
        $container->set(Io::class, $this->io);

        $loader = new YamlFileLoader($container, new FileLocator("$rootDir/config"));
        $loader->load('services.yml');
        $container->compile();

        return $container;
    }

    /** This is dispatched just after binding input and before command.run().
     * @throws Exception
     */
    private function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if (!($command instanceof Command))
            return;

        $input = $event->getInput();
        $workingDir = $input->getOption('working-dir') ?? getcwd();
        $workingDir = Assert::directory(Path::makeAbsolute($workingDir, getcwd()), 'working-dir');
        $projectDir = Path::join($workingDir, new ProjectName($input->getArgument('project')));
        $container = $this->compileContainer($projectDir);

        $command->setConsoleIo($this->io);
        $command->setProjectDir($projectDir);
        $command->setCommandBus($container->get(CommandBus::class));
        $container->get(EventDispatcherInterface::class)->addSubscriber($command);
    }
}