<?php
/**
 * @package Applejackyll
 * организует работы с сервером
 */

namespace Applejackyll;

/**
 * Description of Server
 *
 * @author vs
 */
class Server{
    const COMMAND_LINE='php -S %s:%s -t %s >/dev/null 2>/dev/null & echo $!';
    const STATUS_RUN='run';
    const STATUS_EMPTY='empty';
    const FILE_NAME='_server.pid';

    private $_app;
    private $_host, $_port, $_docroot;
    private $_status;

    function __construct($app)
    {
        $this->_app=$app;
        return $this;
    }

    private function _pidfile()
    {
        return $this->_app->site['temp'].DIRECTORY_SEPARATOR.self::FILE_NAME;
    }

    private function _lock($pid)
    {
        if (!$this->_process_id()) {
            file_put_contents($this->_pidfile(),$pid,LOCK_EX);
        }
        return $this;
    }

    private function _process_id()
    {
        $pf=$this->_pidfile();
        return is_readable($pf) ? (int)file_get_contents($pf) : 0;
    }

    private function _command_string()
    {
        return sprintf(self::COMMAND_LINE,$this->_host,$this->_port,$this->_docroot);
    }

    public function set($host, $port, $docroot)
    {
        $this->_host=$host; $this->_port=$port; $this->_docroot=(empty($docroot)?$this->_app->site['destination']:$docroot);
        return $this;
    }

    public function start()
    {
        if (!$pid=$this->_process_id()) {
            ob_start();
            $pid=(int)system( $this->_command_string() );
            ob_end_clean();
            if ($pid) $this->_lock($pid);
            else throw new \ErrorException('что-то пошло не так');
        }
        else
            throw new \ErrorException('кажется, сервер уже запущен. pid='.$pid);
    }

    public function status()
    {

    }


    public function stop()
    {

    }

    public function restart()
    {

    }
}
