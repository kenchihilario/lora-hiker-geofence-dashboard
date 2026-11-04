<?php
require_once __DIR__.'/../src/db.php';
try{require_disposable_test_profile();echo 'OK';}catch(Throwable $e){fwrite(STDERR,'REFUSED: '.$e->getMessage());exit(2);}
