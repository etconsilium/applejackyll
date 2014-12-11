<?php
require_once __DIR__ . '/vendor/autoload.php';

use \Applejackyll\Storage;

(new \Applejackyll\Applejackyll( './site.yaml' ))->storage();