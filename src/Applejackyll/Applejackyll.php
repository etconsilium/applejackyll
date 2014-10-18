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
use \Symfony\Component\Yaml\Yaml;   //  кривой
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
        is_array($cache_list) && $this->init($cache_list, $default_temp);
        return $this;
    }

    public function init($configs, $default_temp=null){
        //  второй параметр злой хардкод
        $this->_tmpdir = empty($default_temp) ? sys_get_temp_dir().DIRECTORY_SEPARATOR.APP_ID : $default_temp ;
        foreach ($configs as $id=>$c) {
            $prt=array_merge(['save'=>50,'delete'=>50,'fetch'=>50,'precheck'=>false],(!empty($c['priority'])?$c['priority']:[]));
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

            $this->_priority['save'][$id]=$prt['save'];
            $this->_priority['delete'][$id]=$prt['delete'];
            $this->_priority['fetch'][$id]=$prt['fetch'];
            $this->_priority['precheck'][$id]=$prt['precheck'];
        }

        asort($this->_priority['save']);
        asort($this->_priority['delete']);
        asort($this->_priority['fetch']);
        $this->_priority['precheck']=array_filter($this->_priority['precheck']);

        $this->_priority['save']=array_keys($this->_priority['save']);
        $this->_priority['delete']=array_keys($this->_priority['delete']);
        $this->_priority['fetch']=array_keys($this->_priority['fetch']);
        $this->_priority['precheck']=array_keys($this->_priority['precheck']);

        return $this;
    }

    /**
     * Fetches an entry from the cache.
     *
     * @param string $id The id of the cache entry to fetch.
     *
     * @return mixed The cached data or FALSE, if no cache entry exists for the given id.
     */
    function fetch($id){
        $tmp=[]; $data=null;
        /**
         * @var $adapter \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['fetch'] as $adapter_id) {
            $adapter=&$this->_spool[$adapter_id];
            if (array_key_exists($adapter_id, $this->_priority['precheck'])) {
                if (!$adapter->contains($id)) {
                    $tmp[]=$adapter;
                    continue;
                }
                else {
                    $data=$adapter->fetch($id);
                    break;
                }
            }
            else {
                $data=$adapter->fetch($id);
                break;
            }
        }
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
    function contains($id){
        /**
         * @var $this->_spool[$adapter_id] \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['fetch'] as $adapter_id) {
            if ($this->_spool[$adapter_id]->contains($id)) return true;
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
     * @return boolean TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    function save($id, $data, $lifeTime = 0){
        /**
         * @var $this->_spool[$adapter_id] \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['save'] as $adapter_id) {
            $this->_spool[$adapter_id]->save($id, $data, $lifeTime);
        }
    }

    /**
     * Deletes a cache entry.
     *
     * @param string $id The cache id.
     *
     * @return boolean TRUE if the cache entry was successfully deleted, FALSE otherwise.
     */
    function delete($id){
        /**
         * @var $this->_spool[$adapter_id] \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['delete'] as $adapter_id) {
            $this->_spool[$adapter_id]->delete($id);
        }
    }

    function getStats(){
        $stat=[];
        /**
         * @var $this->_spool[$adapter_id] \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['fetch'] as $adapter_id) {
            $stat[$adapter_id]=$this->_spool[$adapter_id]->getStats();
        }
        return $stat;
    }

    function flushAll($id=null){
        if (array_key_exists($id,$this->_spool)) {
            $this->_spool[$id]->flushAll();
            return;
        }
        /**
         * @var $adapter \Doctrine\Common\Cache\Cache
         */
        foreach ($this->_priority['delete'] as $adapter_id) {
            $this->_spool[$adapter_id]->flushAll();
        }
        return;
    }
}


class Applejackyll extends \stdClass{

    CONST VERSION='1.4.18.13';
    CONST CONFIG_FILENAME='site.yaml';

    public  $site=['pages'=>[],'posts'=>[],'categories'=>[],'tags'=>[]];
    protected $_ids=[];
    protected $_posts=[];
    protected $_categories=[];
    protected $_tags=[];
    protected $_urls=[];
    protected $_cache;
    protected $_page=[
        //  _config.yaml:defaults
                   ];

    public function __construct($configfile=null)
    {
        if (is_string($configfile)) {
            $this->init($configfile);
        }

        return $this;
    }

    /**
     * Parser initialization
     *
     * @param string $configfile
     * @throws FileNotFoundException
     * @return $this
     */
    public function init($configfile)
    {
        if (!(is_string($configfile) && is_file($configfile) && is_readable($configfile))) {
            //  try search
            $finder=(new Finder)->files();
            $files=$finder
                ->name(self::CONFIG_FILENAME)
                ->useBestAdapter()
                ->in(getcwd())
                ->ignoreDotFiles(1)
                ->ignoreVCS(1)
                ->ignoreUnreadableDirs(1)
            ;
            foreach ($files as $file) {$configfile=$file->getRealPath();break;}
        }
        try {
            $c=file_get_contents($configfile);
        } catch (\Exception $e) {
            throw new \Symfony\Component\Filesystem\Exception\FileNotFoundException($e->getMessage()); die;
        }

        $this->site=\Symfony\Component\Yaml\Yaml::parse( $c ); unset($c);
        $this->site=new \ArrayObject($this->site,\ArrayObject::ARRAY_AS_PROPS);

//        $this->site['page']=&$this->_page;
        $this->_page=&$this->site['page'];

        $site=&$this->site;

        $site['time']=TIMESTART;
        if (!empty($site['timezone'])) date_default_timezone_set($site['timezone']);
        if (empty($site['root'])) $site['root']=getcwd();
        $site['source_path']=$site['root'].DIRECTORY_SEPARATOR.$site['source'];

        if (empty($site['temp'])) $site['temp']=sys_get_temp_dir();
        if (!is_dir($site['temp'])) {
            $site['temp']=$site['root'].DIRECTORY_SEPARATOR.$site['temp'];
            @mkdir($site['temp'],0755,1);
        }
        $this->_cache=new CacheSpooler($site['cache'], $site['temp']);

        if (!is_dir($site['destination'])) {
            $site['destination']=$site['root'].DIRECTORY_SEPARATOR.$site['destination'];
            @mkdir($site['destination'],0755,1);
        }

        if (!empty($site['frontmatter']) || !in_array($site['frontmatter'], ['jekyll','phrozn'] ) )
            $site['frontmatter']='jekyll';

        return $this;
    }

    protected function phase1_analyze()
    {
        $site=&$this->site;

        $finder=(new Finder)->files();
        foreach ($site['include'] as $fn) $finder->name($fn);
        foreach ($site['exclude'] as $fn) $finder->exclude($fn);
        foreach ($site['notname'] as $fn) $finder->notName($fn);
        $posts=$finder
            ->useBestAdapter()
            ->in($this->site['source_path'])
            ->ignoreDotFiles(1)
            ->ignoreVCS(1)
            ->ignoreUnreadableDirs(1)
            ->sortByName()
        ;

        /**
         * @var $file \SplFileInfo
         */
        foreach ($posts as $file) { //  перевернём позже
            $this->phase1_file_prepare($file);
        }
        $this->_cache->save('$categories',$this->_categories);
        $this->_cache->save('$tags',$this->_tags);
        $this->_cache->save('$ids',$this->_ids);
        arsort($this->_posts); $this->_posts=array_keys($this->_posts);
        $this->_cache->save('$posts',$this->_posts);
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
     * @param $file \Symfony\Component\Finder\SplFileInfo
     * @return $this
     */
    protected function phase1_file_prepare($file)
    {
        //  не перепутайте пути
        $basename=$file->getBasename();
        $realpath=$file->getRealPath();
        $relative_path=$file->getRelativePath();
//        $source_path=$this->site['source_path'];
//        $dest_path=$this->site['dest_path'];

        //  порядок заполненения переменных подчиняется внутренней логике
        $page=$this->site['defaults']['values'];

        $page['source_path']=$realpath;  //  raw
//
        $page['hash']=sha1($realpath.md5_file($realpath));
        $page['type']=strtolower($file->getExtension());

        //  FrontMatter default like a jekyll
        //  'jekyll' = yaml between two tripledash
        //  'phrozn' = one tripledash delimiter
        $tripledash_pattern='~^---$~';
        $fc=trim($file->getContents());
        if ('phrozn'==$this->site['frontmatter']) {
            $a = preg_split($tripledash_pattern,$fc,2);
        }
        elseif ('jekyll'==$this->site['frontmatter']) {
            $a = preg_split($tripledash_pattern,$fc,3);
            array_shift($a);
        }
        else {
            throw new \Symfony\Component\Yaml\Exception\ParseException('parsing trouble'); die;
        }

        if (1===count($a)) {
            //  не пост
            $page['content']=trim($a[0]);
            $page['layout']=null;
            $page['dest_path']=$this->site['destination']
                .( !empty($relative_path) ? DIRECTORY_SEPARATOR.$relative_path : '' )
                .DIRECTORY_SEPARATOR.$basename;
            $page['date']=new \DateTime(date('Y-m-d H:i:s',$file->getMTime()));
        }
        else {
            //  пост и много переменных. и много неоптимальной магии

            $page=array_replace_recursive($page,(array)Yaml::parse($a[0]));
            $page['content']=trim($a[1]);

            //  заголовок и имя файла без расширения
            if (empty($page['title'])) $page['title']=$file->getBasename('.'.$file->getExtension());
            $filename=(empty($this->site['transliteration']) ? \URLify::filter($page['title']) : $page['title']);

            //  ошибка внутренней даты
            if (!empty($page['date']) && !strtotime($page['date'])) {
                trigger_error("Invalid date format `{$page['date']}` in file `{$realpath}`",E_USER_NOTICE);
            }
            elseif (empty($page['date'])) { //  ещё больше магии

                //  дата не была указана. пытаемся выделить из пути
                $pattern='*/?(?<year>\d{4})[\\\\.\-\s/](?<month>\d{2})[\\\\.\-\s/](?<day>\d{2})(?!-|/)?*';
                $a=[];  //  all'sok
                $a=preg_split($pattern,$relative_path,2);

                if (1===sizeof($a)) {
                    //  нет даты, не надо подставлять в путь
                    $page['dest_path']=$this->site['destination']
                        .( !empty($relative_path) ? DIRECTORY_SEPARATOR.$relative_path : '' )
                        .DIRECTORY_SEPARATOR.$filename
                        .'.html';   //  hardcode ext
                    $page['url']=$this->site['baseurl'].$filename.'.html';

                    //  забираем дату из времени модификации файла
                    $page['date']=new \DateTime(date('Y-m-d H:i:s',$file->getMTime()));

                    //  вдруг есть категории
                    $page['categories']=array_merge($page['categories'],array_filter(explode(DIRECTORY_SEPARATOR,$relative_path)));
                }
                else {
                    //  перваяя часть - категории, вторая - что-то ещё, ненужное и неинтересное
                    $page['categories']=array_merge($page['categories'],array_filter(explode(DIRECTORY_SEPARATOR,$a[0])));

                    //  достаём дату тем же шаблоном
                    preg_match_all($pattern,$basename,$a,PREG_SET_ORDER);   //  all'sok
                    $a=array_shift($a);
                    if (is_array($a))
                        $page['date']=new \DateTime($a[0]);
                    else
                        $page['date']=new \DateTime(date('Y-m-d H:i:s',$file->getMTime()));

                    $page['dest_path']=$this->site['destination']
                        .DIRECTORY_SEPARATOR.$page['date']->format('Y'.DIRECTORY_SEPARATOR.'m'.DIRECTORY_SEPARATOR.'d')
                        .DIRECTORY_SEPARATOR.$filename
                        .'.html';   //  hardcode ext
                    $page['url']=$this->site['baseurl'].$page['date']->format('Y/m/d/').$filename.'.html';
                }
            }

            if (!empty($page['slug']))
                $page['url']=$this->site['baseurl'].(!empty($this->site['transliteration']) ? \URLify::filter($page['slug']) : $page['slug']).'.html';

            if (in_array($page['url'],$this->_urls))    //  вдруг коллизия
                $page['url']=str_replace('.html','-'.count($this->_urls).'.html',$page['url']);    //  если не добавлять статей задним числом, то номера совпадут

            $this->_urls[]=$page['url'];
            $page['permalink']=$this->site['baseurl'].$page['hash'].'.html';

            //  @TODO + shorter() $page['shortlink'] or twig-plugin shorter, clicker
        }

        $this->_ids[]=$page['id']=$page['url'];
        $this->_posts[$page['id']]=$page['date']->getTimestamp();


        //
        if (!empty($page['category'])) is_array($page['category'])?$page['categories']=array_merge($page['categories'],$page['category']):$page['categories'][]=$page['category'];
        if (!empty($this->site['categiries_path'])) $page['categories']=array_merge($page['categories'],explode(DIRECTORY_SEPARATOR,$relative_path));
        if (!empty($page['tag'])) is_array($page['tag'])?$page['tags']=array_merge($page['tags'],$page['tag']):$page['tags'][]=$page['tag'];

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

        return $this;
    }

    protected function phase2_synthesis()
    {
        $site=$this->site;
        $cache=$this->_cache;
        $categories=$cache->fetch('$categories');
        $tags=$cache->fetch('$tags');
        $ids=$cache->fetch('$ids');
        $posts=$cache->fetch('$posts');
        $site['categories']=$categories;
        $site['tags']=$tags;
        $site['posts']=$posts;  //  A reverse chronological list of all Posts. i do not know that it will contains
//        $site['pages']=$pages;  //  A list of all Pages. i do know that php havent resources for _all_ pages
        $prev=null;$next=null;
        foreach ($posts as $id) {
            $page=$cache->fetch('page#'.$id);
            $page['prev']=&$prev;
            if ($prev) $page['prev']['next']=&$page;
//            $site['html_pages'][$id]=$this->phase2_page_parse($page);
            @mkdir(dirname($page['dest_path']),0775,1); //  предохранительный костыль
            file_put_contents($page['dest_path']
                ,$this->phase2_page_parse($page), LOCK_EX);

            $prev=$page;
        }
    }

    protected function phase2_page_parse($page)
    {
        $site=&$this->site;
        $this->_page=&$page;

        \Twig_Autoloader::register();
        $twig=new \Twig_Environment(new \Twig_Loader_Filesystem($site['root'].DIRECTORY_SEPARATOR.$site['layouts'])
            ,['cache'=>$site['temp'], 'auto_reload'=>true, 'autoescape'=>false]);

        $engine = new \Aptoma\Twig\Extension\MarkdownEngine\MichelfMarkdownEngine();
        $parser=new \Twig_Environment(new \Twig_Loader_String(),['cache'=>$site['temp'], 'auto_reload'=>true, 'autoescape'=>false]);
        $parser->addExtension(new \Aptoma\Twig\Extension\MarkdownExtension($engine));

//        if ('md'===$page['type']) {
        if (!empty($page['layout'])) {
            $tmp_content=$page['content'];
            $page['content']='{% markdown %}'.$page['content'].'{% endmarkdown %}';
            $content=$twig->render($page['layout'].'.twig'
                ,['site'=>$site, 'page'=>$page, 'content'=>$page['content']]
            );
            $page['content']=$tmp_content;

            return $parser->render($content, ['site'=>$site, 'page'=>$page, 'content'=>$page['content']]);
        }
        else {
            return $twig->render($page['path'],['site'=>$site, 'page'=>$page]);
        }
    }

    protected function phase3_additive()
    {
        $site=$this->site;
        if (!empty($this->site['copy'])) {
            $finder=(new Finder)->files();
            $finder->name('*');
            foreach ($site['include'] as $fn) $finder->exclude($fn);
            foreach ($site['exclude'] as $fn) $finder->exclude($fn);
            foreach ($site['notname'] as $fn) $finder->notName($fn);
            $files=$finder
                ->useBestAdapter()
                ->in($this->site['source_path'])
                ->ignoreDotFiles(1)
                ->ignoreVCS(1)
                ->ignoreUnreadableDirs(1)
                ->sortByName()
            ;
            foreach ($files as $file) {
                $realpath=$file->getRealPath();
                copy($realpath, str_replace($this->site['root'], $this->site['destination'], $realpath));
            }
        }
    }


    public function clearCache($cache_id = null)
    {
        $this->_cache->flushAll($cache_id);
        return $this;
    }

    /**
     * @param \DateTime $after
     * @param \DateTime $before
     * @throws \ErrorException
     * @return $this
     */
    public function deleteByDate($after,$before)
    {
        /**
         * @var {$this->_cache} CacheSpooler
         */
        if (!$this->_cache->contains('$ids'))
            throw new \ErrorException('Cache not ready. Maybe clear?');

        $ids=$this->_cache->fetch('$ids');
        foreach ($ids as $id) {
            $page=$this->_cache->fetch($pid='page#'.$id);
            if ($page['date']>$after || $page['date']<$before)
                $this->_cache->delete($pid);
        }
        return $this;
    }

    /**
     * @param string $data pagers data
     * @return $this
     */
    public function parse($data=null)
    {
        $this->clearCache();
        $this->phase1_analyze();
        $this->phase2_synthesis();
//        $filesystem=new Filesystem();
//        $tmp_path=$site['root'].DIRECTORY_SEPARATOR.$site['temp'].DIRECTORY_SEPARATOR;

        return $this;
    }

    public function urlify($url)
    {
        $url=\URLify::transliterate($url);
        // remove all these words from the string before urlifying
        $remove_pattern = '~[^-.\w\s/?&+]~u';
        $url = preg_replace ($remove_pattern, '', $url);
        $url = str_replace ('_', ' ', $url);
        $url = preg_replace ('~^\s+|\s+$~', '', $url);
//        $url = preg_replace ('~\s{2,}~', ' ', $url);
        $url = preg_replace ('~[-\s]+~', '-', $url);
        $url = strtolower(trim($url, '-'));
        return $url;
    }

    public function server()
    {
        return (new \Applejackyll\Server($this));
    }
}


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