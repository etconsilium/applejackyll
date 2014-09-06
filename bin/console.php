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
            ->setName('start')
            ->setDescription('Start site generation')
            ->addArgument(
                'config'
                ,InputArgument::OPTIONAL
                ,'Path to config file site.yaml'
                ,'.'.DIRECTORY_SEPARATOR.'site.yaml'
            )
//            ->addArgument(
//                'homedir'
//                ,InputArgument::OPTIONAL
//                ,'Site directory'
//                ,getcwd().DIRECTORY_SEPARATOR.'site'
//            )
        ;
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
//        $config = $input->getArgument('config');
//        $homedir = $input->getArgument('homedir');

        (new \Applejackyll\Applejackyll( $input->getArgument('config') ));//->parse();
    }
}
class ClearcacheCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('clearcache')
            ->setDescription('Clear all caches')
            ->addArgument(
                'after'
                ,InputArgument::OPTIONAL
                ,'Clear cache for pages after `date`'
            )
            ->addArgument(
                'before'
                ,InputArgument::OPTIONAL
                ,'Clear cache for pages before `date`'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $date_before = $input->getArgument('before');
        $date_after = $input->getArgument('after');
    }
}

$application = new Application();
$application->add(new StartCommand());
$application->add(new ClearcacheCommand());
$application->run();

?>