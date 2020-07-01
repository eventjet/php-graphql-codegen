<?php

declare(strict_types=1);

namespace Eventjet\GraphqlCodegen\Cli;

use Eventjet\GraphqlCodegen\Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateCommand extends Command
{
    protected static $defaultName = 'graphql:generate';

    protected function configure()
    {
        $this
            ->setDescription('Generate DTOs from a graphql endpoint.')
            ->setHelp('This command allows you to generate DTOs from a passed graphql endpoint');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dir = getcwd();
        $output->writeln(\Safe\sprintf('Generating DTOs from schema.graphql in %s/data', $dir));
        Generator::run(
            $dir . '/data/schema.graphql',
            $dir . '/data/getEvents.graphql',
            'Generated',
            $dir . '/data/gql-types/',
            '7.3'
        );
        return Command::SUCCESS;

        // or return this if some error happened during the execution
        // (it's equivalent to returning int(1))
        // return Command::FAILURE;
    }
}
