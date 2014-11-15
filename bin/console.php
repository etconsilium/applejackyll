#!/usr/bin/env php
<?php
namespace Applejackyll;

set_time_limit(0);
date_default_timezone_set('Europe/Moscow');
if (is_readable(__DIR__ . '/../vendor/autoload.php') ) require_once __DIR__ . '/../vendor/autoload.php';
elseif (is_readable(__DIR__ . '/vendor/autoload.php') ) require_once __DIR__ . '/vendor/autoload.php';
elseif (is_readable(getcwd() . '/../vendor/autoload.php') ) require_once getcwd() . '/../vendor/autoload.php';
elseif (is_readable(getcwd() . '/vendor/autoload.php') ) require_once getcwd() . '/vendor/autoload.php';
elseif (is_readable('vendor/autoload.php') ) require_once 'vendor/autoload.php';
else die(-1);

define('DEFAULT_CONFIG_FILENAME', getcwd().DIRECTORY_SEPARATOR.'site.yaml');

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Console extends \Symfony\Component\Console\Application
{
    /**
     * Gets the default input definition.
     *
     * @return InputDefinition An InputDefinition instance
     */
    protected function getDefaultInputDefinition()
    {
        return new \Symfony\Component\Console\Input\InputDefinition(array(
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),

            new InputOption('--help',           '-h', InputOption::VALUE_NONE, 'Display this help message.'),
            new InputOption('--quiet',          '-q', InputOption::VALUE_NONE, 'Do not output any message.'),
            new InputOption('--config',         '-c', InputOption::VALUE_REQUIRED, 'Path to config file <site.yaml>', DEFAULT_CONFIG_FILENAME),
            new InputOption('--version',        '-V', InputOption::VALUE_NONE, 'Display this application version.'),
//            new InputOption('--ansi',           '',   InputOption::VALUE_NONE, 'Force ANSI output.'),
            new InputOption('--no-ansi',        '',   InputOption::VALUE_NONE, 'Disable ANSI output.'),
//            new InputOption('--no-interaction', '-n', InputOption::VALUE_NONE, 'Do not ask any interactive question.'),
        ));
    }
}

class StartCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('start')
            ->setDescription('Start site generation')
        ;
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
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
                'action'
                ,InputArgument::OPTIONAL
                ,'Can take values: start, stop, restart, status'
                ,'status'
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
        ;
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        $server = (new \Applejackyll\Applejackyll( $input->getOption('config') ))
                    ->server()
                        ->set($input->getOption('host')
                            ,$input->getOption('port')
                            ,$input->getOption('docroot')
                        );
        if (method_exists($server,$action)) $server->$action();
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
            (new \Applejackyll\Applejackyll($input->getOption('config')))->clearCache();
        }
        else {
            (new \Applejackyll\Applejackyll($input->getOption('config')))->deleteByDate(new \DateTime($date_after),new \DateTime($date_before));
        }
    }
}


$console = new Console('\\Applejackyll\\Console',\Applejackyll\Applejackyll::VERSION);
$console->add(new StartCommand());
$console->add(new ClearcacheCommand());
$console->add(new ServerCommand());
$console->run();

?>