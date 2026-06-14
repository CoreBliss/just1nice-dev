<?php

$_SERVER['HTTP_ACCEPT'] = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';

if (! str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
}

chdir(__DIR__.'/..');

require __DIR__.'/../public/index.php';