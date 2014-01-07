<?php namespace Applejackyll;

define ('TIMESTART',microtime(1));
define ('SITE_CONFIG',__DIR__.'/site.yaml');

use \Symfony\Component\Finder\Finder;
use \Symfony\Component\Filesystem\Filesystem;
//use \Eden\System;   //  внедрить вместо ^
//use \Eden\Type;
//use \Eden\Core;
//use \mustangostang\spyc;    //  добавить в композер psr0:{...}
//use \Symfony\Component\Yaml\Yaml;   //  кривой
use TwigTestExtension;
use Twig_Autoloader;
use Twig_Environment;
use Twig_Extension;
use \Aptoma\Twig\Extension\MarkdownExtension;
use \Aptoma\Twig\Extension\MarkdownEngine;

class Applejackyll{

    public $config=array('site'=>array(),'page'=>array());
    private $page=array(
                        'layout'=>'post'
                        ,'title'=>''
                        ,'permalink'=>''
                        ,'published'=>true
                        ,'categories'=>array()
                        ,'tags'=>array()
                       );
    protected $target=array();


    public function __construct($config=null){
        $config && $this->init($config);
    }
    /**
     * Parser initialization
     *
     * @param string Filename (or data-array)
     * @return $this
     */
    public function init($config){
        $site=&$this->config['site'];
        $page=&$this->config['page'];
        $page=$this->page;
        $target=&$this->target;

        $site['time']=TIMESTART;
        $site['posts']=array();
        $site['categories']=array();
        $site['tags']=array();

        $site=array_replace_recursive(
            $site
            ,spyc_load_file(SITE_CONFIG)
            ,spyc_load_file($config)
        );

        if (!empty($site['timezone'])) date_default_timezone_set($site['timezone']);

        $source=$site['root'].DIRECTORY_SEPARATOR.$site['source'];

        $finder=(new Finder)->files();
        foreach ($site['include'] as $fn) $finder->name($fn);
        foreach ($site['notname'] as $fn) $finder->notName($fn);
        $site['posts']=$finder
            ->in($source)
            ->exclude($site['exclude'])
            ->ignoreDotFiles(1)
            ->ignoreVCS(1)
            ->ignoreUnreadableDirs(1)
            ->sortByName()
        ;

        $filesystem=new Filesystem();

        foreach ($site['posts'] as $fi)
        {
            //
            //$page=array_replace_recursive($page,spyc_load_file($fi)); //	оба парсера работают неудовлетворительно
            $ar=explode('---',trim(file_get_contents($fi)));
            //	считаем, что это yaml-front-matter и парсим его на конфиг
            $page=array_replace_recursive($page,spyc_load($ar[1]));
            $page['content']=trim($ar[2]);

            //
            $page['url']=
            $page['permalink']=$site['baseurl']
                .$site['destination'].DIRECTORY_SEPARATOR
                .($fi->getRelativePath()).DIRECTORY_SEPARATOR
                .($fi->getBasename($fi->getExtension())).'html';    //  hardcode

            $target[(string)$fi]=$fn=$site['root'].DIRECTORY_SEPARATOR.$page['permalink'];  //  здесь преобразование имён

            $page['date']=$fi->getMTime();
            $page['path']=(string)$fi;  //  raw
            $page['id']=md5_file((string)$fi);  //  нужен неизменяемый вариант для адреса в рсс\атом

            //
            $filesystem->dumpFile($fn,\Spyc::YAMLDump($page),0644);

            //
            if (!empty($page['category'])) $page['categories'][]=$page['category'];
            if (!empty($page['tag'])) $page['tags'][]=$page['tag'];

            foreach ($page['categories'] as $tmp) {
                //  два действия сразу
                $site['categories'][$tmp][]=$page['permalink'];
                $filesystem->symlink($fn,$site['root'].DIRECTORY_SEPARATOR.$site['destination'].DIRECTORY_SEPARATOR.$site['category_dir'].$page['permalink']);
            }
            foreach ($page['tags'] as $tmp) {
                $site['tags'][$tmp][]=$page['permalink'];
                $filesystem->symlink($fn,$site['root'].DIRECTORY_SEPARATOR.$site['destination'].$site['tag_dir'].$page['permalink']);
            }
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
