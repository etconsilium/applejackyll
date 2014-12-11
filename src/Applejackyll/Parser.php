<?php
namespace \Applejackyll\Parser;
use \Applejackyll;

/**
 * Description of Parser
 *
 * @author vs
 */
class Parser{
    protected $_app;
    protected $dir;
    
    public function __construct($app=null)
    {
        if ($app instanceof \Applejackyll) {
            $this->_app = $app;
            $this->dir = $app->site['tmp'];
        }
    }
}
