<?php namespace Applejackyll;

define ('TIMESTART',microtime(1));
define ('SITE_CONFIG',__DIR__.'/site.yaml');

use \Silex\Application;
//use Apache\Log4php;
use \Symfony\Component\Finder\Finder;
use \Symfony\Component\Filesystem\Filesystem;
//use \Symfony\Component\Yaml\Yaml;   //  кривой
use \Pimple;
use \Eden\System;   //  внедрить
use \Eden\Type;
use \Eden\Core;
//use \mustangostang\spyc;    //  добавить в композер

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
            $page=array_replace_recursive($page,spyc_load_file($fi));
            $page['content']=$page[0]; unset($page[0]);

            //
            $page['url']=
            $page['permalink']=$site['baseurl']
                .$site['destination'].DIRECTORY_SEPARATOR
                .($fi->getRelativePath()).DIRECTORY_SEPARATOR
                .($fi->getBasename($fi->getExtension())).'html';    //  hardcode

            $target[(string)$fi]=$fn=$site['root'].DIRECTORY_SEPARATOR.$page['permalink'];

            $page['date']=$fi->getMTime();
            $page['path']=(string)$fi;  //  raw
            $page['id']=md5_file((string)$fi);  //  нужен неизменяемый вариант для адреса в рсс\атом

            //
            $a=\Spyc::YAMLDump($page,2,0); var_dump($a);
            $filesystem->dumpFile($fn,\Spyc::YAMLDump($page,2,0),0644);

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
        $config && $this->init($config);

        return $this;
    }

}
