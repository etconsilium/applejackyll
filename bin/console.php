#!/usr/bin/env php
<?php
set_time_limit(0);
date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('apjack:start')
            ->setDescription('Start site generation')
            ->addArgument(
                'config'
                ,InputArgument::OPTIONAL
                ,'Path to config file site.yaml'
                ,'.'.DIRECTORY_SEPARATOR.'site.yaml'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $filename = $input->getArgument('config');

        $output->writeln($filename);
    }
}

$application = new Application();
$application->add(new StartCommand());
$application->run();

?>