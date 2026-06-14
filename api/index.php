<?php

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error !== null) {
        file_put_contents('php://stderr', "\n==== REAL PHP SHUTDOWN ERROR ====\n");
        file_put_contents('php://stderr', print_r($error, true));
        file_put_contents('php://stderr', "\n==== END REAL PHP SHUTDOWN ERROR ====\n");
    }
});

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
