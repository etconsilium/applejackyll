<?php namespace Applejackyll;

define ('TIMESTART',microtime(1));
define ('APP_SALT',md5_file(__FILE__));
define ('APP_ID',__NAMESPACE__);

use \Symfony\Component\Finder\Finder;
use \Symfony\Component\Yaml\Yaml;   //  кривой
use \Twig_Autoloader;
use \Twig_Environment;
use \Twig_Extension;
use \Aptoma\Twig\Extension\MarkdownExtension;
use \Aptoma\Twig\Extension\MarkdownEngine;
use \Michelf\MarkdownExtra; //  необходимо записывать в композер вручную, автоматически зависимости не подгружаются
use \Eloquent\Pathogen\Path as EPath;
use \Eloquent\Pathogen\FileSystem\FileSystemPath as EFSPath;
use \RecursiveArrayObject as JSObject;


class Applejackyll extends \stdClass{

    CONST VERSION='1.7.18.22';
    CONST CONFIG_FILENAME='site.yaml';

    public  $site=[];
    protected $_ids=[];
    protected $_posts=[];
    protected $_urls=[];
    /**
     * @var \Applejackyll\CacheSpooler
     */
    protected $_cache;
    protected $_page=[
        //  _config.yaml:defaults
                   ];

    public function __construct($configfile=null)
    {
        $this->site['time']=TIMESTART;
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
            //  попытка найти конфигурационный файл
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
        
        $configfile=realpath($configfile);
        try {
            $c=file_get_contents($configfile);
        } catch (\Exception $e) {
            throw new \Symfony\Component\Filesystem\Exception\FileNotFoundException($e->getMessage()); die;
        }
        $this->site=\Symfony\Component\Yaml\Yaml::parse( $c );

        $site=&$this->site;

        //  обработка общих конфигов
        if (!empty($site['timezone'])) date_default_timezone_set($site['timezone']);

//        if (empty($site['root'])) $site['root']='./';
        $basepath = EFSPath::fromString(dirname($configfile));
        $rootpath = $basepath->resolve( EFSPath::fromString($site['root']?:'') );
        $site['root'] = $rootpath->normalize()->string();

        if (!is_dir($site['source'])) {
            $site['source']=$this->_resolve_path($site['source']);
            @mkdir($site['source'],0755,1);
        }
        if (!is_dir($site['destination'])) {
            $site['destination']=$this->_resolve_path($site['destination']);
            @mkdir($site['destination'],0755,1);
        }

        if (empty($site['temp'])) $site['temp']=sys_get_temp_dir();
        if (!is_dir($site['temp'])) {
            $site['temp']=$this->_resolve_path($site['temp']);
            @mkdir($site['temp'],0755,1);
        }
        $this->_cache=new CacheSpooler($site['cache'], $site['temp']);

        if (empty($site['frontmatter']) || !in_array($site['frontmatter'], ['jekyll','phrozn'] ) )
            $site['frontmatter']='jekyll';

        if (empty($this->site['baseurl'])) $this->site['baseurl']='/';
        
        return $this;
    }

    protected function _resolve_path($path, $root=null)
    {
        if (!is_string($root)) $root=$this->site['root'];
        return EFSPath::fromString($root)->resolve( EFSPath::fromString($path))->normalize()->string();
    }
    
   
    protected function phase1_analyze()
    {
        $site=&$this->site;

        $finder=(new Finder);
        $finder->files()
            ->useBestAdapter()
            ->in($this->site['source'])
            ->exclude($this->site['category_dir'])
            ->exclude($this->site['tag_dir'])
            ->ignoreDotFiles(1)
            ->ignoreVCS(1)
            ->ignoreUnreadableDirs(1)
            ->sortByName()
        ;
        foreach ($site['include'] as $fn) $finder->name($fn);
        foreach ($site['exclude'] as $fn) $finder->exclude($fn);
        foreach ($site['notname'] as $fn) $finder->notName($fn);

        /**
         * @var $file \SplFileInfo
         */
        foreach ($finder as $file) { //  перевернём позже
            $this->phase1_file_prepare($file);
        }
        $this->_cache->save('$categories',$site['categories']);
        $this->_cache->save('$tags',$site['tags']);
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
        //  не перепутать пути
        $basepath=$this->site['root'];
        $basename=$file->getBasename();
        $filename=$file->getBasename('.'.$file->getExtension());    //  ниже может перезаписываться
        if ($this->site['transliteration']) $filename=$this->urlify($filename);
        $realpath=$file->getRealPath();
        $relative_path=$file->getRelativePath();

        //  порядок заполненения переменных подчиняется внутренней логике
        $page=$this->site['defaults']['values'];

        $page['source_path']=$realpath;  //  raw

        $page['hash']=sha1($realpath.sha1_file($realpath));
        $page['type']=strtolower($file->getExtension());

        //  FrontMatter default like a jekyll
        //  'jekyll' = yaml between two tripledash
        //  'phrozn' = one tripledash delimiter
        $tripledash_pattern='~^---$~mu';
        $file_contents=trim($file->getContents());
        if ('phrozn'==$this->site['frontmatter']) {
            $a = preg_split($tripledash_pattern,$file_contents,2);
        }
        elseif ('jekyll'==$this->site['frontmatter']) {
            $a = preg_split($tripledash_pattern,$file_contents,3);
            if (1<count($a))    {
                if (!empty($a[0]))
                    trigger_error( sprintf('кажется, в файле `%s` пропущен разделитель',$file->getFilename()), E_USER_WARNING);

                array_shift($a);
            }
        }
        else {
            throw new \Symfony\Component\Yaml\Exception\ParseException('parsing trouble'); die;
        }

        //  готовим пути
        
        if (1===count($a)) {
            //  не было разделителей, не псто
            $page['content']=trim($a[0]);
            $page['layout']=null;
            $page['date']=new \DateTime(date('Y-m-d H:i:s',$file->getMTime()));
            $page['url']=str_replace($this->site['source'].DIRECTORY_SEPARATOR, $this->site['baseurl'], $realpath);
//            $page['dest_path']=str_replace($this->site['source'], $this->site['destination'], $realpath);

        }
        elseif (2===count($a)) {
            //  пост и много переменных. и много неоптимальной магии

            $page=array_replace_recursive($page,(array)Yaml::parse($a[0]));
            $page['content']=trim($a[1]);

            //  ошибка внутренней даты
            if (!empty($page['date']) && !strtotime($page['date'])) {
                trigger_error("Invalid date format `{$page['date']}` in file `{$realpath}`",E_USER_NOTICE);
            }

            if (empty($page['date'])) { //  ещё больше магии
                //  дата не была указана. пытаемся выделить из пути^W имени файла

                $date_pattern='`/?(?<year>\d{4})[\.\-\s/\\\](?<month>\d{2})[\.\-\s/\\\](?<day>\d{2})[\.\-\s/\\\]?(?<filename>.*)[.][^.]*$`ui';
//                $date_pattern='`/?(?<year>\d{4})[\.\-\s/\\\](?<month>\d{2})[\.\-\s/\\\](?<day>\d{2})[\.\-\s/\\\]?`ui';
                $d=[]; $d=preg_split($date_pattern, $basename);

                if (1===sizeof($d)) {
                    //  нет даты, не надо подставлять в путь!
                    $page['url']=$this->site['baseurl'].$relative_path.'/'.$filename.'.html';  //  hardcode ext
                    //  забираем дату из времени модификации файла
                    $page['date']=new \DateTime(date('Y-m-d H:i:s',$file->getMTime()));
                }
                elseif (2===sizeof($d)) {
                    //  достаём дату тем же шаблоном
                    preg_match_all($date_pattern, $basename, $d, PREG_SET_ORDER);
                    array_shift($d[0]); $d=$d[0];

                    if (is_array($d)) {
                        $page['date']=new \DateTime("{$d['year']}-{$d['month']}-{$d['day']}");
                        $filename=$d['filename'];
                    }
                    else {
                        $page['date']=new \DateTime(date('Y-m-d H:i:s',$file->getMTime()));
                        $filename=$page['title'];
                    }
                    if ($this->site['transliteration']) $filename=$this->urlify($filename);

                    $page['url']=$this->site['baseurl'].$page['date']->format('Y/m/d/').$filename.'.html';
                }
            }

        }

        if (!empty($page['slug']))
            $page['url']=$this->site['baseurl'].(!empty($this->site['transliteration']) ? $this->urlify($page['slug']) : $page['slug']).'.html';

        if (in_array($page['url'],$this->_urls))    //  вдруг коллизия
            $page['url']=str_replace('.html','-'.count($this->_urls).'.html',$page['url']);    //  если не добавлять статей задним числом, то номера совпадут

        $this->_urls[]=$page['url'];
        if (empty($page['permalink']))
            $page['permalink']=$this->site['baseurl'].$page['hash'].'.html';

        $page['dest_path']=preg_replace('`^'.$this->site['baseurl'].'`', $this->site['destination'].DIRECTORY_SEPARATOR, $page['url']);
        //  @TODO + shorter() $page['shortlink'] or twig-plugin shorter, clicker

        $this->_ids[]=$page['id']=$page['hash'];
        $this->_posts[$page['id']]=$page['date']->getTimestamp();

        //  категории из пути и переменных
        $page['categories']=array_merge($page['categories'],array_filter(explode(DIRECTORY_SEPARATOR,$relative_path)));
        if (!empty($page['category'])) is_array($page['category'])?$page['categories']=array_merge($page['categories'],$page['category']):$page['categories'][]=$page['category'];
        // @TODO        if (!empty($this->site['categories_path'])) $page['categories']=array_merge($page['categories'],explode(DIRECTORY_SEPARATOR,$relative_path));
        if (!empty($page['tag'])) is_array($page['tag'])?$page['tags']=array_merge($page['tags'],$page['tag']):$page['tags'][]=$page['tag'];

        foreach ($page['categories'] as $i) {
            $this->site['categories'][$i][]=$page['id'];
        }
        foreach ($page['tags'] as $i) {
            $this->site['tags'][$i][]=$page['id'];
        }
        $this->_cache->save('$page#'.$page['id'],$page);

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
            $page=$cache->fetch('$page#'.$id);
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
        $this->site['page']=&$page;

//        if ('md'===$page['type']) {
        if (!empty($page['layout'])) {

            \Twig_Autoloader::register();
            $twig=new \Twig_Environment(new \Twig_Loader_Filesystem($site['root'].DIRECTORY_SEPARATOR.$site['layouts'])
                ,['cache'=>$site['temp'], 'auto_reload'=>true, 'autoescape'=>false, 'strict_variables'=>true]);

            $engine = new \Aptoma\Twig\Extension\MarkdownEngine\MichelfMarkdownEngine();
            $parser=new \Twig_Environment(new \Twig_Loader_String()
                ,['cache'=>$site['temp'], 'auto_reload'=>true, 'autoescape'=>false, 'strict_variables'=>true]);
            $parser->addExtension(new \Aptoma\Twig\Extension\MarkdownExtension($engine));

            $tmp_content=$page['content'];
            $page['content']='{% markdown %}'.$page['content'].'{% endmarkdown %}';

            $content=$twig->render($page['layout'].'.twig', ['site'=>$site, 'page'=>$page, 'content'=>$page['content']]);
            $page['content']=$tmp_content;

            return $parser->render($content, ['site'=>$site, 'page'=>$page, 'content'=>$page['content']]);
        }
        else {
            $parser=new \Twig_Environment(new \Twig_Loader_String()
                ,['cache'=>$site['temp'], 'auto_reload'=>true, 'autoescape'=>false, 'strict_variables'=>true]);
            return $parser->render(file_get_contents($page['source_path']), ['site'=>$site, 'page'=>$page]);
        }
    }

    protected function phase3_copy_files()
    {
        $site=$this->site;
        $finder=(new Finder);
        $finder->files()
            ->name('*')
            ->useBestAdapter()
            ->in($this->site['source'])
            ->exclude($this->site['category_dir'])
            ->exclude($this->site['tag_dir'])
            ->ignoreDotFiles(1)
            ->ignoreVCS(1)
            ->ignoreUnreadableDirs(1)
            ->sortByName()
        ;
        foreach ($site['include'] as $fn) $finder->notName($fn);
        foreach ($site['exclude'] as $fn) $finder->exclude($fn);
        foreach ($site['notname'] as $fn) $finder->notName($fn);
        
        foreach ($finder as $file) {
            $realpath=$file->getRealPath();
            $targetpath=str_replace($this->site['source'], $this->site['destination'], $realpath);
            @mkdir(dirname($targetpath),0755,1);
            copy($realpath, $targetpath);
        }
    }

    protected function phase3_gen_categories()
    {
//        $ctg=$this->_cache->fetch($pid='page#'.$id);
        if (empty($this->site['categories']))
            $this->site['categories']=$this->_cache->fetch('$categories');
        
        $sourcepath=$this->_resolve_path($this->site['category_dir'], $this->site['source']);
        $targetpath=$this->_resolve_path($this->site['category_dir'], $this->site['destination']);
        
        foreach ($this->site['categories'] as $category_name=>$ids) {
            foreach ($ids as $id) {
                $page=$this->_cache->fetch('$page#'.$id);
//                var_dump($page);
//                @TODO: проблема с урлами и путями. проверить, привести к единому виду
            }
        }
    }
    
    protected function phase3_gen_tags()
    {
        
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
        $this->phase3_copy_files();
        $this->phase3_gen_categories();
        $this->phase3_gen_tags();

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

