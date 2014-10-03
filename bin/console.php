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
            ->addOption('config','c'
                ,InputOption::VALUE_OPTIONAL
                ,'Path to config file <site.yaml>'
                ,getcwd().DIRECTORY_SEPARATOR.'site.yaml'
            )
        ;
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
//        $config = $input->getArgument('config');
//        $homedir = $input->getArgument('homedir');

        (new \Applejackyll\Applejackyll( $input->getOption('config') ))->parse();
    }
}
class ServerCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('server')
            ->setDescription('Operations with built-in-php web-server...')
            ->addArgument(
                'start'
                ,InputArgument::OPTIONAL
                ,'start'
            )
            ->addArgument(
                'stop'
                ,InputArgument::OPTIONAL
                ,'stop'
            )
            ->addArgument(
                'restart'
                ,InputArgument::OPTIONAL
                ,'restart'
            )
            ->addArgument(
                'status'
                ,InputArgument::OPTIONAL
                ,'show status. default command'
            )
            ->addOption('host','a'
                ,InputOption::VALUE_OPTIONAL
                ,'Specify address (hostname or ip) for built-in web server'
                ,'127.0.0.1'
            )
            ->addOption('port','p'
                ,InputOption::VALUE_OPTIONAL
                ,'Specify port for built-in web server'
                ,4040
            )
            ->addOption('docroot','r'
                ,InputOption::VALUE_OPTIONAL
                ,'Specify document root <docroot> for built-in web server'
                ,null
            )
            ->addOption('config','c'
                ,InputOption::VALUE_OPTIONAL
                ,'Path to config file <site.yaml>'
                ,getcwd().DIRECTORY_SEPARATOR.'site.yaml'
            )

        ;
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
//        $config = $input->getArgument('config');
//        $homedir = $input->getArgument('homedir');

//        (new \Applejackyll\Applejackyll( $input->getArgument('config') ))->parse();
    }
}
class ClearcacheCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('clearcache')
            ->setDescription('Clear all caches')
            ->addOption('after','a'
                ,InputOption::VALUE_OPTIONAL
                ,'Clear cache for pages after `date`'
                ,null
            )
            ->addOption(
                'before','b'
                ,InputOption::VALUE_OPTIONAL
                ,'Clear cache for pages before `date`'
                ,null
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $date_after = $input->getOption('after');
        $date_before = $input->getOption('before');
        if (is_null($date_before) && is_null($date_after)) {
            (new \Applejackyll\Applejackyll())->clearCache();
        }
        else {
            (new \Applejackyll\Applejackyll())->deleteByDate(new \DateTime($date_after),new \DateTime($date_before));
        }
    }
}

$application = new Application();
$application->add(new StartCommand());
$application->add(new ClearcacheCommand());
$application->add(new ServerCommand());
$application->run();

?>