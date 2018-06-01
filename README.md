# Applejackyll

Jeckyll clone

## Install

* with composer.json

```
    "require": {
		"php":">5.3",

		"etconsilium/applejackyll": "dev-master"
    }
    ,
    "repositories": [
	{
            "type": "vcs",
            "url": "https://github.com/etconsilium/applejackyll"
        }
    ]

```

* w\o composer

download & unzip

```
$ilex = new \Silex\Application();
$ilex['autoloader']=new \Composer\Autoload\ClassLoader;
$ilex['autoloader']->add('Applejackyll',__DIR__.'/vendor/etconsilium/applejackyll/src');
$ilex['autoloader']->register();

```

## Example

```
use \Applejackyll\Applejackyll;

$ajk=new Applejackyll('_config.yaml');
$ajk->parse();
```
or
```
(new \Applejackyll\Applejackyll)->init('_config.yaml')->parse();
```

## Enjoy!

~VS \wl
