<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/';
if(str_starts_with($path,'/api/')){require __DIR__.'/api/index.php';return true;}
$pageMap=['/safe-zone'=>'safe-zone.php','/settings'=>'settings.php'];if(isset($pageMap[$path])){require __DIR__.'/'.$pageMap[$path];return true;}
$file=__DIR__.$path;if($path!=='/'&&is_file($file))return false;
require __DIR__.'/index.php';
