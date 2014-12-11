<?php
namespace \Applejackyll\Storage;
use \Applejackyll;


use Doctrine\Common\Cache\FilesystemCache;
/**
 * Description of Storage
 *
 * @author vs
 */
class Storage extends \Doctrine\Common\Cache\FilesystemCache{
    //  @TODO   сделать нормальную имплементацию

    protected $_app;
    protected $dir;
    protected $fs;
    
    public function __construct($app=null)
    {
        if ($app instanceof \Applejackyll) {
            $this->_app = $app;
            $this->dir = $app->site['tmp'];
            $this->fs = new parent($this->dir);
        }
    }
    
    public function __call($method,$param)
    {
        if (method_exists($this->fs, $method))
            return call_user_func_array([$this->fs, $method], $param);
    }
}
