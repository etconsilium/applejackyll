<?php
set_time_limit(0);
date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/vendor/autoload.php';

use \Eloquent\Pathogen\Path as EPath;
use \Eloquent\Pathogen\FileSystem\FileSystemPath as EFSPath;
use \Eloquent\Pathogen\Resolver\FixedBasePathResolver as FPResolver;


use Eloquent\Pathogen\FileSystem\FileSystemPath;

$basePath = FileSystemPath::fromString(getcwd());
//$basePath = FileSystemPath::fromString('/path/to/foo');
$relativePath = FileSystemPath::fromString('./');
$absolutePath = FileSystemPath::fromString(getcwd().'/path/to/qux');

echo $basePath->resolve($relativePath)->normalize().PHP_EOL; // outputs '/path/to/foo/bar/baz'
echo $basePath->resolve($absolutePath).PHP_EOL; // outputs '/path/to/qux'

echo $relativePath->resolveAgainst($basePath).PHP_EOL; // outputs '/path/to/foo/bar/baz'

die;


$fp='';
/**
 * @var EPath
 */
$p=  EPath::fromString($fp);
/**
 * @var EFSPath
 */
$fsp =  EFSPath::fromString($fp);
//$p =  EFSPath::fromString('_posts');
$r = new FPResolver($p);

var_dump($p->name());

var_dump((string)$r->resolve(EFSPath::fromString('/vs/')->normalize()));