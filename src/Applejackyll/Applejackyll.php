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

class CacheSpooler implements \Doctrine\Common\Cache\Cache{

    protected $_tmpdir;
    protected $_spool=[];
    protected $_priority=[];
    protected $_mem_start;
    protected $_mem_limit;

    function __construct($cache_list=null, $default_temp=null) {
        $this->_mem_start=memory_get_usage();
        is_array($cache_list) && $this->init($cache_list, $default_temp=null);
        return $this;
    }

    public function init($configs, $default_temp=null){
        //  второй параметр злой хардкод
        $this->_tmpdir = empty($default_temp) ? sys_get_temp_dir().DIRECTORY_SEPARATOR.APP_ID : $default_temp ;
        foreach ($configs as $id=>$c) {
            $prt=$c['priority'];
            $cache=$c['adapter'];
            switch (strtolower($cache['name'])) :
                case 'apc':
                    $this->_spool[$id]=new \Doctrine\Common\Cache\ApcCache;
                    break;
//                case 'array':
//                    $this->_spool[$id]=new \Doctrine\Common\Cache\ArrayCache;   //  bug inside
//                    break;
//                case 'Couchbase': //  pecl require
//                    $this->_spool[$id]=(new \Doctrine\Common\Cache\CouchbaseCache())->setCouchbase()
//                    break;
                case 'filesystem':
                    empty($cache['dir']) && $cache['dir']=$this->_tmpdir;
                    $this->_spool[$id]=(new FilesystemCache($cache['dir']));
                    break;
//                case 'memcache':  //  ooooldschooool
//                    $this->_spool[$id]=(new MemcacheCache())->setMemcache((new \Memcache()->addserver()));
//                    break;
                case 'memcached':
                    empty($cache['servers']) && $cache['servers'][]=['localhost',11211,50];
                    $memcached=new \Memcached(empty($cache['persistent'])?APP_ID:null);
                    $memcached->addServers($cache['servers']);
                    $this->_spool[$id]=new MemcachedCache();
                    $this->_spool[$id]->setMemcached($memcached);
                    break;
                case 'mongodb':
                    empty($cache['server']) && $cache['server']='mongodb://localhost:27017';
                    empty($cache['options']) && $cache['options']=['connection'=>true];
                    empty($cache['database']) && $cache['database']='applejackyll';
                    empty($cache['collection']) && $cache['collection']='cache';
                    //  MongoConnectionException
                    $this->_spool[$id]=(new MongoDBCache(
                        new \MongoCollection(
                            new \MongoDB(
                                new \Mongo($cache['server'],$cache['options'])
                                ,$cache['database'])
                            ,$cache['collection'])
                    ));
                    break;
                case 'phpfile':
                    empty($cache['dir']) && $cache['dir']=$this->_tmpdir;
                    $this->_spool[$id]=(new PhpFileCache($cache['dir']));
                    break;
//                case 'redis': //  pecl again
//                    $this->_spool[$id]=(new RedisCache())->setRedis((new \Redis())->connect($cache['host'],$cache['port']));
//                    break;
//                case 'riak':  //  pecl
//                    break;
//                case 'wincache':  //  wtf
//                    break;
                case 'xcache':
                    $this->_spool[$id]=new XcacheCache;
                    break;
                case 'zenddata':
                    $this->_spool[$id]=new ZendDataCache;
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Not supported `%s` cache adapter. Canceled',$id));
                    die;
            endswitch;

            if (!empty($prt['save'])) $this->_priority['save'][$prt['save']]=&$this->_spool[$id];
            else $this->_priority['save'][]=&$this->_spool[$id];
            if (!empty($prt['delete'])) $this->_priority['delete'][$prt['delete']]=&$this->_spool[$id];
            else $this->_priority['delete'][]=&$this->_spool[$id];
            if (!empty($prt['fetch'])) $this->_priority['fetch'][$prt['fetch']]=&$this->_spool[$id];
            else $this->_priority['fetch'][]=&$this->_spool[$id];
            if (!empty($prt['precheck'])) $this->_priority['precheck'][]=$id;
        }
        ksort($this->_priority['save']);
        ksort($this->_priority['delete']);
        ksort($this->_priority['fetch']);

        return $this;
    }

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id The id of the cache entry to fetch.
     *
     * @return mixed The cached data or FALSE, if no cache entry exists for the given id.
     */
    function fetch($id) {
        /**
         * @var $adapter \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['fetch'] as $adapter_id => $adapter) {
            $tmp=[];
            if (array_key_exists($adapter_id, $this->_priority['precheck'])) {
                if (!$adapter->contains($id)) {
                    $tmp[]=$adapter;
                    continue;
                }
                else {
                    break;
                }
            }
            break;
        }
        $data=$adapter->fetch($id);
        if (!empty($tmp)) {
            array_reverse($tmp);
            foreach ($tmp as $adapter) {
                $adapter->save($id, $data);
            }
        }
        return $data;
    }

    /**
     * Tests if an entry exists in the cache.
     *
     * @param string $id The cache id of the entry to check for.
     *
     * @return boolean TRUE if a cache entry exists for the given cache id, FALSE otherwise.
     */
    function contains($id) {
        /**
         * @var $adapter \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['fetch'] as $adapter) {
            if ($adapter->contains($id)) return true;
        }
        return false;
    }

    /**
     * Puts data into the cache.
     *
     * @param string $id       The cache id.
     * @param mixed  $data     The cache entry/data.
     * @param int    $lifeTime The cache lifetime.
     *                         If != 0, sets a specific lifetime for this cache entry (0 => infinite lifeTime).
     *
     * @return boolean TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    function save($id, $data, $lifeTime = 0) {
        /**
         * @var $adapter \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['save'] as $adapter) {
            $adapter->save($id, $data, $lifeTime);
        }
    }

    /**
     * Deletes a cache entry.
     *
     * @param string $id The cache id.
     *
     * @return boolean TRUE if the cache entry was successfully deleted, FALSE otherwise.
     */
    function delete($id) {
        /**
         * @var $adapter \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['delete'] as $adapter) {
            $adapter->delete($id);
        }
    }

    function getStats() {
        $stat=[];
        /**
         * @var $adapter \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['fetch'] as $adapter_id => $adapter) {
            $stat[$adapter_id]=$adapter->getStats();
        }
        return $stat;
    }

}


class Applejackyll extends \stdClass{

    public  $site=['pages'=>[],'posts'=>[],'categories'=>[],'tags'=>[]];
    protected $_ids, $_categories, $_tags;
    protected $_cache;
    private $_page=[
//                    'layout'=>'post'
//                    ,'id'=>null
//                    ,'date'=>null
//                    ,'title'=>''
//                    ,'content'=>''
//                    ,'permalink'=>null
//                    ,'path'=>null
//                    ,'previous'=>null
//                    ,'next'=>null
//                    ,'published'=>true
//                    ,'categories'=>[]
//                    ,'tags'=>[]
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
        $this->site=new \ArrayObject($this->site,\ArrayObject::ARRAY_AS_PROPS);
    }

    protected function phase1_analyze(){

        $site=&$this->site;
        $site['time']=TIMESTART;

        if (!empty($site['timezone'])) date_default_timezone_set($site['timezone']);

        if (empty($site['root'])) $site['root']=getcwd();
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

        if (empty($site['temp'])) $site['temp']=sys_get_temp_dir();
        $this->_cache=new CacheSpooler($site['cache'], $site['root'].DIRECTORY_SEPARATOR.$site['temp']);
        $categories=[]; $tags=[]; $ids=[];
        /**
         * @var $file \SplFileInfo
         */
        foreach ($posts as $file) { //  перевернём позже
            $this->phase1_file_prepare($file);
        }
        $this->_cache->save('$categories',$this->_categories);
        $this->_cache->save('$tags',$this->_tags);
        $this->_cache->save('$ids',$this->_ids);
//var_dump($page);
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

        return $this;
    }

    /**
     * @param $file \SplFileInfo
     */
    protected function phase1_file_prepare($file){

        $page=$this->site['defaults']['values'];
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

        $page['hash']=sha1_file($realpath);

        $relative_path=$file->getRelativePath();
        $this->_ids[]=$page['id']=(!empty($relative_path)?$relative_path.DIRECTORY_SEPARATOR:'').($file->getBasename('.'.$file->getExtension()));

        $page['type']=strtolower($file->getExtension());

        if (empty($page['date'])) {
            !($page['date']=strtotime($relative_path)) && $page['date']=$file->getMTime();
        }
//            $page['permalink']=
        $page['url']=$this->site['baseurl']
            .(!empty($relative_path)?$relative_path.'/':'')
            .($file->getBasename($file->getExtension())).'html';    //  hardcode
                                                                                                                                                                        if (!empty($site['transliteration'])) $page['url']=\Behat\Transliterator\Transliterator::urlize($page['url']);

        $page['path']=$realpath;  //  raw
        //
        if (!empty($page['category'])) $page['categories'][]=$page['category'];
        if (!empty($page['tag'])) $page['tags'][]=$page['tag'];

        foreach ($page['categories'] as $i) {
            $this->_categories[$i][]=$page['id'];
        }
        foreach ($page['tags'] as $i) {
            $this->_tags[$i][]=$page['id'];
        }

        /**
         * @var $this->_cache CacheSpooler
         */
        $this->_cache->save('page#'.$page['id'],$page);
    }

    protected function phase2_synthesis(){
        $site=$this->site;
        $cache=new CacheSpooler($site['cache'], $site['root'].DIRECTORY_SEPARATOR.$site['temp']);
        $categories=$cache->fetch('$categories');
        $tags=$cache->fetch('$tags');
        $posts=$ids=$cache->fetch('$ids');
        array_reverse($posts);
        var_dump($posts);
        foreach ($ids as $id) {

        }
    }

    protected function phase3_additive(){

    }

    /**
     *
     *
     * @param string $data pagers data
     * @return $this
     */
    public function parse($data=null){
        $this->phase1_analyze();
        $this->phase2_synthesis();
//        $filesystem=new Filesystem();
//        $tmp_path=$site['root'].DIRECTORY_SEPARATOR.$site['temp'].DIRECTORY_SEPARATOR;
        die;
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
