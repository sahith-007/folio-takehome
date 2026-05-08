<?php

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

if (isset($argv[1]) && $argv[1] !== '') {
    $_GET['q'] = $argv[1];
}

require __DIR__ . '/../../public/admin.php';
