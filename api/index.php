<?php

$_SERVER['HTTP_ACCEPT'] = 'application/json';

$_ENV['APP_BASE_PATH'] = dirname(__DIR__);
$_ENV['VIEW_COMPILED_PATH'] = '/tmp/views';

putenv('APP_BASE_PATH=' . dirname(__DIR__));
putenv('VIEW_COMPILED_PATH=/tmp/views');

if (! is_dir('/tmp/views')) {
    mkdir('/tmp/views', 0755, true);
}

chdir(dirname(__DIR__));

require dirname(__DIR__) . '/public/index.php';
