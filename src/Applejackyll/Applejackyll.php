<?php namespace Applejackyll;

define ('TIMESTART',microtime(1));
define ('APP_SALT',md5_file(__FILE__));
define ('APP_ID',__NAMESPACE__);

use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\MemcacheCache;
use Doctrine\Common\Cache\MemcachedCache;
use Doctrine\Common\Cache\MongoDBCache;
use Doctrine\Common\Cache\PhpFileCache;
use Doctrine\Common\Cache\RedisCache;
use Doctrine\Common\Cache\XcacheCache;
use Doctrine\Common\Cache\ZendDataCache;
use \Symfony\Component\Finder\Finder;
use \Symfony\Component\Filesystem\Filesystem;
use \Symfony\Component\Yaml\Yaml;   //  кривой
use TwigTestExtension;
use Twig_Autoloader;
use Twig_Environment;
use Twig_Extension;
use \Aptoma\Twig\Extension\MarkdownExtension;
use \Aptoma\Twig\Extension\MarkdownEngine;

class CacheSpooler extends \ArrayObject{
    protected $_tmpdir;
    function __construct($cache_list=null, $default_temp=null){
        //  второй параметр злой хардкод
        $this->_tmpdir = empty($default_temp) ? sys_get_temp_dir().DIRECTORY_SEPARATOR.APP_ID : $default_temp ;
        var_dump($this->_tmpdir);
        is_array($cache_list) && $this->init($cache_list);
    }

    function __call($method,$args) {
        foreach ($this as $name => $cache) {
            if (method_exists($cache, $method))
                return call_user_func_array([$cache, $method], $args);
        }
    }

    public function init($configs){
        foreach ($configs as $name=>$cache) {
            switch ($cache['adapter']) :
                case 'Apc':
                    $this[$name]=new \Doctrine\Common\Cache\ApcCache;
                    break;
                case 'Array':
                    $this[$name]=new \Doctrine\Common\Cache\ArrayCache;
                    break;
//                case 'Couchbase': //  через пекл надо ставить
//                    $this[$name]=(new \Doctrine\Common\Cache\CouchbaseCache())->setCouchbase()
//                    break;
                case 'Filesystem':
                    empty($cache['dir']) && $cache['dir']=$this->_tmpdir;
                    $this[$name]=(new FilesystemCache($cache['dir']));
                    break;
//                case 'Memcache':
//                    $this[$name]=(new MemcacheCache())->setMemcache((new \Memcache()->addserver()));
//                    break;
                case 'Memcached':
                    empty($cache['servers']) && $cache['servers'][]=['localhost',11211,50];
                    $memcached=new \Memcached(empty($cache['persistent'])?APP_ID:null);
                    $memcached->addServers($cache['servers']);
                    $this[$name]=new MemcachedCache();
                    $this[$name]->setMemcached($memcached);
                    break;
                case 'MongoDB':
                    empty($cache['server']) && $cache['server']='mongodb://localhost:27017';
                    empty($cache['options']) && $cache['options']=['connection'=>true];
                    empty($cache['database']) && $cache['database']='applejackyll';
                    empty($cache['collection']) && $cache['collection']='cache';
                    //  MongoConnectionException при ошибке
                    $this[$name]=(new MongoDBCache(
                        new \MongoCollection(
                            new \MongoDB(
                                new \Mongo($cache['server'],$cache['options'])
                            ,$cache['database'])
                        ,$cache['collection'])
                    ));
                    break;
                case 'PhpFile':
                    empty($cache['dir']) && $cache['dir']=$this->_tmpdir;
                    $this[$name]=(new PhpFileCache($cache['dir']));
                    break;
//                case 'Redis': //  pecl again
//                    $this[$name]=(new RedisCache())->setRedis((new \Redis())->connect($cache['host'],$cache['port']));
//                    break;
//                case 'Riak':
//                    break;
//                case 'WinCache':
//                    break;
                case 'Xcache':
                    $this[$name]=new XcacheCache;
                    break;
                case 'ZendData':
                    $this[$name]=new ZendDataCache;
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Not supported `%s` cache adapter. Canceled',$name));
                    die;
            endswitch;
        }
    }
}

class Applejackyll extends \stdClass{

    public  $config=['site'=>[],'posts'=>[],'categories'=>[],'tags'=>[]];
    public  $site=['pages'=>[],'posts'=>[],'categories'=>[],'tags'=>[]];
    private $page=[
                    'layout'=>'post'
                    ,'id'=>null
                    ,'date'=>null
                    ,'title'=>''
                    ,'content'=>''
                    ,'permalink'=>null
                    ,'path'=>null
                    ,'previous'=>null
                    ,'next'=>null
                    ,'published'=>true
                    ,'categories'=>[]
                    ,'tags'=>[]
                   ];

    public function __construct($config=null){
        is_string($config) && $this->init($config);
    }
    /**
     * Parser initialization
     *
     * @param string Filename (or data-array)
     * @return $this
     */
    public function init($configfile){
        $this->site=\Symfony\Component\Yaml\Yaml::parse( file_get_contents($configfile) );
        $this->config=new \ArrayObject($this->config,\ArrayObject::ARRAY_AS_PROPS);
//var_dump($this->config->site); die;
        $site=&$this->site;
        $site['time']=TIMESTART;
//        $page=&$this->site->page;
//        $this->site->pages[]=&$page;
        $page=$this->page;
//        $categories=&$this->site->categories;
//        $tags=&$this->site->tags;


        if (!empty($site['timezone'])) date_default_timezone_set($site['timezone']);

        if (empty($site['root'])) $site['root'] = getcwd();
        $source_dir=$site['root'].DIRECTORY_SEPARATOR.$site['source'];

        $finder=(new Finder)->files();
        foreach ($site['include'] as $fn) $finder->name($fn);
        foreach ($site['exclude'] as $fn) $finder->exclude($fn);
        foreach ($site['notname'] as $fn) $finder->notName($fn);
        $posts=$finder
            ->useBestAdapter()
            ->in($source_dir)
            ->ignoreDotFiles(1)
            ->ignoreVCS(1)
            ->ignoreUnreadableDirs(1)
            ->sortByName()
        ;

        $cache=new CacheSpooler($site['cache'], $site['root'].DIRECTORY_SEPARATOR.$site['temp']);
        $filesystem=new Filesystem();
        $tmp_path=$site['root'].DIRECTORY_SEPARATOR.$site['temp'].DIRECTORY_SEPARATOR;
        $categories=[]; $tags=[];
        /**
         * @var $file \SplFileInfo
         */
        foreach ($posts as $file) { //  перевернём позже
        //  здесь только локальные переменные
            $page=$this->page;  //  шаблон
            $realpath=$file->getRealPath();
            $ar=explode('---',trim(file_get_contents($realpath)));

            if (1===count($ar)) {
                $page['content']=trim($ar[0]);
            }
            elseif (2===count($ar)) {
                $page=array_replace_recursive($page,Yaml::parse($ar[0]));
                $page['content']=trim($ar[1]);
            }
            elseif (2<count($ar)) {
                $page=array_replace_recursive($page,Yaml::parse(array_shift($ar)));
                $page['content']=trim(implode('---',$ar));
            }
            //  заполняем переменные
            $page['id']=sha1($realpath);  //  нужен неизменяемый вариант для адреса в рсс\атом

            //  поправить все пути. придумать ид. сделать выделение времени
            $page['url']=
            $page['permalink']=$site['baseurl']
                .($file->getRelativePath()).'/'
                .($file->getBasename($file->getExtension())).'html';    //  hardcode

            $page['date']=$file->getMTime();
            $page['path']=$realpath;  //  raw
var_dump($page);
            //
            if (!empty($page['category'])) $page['categories'][]=$page['category'];
            if (!empty($page['tag'])) $page['tags'][]=$page['tag'];

            $categories=array_merge($categories,$page['categories']);
            $tags=array_merge($tags,$page['tags']);

            /**
             * $cache \Doctrine\Common\Cache\MemcachedCache
             */
//            (new \Doctrine\Common\Cache\MemcachedCache)->save();
            $cache->save('page#'.$page['id'],$page);
            $cache->save('categories',$categories);
            $cache->save('tags',$tags);

/*  это позже
            foreach ($page['categories'] as $tmp) {
                //  два действия сразу
                $site['categories'][$tmp][]=$page['permalink'];
                $filesystem->symlink($fn,$site['root'].DIRECTORY_SEPARATOR.$site['destination'].DIRECTORY_SEPARATOR.$site['category_dir'].$page['permalink']);
            }
            foreach ($page['tags'] as $tmp) {
                $site['tags'][$tmp][]=$page['permalink'];
                $filesystem->symlink($fn,$site['root'].DIRECTORY_SEPARATOR.$site['destination'].$site['tag_dir'].$page['permalink']);
            }*/
        }

        return $this;
    }
    /**
     *
     *
     * @param string $data pagers data
     * @return $this
     */
    public function parse($data=null){
        \Twig_Autoloader::register();
        $twig=new \Twig_Environment(new \Twig_Loader_String());
        // Uses dflydev\markdown engine
        //$engine = new MarkdownEngine\DflydevMarkdownEngine();

        // Uses Michelf\Markdown engine (if you prefer)
        $engine = new \Aptoma\Twig\Extension\MarkdownEngine\MichelfMarkdownEngine();

        $twig->addExtension(new \Aptoma\Twig\Extension\MarkdownExtension($engine));

        if (!empty($this->target))
            foreach ($this->target as $fn) {
                $a=spyc_load(file_get_contents($fn));   //  снова косяк с загрузкой из файла
                $content=$a['content'];
                $t=$twig->render('{% markdown %}'.$a['content'].'{% endmarkdown %}', array('site'=>$this->config['site'],'page'=>$a));
                file_put_contents($fn,$t,LOCK_EX);  //  не зачем напрягать фреймворки
            }
        return $this;
    }

}
